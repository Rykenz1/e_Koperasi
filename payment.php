<?php
// payment.php - Simulated payment gateway for customer orders
require_once 'includes/db_connect.php';
require_once 'includes/storefront_helpers.php';

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$role    = $_SESSION['role'] ?? '';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if (!$order_id && isset($_POST['order_id'])) {
    $order_id = intval($_POST['order_id']);
}

if (!$user_id) {
    $redirect = 'payment.php' . ($order_id ? '?order_id=' . $order_id : '');
    header('Location: login.php?redirect=' . rawurlencode($redirect));
    exit();
}

if ($role !== 'Customer') {
    header('Location: ' . ($role === 'Admin' ? 'admin_dashboard.php' : 'outlet_dashboard.php'));
    exit();
}

if ($order_id <= 0) {
    header('Location: my_orders.php');
    exit();
}

$csrf_token = storefront_csrf_token();
$flash      = '';
$flash_type = 'error';

function load_payment_order($conn, int $order_id, int $user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT o.order_id, o.user_id, o.outlet_id, o.order_date,
               o.fulfilment_method, o.order_status, o.total_amount,
               ou.outlet_name, ou.location,
               p.payment_id, p.amount, p.payment_method, p.payment_status,
               p.payment_date, p.transaction_reference
        FROM orders o
        JOIN outlet ou ON ou.outlet_id = o.outlet_id
        LEFT JOIN payment p ON p.order_id = o.order_id
        WHERE o.order_id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $order_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

$order = load_payment_order($conn, $order_id, $user_id);
if (!$order) {
    header('Location: my_orders.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!storefront_csrf_valid($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Please refresh the page and try again.';
    } elseif ($action === 'simulate_payment') {
        $result = $_POST['result'] ?? '';

        if (!in_array($result, ['success', 'failed'], true)) {
            $flash = 'Choose whether the simulated payment succeeded or failed.';
        } elseif (in_array($order['order_status'], ['Cancelled', 'Completed'], true)) {
            $flash = 'This order can no longer be paid.';
        } elseif ($order['payment_status'] === 'Success') {
            $flash = 'This order is already paid.';
        } elseif (empty($order['payment_id'])) {
            $flash = 'No payment record exists for this order.';
        } else {
            $new_status = $result === 'success' ? 'Success' : 'Failed';
            $stmt = $conn->prepare(
                "UPDATE payment
                 SET payment_status = ?, payment_date = NOW()
                 WHERE payment_id = ? AND order_id = ?"
            );
            $stmt->bind_param('sii', $new_status, $order['payment_id'], $order_id);
            $stmt->execute();
            $stmt->close();

            if ($result === 'success') {
                header('Location: my_orders.php?placed=' . $order_id . '&paid=1&outlet_id=' . intval($order['outlet_id']));
                exit();
            }

            header('Location: payment.php?order_id=' . $order_id . '&failed=1');
            exit();
        }
    } elseif ($action === 'cancel_order') {
        if ($order['payment_status'] !== 'Failed') {
            $flash = 'Only an order with a failed payment can be cancelled here.';
        } elseif ($order['order_status'] !== 'Pending') {
            $flash = 'This order can no longer be cancelled.';
        } else {
            $conn->begin_transaction();

            try {
                $lock_order = $conn->prepare(
                    "SELECT order_status, outlet_id FROM orders
                     WHERE order_id = ? AND user_id = ? FOR UPDATE"
                );
                $lock_order->bind_param('ii', $order_id, $user_id);
                $lock_order->execute();
                $locked_order = $lock_order->get_result()->fetch_assoc();
                $lock_order->close();

                if (!$locked_order || $locked_order['order_status'] !== 'Pending') {
                    throw new RuntimeException('This order can no longer be cancelled.');
                }

                $lock_payment = $conn->prepare(
                    "SELECT payment_status FROM payment WHERE order_id = ? FOR UPDATE"
                );
                $lock_payment->bind_param('i', $order_id);
                $lock_payment->execute();
                $locked_payment = $lock_payment->get_result()->fetch_assoc();
                $lock_payment->close();

                if (!$locked_payment || $locked_payment['payment_status'] !== 'Failed') {
                    throw new RuntimeException('Only an order with a failed payment can be cancelled.');
                }

                $items_stmt = $conn->prepare(
                    "SELECT product_id, quantity FROM order_item WHERE order_id = ?"
                );
                $items_stmt->bind_param('i', $order_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $order_items  = [];
                while ($item = $items_result->fetch_assoc()) {
                    $order_items[] = $item;
                }
                $items_stmt->close();

                $outlet_id     = intval($locked_order['outlet_id']);
                $audit_user_id = storefront_audit_user_id($conn, $outlet_id, $user_id);
                $restore_stmt  = $conn->prepare(
                    "UPDATE outlet_inventory
                     SET stock_quantity = stock_quantity + ?
                     WHERE outlet_id = ? AND product_id = ?"
                );
                $log_stmt = $conn->prepare(
                    "INSERT INTO inventory_transaction
                     (outlet_id, product_id, staff_user_id, transaction_type, quantity_changed, remarks)
                     VALUES (?, ?, ?, 'Stock_In', ?, ?)"
                );

                foreach ($order_items as $item) {
                    $quantity   = intval($item['quantity']);
                    $product_id = intval($item['product_id']);

                    $restore_stmt->bind_param('iii', $quantity, $outlet_id, $product_id);
                    $restore_stmt->execute();
                    if ($restore_stmt->affected_rows !== 1) {
                        throw new RuntimeException('Stock could not be restored for one of the order items.');
                    }

                    $remark = 'Order #' . $order_id . ' cancelled after failed payment';
                    $log_stmt->bind_param('iiiis', $outlet_id, $product_id, $audit_user_id, $quantity, $remark);
                    $log_stmt->execute();
                }

                $restore_stmt->close();
                $log_stmt->close();

                $cancel_stmt = $conn->prepare(
                    "UPDATE orders SET order_status = 'Cancelled' WHERE order_id = ?"
                );
                $cancel_stmt->bind_param('i', $order_id);
                $cancel_stmt->execute();
                $cancel_stmt->close();

                $conn->commit();

                header('Location: my_orders.php?payment=cancelled&order_id=' . $order_id);
                exit();
            } catch (Throwable $exception) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                    // No active transaction to roll back.
                }
                error_log('Payment cancellation error: ' . $exception->getMessage());
                $flash = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'We could not cancel this order. Please try again.';
            }
        }
    }
}

$order = load_payment_order($conn, $order_id, $user_id);

$order_items = [];
$stmt_items = $conn->prepare("
    SELECT oi.quantity, oi.unit_price, oi.subtotal,
           p.product_id, p.product_name, p.image_url, p.group_id, p.variant_name,
           g.group_name
    FROM order_item oi
    JOIN product p ON p.product_id = oi.product_id
    LEFT JOIN product_group g ON g.group_id = p.group_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id ASC
");
$stmt_items->bind_param('i', $order_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();
while ($item = $result_items->fetch_assoc()) {
    $item['display_name'] = storefront_display_name($item);
    $item['image_url']    = $item['image_url'] ?: PRODUCT_IMAGE_DEFAULT;
    $order_items[]        = $item;
}
$stmt_items->close();

$store_url = 'index.php?outlet_id=' . intval($order['outlet_id']);
$storefront_config = [
    'outletId'     => intval($order['outlet_id']),
    'outletName'   => $order['outlet_name'],
    'isLoggedIn'   => true,
    'userId'       => $user_id,
    'role'         => $role,
    'cartKey'      => STOREFRONT_CART_KEY,
    'defaultImage' => PRODUCT_IMAGE_DEFAULT,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - e-Koperasi</title>
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
        <nav class="store-nav" aria-label="Payment navigation">
            <a class="store-nav-link" href="my_orders.php">My Orders</a>
            <a class="store-nav-link" href="logout.php" data-storefront-logout>Logout</a>
        </nav>
    </div>
</header>

<main class="store-main payment-main">
    <div class="checkout-heading">
        <a class="back-link" href="my_orders.php">&larr; Back to My Orders</a>
        <h1>Payment simulation</h1>
        <p>Order #<?php echo intval($order['order_id']); ?> &middot; <?php echo htmlspecialchars($order['outlet_name']); ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <section class="checkout-panel payment-summary">
        <h2>Order summary</h2>
        <div class="payment-summary-grid">
            <div class="payment-summary-item">
                <span>Order</span>
                <strong>#<?php echo intval($order['order_id']); ?></strong>
            </div>
            <div class="payment-summary-item">
                <span>Total</span>
                <strong><?php echo storefront_money($order['total_amount']); ?></strong>
            </div>
            <div class="payment-summary-item">
                <span>Method</span>
                <strong><?php echo htmlspecialchars(storefront_payment_method_label($order['payment_method'])); ?></strong>
            </div>
            <div class="payment-summary-item">
                <span>Reference</span>
                <strong><?php echo htmlspecialchars($order['transaction_reference'] ?: 'Not recorded'); ?></strong>
            </div>
        </div>

        <div class="payment-order-items">
            <?php foreach ($order_items as $item): ?>
                <div class="payment-order-item">
                    <span><?php echo htmlspecialchars($item['display_name']); ?> &times; <?php echo intval($item['quantity']); ?></span>
                    <strong><?php echo storefront_money($item['subtotal']); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($order['order_status'] === 'Cancelled'): ?>
        <section class="payment-panel">
            <div class="payment-warning">This order was cancelled and its stock has been restored.</div>
            <a class="btn-outline" href="<?php echo htmlspecialchars($store_url, ENT_QUOTES, 'UTF-8'); ?>">Return to storefront</a>
        </section>
    <?php elseif ($order['payment_status'] === 'Success'): ?>
        <section class="payment-panel">
            <div class="payment-success-note">Payment successful. Your order is pending pickup at the outlet.</div>
            <a class="btn-outline" href="my_orders.php">View My Orders</a>
        </section>
    <?php elseif (empty($order['payment_id'])): ?>
        <section class="payment-panel">
            <div class="payment-warning">No payment record exists for this order. Please contact the outlet.</div>
        </section>
    <?php else: ?>
        <section class="payment-panel">
            <h2>Did the payment succeed?</h2>

            <?php if ($order['payment_status'] === 'Failed'): ?>
                <div class="payment-warning">
                    The previous payment attempt failed. Your order is still pending, so you can retry or cancel it.
                </div>
            <?php endif; ?>

            <p class="form-hint">This is a demonstration gateway. Choose the simulated result for this payment.</p>

            <form method="post" action="payment.php?order_id=<?php echo intval($order['order_id']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="order_id" value="<?php echo intval($order['order_id']); ?>">
                <input type="hidden" name="action" value="simulate_payment">
                <div class="payment-actions">
                    <button type="submit" name="result" value="success" class="btn-payment-success">Payment Successful</button>
                    <button type="submit" name="result" value="failed" class="btn-payment-failed">Payment Failed</button>
                </div>
            </form>

            <?php if ($order['payment_status'] === 'Failed'): ?>
                <form method="post" action="payment.php?order_id=<?php echo intval($order['order_id']); ?>"
                      onsubmit="return confirm('Cancel this order and restore its stock?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="order_id" value="<?php echo intval($order['order_id']); ?>">
                    <input type="hidden" name="action" value="cancel_order">
                    <button type="submit" class="btn-cancel-order">Cancel order</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<footer class="store-footer">
    <p>e-Koperasi &middot; Campus convenience store</p>
</footer>

<script>
window.STOREFRONT = <?php echo json_encode($storefront_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/main.js"></script>
<script src="js/storefront.js"></script>
</body>
</html>
