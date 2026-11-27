<?php
// includes/storefront_helpers.php
// Shared read helpers for the customer storefront pages.

require_once __DIR__ . '/upload_image.php';
require_once __DIR__ . '/csrf_helpers.php';

const STOREFRONT_OUTLET_SESSION_KEY = 'storefront_outlet_id';
const STOREFRONT_CART_KEY           = 'ekoperasi_storefront_cart_v1';

/**
 * Active outlets ordered by name.
 */
function storefront_active_outlets($conn): array
{
    $outlets = [];
    $res = $conn->query(
        "SELECT outlet_id, outlet_name, location, contact_number, operating_hours
         FROM outlet
         WHERE status = 'Active'
         ORDER BY outlet_name ASC"
    );

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['outlet_id'] = intval($row['outlet_id']);
            $outlets[] = $row;
        }
    }

    return $outlets;
}

/**
 * Resolve the selected outlet from the query string, then session, then first.
 */
function storefront_pick_outlet(array $outlets, $requested_id): ?array
{
    if (empty($outlets)) {
        return null;
    }

    $requested_id = intval($requested_id);
    if ($requested_id > 0) {
        foreach ($outlets as $outlet) {
            if (intval($outlet['outlet_id']) === $requested_id) {
                $_SESSION[STOREFRONT_OUTLET_SESSION_KEY] = $requested_id;
                return $outlet;
            }
        }
    }

    $session_id = intval($_SESSION[STOREFRONT_OUTLET_SESSION_KEY] ?? 0);
    if ($session_id > 0) {
        foreach ($outlets as $outlet) {
            if (intval($outlet['outlet_id']) === $session_id) {
                return $outlet;
            }
        }
    }

    $first = $outlets[0];
    $_SESSION[STOREFRONT_OUTLET_SESSION_KEY] = intval($first['outlet_id']);

    return $first;
}

/**
 * Display name: group + variant when grouped, otherwise product name.
 */
function storefront_display_name(array $row): string
{
    $group   = trim((string)($row['group_name'] ?? ''));
    $variant = trim((string)($row['variant_name'] ?? ''));
    $product = trim((string)($row['product_name'] ?? ''));

    if ($group !== '') {
        if ($variant !== '') {
            return $group . ' ' . $variant;
        }
        if ($product !== '' && stripos($product, $group) === 0) {
            return $product;
        }
        return trim($group . ' ' . $product);
    }

    return $product;
}

function storefront_money($amount): string
{
    return 'RM ' . number_format((float)$amount, 2);
}

function storefront_stock_state(int $quantity, int $reorder_level): string
{
    if ($quantity <= 0) {
        return 'out';
    }
    if ($quantity <= $reorder_level) {
        return 'low';
    }
    return 'in';
}

function storefront_stock_label(string $state): string
{
    if ($state === 'out') {
        return 'Out of Stock';
    }
    if ($state === 'low') {
        return 'Low Stock';
    }
    return 'In Stock';
}

function storefront_stock_badge_class(string $state): string
{
    if ($state === 'out') {
        return 'badge-out';
    }
    if ($state === 'low') {
        return 'badge-low';
    }
    return 'badge-active';
}

function storefront_order_badge_class(string $status): string
{
    switch ($status) {
        case 'Ready':
            return 'badge-active';
        case 'Completed':
            return 'badge-muted';
        case 'Cancelled':
            return 'badge-out';
        case 'Pending':
        default:
            return 'badge-low';
    }
}

function storefront_payment_badge_class(?string $status): string
{
    switch ($status) {
        case 'Success':
            return 'badge-active';
        case 'Failed':
            return 'badge-out';
        case 'Refunded':
            return 'badge-muted';
        case 'Pending':
        default:
            return 'badge-low';
    }
}

function storefront_payment_method_label(?string $method): string
{
    switch ($method) {
        case 'FPX':
            return 'FPX Online Banking';
        case 'E_WALLET':
            return 'E-Wallet';
        case 'CARD':
            return 'Card';
        default:
            return 'Not recorded';
    }
}

/**
 * Load the active branch catalog and group variants by product_group.
 *
 * @return array{grouped: array, standalone: array, categories: array, items: int}
 */
function storefront_catalog($conn, int $outlet_id): array
{
    $grouped    = [];
    $standalone = [];
    $categories = [];

    $stmt = $conn->prepare("
        SELECT p.product_id, p.category_id, p.group_id, p.variant_name,
               p.product_name, p.price, p.image_url,
               c.category_name,
               g.group_name, g.category_id AS group_category_id,
               g.image_url AS group_image_url, gc.category_name AS group_category_name,
               oi.stock_quantity, oi.reorder_level, oi.status AS inventory_status
        FROM outlet_inventory oi
        JOIN product p ON p.product_id = oi.product_id
        LEFT JOIN category c ON c.category_id = p.category_id
        LEFT JOIN product_group g ON g.group_id = p.group_id
        LEFT JOIN category gc ON gc.category_id = g.category_id
        WHERE oi.outlet_id = ? AND oi.status = 'Active'
        ORDER BY COALESCE(gc.category_name, c.category_name) ASC,
                 COALESCE(g.group_name, p.product_name) ASC,
                 p.variant_name ASC, p.product_name ASC
    ");

    if (!$stmt) {
        return ['grouped' => [], 'standalone' => [], 'categories' => [], 'items' => 0];
    }

    $stmt->bind_param('i', $outlet_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $row['product_id']      = intval($row['product_id']);
        $row['category_id']     = intval($row['category_id']);
        $row['group_id']        = $row['group_id'] !== null ? intval($row['group_id']) : null;
        $row['price']           = (float)$row['price'];
        $row['stock_quantity']  = intval($row['stock_quantity']);
        $row['reorder_level']   = intval($row['reorder_level']);
        $row['image_url']       = $row['image_url'] ?: PRODUCT_IMAGE_DEFAULT;
        $row['group_image_url'] = $row['group_image_url'] ?: PRODUCT_IMAGE_DEFAULT;
        $row['category_name']   = $row['group_category_name'] ?: ($row['category_name'] ?: 'Uncategorised');
        $row['category_id']     = $row['group_category_id'] !== null
            ? intval($row['group_category_id'])
            : $row['category_id'];
        $row['display_name']    = storefront_display_name($row);
        $row['variant_label']   = trim((string)($row['variant_name'] ?? '')) !== ''
            ? trim((string)$row['variant_name'])
            : $row['display_name'];
        $row['stock_state']     = storefront_stock_state($row['stock_quantity'], $row['reorder_level']);

        if ($row['group_id'] !== null && $row['group_id'] > 0) {
            $group_id = $row['group_id'];

            if (!isset($grouped[$group_id])) {
                $grouped[$group_id] = [
                    'group_id'      => $group_id,
                    'group_name'    => $row['group_name'] ?: $row['product_name'],
                    'category_id'   => $row['category_id'],
                    'category_name' => $row['category_name'],
                    'image_url'     => $row['group_image_url'],
                    'variants'      => [],
                    'search_parts'  => [$row['group_name'] ?: '', $row['category_name']],
                ];
            }

            $grouped[$group_id]['variants'][] = $row;
            $grouped[$group_id]['search_parts'][] = $row['display_name'];
            $grouped[$group_id]['search_parts'][] = $row['variant_label'];
        } else {
            $standalone[] = $row;
        }
    }

    $stmt->close();

    foreach ($grouped as $group_id => $group) {
        $default_index = 0;
        $has_stock     = false;

        foreach ($group['variants'] as $index => $variant) {
            if ($variant['stock_quantity'] > 0) {
                $has_stock = true;
                if ($default_index === 0 && $group['variants'][0]['stock_quantity'] <= 0) {
                    $default_index = $index;
                }
            }
        }

        $grouped[$group_id]['default_index'] = $default_index;
        $grouped[$group_id]['has_stock']     = $has_stock;
        $grouped[$group_id]['search']        = strtolower(implode(' ', array_filter($group['search_parts'])));
        unset($grouped[$group_id]['search_parts']);

        $categories[$group['category_id']] = [
            'category_id'   => $group['category_id'],
            'category_name' => $group['category_name'],
            'count'         => ($categories[$group['category_id']]['count'] ?? 0) + 1,
        ];
    }

    foreach ($standalone as $index => $product) {
        $standalone[$index]['search'] = strtolower($product['display_name'] . ' ' . $product['category_name']);
        $categories[$product['category_id']] = [
            'category_id'   => $product['category_id'],
            'category_name' => $product['category_name'],
            'count'         => ($categories[$product['category_id']]['count'] ?? 0) + 1,
        ];
    }

    uasort($categories, static function ($a, $b) {
        return strcasecmp($a['category_name'], $b['category_name']);
    });

    return [
        'grouped'    => array_values($grouped),
        'standalone' => $standalone,
        'categories' => array_values($categories),
        'items'      => count($grouped) + count($standalone),
    ];
}

/**
 * Flat product map used by checkout JS and server validation.
 */
function storefront_catalog_stock_map(array $catalog): array
{
    $map = [];

    foreach ($catalog['grouped'] as $group) {
        foreach ($group['variants'] as $variant) {
            $map[$variant['product_id']] = [
                'product_id'    => $variant['product_id'],
                'name'          => $variant['display_name'],
                'price'         => $variant['price'],
                'stock'         => $variant['stock_quantity'],
                'image'         => $variant['image_url'],
                'group_name'    => $group['group_name'],
                'variant_name'  => $variant['variant_label'],
                'category_name' => $group['category_name'],
            ];
        }
    }

    foreach ($catalog['standalone'] as $product) {
        $map[$product['product_id']] = [
            'product_id'    => $product['product_id'],
            'name'          => $product['display_name'],
            'price'         => $product['price'],
            'stock'         => $product['stock_quantity'],
            'image'         => $product['image_url'],
            'group_name'    => null,
            'variant_name'  => null,
            'category_name' => $product['category_name'],
        ];
    }

    return $map;
}

/**
 * inventory_transaction requires a user id. Prefer branch staff, then admin.
 */
function storefront_audit_user_id($conn, int $outlet_id, int $fallback_user_id): int
{
    $stmt = $conn->prepare(
        "SELECT user_id FROM user
         WHERE role = 'Staff' AND outlet_id = ?
         ORDER BY user_id ASC LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $outlet_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return intval($row['user_id']);
        }
    }

    $res = $conn->query("SELECT user_id FROM user WHERE role = 'Admin' ORDER BY user_id ASC LIMIT 1");
    if ($res) {
        $row = $res->fetch_assoc();
        if ($row) {
            return intval($row['user_id']);
        }
    }

    return $fallback_user_id;
}

// Thin wrappers so existing storefront call sites keep working unchanged.
function storefront_csrf_token(): string
{
    return csrf_token();
}

function storefront_csrf_valid($token): bool
{
    return csrf_valid($token);
}
