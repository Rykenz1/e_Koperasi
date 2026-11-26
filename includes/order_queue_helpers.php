<?php
// includes/order_queue_helpers.php
// Shared read for the outlet order queue and its polling API. Keeping the
// query in one place means the initial page markup and the polled cards
// always describe the same orders.

require_once __DIR__ . '/storefront_helpers.php';

/**
 * Active (Pending/Ready) orders for one outlet, each with its item lines.
 */
function fetch_outlet_order_queue($conn, int $outlet_id): array
{
    $stmt = $conn->prepare("
        SELECT o.order_id, o.order_date, o.fulfilment_method, o.order_status, o.total_amount,
               u.fullname AS customer_name,
               oi.order_item_id, oi.quantity, oi.unit_price, oi.subtotal,
               pr.product_id, pr.product_name, pr.variant_name, g.group_name
        FROM orders o
        JOIN user u ON o.user_id = u.user_id
        LEFT JOIN order_item oi ON oi.order_id = o.order_id
        LEFT JOIN product pr ON pr.product_id = oi.product_id
        LEFT JOIN product_group g ON g.group_id = pr.group_id
        WHERE o.outlet_id = ? AND o.order_status IN ('Pending', 'Ready')
        ORDER BY o.order_date ASC, o.order_id ASC, oi.order_item_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $outlet_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $order_id = intval($row['order_id']);

        if (!isset($orders[$order_id])) {
            $orders[$order_id] = [
                'order_id'          => $order_id,
                'order_date'        => $row['order_date'],
                'fulfilment_method' => $row['fulfilment_method'],
                'order_status'      => $row['order_status'],
                'total_amount'      => (float)$row['total_amount'],
                'customer_name'     => $row['customer_name'],
                'items'             => [],
            ];
        }

        if ($row['product_id'] !== null) {
            $orders[$order_id]['items'][] = [
                'product_name' => storefront_display_name($row),
                'quantity'     => intval($row['quantity']),
                'unit_price'   => (float)$row['unit_price'],
                'subtotal'     => (float)$row['subtotal'],
            ];
        }
    }
    $stmt->close();

    return array_values($orders);
}
