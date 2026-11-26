<?php
// my_orders.php - Customer order history
require_once 'includes/db_connect.php';
require_once 'includes/storefront_helpers.php';

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$role    = $_SESSION['role'] ?? '';

if (!$user_id) {
    header('Location: login.php?redirect=' . rawurlencode('my_orders.php'));
    exit();
}

if ($role !== 'Customer') {
    header('Location: ' . ($role === 'Admin' ? 'admin_dashboard.php' : 'outlet_dashboard.php'));
    exit();
}

$display_name = trim((string)($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Customer'));
$placed_id    = isset($_GET['placed']) ? intval($_GET['placed']) : 0;
$paid         = isset($_GET['paid']) && $_GET['paid'] === '1';
$payment_state = $_GET['payment'] ?? '';
$cancelled_id  = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

$orders = [];
$stmt = $conn->prepare("
    SELECT o.order_id, o.order_date, o.fulfilment_method, o.order_status, o.total_amount,
           ou.outlet_id, ou.outlet_name, ou.location,
           p.payment_method, p.payment_status, p.payment_date, p.transaction_reference,
           oi.order_item_id, oi.quantity, oi.unit_price, oi.subtotal,
           pr.product_id, pr.product_name, pr.image_url, pr.group_id, pr.variant_name,
           g.group_name
    FROM orders o
    JOIN outlet ou ON ou.outlet_id = o.outlet_id
    LEFT JOIN payment p ON p.order_id = o.order_id
    JOIN order_item oi ON oi.order_id = o.order_id
    JOIN product pr ON pr.product_id = oi.product_id
    LEFT JOIN product_group g ON g.group_id = pr.group_id
    WHERE o.user_id = ?
    ORDER BY o.order_date DESC, o.order_id DESC, oi.order_item_id ASC
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $order_id = intval($row['order_id']);

    if (!isset($orders[$order_id])) {
        $orders[$order_id] = [
            'order_id'              => $order_id,
            'order_date'            => $row['order_date'],
            'fulfilment_method'     => $row['fulfilment_method'],
            'order_status'          => $row['order_status'],
            'total_amount'          => (float)$row['total_amount'],
            'outlet_id'             => intval($row['outlet_id']),
            'outlet_name'           => $row['outlet_name'],
            'location'              => $row['location'],
            'payment_method'        => $row['payment_method'],
            'payment_status'        => $row['payment_status'],
            'payment_date'          => $row['payment_date'],
            'transaction_reference' => $row['transaction_reference'],
            'items'                 => [],
        ];
    }

    $row['display_name'] = storefront_display_name($row);
    $row['image_url']    = $row['image_url'] ?: PRODUCT_IMAGE_DEFAULT;

    $orders[$order_id]['items'][] = [
        'product_name' => $row['display_name'],
        'image_url'    => $row['image_url'],
        'quantity'     => intval($row['quantity']),
        'unit_price'   => (float)$row['unit_price'],
        'subtotal'     => (float)$row['subtotal'],
    ];
}
$stmt->close();

$orders = array_values($orders);

$placed_order = null;
if ($paid && $placed_id > 0) {
    foreach ($orders as $order) {
        if ($order['order_id'] === $placed_id) {
            $placed_order = $order;
            break;
        }
    }
}

$storefront_config = [
    'outletId'     => 0,
    'outletName'   => '',
    'isLoggedIn'   => true,
    'userId'       => $user_id,
    'role'         => $role,
    'cartKey'      => STOREFRONT_CART_KEY,
    'defaultImage' => PRODUCT_IMAGE_DEFAULT,
];

$orders_page = [
    'clearCart' => $placed_order !== null,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - e-Koperasi</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/storefront.css">
    <link rel="stylesheet" href="css/orders.css">
</head>
<body class="storefront-body">

<header class="store-header">
    <div class="store-header-inner">
        <a class="store-brand" href="index.php">
            <span class="store-brand-mark">e</span>
            <span>e-Koperasi</span>
        </a>
        <nav class="store-nav" aria-label="Order history navigation">
            <span class="store-user">Hi, <?php echo htmlspecialchars($display_name); ?></span>
            <a class="store-nav-link" href="my_orders.php">My Orders</a>
            <a class="store-nav-link" href="logout.php" data-storefront-logout>Logout</a>
        </nav>
    </div>
</header>

<main class="store-main orders-main">
    <div class="orders-heading">
        <h1>My Orders</h1>
        <p>Track your pickup orders, payment status, and item breakdown.</p>
    </div>

    <?php if ($placed_order): ?>
        <div class="alert alert-success">
            Payment successful. Order #<?php echo intval($placed_order['order_id']); ?> is pending pickup at
            <?php echo htmlspecialchars($placed_order['outlet_name']); ?>.
        </div>
    <?php endif; ?>

    <?php if ($payment_state === 'cancelled'): ?>
        <div class="alert alert-success">
            Order #<?php echo $cancelled_id > 0 ? intval($cancelled_id) : ''; ?> was cancelled and its stock was restored.
        </div>
    <?php endif; ?>

    <?php if ($payment_state === 'failed'): ?>
        <div class="alert alert-error">Payment failed. Your order is still pending.</div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="orders-empty">
            <h2>No orders yet</h2>
            <p>Your pickup orders will appear here after checkout.</p>
            <a class="btn-outline" href="index.php">Browse the storefront</a>
        </div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($orders as $order): ?>
                <?php
                $payment_status = $order['payment_status'] ?: 'Not recorded';
                $can_pay = in_array($order['order_status'], ['Pending', 'Ready'], true)
                    && in_array($order['payment_status'], ['Pending', 'Failed'], true);
                ?>
                <article class="order-card">
                    <header class="order-card-header">
                        <div>
                            <h2>Order #<?php echo intval($order['order_id']); ?></h2>
                            <p>
                                <?php echo htmlspecialchars($order['order_date']); ?> &middot;
                                <?php echo htmlspecialchars($order['outlet_name']); ?>
                            </p>
                        </div>
                        <div class="order-badges">
                            <span class="badge <?php echo storefront_order_badge_class($order['order_status']); ?>">
                                <?php echo htmlspecialchars($order['order_status']); ?>
                            </span>
                            <span class="badge <?php echo storefront_payment_badge_class($order['payment_status']); ?>">
                                Payment: <?php echo htmlspecialchars($payment_status); ?>
                            </span>
                        </div>
                    </header>

                    <div class="order-meta">
                        <span>Method: <strong><?php echo htmlspecialchars($order['fulfilment_method']); ?></strong></span>
                        <span>Total: <strong><?php echo storefront_money($order['total_amount']); ?></strong></span>
                        <span>Payment: <strong><?php echo htmlspecialchars(storefront_payment_method_label($order['payment_method'])); ?></strong></span>
                    </div>

                    <div class="order-items">
                        <?php foreach ($order['items'] as $item): ?>
                            <div class="order-item">
                                <img src="<?php echo htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(PRODUCT_IMAGE_DEFAULT, ENT_QUOTES, 'UTF-8'); ?>';">
                                <div>
                                    <p class="order-item-name"><?php echo htmlspecialchars($item['product_name']); ?></p>
                                    <p class="order-item-meta">
                                        <?php echo intval($item['quantity']); ?> &times;
                                        <?php echo storefront_money($item['unit_price']); ?> each
                                    </p>
                                </div>
                                <span class="order-item-total"><?php echo storefront_money($item['subtotal']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <footer class="order-card-footer">
                        <span class="order-reference">
                            <?php if (!empty($order['transaction_reference'])): ?>
                                Ref: <?php echo htmlspecialchars($order['transaction_reference']); ?>
                            <?php else: ?>
                                Payment reference pending
                            <?php endif; ?>
                        </span>
                        <div class="order-actions">
                            <?php if ($can_pay): ?>
                                <a class="btn-outline" href="payment.php?order_id=<?php echo intval($order['order_id']); ?>">
                                    <?php echo $order['payment_status'] === 'Failed' ? 'Retry payment' : 'Complete payment'; ?>
                                </a>
                            <?php endif; ?>
                            <a class="btn-outline" href="index.php?outlet_id=<?php echo intval($order['outlet_id']); ?>">Back to store</a>
                        </div>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<footer class="store-footer">
    <p>e-Koperasi &middot; Campus convenience store</p>
</footer>

<script>
window.STOREFRONT = <?php echo json_encode($storefront_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORDERS_PAGE = <?php echo json_encode($orders_page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/main.js"></script>
<script src="js/storefront.js"></script>
<script src="js/orders.js"></script>
</body>
</html>
