<?php
// outlet_dashboard.php
require_once 'db_connect.php';

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

    try {
        // --- ADD NEW PRODUCT & LINK TO OUTLET_INVENTORY ---
        if ($action === 'add_product') {
            $product_name   = trim($_POST['product_name']);
            $category_id    = intval($_POST['category_id']);
            $price          = floatval($_POST['price']);
            $stock_quantity = max(0, intval($_POST['stock_quantity']));
            $reorder_level  = max(0, intval($_POST['reorder_level']));
            $status         = ($_POST['status'] === 'Inactive') ? 'Inactive' : 'Active';

            if (!empty($product_name) && $price >= 0) {
                $conn->begin_transaction();

                // 1. Insert into master `product` catalog
                $stmt = $conn->prepare("INSERT INTO product (category_id, product_name, price) VALUES (?, ?, ?)");
                $stmt->bind_param("isd", $category_id, $product_name, $price);
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

            $conn->begin_transaction();

            // Update master product details
            $stmt = $conn->prepare("UPDATE product SET category_id = ?, product_name = ?, price = ? WHERE product_id = ?");
            $stmt->bind_param("isdi", $category_id, $product_name, $price, $product_id);
            $stmt->execute();
            $stmt->close();

            // Update outlet-specific reorder level & availability
            $inv = $conn->prepare("UPDATE outlet_inventory SET reorder_level = ?, status = ? WHERE outlet_id = ? AND product_id = ?");
            $inv->bind_param("isii", $reorder_level, $status, $outlet_id, $product_id);
            $inv->execute();
            $inv->close();

            $conn->commit();
            $message = "Item '$product_name' updated successfully.";
            $msg_type = "success";
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
    SELECT p.product_id, p.category_id, p.product_name, p.price,
           c.category_name,
           oi.stock_quantity, oi.reorder_level, oi.status
    FROM outlet_inventory oi
    JOIN product p ON oi.product_id = p.product_id
    LEFT JOIN category c ON p.category_id = c.category_id
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
    SELECT p.product_id, p.product_name, p.price
    FROM product p
    WHERE p.product_id NOT IN (
        SELECT product_id FROM outlet_inventory WHERE outlet_id = ?
    )
    ORDER BY p.product_name ASC
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
    <style>
        :root {
            --primary-blue: #0047AB;
            --accent-blue: #0066CC;
            --light-blue-bg: #F0F5FF;
            --card-blue: #EBF2FA;
            --card-hover: #DCE8F8;
            --white: #FFFFFF;
            --text-dark: #1E293B;
            --text-muted: #64748B;
            --sidebar-width: 230px;
            --sidebar-collapsed: 68px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; }

        body { background-color: var(--white); color: var(--text-dark); display: flex; flex-direction: column; min-height: 100vh; }

        /* Top Royal Blue Bar */
        .topbar {
            height: 60px; background-color: var(--primary-blue); color: var(--white);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 20px; position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 2px 6px rgba(0, 71, 171, 0.2);
        }
        .topbar-left { display: flex; align-items: center; gap: 15px; }
        .toggle-btn { background: none; border: none; color: var(--white); font-size: 22px; cursor: pointer; padding: 6px 10px; border-radius: 6px; }
        .toggle-btn:hover { background-color: rgba(255, 255, 255, 0.15); }
        .brand-title { font-size: 18px; font-weight: 600; }
        .outlet-pill { background: rgba(255, 255, 255, 0.18); padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; }
        .topbar-right { display: flex; align-items: center; gap: 15px; font-size: 14px; }
        .logout-btn { background-color: var(--white); color: var(--primary-blue); border: none; padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; }
        .logout-btn:hover { background-color: var(--light-blue-bg); }

        /* Layout & Collapsible Sidebar */
        .layout-wrapper { display: flex; margin-top: 60px; min-height: calc(100vh - 60px); }
        .sidebar { width: var(--sidebar-width); background-color: var(--light-blue-bg); border-right: 1px solid #D6E4FF; padding-top: 25px; transition: width 0.25s ease; overflow: hidden; flex-shrink: 0; }
        .sidebar.collapsed { width: var(--sidebar-collapsed); }
        .nav-item { display: flex; align-items: center; gap: 14px; padding: 14px 22px; color: var(--text-dark); text-decoration: none; font-size: 16px; font-weight: 500; white-space: nowrap; }
        .nav-item:hover { background-color: #DCE8F8; color: var(--primary-blue); }
        .nav-item.active { background-color: var(--primary-blue); color: var(--white); font-weight: 600; }
        .nav-icon { font-size: 19px; min-width: 24px; text-align: center; }
        .sidebar.collapsed .nav-text { display: none; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 32px 44px 100px 44px; background-color: var(--white); position: relative; }
        .header-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; max-width: 960px; margin-bottom: 22px; }
        .page-title { font-size: 28px; font-weight: 700; color: var(--primary-blue); }

        /* Summary Pills & Toolbar */
        .stats-bar { display: flex; gap: 12px; flex-wrap: wrap; max-width: 960px; margin-bottom: 20px; }
        .stat-chip { background: var(--light-blue-bg); border: 1px solid #D0E1F9; padding: 10px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; color: var(--primary-blue); }
        .stat-chip.warn { background: #FEF3C7; border-color: #FDE68A; color: #92400E; }
        .stat-chip.danger { background: #FEE2E2; border-color: #FECACA; color: #991B1B; }

        .toolbar { display: flex; gap: 12px; flex-wrap: wrap; max-width: 960px; margin-bottom: 20px; }
        .search-input { flex: 1; min-width: 220px; padding: 10px 14px; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 14px; }
        .search-input:focus { outline: none; border-color: var(--primary-blue); }
        .filter-btn { padding: 9px 15px; border: 1px solid #CBD5E1; background: var(--white); color: var(--text-dark); border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .filter-btn.active { background: var(--primary-blue); color: var(--white); border-color: var(--primary-blue); }

        /* Alerts */
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; max-width: 960px; }
        .alert-success { background-color: #E6F4EA; color: #137333; border: 1px solid #CEEAD6; }
        .alert-error { background-color: #FCE8E6; color: #C5221F; border: 1px solid #FAD2CF; }

        /* Cards */
        .card-list { display: flex; flex-direction: column; gap: 14px; max-width: 960px; }
        .item-card { background-color: var(--card-blue); border: 1px solid #D0E1F9; border-radius: 14px; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .item-card:hover { background-color: var(--card-hover); box-shadow: 0 3px 10px rgba(0, 71, 171, 0.08); }
        .item-card.inactive-card { opacity: 0.68; background-color: #F1F5F9; border-color: #CBD5E1; }
        .item-info h3 { font-size: 17px; color: var(--text-dark); margin-bottom: 5px; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .item-info p { font-size: 14px; color: var(--text-muted); }
        .card-actions { display: flex; align-items: center; gap: 10px; }

        .btn-quick { background-color: var(--primary-blue); color: var(--white); border: none; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .btn-quick:hover { background-color: var(--accent-blue); }
        .btn-outline { background-color: var(--white); color: var(--primary-blue); border: 1px solid #B6D0F5; padding: 7px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .btn-outline:hover { background-color: var(--light-blue-bg); }

        /* Badges */
        .badge { font-size: 12px; padding: 3px 9px; border-radius: 12px; font-weight: 600; }
        .badge-active { background-color: #D1FAE5; color: #065F46; }
        .badge-inactive { background-color: #E2E8F0; color: #475569; }
        .badge-low { background-color: #FEF3C7; color: #92400E; }
        .badge-out { background-color: #FEE2E2; color: #991B1B; }
        .badge-cat { background-color: #DBEAFE; color: #1E40AF; }

        /* 3-Dot Menu */
        .menu-wrapper { position: relative; }
        .dots-btn { background: none; border: none; cursor: pointer; padding: 8px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
        .dots-btn:hover { background-color: rgba(0, 71, 171, 0.12); }
        .dot { width: 5px; height: 5px; background-color: var(--primary-blue); border-radius: 50%; }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 38px; background-color: var(--white); border: 1px solid #D0E1F9; border-radius: 8px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12); min-width: 175px; z-index: 500; overflow: hidden; }
        .dropdown-menu.show { display: block; }
        .dropdown-item { width: 100%; text-align: left; background: none; border: none; padding: 11px 16px; font-size: 14px; color: var(--text-dark); cursor: pointer; display: block; }
        .dropdown-item:hover { background-color: var(--light-blue-bg); color: var(--primary-blue); }
        .dropdown-item.delete { color: #DC2626; }
        .dropdown-item.delete:hover { background-color: #FEF2F2; }

        /* Floating '+' Button */
        .fab-add {
            position: fixed; bottom: 32px; right: 36px; width: 60px; height: 60px; border-radius: 50%;
            background-color: var(--primary-blue); color: var(--white); border: none; font-size: 32px;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 71, 171, 0.35); z-index: 900;
        }
        .fab-add:hover { background-color: var(--accent-blue); transform: scale(1.06); }

        /* Log Table */
        .log-table { width: 100%; max-width: 960px; border-collapse: collapse; background: var(--white); border: 1px solid #D0E1F9; border-radius: 12px; overflow: hidden; font-size: 14px; }
        .log-table th { background: var(--primary-blue); color: var(--white); text-align: left; padding: 12px 16px; }
        .log-table td { padding: 12px 16px; border-bottom: 1px solid #E2E8F0; }
        .log-table tr:nth-child(even) { background: #F8FAFC; }

        /* Modals */
        .modal-overlay { display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.45); z-index: 2000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background-color: var(--white); border-radius: 12px; width: 100%; max-width: 450px; padding: 26px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid #E2E8F0; padding-bottom: 10px; }
        .modal-header h2 { font-size: 19px; color: var(--primary-blue); }
        .close-modal { background: none; border: none; font-size: 22px; color: var(--text-muted); cursor: pointer; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--text-dark); }
        .form-group input, .form-group select { width: 100%; padding: 9px 12px; border: 1px solid #CBD5E1; border-radius: 6px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary-blue); }
        .btn-submit { width: 100%; padding: 11px; background-color: var(--primary-blue); color: var(--white); border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 6px; }
        .btn-submit:hover { background-color: var(--accent-blue); }

        /* Modal Mode Switcher Tabs */
        .modal-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .modal-tab-btn { flex: 1; padding: 8px; border: 1px solid #CBD5E1; background: #F8FAFC; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text-dark); }
        .modal-tab-btn.active { background: var(--primary-blue); color: var(--white); border-color: var(--primary-blue); }

        /* Logout Confirmation Modal */
        .logout-modal-box { max-width: 340px; text-align: center; padding: 28px 24px; }
        .logout-modal-box p { font-size: 18px; font-weight: 600; color: var(--text-dark); margin-bottom: 22px; }
        .logout-actions { display: flex; align-items: center; justify-content: center; gap: 24px; }
        .btn-cancel-link { background: none; border: none; color: var(--primary-blue); text-decoration: underline; font-size: 15px; font-weight: 500; cursor: pointer; padding: 6px; }
        .btn-confirm-logout { background-color: #EF4444; color: #000000; text-decoration: none; padding: 9px 22px; border-radius: 8px; font-size: 15px; font-weight: 600; border: none; cursor: pointer; }
        .btn-confirm-logout:hover { background-color: #DC2626; }
    </style>
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

                <div class="card-list" id="inventoryList">
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
                                <div class="item-info">
                                    <h3>
                                        <?php echo htmlspecialchars($p['product_name']); ?>
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

                                <div class="card-actions">
                                    <button type="button" class="btn-quick" onclick='openStockModal(<?php echo json_encode($p); ?>)'>
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
                                            <button type="button" class="dropdown-item" onclick='openEditProductModal(<?php echo json_encode($p); ?>)'>
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
                                    <td>@<?php echo htmlspecialchars($log['staff_username']); ?></td>
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
            <form method="POST" id="newProductForm">
                <input type="hidden" name="action" value="add_product">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" placeholder="e.g., Mineral Water 500ml" required>
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
                            <?php foreach ($unassigned_products as $up): ?>
                                <option value="<?php echo $up['product_id']; ?>">
                                    <?php echo htmlspecialchars($up['product_name']); ?> (RM <?php echo number_format($up['price'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
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
            <form method="POST">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" id="edit_product_name" required>
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

    <script>
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });

        function toggleMenu(event, menuId) {
            event.stopPropagation();
            document.querySelectorAll('.dropdown-menu').forEach(m => {
                if (m.id !== menuId) m.classList.remove('show');
            });
            document.getElementById(menuId).classList.toggle('show');
        }

        window.addEventListener('click', () => {
            document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
        });

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function switchAddMode(mode) {
            const newForm = document.getElementById('newProductForm');
            const exForm = document.getElementById('existingProductForm');
            const tabNew = document.getElementById('tabNewBtn');
            const tabEx = document.getElementById('tabExistingBtn');

            if (mode === 'existing' && exForm) {
                newForm.style.display = 'none';
                exForm.style.display = 'block';
                tabNew.classList.remove('active');
                tabEx.classList.add('active');
            } else {
                newForm.style.display = 'block';
                if (exForm) exForm.style.display = 'none';
                tabNew.classList.add('active');
                if (tabEx) tabEx.classList.remove('active');
            }
        }

        function openEditProductModal(product) {
            document.getElementById('edit_product_id').value = product.product_id;
            document.getElementById('edit_product_name').value = product.product_name;
            document.getElementById('edit_category_id').value = product.category_id;
            document.getElementById('edit_price').value = product.price;
            document.getElementById('edit_reorder_level').value = product.reorder_level;
            document.getElementById('edit_status').value = product.status;
            openModal('editProductModal');
        }

        function openStockModal(product) {
            document.getElementById('stock_product_id').value = product.product_id;
            document.getElementById('stock_product_label').textContent = product.product_name;
            document.getElementById('stock_current_qty').textContent = product.stock_quantity;
            openModal('stockModal');
        }

        let currentStockFilter = 'all';
        function setStockFilter(filter, btn) {
            currentStockFilter = filter;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterInventory();
        }

        function filterInventory() {
            const query = document.getElementById('inventorySearch').value.toLowerCase();
            document.querySelectorAll('#inventoryList .item-card').forEach(card => {
                const nameMatch = card.dataset.name.includes(query);
                let filterMatch = true;
                if (currentStockFilter === 'low') filterMatch = (card.dataset.stock === 'low');
                else if (currentStockFilter === 'out') filterMatch = (card.dataset.stock === 'out');
                else if (currentStockFilter === 'inactive') filterMatch = (card.dataset.status === 'inactive');
                card.style.display = (nameMatch && filterMatch) ? 'flex' : 'none';
            });
        }
    </script>
</body>
</html>