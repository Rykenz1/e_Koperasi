<?php
// outlet_dashboard.php
require_once 'includes/db_connect.php';
require_once 'includes/upload_image.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Strict RBAC & Outlet Assignment Guard
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Staff', 'Admin']) || empty($_SESSION['outlet_id'])) {
    header("Location: login.php");
    exit();
}

$staff_id  = intval($_SESSION['user_id']);
$outlet_id = intval($_SESSION['outlet_id']);
$message   = "";
$msg_type  = "";

// Fetch Assigned Outlet Info
$outlet_name = "Outlet #$outlet_id";
$stmt_out = $conn->prepare("SELECT outlet_name FROM outlet WHERE outlet_id = ?");
if ($stmt_out) {
    $stmt_out->bind_param("i", $outlet_id);
    $stmt_out->execute();
    $res_out = $stmt_out->get_result()->fetch_assoc();
    if ($res_out) {
        $outlet_name = $res_out['outlet_name'];
    }
    $stmt_out->close();
}

$active_tab = $_GET['tab'] ?? 'inventory';
if (!in_array($active_tab, ['inventory', 'orders', 'logs'])) {
    $active_tab = 'inventory';
}

// 2. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Tracks a newly uploaded file so it can be removed if the DB write fails.
    $uploaded_image_to_cleanup = null;

    try {
        // --- ADD NEW PRODUCT & LINK TO OUTLET_INVENTORY ---
        if ($action === 'add_product') {
            $product_name   = trim($_POST['product_name']);
            $category_id    = intval($_POST['category_id']);
            $price          = floatval($_POST['price']);
            $stock_quantity = max(0, intval($_POST['stock_quantity']));
            $reorder_level  = max(0, intval($_POST['reorder_level']));
            $status         = ($_POST['status'] === 'Inactive') ? 'Inactive' : 'Active';

            $upload = save_product_image($_FILES['product_image'] ?? []);

            if (!$upload['ok']) {
                $message  = $upload['error'];
                $msg_type = "error";
            } elseif (!empty($product_name) && $price >= 0) {
                $uploaded_image_to_cleanup = $upload['path'];
                $image_url = $upload['path'] ?? PRODUCT_IMAGE_DEFAULT;

                $conn->begin_transaction();

                // 1. Insert into master `product` catalog
                $stmt = $conn->prepare("INSERT INTO product (category_id, product_name, price, image_url) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isds", $category_id, $product_name, $price, $image_url);
                $stmt->execute();
                $new_product_id = $conn->insert_id;
                $stmt->close();

                // 2. Insert branch stock into `outlet_inventory`
                $inv = $conn->prepare("INSERT INTO outlet_inventory (outlet_id, product_id, stock_quantity, reorder_level, status) VALUES (?, ?, ?, ?, ?)");
                $inv->bind_param("iiiis", $outlet_id, $new_product_id, $stock_quantity, $reorder_level, $status);
                $inv->execute();
                $inv->close();

                // 3. Log initial stock in `inventory_transaction`
                if ($stock_quantity > 0) {
                    $log = $conn->prepare("INSERT INTO inventory_transaction (outlet_id, product_id, staff_user_id, transaction_type, quantity_changed, remarks) VALUES (?, ?, ?, 'Stock_In', ?, ?)");
                    $remark = "Initial stock added at $outlet_name";
                    $log->bind_param("iiiis", $outlet_id, $new_product_id, $staff_id, $stock_quantity, $remark);
                    $log->execute();
                    $log->close();
                }

                $conn->commit();
                $message = "Product '$product_name' added to $outlet_name inventory.";
                $msg_type = "success";
            }
            $active_tab = 'inventory';
        }

        // --- ASSIGN EXISTING MASTER PRODUCT TO THIS OUTLET ---
        elseif ($action === 'assign_existing_product') {
            $product_id     = intval($_POST['product_id']);
            $stock_quantity = max(0, intval($_POST['stock_quantity']));
            $reorder_level  = max(0, intval($_POST['reorder_level']));
            $status         = ($_POST['status'] === 'Inactive') ? 'Inactive' : 'Active';

            if ($product_id > 0) {
                $conn->begin_transaction();

                $inv = $conn->prepare("INSERT INTO outlet_inventory (outlet_id, product_id, stock_quantity, reorder_level, status) VALUES (?, ?, ?, ?, ?)");
                $inv->bind_param("iiiis", $outlet_id, $product_id, $stock_quantity, $reorder_level, $status);
                $inv->execute();
                $inv->close();

                // if ($stock_quantity > 0) {
                //     $log = $conn->prepare("INSERT INTO inventory_transaction (product_id, staff_user_id, transaction_type, quantity_changed, remarks) VALUES (?, ?, 'Stock_In', ?, ?)");
                //     $remark = "Added catalog item to $outlet_name";
                //     $log->bind_param("iiis", $product_id, $staff_id, $stock_quantity, $remark);
                //     $log->execute();
                //     $log->close();
                // }
                if ($stock_quantity > 0) {
                    $log = $conn->prepare("INSERT INTO inventory_transaction (outlet_id, product_id, staff_user_id, transaction_type, quantity_changed, remarks) VALUES (?, ?, ?, 'Stock_In', ?, ?)");
                    $remark = "Added catalog item to $outlet_name";
                    $log->bind_param("iiiis", $outlet_id, $product_id, $staff_id, $stock_quantity, $remark);
                    $log->execute();
                    $log->close();
                }

                $conn->commit();
                $message = "Catalog item added to this outlet's inventory.";
                $msg_type = "success";
            }
            $active_tab = 'inventory';
        }

        // --- EDIT PRODUCT & OUTLET INVENTORY SETTINGS ---
        elseif ($action === 'edit_product') {
            $product_id    = intval($_POST['product_id']);
            $product_name  = trim($_POST['product_name']);
            $category_id   = intval($_POST['category_id']);
            $price         = floatval($_POST['price']);
            $reorder_level = max(0, intval($_POST['reorder_level']));
            $status        = ($_POST['status'] === 'Inactive') ? 'Inactive' : 'Active';

            $upload = save_product_image($_FILES['product_image'] ?? []);

            if (!$upload['ok']) {
                $message  = $upload['error'];
                $msg_type = "error";
            } else {
                // Grouped variants have admin-managed master fields (name,
                // category, price, image). Outlet staff only control branch stock
                // settings, so the master row is left untouched for those items.
                $get = $conn->prepare("SELECT image_url, group_id, product_name FROM product WHERE product_id = ?");
                $get->bind_param("i", $product_id);
                $get->execute();
                $row = $get->get_result()->fetch_assoc();
                $get->close();

                $is_grouped     = intval($row['group_id'] ?? 0) > 0;
                $display_name   = $is_grouped ? ($row['product_name'] ?? '') : $product_name;
                $current_image  = $row['image_url'] ?? null;
                $image_url      = $upload['path'] ?? $current_image ?? PRODUCT_IMAGE_DEFAULT;

                $conn->begin_transaction();

                if (!$is_grouped) {
                    // Update master product details for standalone products.
                    $stmt = $conn->prepare("UPDATE product SET category_id = ?, product_name = ?, price = ?, image_url = ? WHERE product_id = ?");
                    $stmt->bind_param("isdsi", $category_id, $product_name, $price, $image_url, $product_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Update outlet-specific reorder level & availability
                $inv = $conn->prepare("UPDATE outlet_inventory SET reorder_level = ?, status = ? WHERE outlet_id = ? AND product_id = ?");
                $inv->bind_param("isii", $reorder_level, $status, $outlet_id, $product_id);
                $inv->execute();
                $inv->close();

                $conn->commit();

                if ($is_grouped) {
                    // No master image was changed - discard any file uploaded.
                    if ($upload['path'] !== null) {
                        delete_product_image($upload['path']);
                    }
                    $message = "Branch settings for '$display_name' updated. Name, price and image are managed by the admin.";
                } else {
                    // Only drop the old file once the new path is safely committed.
                    if ($upload['path'] !== null) {
                        delete_product_image($current_image);
                    }
                    $message = "Item '$product_name' updated successfully.";
                }
                $msg_type = "success";
            }
            $active_tab = 'inventory';
        }

        // --- ADJUST / RESTOCK QUANTITY IN OUTLET_INVENTORY ---
        elseif ($action === 'adjust_stock') {
            $product_id = intval($_POST['product_id']);
            $adj_type   = $_POST['adj_type'] ?? 'Stock_In';
            $qty_change = intval($_POST['qty_change']);
            $remarks    = trim($_POST['remarks']) ?: ($adj_type === 'Stock_In' ? "Restock at $outlet_name" : "Manual adjustment at $outlet_name");

            if ($qty_change !== 0) {
                $conn->begin_transaction();

                $chk = $conn->prepare("
                    SELECT p.product_name, oi.stock_quantity 
                    FROM outlet_inventory oi
                    JOIN product p ON oi.product_id = p.product_id
                    WHERE oi.outlet_id = ? AND oi.product_id = ? 
                    FOR UPDATE
                ");
                $chk->bind_param("ii", $outlet_id, $product_id);
                $chk->execute();
                $prod = $chk->get_result()->fetch_assoc();
                $chk->close();

                if ($prod) {
                    $delta = ($adj_type === 'Stock_In') ? abs($qty_change) : $qty_change;
                    $new_qty = max(0, intval($prod['stock_quantity']) + $delta);
                    $actual_diff = $new_qty - intval($prod['stock_quantity']);

                    $upd = $conn->prepare("UPDATE outlet_inventory SET stock_quantity = ? WHERE outlet_id = ? AND product_id = ?");
                    $upd->bind_param("iii", $new_qty, $outlet_id, $product_id);
                    $upd->execute();
                    $upd->close();

                    $log = $conn->prepare("INSERT INTO inventory_transaction (outlet_id, product_id, staff_user_id, transaction_type, quantity_changed, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    $log->bind_param("iiisis", $outlet_id, $product_id, $staff_id, $adj_type, $actual_diff, $remarks);
                    $log->execute();
                    $log->close();

                    $conn->commit();
                    $message = "Stock updated for '{$prod['product_name']}' (New stock: $new_qty).";
                    $msg_type = "success";
                } else {
                    $conn->rollback();
                }
            }
            $active_tab = 'inventory';
        }

        // --- TOGGLE OUTLET AVAILABILITY IN OUTLET_INVENTORY ---
        elseif ($action === 'toggle_availability') {
            $product_id = intval($_POST['product_id']);
            $new_status = ($_POST['new_status'] === 'Active') ? 'Active' : 'Inactive';

            $stmt = $conn->prepare("UPDATE outlet_inventory SET status = ? WHERE outlet_id = ? AND product_id = ?");
            $stmt->bind_param("sii", $new_status, $outlet_id, $product_id);
            $stmt->execute();
            $stmt->close();

            $message = "Item availability for $outlet_name set to '$new_status'.";
            $msg_type = "success";
            $active_tab = 'inventory';
        }

        // --- REMOVE PRODUCT FROM THIS OUTLET'S INVENTORY ---
        elseif ($action === 'remove_from_outlet') {
            $product_id = intval($_POST['product_id']);
            $stmt = $conn->prepare("DELETE FROM outlet_inventory WHERE outlet_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $outlet_id, $product_id);
            $stmt->execute();
            $stmt->close();

            $message = "Item removed from $outlet_name inventory.";
            $msg_type = "success";
            $active_tab = 'inventory';
        }

        // --- UPDATE ORDER STATUS ---
        elseif ($action === 'update_order_status') {
            $order_id   = intval($_POST['order_id']);
            $next_state = $_POST['order_status'] ?? 'Ready';

            if (in_array($next_state, ['Pending', 'Ready', 'Completed', 'Cancelled'])) {
                $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE order_id = ? AND outlet_id = ?");
                $stmt->bind_param("sii", $next_state, $order_id, $outlet_id);
                $stmt->execute();
                $stmt->close();

                $message = "Order #$order_id marked as $next_state.";
                $msg_type = "success";
            }
            $active_tab = 'orders';
        }

    } catch (mysqli_sql_exception $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        // If the DB write failed, don't leave the new image behind as an orphan.
        if ($uploaded_image_to_cleanup !== null) {
            delete_product_image($uploaded_image_to_cleanup);
        }
        $message = "Database Error: " . $e->getMessage();
        $msg_type = "error";
    }
}

// 3. Fetch Categories
$categories = [];
$res_cat = $conn->query("SELECT category_id, category_name FROM category ORDER BY category_name ASC");
if ($res_cat) {
    while ($row = $res_cat->fetch_assoc()) {
        $categories[] = $row;
    }
}

// 4. Fetch THIS Outlet's Inventory via JOIN (`outlet_inventory` + `product` + `category`)
$products = [];
$stmt_prod = $conn->prepare("
    SELECT p.product_id, p.category_id, p.group_id, p.variant_name,
           p.product_name, p.price, p.image_url,
           c.category_name, g.group_name,
           oi.stock_quantity, oi.reorder_level, oi.status
    FROM outlet_inventory oi
    JOIN product p ON oi.product_id = p.product_id
    LEFT JOIN category c ON p.category_id = c.category_id
    LEFT JOIN product_group g ON g.group_id = p.group_id
    WHERE oi.outlet_id = ?
    ORDER BY oi.status ASC, oi.stock_quantity ASC, p.product_name ASC
");
$stmt_prod->bind_param("i", $outlet_id);
$stmt_prod->execute();
$res_prod = $stmt_prod->get_result();

$low_stock_count = 0;
$out_of_stock_count = 0;
while ($row = $res_prod->fetch_assoc()) {
    if (intval($row['stock_quantity']) === 0) {
        $out_of_stock_count++;
    } elseif (intval($row['stock_quantity']) <= intval($row['reorder_level'])) {
        $low_stock_count++;
    }
    $products[] = $row;
}
$stmt_prod->close();

// 4b. Fetch Master Catalog Products NOT YET in THIS Outlet (for quick assignment)
$unassigned_products = [];
$stmt_unassigned = $conn->prepare("
    SELECT p.product_id, p.product_name, p.price, p.group_id, p.variant_name,
           g.group_name
    FROM product p
    LEFT JOIN product_group g ON g.group_id = p.group_id
    WHERE p.product_id NOT IN (
        SELECT product_id FROM outlet_inventory WHERE outlet_id = ?
    )
    ORDER BY (g.group_name IS NULL) ASC, g.group_name ASC, p.product_name ASC
");
if ($stmt_unassigned) {
    $stmt_unassigned->bind_param("i", $outlet_id);
    $stmt_unassigned->execute();
    $res_un = $stmt_unassigned->get_result();
    while ($row = $res_un->fetch_assoc()) {
        $unassigned_products[] = $row;
    }
    $stmt_unassigned->close();
}

// Group catalog options by variant family so flavours sit together in the picker.
$catalog_options_ungrouped = [];
$catalog_options_grouped   = [];
foreach ($unassigned_products as $up) {
    $option = '<option value="' . intval($up['product_id']) . '">' .
              htmlspecialchars($up['product_name']) .
              ' (RM ' . number_format($up['price'], 2) . ')</option>';
    if (!empty($up['group_name'])) {
        $catalog_options_grouped[$up['group_name']][] = $option;
    } else {
        $catalog_options_ungrouped[] = $option;
    }
}

// 5. Fetch Active Orders for THIS Outlet
$orders = [];
$stmt_ord = $conn->prepare("
    SELECT o.order_id, o.order_date, o.fulfilment_method, o.order_status, o.total_amount, u.fullname AS customer_name
    FROM orders o
    JOIN user u ON o.user_id = u.user_id
    WHERE o.outlet_id = ? AND o.order_status IN ('Pending', 'Ready')
    ORDER BY o.order_date ASC
");
if ($stmt_ord) {
    $stmt_ord->bind_param("i", $outlet_id);
    $stmt_ord->execute();
    $res_ord = $stmt_ord->get_result();
    while ($row = $res_ord->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt_ord->close();
}

// 6. Fetch Recent Inventory Audit Logs for Products Carried by THIS Outlet
$logs = [];
$stmt_log = $conn->prepare("
    SELECT t.transaction_id, t.transaction_type, t.quantity_changed, t.transaction_date, t.remarks,
           p.product_name, COALESCE(NULLIF(u.fullname, ''), u.username) AS staff_name
    FROM inventory_transaction t
    JOIN product p ON t.product_id = p.product_id
    JOIN user u ON t.staff_user_id = u.user_id
    WHERE t.outlet_id = ?
    ORDER BY t.transaction_date DESC
    LIMIT 30
");
if ($stmt_log) {
    $stmt_log->bind_param("i", $outlet_id);
    $stmt_log->execute();
    $res_log = $stmt_log->get_result();
    while ($row = $res_log->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt_log->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outlet Dashboard - <?php echo htmlspecialchars($outlet_name); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/outlet.css">
</head>
<body>

    <header class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar">&#9776;</button>
            <span class="brand-title">e-Koperasi Outlet Terminal</span>
            <span class="outlet-pill"><?php echo htmlspecialchars($outlet_name); ?></span>
        </div>
        <div class="topbar-right">
            <span>Staff: <?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></span>
            <button type="button" class="logout-btn" onclick="openModal('logoutModal')">Logout</button>
        </div>
    </header>

    <div class="layout-wrapper">
        <aside class="sidebar" id="sidebar">
            <a href="?tab=inventory" class="nav-item <?php echo $active_tab === 'inventory' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128230;</span>
                <span class="nav-text">Inventory</span>
            </a>
            <a href="?tab=orders" class="nav-item <?php echo $active_tab === 'orders' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128203;</span>
                <span class="nav-text">Order Queue (<?php echo count($orders); ?>)</span>
            </a>
            <a href="?tab=logs" class="nav-item <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128221;</span>
                <span class="nav-text">Stock Logs</span>
            </a>
        </aside>

        <main class="main-content">
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $msg_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'inventory'): ?>
                <div class="header-row">
                    <h1 class="page-title">Outlet Inventory</h1>
                </div>

                <div class="stats-bar">
                    <div class="stat-chip">Listed Items: <?php echo count($products); ?></div>
                    <div class="stat-chip warn">Low Stock: <?php echo $low_stock_count; ?></div>
                    <div class="stat-chip danger">Out of Stock: <?php echo $out_of_stock_count; ?></div>
                </div>

                <div class="toolbar">
                    <input type="text" id="inventorySearch" class="search-input" placeholder="Search product name or category..." onkeyup="filterInventory()">
                    <button type="button" class="filter-btn active" onclick="setStockFilter('all', this)">All</button>
                    <button type="button" class="filter-btn" onclick="setStockFilter('low', this)">Low Stock</button>
                    <button type="button" class="filter-btn" onclick="setStockFilter('out', this)">Out of Stock</button>
                    <button type="button" class="filter-btn" onclick="setStockFilter('inactive', this)">Unavailable</button>
                </div>

                <div class="card-list inventory-list" id="inventoryList">
                    <?php if (empty($products)): ?>
                        <p style="color: var(--text-muted);">No items assigned to <?php echo htmlspecialchars($outlet_name); ?> yet. Click <strong>+</strong> to add stock.</p>
                    <?php else: ?>
                        <?php foreach ($products as $p): 
                            $qty = intval($p['stock_quantity']);
                            $reorder = intval($p['reorder_level']);
                            $is_active = ($p['status'] === 'Active');
                            $stock_state = ($qty === 0) ? 'out' : (($qty <= $reorder) ? 'low' : 'ok');
                        ?>
                            <div class="item-card <?php echo !$is_active ? 'inactive-card' : ''; ?>"
                                 data-name="<?php echo strtolower(htmlspecialchars($p['product_name'] . ' ' . ($p['category_name'] ?? ''))); ?>"
                                 data-stock="<?php echo $stock_state; ?>"
                                 data-status="<?php echo strtolower($p['status']); ?>">
                                <div class="item-main">
                                    <img class="item-thumb"
                                         src="<?php echo htmlspecialchars($p['image_url'] ?: PRODUCT_IMAGE_DEFAULT); ?>"
                                         alt="<?php echo htmlspecialchars($p['product_name']); ?>"
                                         onerror="this.onerror=null;this.src='<?php echo PRODUCT_IMAGE_DEFAULT; ?>';">
                                    <div class="item-info">
                                        <h3>
                                            <?php echo htmlspecialchars($p['product_name']); ?>
                                            <?php if (!empty($p['group_name'])): ?>
                                                <span class="badge badge-group"><?php echo htmlspecialchars($p['group_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($p['category_name'])): ?>
                                                <span class="badge badge-cat"><?php echo htmlspecialchars($p['category_name']); ?></span>
                                            <?php endif; ?>
                                            <span class="badge <?php echo $is_active ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo $is_active ? 'Available' : 'Unavailable'; ?>
                                            </span>
                                            <?php if ($qty === 0): ?>
                                                <span class="badge badge-out">Out of Stock</span>
                                            <?php elseif ($qty <= $reorder): ?>
                                                <span class="badge badge-low">Low Stock (&le;<?php echo $reorder; ?>)</span>
                                            <?php endif; ?>
                                        </h3>
                                        <p>
                                            Price: <strong>RM <?php echo number_format($p['price'], 2); ?></strong> &bull;
                                            Branch Stock: <strong><?php echo $qty; ?> units</strong>
                                        </p>
                                    </div>
                                </div>

                                <div class="card-actions">
                                    <button type="button" class="btn-quick" onclick='openStockModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        &plusmn; Stock
                                    </button>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_availability">
                                        <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $is_active ? 'Inactive' : 'Active'; ?>">
                                        <button type="submit" class="btn-outline">
                                            <?php echo $is_active ? 'Set Unavailable' : 'Set Available'; ?>
                                        </button>
                                    </form>

                                    <div class="menu-wrapper">
                                        <button type="button" class="dots-btn" onclick="toggleMenu(event, 'menu-prod-<?php echo $p['product_id']; ?>')">
                                            <span class="dot"></span>
                                            <span class="dot"></span>
                                            <span class="dot"></span>
                                        </button>
                                        <div class="dropdown-menu" id="menu-prod-<?php echo $p['product_id']; ?>">
                                            <button type="button" class="dropdown-item" onclick='openEditProductModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                Edit Item
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($p['product_name'])); ?> from <?php echo htmlspecialchars(addslashes($outlet_name)); ?>?');">
                                                <input type="hidden" name="action" value="remove_from_outlet">
                                                <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                                                <button type="submit" class="dropdown-item delete">Remove from Outlet</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="fab-add" onclick="openModal('addProductModal')" title="Add Item to Outlet">+</button>

            <?php elseif ($active_tab === 'orders'): ?>
                <h1 class="page-title" style="margin-bottom: 22px;">Active Order Queue</h1>

                <div class="card-list">
                    <?php if (empty($orders)): ?>
                        <p style="color: var(--text-muted);">No pending or ready orders for <?php echo htmlspecialchars($outlet_name); ?> right now.</p>
                    <?php else: ?>
                        <?php foreach ($orders as $ord): ?>
                            <div class="item-card">
                                <div class="item-info">
                                    <h3>
                                        Order #<?php echo $ord['order_id']; ?> &mdash; <?php echo htmlspecialchars($ord['customer_name']); ?>
                                        <span class="badge <?php echo $ord['order_status'] === 'Ready' ? 'badge-active' : 'badge-low'; ?>">
                                            <?php echo htmlspecialchars($ord['order_status']); ?>
                                        </span>
                                    </h3>
                                    <p>
                                        Method: <strong><?php echo htmlspecialchars($ord['fulfilment_method']); ?></strong> &bull;
                                        Total: <strong>RM <?php echo number_format($ord['total_amount'], 2); ?></strong> &bull;
                                        Placed: <?php echo htmlspecialchars($ord['order_date']); ?>
                                    </p>
                                </div>
                                <div class="card-actions">
                                    <?php if ($ord['order_status'] === 'Pending'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['order_id']; ?>">
                                            <input type="hidden" name="order_status" value="Ready">
                                            <button type="submit" class="btn-quick">Mark Ready</button>
                                        </form>
                                    <?php elseif ($ord['order_status'] === 'Ready'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_order_status">
                                            <input type="hidden" name="order_id" value="<?php echo $ord['order_id']; ?>">
                                            <input type="hidden" name="order_status" value="Completed">
                                            <button type="submit" class="btn-quick">Complete Handover</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <h1 class="page-title" style="margin-bottom: 22px;">Inventory Audit Logs</h1>

                <?php if (empty($logs)): ?>
                    <p style="color: var(--text-muted);">No stock adjustments recorded yet.</p>
                <?php else: ?>
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Change</th>
                                <th>Staff</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['transaction_date']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($log['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($log['transaction_type']); ?></td>
                                    <td style="font-weight:600; color: <?php echo $log['quantity_changed'] >= 0 ? '#065F46' : '#991B1B'; ?>;">
                                        <?php echo ($log['quantity_changed'] > 0 ? '+' : '') . $log['quantity_changed']; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['staff_name']); ?></td>
                                    <td><?php echo htmlspecialchars($log['remarks'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- MODAL 1: ADD ITEM TO OUTLET (CREATE NEW OR PICK FROM MASTER CATALOG) -->
    <div class="modal-overlay" id="addProductModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Add Item to <?php echo htmlspecialchars($outlet_name); ?></h2>
                <button class="close-modal" onclick="closeModal('addProductModal')">&times;</button>
            </div>

            <?php if (!empty($unassigned_products)): ?>
                <div class="modal-tabs">
                    <button type="button" class="modal-tab-btn active" id="tabNewBtn" onclick="switchAddMode('new')">New Product</button>
                    <button type="button" class="modal-tab-btn" id="tabExistingBtn" onclick="switchAddMode('existing')">From Catalog (<?php echo count($unassigned_products); ?>)</button>
                </div>
            <?php endif; ?>

            <!-- Form A: Create New Master Product + Link to Outlet -->
            <form method="POST" id="newProductForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_product">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" placeholder="e.g., Mineral Water 500ml" required>
                </div>
                <div class="form-group">
                    <label>Product Image</label>
                    <div class="dropzone" id="addProductDropzone" data-dropzone tabindex="0" role="button"
                         aria-label="Upload product image">
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="dropzone-preview">
                            <img alt="Product image preview">
                            <span class="dropzone-hint">Drag &amp; drop an image here, or click to browse</span>
                        </div>
                    </div>
                    <small class="form-hint">JPG, PNG or WEBP &middot; max 5 MB</small>
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" required>
                        <?php if (empty($categories)): ?>
                            <option value="1">General</option>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Price (RM) *</label>
                        <input type="number" name="price" step="0.01" min="0" placeholder="1.50" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Initial Stock *</label>
                        <input type="number" name="stock_quantity" min="0" value="10" required>
                    </div>
                </div>
                <div style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Low-Stock Alert Level</label>
                        <input type="number" name="reorder_level" min="0" value="5">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Availability</label>
                        <select name="status">
                            <option value="Active">Available (Active)</option>
                            <option value="Inactive">Unavailable (Inactive)</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Create & Add to Outlet</button>
            </form>

            <!-- Form B: Assign Existing Master Product to Outlet -->
            <?php if (!empty($unassigned_products)): ?>
                <form method="POST" id="existingProductForm" style="display:none;">
                    <input type="hidden" name="action" value="assign_existing_product">
                    <div class="form-group">
                        <label>Select Master Catalog Product *</label>
                        <select name="product_id" required>
                            <?php foreach ($catalog_options_grouped as $group_label => $options): ?>
                                <optgroup label="<?php echo htmlspecialchars($group_label); ?>">
                                    <?php echo implode('', $options); ?>
                                </optgroup>
                            <?php endforeach; ?>
                            <?php if ($catalog_options_ungrouped): ?>
                                <optgroup label="Other Products">
                                    <?php echo implode('', $catalog_options_ungrouped); ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <div class="form-group" style="flex:1;">
                            <label>Initial Stock *</label>
                            <input type="number" name="stock_quantity" min="0" value="10" required>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Low-Stock Alert Level</label>
                            <input type="number" name="reorder_level" min="0" value="5">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Availability</label>
                        <select name="status">
                            <option value="Active">Available (Active)</option>
                            <option value="Inactive">Unavailable (Inactive)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-submit">Add to Outlet Inventory</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL 2: EDIT PRODUCT & OUTLET INVENTORY -->
    <div class="modal-overlay" id="editProductModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Edit Inventory Item</h2>
                <button class="close-modal" onclick="closeModal('editProductModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="form-hint" id="edit_grouped_notice" style="display:none; margin-bottom:14px;">
                    This is an admin-managed variant. Name, category, price and image are read-only here;
                    you can still change reorder level and branch availability.
                </div>
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" id="edit_product_name" required>
                </div>
                <div class="form-group">
                    <label>Product Image</label>
                    <div class="dropzone" id="editProductDropzone" data-dropzone tabindex="0" role="button"
                         aria-label="Replace product image">
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="dropzone-preview">
                            <img alt="Product image preview">
                            <span class="dropzone-hint">Drag &amp; drop a new image to replace the current one</span>
                        </div>
                    </div>
                    <small class="form-hint">JPG, PNG or WEBP &middot; max 5 MB &middot; leave empty to keep the current photo</small>
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" id="edit_category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Price (RM) *</label>
                        <input type="number" name="price" id="edit_price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Low-Stock Alert Level</label>
                        <input type="number" name="reorder_level" id="edit_reorder_level" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Branch Availability</label>
                    <select name="status" id="edit_status">
                        <option value="Active">Available (Active)</option>
                        <option value="Inactive">Unavailable (Inactive)</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: RESTOCK / ADJUST QUANTITY -->
    <div class="modal-overlay" id="stockModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Restock / Adjust Stock</h2>
                <button class="close-modal" onclick="closeModal('stockModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="product_id" id="stock_product_id">
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 14px;">
                    Item: <strong id="stock_product_label" style="color: var(--text-dark);"></strong><br>
                    Current Branch Stock: <strong id="stock_current_qty" style="color: var(--primary-blue);"></strong> units
                </p>
                <div class="form-group">
                    <label>Transaction Type</label>
                    <select name="adj_type">
                        <option value="Stock_In">Restock Shipment (+ Add Units)</option>
                        <option value="Adjustment">Manual Adjustment (+/- Units)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity Change (use negative e.g. -2 for damaged/expired)</label>
                    <input type="number" name="qty_change" value="10" required>
                </div>
                <div class="form-group">
                    <label>Remarks</label>
                    <input type="text" name="remarks" placeholder="e.g., Morning supplier restock">
                </div>
                <button type="submit" class="btn-submit">Update Stock</button>
            </form>
        </div>
    </div>

    <!-- MODAL 4: LOGOUT CONFIRMATION -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box logout-modal-box">
            <p>Confirm logout?</p>
            <div class="logout-actions">
                <button type="button" class="btn-cancel-link" onclick="closeModal('logoutModal')">Cancel</button>
                <a href="logout.php" class="btn-confirm-logout">Logout</a>
            </div>
        </div>
    </div>

    <script src="js/main.js"></script>
    <script src="js/upload.js"></script>
    <script src="js/dashboard.js"></script>
    <script src="js/outlet.js"></script>
</body>
</html>