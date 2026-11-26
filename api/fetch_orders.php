<?php
// api/fetch_orders.php - Live order queue feed for the outlet dashboard.
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/order_queue_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Match outlet_dashboard.php's strict RBAC and outlet assignment guard.
if (empty($_SESSION['user_id'])
    || empty($_SESSION['role'])
    || !in_array($_SESSION['role'], ['Staff', 'Admin'], true)
    || empty($_SESSION['outlet_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$outlet_id = intval($_SESSION['outlet_id']);
$orders    = fetch_outlet_order_queue($conn, $outlet_id);

$payload = [];
foreach ($orders as $order) {
    $items = [];
    foreach ($order['items'] as $item) {
        $items[] = [
            'product_name' => $item['product_name'],
            'quantity'     => $item['quantity'],
            'unit_price'   => number_format($item['unit_price'], 2, '.', ''),
            'subtotal'     => number_format($item['subtotal'], 2, '.', ''),
        ];
    }

    $payload[] = [
        'order_id'          => $order['order_id'],
        'order_date'        => $order['order_date'],
        'fulfilment_method' => $order['fulfilment_method'],
        'order_status'      => $order['order_status'],
        'total_amount'      => number_format($order['total_amount'], 2, '.', ''),
        'customer_name'     => $order['customer_name'],
        'items'             => $items,
    ];
}

echo json_encode(
    ['count' => count($payload), 'orders' => $payload],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
