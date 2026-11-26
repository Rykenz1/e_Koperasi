<?php
// checkout.php - Customer checkout
require_once 'includes/db_connect.php';
require_once 'includes/storefront_helpers.php';

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$role    = $_SESSION['role'] ?? '';

$requested_outlet_id = isset($_GET['outlet_id']) ? intval($_GET['outlet_id']) : 0;
$outlets             = storefront_active_outlets($conn);
$selected_outlet     = storefront_pick_outlet($outlets, $requested_outlet_id);
$outlet_id           = $selected_outlet ? intval($selected_outlet['outlet_id']) : 0;

if (!$user_id) {
    $redirect = 'checkout.php' . ($outlet_id ? '?outlet_id=' . $outlet_id : '');
    header('Location: login.php?redirect=' . rawurlencode($redirect));
    exit();
}

if ($role !== 'Customer') {
    header('Location: ' . ($role === 'Admin' ? 'admin_dashboard.php' : 'outlet_dashboard.php'));
    exit();
}

if (!$selected_outlet) {
    header('Location: index.php');
    exit();
}

$catalog    = storefront_catalog($conn, $outlet_id);
$stock_map  = storefront_catalog_stock_map($catalog);
$csrf_token = storefront_csrf_token();
$errors     = [];
$selected_payment = $_POST['payment_method'] ?? 'FPX';
if (!in_array($selected_payment, ['FPX', 'E_WALLET', 'CARD'], true)) {
    $selected_payment = 'FPX';
}

$customer = [
    'fullname'     => $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Customer',
    'email'        => '',
    'phone_number' => '',
];

$stmt_customer = $conn->prepare("SELECT fullname, email, phone_number FROM user WHERE user_id = ? LIMIT 1");
if ($stmt_customer) {
    $stmt_customer->bind_param('i', $user_id);
    $stmt_customer->execute();
    $row_customer = $stmt_customer->get_result()->fetch_assoc();
    $stmt_customer->close();
    if ($row_customer) {
        $customer['fullname']     = $row_customer['fullname'] ?: $customer['fullname'];
        $customer['email']        = $row_customer['email'] ?? '';
        $customer['phone_number'] = $row_customer['phone_number'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!storefront_csrf_valid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    $fulfilment_method = $_POST['fulfilment_method'] ?? 'Pickup';
    if ($fulfilment_method !== 'Pickup') {
        $errors[] = 'Delivery is not available yet. Please choose pickup.';
    }

    if (!in_array($selected_payment, ['FPX', 'E_WALLET', 'CARD'], true)) {
        $errors[] = 'Please choose a valid payment method.';
    }

    $requested = [];
    $raw_items = json_decode($_POST['cart_json'] ?? '', true);

    if (!is_array($raw_items)) {
        $errors[] = 'Your cart could not be read. Please return to the storefront and try again.';
    } else {
        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product_id = intval($item['product_id'] ?? 0);
            $quantity   = intval($item['quantity'] ?? 0);

            if ($product_id <= 0 || $quantity <= 0) {
                $errors[] = 'Your cart contains an invalid item.';
                break;
            }

            $requested[$product_id] = ($requested[$product_id] ?? 0) + $quantity;
            if ($requested[$product_id] > 99) {
                $errors[] = 'You can order at most 99 units of a single product.';
                break;
            }
        }

        if (empty($requested)) {
            $errors[] = 'Your cart is empty.';
        } elseif (count($requested) > 50) {
            $errors[] = 'Your cart contains too many different products.';
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            $line_items = [];
            $total      = 0.0;

            $stock_check = $conn->prepare("
                SELECT p.product_id, p.product_name, p.price,
                       oi.stock_quantity, oi.status
                FROM outlet_inventory oi
                JOIN product p ON p.product_id = oi.product_id
                WHERE oi.outlet_id = ? AND oi.product_id = ?
                FOR UPDATE
            ");

            foreach ($requested as $product_id => $quantity) {
                $stock_check->bind_param('ii', $outlet_id, $product_id);
                $stock_check->execute();
                $product = $stock_check->get_result()->fetch_assoc();

                if (!$product) {
                    throw new RuntimeException('An item in your cart is no longer sold at this outlet.');
                }
                if ($product['status'] !== 'Active') {
                    throw new RuntimeException($product['product_name'] . ' is currently unavailable.');
                }

                $stock = intval($product['stock_quantity']);
                if ($stock < $quantity) {
                    throw new RuntimeException($product['product_name'] . ' only has ' . $stock . ' left in stock.');
                }

                $price    = (float)$product['price'];
                $subtotal = $price * $quantity;
                $total   += $subtotal;

                $line_items[] = [
                    'product_id'   => intval($product['product_id']),
                    'product_name' => $product['product_name'],
                    'price'        => $price,
                    'quantity'     => $quantity,
                    'subtotal'     => $subtotal,
                ];
            }
            $stock_check->close();

            if (empty($line_items)) {
                throw new RuntimeException('Your cart is empty.');
            }

            $audit_user_id = storefront_audit_user_id($conn, $outlet_id, $user_id);

            $order_stmt = $conn->prepare(
                "INSERT INTO orders (user_id, outlet_id, fulfilment_method, order_status, total_amount)
                 VALUES (?, ?, 'Pickup', 'Pending', ?)"
            );
            $order_stmt->bind_param('iid', $user_id, $outlet_id, $total);
            $order_stmt->execute();
            $order_id = intval($conn->insert_id);
            $order_stmt->close();

            $item_stmt = $conn->prepare(
                "INSERT INTO order_item (order_id, product_id, quantity, unit_price, subtotal)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stock_update = $conn->prepare(
                "UPDATE outlet_inventory
                 SET stock_quantity = stock_quantity - ?
                 WHERE outlet_id = ? AND product_id = ? AND stock_quantity >= ?"
            );
            $log_stmt = $conn->prepare(
                "INSERT INTO inventory_transaction
                 (outlet_id, product_id, staff_user_id, transaction_type, quantity_changed, remarks)
                 VALUES (?, ?, ?, 'Stock_Out', ?, ?)"
            );

            foreach ($line_items as $line) {
                $item_stmt->bind_param(
                    'iiidd',
                    $order_id,
                    $line['product_id'],
                    $line['quantity'],
                    $line['price'],
                    $line['subtotal']
                );
                $item_stmt->execute();

                $stock_update->bind_param('iiii', $line['quantity'], $outlet_id, $line['product_id'], $line['quantity']);
                $stock_update->execute();
                if ($stock_update->affected_rows !== 1) {
                    throw new RuntimeException('Stock changed while placing your order. Please review your cart.');
                }

                $negative_quantity = -1 * intval($line['quantity']);
                $remark            = 'Checkout order #' . $order_id;
                $log_stmt->bind_param('iiiis', $outlet_id, $line['product_id'], $audit_user_id, $negative_quantity, $remark);
                $log_stmt->execute();
            }

            $item_stmt->close();
            $stock_update->close();
            $log_stmt->close();

            $reference = 'TXN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $payment_stmt = $conn->prepare(
                "INSERT INTO payment (order_id, amount, payment_method, payment_status, transaction_reference)
                 VALUES (?, ?, ?, 'Pending', ?)"
            );
            $payment_stmt->bind_param('idss', $order_id, $total, $selected_payment, $reference);
            $payment_stmt->execute();
            $payment_stmt->close();

            $conn->commit();

            header('Location: payment.php?order_id=' . $order_id);
            exit();
        } catch (Throwable $exception) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
                // No active transaction to roll back.
            }
            error_log('Checkout error: ' . $exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'We could not place your order. Please try again.';
        }
    }
}

$store_url    = 'index.php?outlet_id=' . $outlet_id;
$storefront_config = [
    'outletId'     => $outlet_id,
    'outletName'   => $selected_outlet['outlet_name'],
    'isLoggedIn'   => true,
    'userId'       => $user_id,
    'role'         => $role,
    'cartKey'      => STOREFRONT_CART_KEY,
    'defaultImage' => PRODUCT_IMAGE_DEFAULT,
    'catalog'      => $stock_map,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - e-Koperasi</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/storefront.css">
    <link rel="stylesheet" href="css/checkout.css">
</head>
<body class="storefront-body">

<header class="store-header">
    <div class="store-header-inner">
        <a class="store-brand" href="<?php echo htmlspecialchars($store_url, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="store-brand-mark">e</span>
            <span>e-Koperasi</span>
        </a>
        <nav class="store-nav" aria-label="Checkout navigation">
            <span class="store-user">Hi, <?php echo htmlspecialchars($customer['fullname']); ?></span>
            <a class="store-nav-link" href="my_orders.php">My Orders</a>
            <a class="store-nav-link" href="logout.php" data-storefront-logout>Logout</a>
        </nav>
    </div>
</header>

<main class="store-main checkout-main">
    <div class="checkout-heading">
        <a class="back-link" href="<?php echo htmlspecialchars($store_url, ENT_QUOTES, 'UTF-8'); ?>">&larr; Back to storefront</a>
        <h1>Checkout</h1>
        <p>Pickup from <strong><?php echo htmlspecialchars($selected_outlet['outlet_name']); ?></strong> &middot; <?php echo htmlspecialchars($selected_outlet['location'] ?: 'On campus'); ?></p>
    </div>

    <noscript>
        <div class="store-alert">Checkout needs JavaScript enabled so the cart can be loaded.</div>
    </noscript>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
        </div>
    <?php endif; ?>

    <div class="store-alert" id="checkoutNotice" hidden></div>

    <form id="checkoutForm" method="post" action="checkout.php?outlet_id=<?php echo $outlet_id; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="outlet_id" value="<?php echo $outlet_id; ?>">
        <input type="hidden" name="cart_json" id="cartJson" value="">

        <div class="checkout-layout">
            <div class="checkout-main-column">
                <section class="checkout-panel">
                    <h2>Pickup details</h2>
                    <div class="option-grid">
                        <label class="option-card">
                            <input type="radio" name="fulfilment_method" value="Pickup" checked>
                            <span>
                                <span class="option-title">Pickup</span>
                                <span class="option-desc">Collect your order at the counter.</span>
                            </span>
                        </label>
                        <label class="option-card is-disabled">
                            <input type="radio" name="fulfilment_method" value="Delivery" disabled>
                            <span>
                                <span class="option-title">Delivery</span>
                                <span class="option-desc">Coming soon for campus hostels.</span>
                            </span>
                        </label>
                    </div>

                    <div class="contact-summary">
                        <p><span>Name:</span> <?php echo htmlspecialchars($customer['fullname']); ?></p>
                        <?php if (!empty($customer['email'])): ?>
                            <p><span>Email:</span> <?php echo htmlspecialchars($customer['email']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($customer['phone_number'])): ?>
                            <p><span>Phone:</span> <?php echo htmlspecialchars($customer['phone_number']); ?></p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="checkout-panel">
                    <h2>Payment method</h2>
                    <div class="option-grid">
                        <label class="option-card">
                            <input type="radio" name="payment_method" value="FPX" <?php echo $selected_payment === 'FPX' ? 'checked' : ''; ?>>
                            <span>
                                <span class="option-title">FPX Online Banking</span>
                                <span class="option-desc">Simulated bank transfer.</span>
                            </span>
                        </label>
                        <label class="option-card">
                            <input type="radio" name="payment_method" value="E_WALLET" <?php echo $selected_payment === 'E_WALLET' ? 'checked' : ''; ?>>
                            <span>
                                <span class="option-title">E-Wallet</span>
                                <span class="option-desc">Simulated wallet payment.</span>
                            </span>
                        </label>
                        <label class="option-card">
                            <input type="radio" name="payment_method" value="CARD" <?php echo $selected_payment === 'CARD' ? 'checked' : ''; ?>>
                            <span>
                                <span class="option-title">Card</span>
                                <span class="option-desc">Simulated card payment.</span>
                            </span>
                        </label>
                    </div>
                    <span class="form-hint">This is a demo. Your payment starts as Pending and you choose the outcome on the next step.</span>
                </section>
            </div>

            <aside class="checkout-summary" aria-labelledby="summaryTitle">
                <h2 id="summaryTitle">Order summary</h2>
                <div class="checkout-items" id="checkoutItems"></div>
                <div class="checkout-empty" id="checkoutEmpty" hidden>
                    Your cart is empty. <a href="<?php echo htmlspecialchars($store_url, ENT_QUOTES, 'UTF-8'); ?>">Return to the storefront</a>.
                </div>
                <div class="checkout-total">
                    <span>Total</span>
                    <strong id="checkoutTotal">RM 0.00</strong>
                </div>
                <button type="submit" class="btn-checkout btn-place-order" id="placeOrderBtn" disabled>Place order</button>
                <div class="checkout-note">
                    Stock is reserved when the order is placed. You can retry or cancel a failed payment before pickup.
                </div>
            </aside>
        </div>
    </form>
</main>

<footer class="store-footer">
    <p>e-Koperasi &middot; Campus convenience store</p>
</footer>

<script>
window.STOREFRONT = <?php echo json_encode($storefront_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/main.js"></script>
<script src="js/storefront.js"></script>
<script src="js/checkout.js"></script>
</body>
</html>
