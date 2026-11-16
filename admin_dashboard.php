<?php
// admin_dashboard.php
require_once 'db_connect.php';

// 1. Strict RBAC Access Control: Only 'Admin' role can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";
$msg_type = ""; // 'success' or 'error'

// Active tab ('outlets' or 'staffs')
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'staffs' ? 'staffs' : 'outlets';

// 2. Handle Form Submissions (Add / Edit / Delete Outlets & Staffs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ADD OUTLET ---
    if ($action === 'add_outlet') {
        $name       = trim($_POST['outlet_name']);
        $location   = trim($_POST['location']);
        $contact    = trim($_POST['contact_number']);
        $open_time  = $_POST['open_time'] ?? '';
        $close_time = $_POST['close_time'] ?? '';
        $hours      = (!empty($open_time) && !empty($close_time)) 
                      ? date("h:i A", strtotime($open_time)) . " - " . date("h:i A", strtotime($close_time)) 
                      : "";
        $status     = $_POST['status'] ?? 'Active';
        if (!empty($name) && !empty($location)) {
            $stmt = $conn->prepare("INSERT INTO outlet (outlet_name, location, contact_number, operating_hours, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $location, $contact, $hours, $status);
            $stmt->execute();
            $message = "Outlet '$name' added successfully.";
            $msg_type = "success";
            $stmt->close();
        }
        $active_tab = 'outlets';
    }
    // --- EDIT OUTLET ---
    elseif ($action === 'edit_outlet') {
        $id         = intval($_POST['outlet_id']);
        $name       = trim($_POST['outlet_name']);
        $location   = trim($_POST['location']);
        $contact    = trim($_POST['contact_number']);
        $open_time  = $_POST['open_time'] ?? '';
        $close_time = $_POST['close_time'] ?? '';
        $hours      = (!empty($open_time) && !empty($close_time)) 
                      ? date("h:i A", strtotime($open_time)) . " - " . date("h:i A", strtotime($close_time)) 
                      : "";
        $status     = $_POST['status'] ?? 'Active';
        $stmt = $conn->prepare("UPDATE outlet SET outlet_name = ?, location = ?, contact_number = ?, operating_hours = ?, status = ? WHERE outlet_id = ?");
        $stmt->bind_param("sssssi", $name, $location, $contact, $hours, $status, $id);
        $stmt->execute();
        $message = "Outlet updated successfully.";
        $msg_type = "success";
        $stmt->close();
        $active_tab = 'outlets';
    }

    // --- DELETE OUTLET ---
    elseif ($action === 'delete_outlet') {
        $id = intval($_POST['outlet_id']);
        $stmt = $conn->prepare("DELETE FROM outlet WHERE outlet_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Outlet deleted successfully.";
            $msg_type = "success";
        } else {
            $message = "Cannot delete outlet because staff or orders are still linked to it. Set status to 'Inactive' instead.";
            $msg_type = "error";
        }
        $stmt->close();
        $active_tab = 'outlets';
    }

    // --- ADD STAFF ---
    elseif ($action === 'add_staff') {
        $username  = trim($_POST['username']);
        $fullname  = trim($_POST['fullname']);
        $email     = trim($_POST['email']);
        $phone     = trim($_POST['phone_number']);
        $password  = $_POST['password'];
        $role      = $_POST['role'] === 'Admin' ? 'Admin' : 'Staff';
        $outlet_id = ($role === 'Staff' && !empty($_POST['outlet_id'])) ? intval($_POST['outlet_id']) : null;

        if (!empty($username) && !empty($email) && !empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO user (outlet_id, username, fullname, email, password, phone_number, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $outlet_id, $username, $fullname, $email, $hashed, $phone, $role);
            if ($stmt->execute()) {
                $message = "Staff account '$username' created successfully.";
                $msg_type = "success";
            } else {
                $message = "Failed to create account (email or username may already exist).";
                $msg_type = "error";
            }
            $stmt->close();
        }
        $active_tab = 'staffs';
    }

    // --- DELETE STAFF ---
    elseif ($action === 'delete_staff') {
        $uid = intval($_POST['user_id']);
        if ($uid !== intval($_SESSION['user_id'])) { // Prevent self-deletion
            $stmt = $conn->prepare("DELETE FROM user WHERE user_id = ? AND role IN ('Staff', 'Admin')");
            $stmt->bind_param("i", $uid);
            if ($stmt->execute()) {
                $message = "Staff account removed.";
                $msg_type = "success";
            } else {
                $message = "Cannot delete staff with existing order/inventory records.";
                $msg_type = "error";
            }
            $stmt->close();
        }
        $active_tab = 'staffs';
    }
}

// 3. Fetch All Outlets
$outlets = [];
$res_outlets = $conn->query("SELECT * FROM outlet ORDER BY outlet_id ASC");
if ($res_outlets) {
    while ($row = $res_outlets->fetch_assoc()) {
        $outlets[] = $row;
    }
}

// 4. Fetch All Staff & Admin Accounts with Outlet Name
$staffs = [];
$res_staffs = $conn->query("
    SELECT u.user_id, u.username, u.fullname, u.email, u.phone_number, u.role, u.outlet_id, o.outlet_name 
    FROM user u 
    LEFT JOIN outlet o ON u.outlet_id = o.outlet_id 
    WHERE u.role IN ('Staff', 'Admin') 
    ORDER BY u.role ASC, u.user_id ASC
");
if ($res_staffs) {
    while ($row = $res_staffs->fetch_assoc()) {
        $staffs[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard - e-Koperasi</title>
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
            --border-blue: #CBD5E1;
            --sidebar-width: 230px;
            --sidebar-collapsed: 68px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background-color: var(--white);
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Top Blue Header Bar */
        .topbar {
            height: 60px;
            background-color: var(--primary-blue);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 6px rgba(0, 71, 171, 0.2);
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .toggle-btn {
            background: none;
            border: none;
            color: var(--white);
            font-size: 22px;
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 6px;
            transition: background 0.2s;
        }

        .toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }

        .brand-title {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }

        .logout-btn {
            background-color: var(--white);
            color: var(--primary-blue);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background-color: var(--light-blue-bg);
        }

        /* Layout Wrapper */
        .layout-wrapper {
            display: flex;
            margin-top: 60px;
            min-height: calc(100vh - 60px);
        }

        /* Collapsible Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--light-blue-bg);
            border-right: 1px solid #D6E4FF;
            padding-top: 25px;
            transition: width 0.25s ease;
            overflow: hidden;
            flex-shrink: 0;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 22px;
            color: var(--text-dark);
            text-decoration: none;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .nav-item:hover {
            background-color: #DCE8F8;
            color: var(--primary-blue);
        }

        .nav-item.active {
            background-color: var(--primary-blue);
            color: var(--white);
            font-weight: 600;
        }

        .nav-icon {
            font-size: 19px;
            min-width: 24px;
            text-align: center;
        }

        .sidebar.collapsed .nav-text {
            display: none;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 35px 45px 100px 45px;
            background-color: var(--white);
            position: relative;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 24px;
        }

        /* Alert Banner */
        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            max-width: 820px;
        }

        .alert-success {
            background-color: #E6F4EA;
            color: #137333;
            border: 1px solid #CEEAD6;
        }

        .alert-error {
            background-color: #FCE8E6;
            color: #C5221F;
            border: 1px solid #FAD2CF;
        }

        /* Branch / Staff Card List (Matches Wireframe) */
        .card-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 820px;
        }

        .item-card {
            background-color: var(--card-blue);
            border: 1px solid #D0E1F9;
            border-radius: 14px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background-color 0.2s, box-shadow 0.2s;
            position: relative;
        }

        .item-card:hover {
            background-color: var(--card-hover);
            box-shadow: 0 3px 10px rgba(0, 71, 171, 0.08);
        }

        .item-info h3 {
            font-size: 18px;
            color: var(--text-dark);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .item-info p {
            font-size: 14px;
            color: var(--text-muted);
        }

        .badge {
            font-size: 12px;
            padding: 3px 9px;
            border-radius: 12px;
            font-weight: 600;
        }

        .badge-active {
            background-color: #D1FAE5;
            color: #065F46;
        }

        .badge-inactive {
            background-color: #FEE2E2;
            color: #991B1B;
        }

        .badge-role {
            background-color: var(--primary-blue);
            color: var(--white);
        }

        /* Three-Dot Action Menu */
        .menu-wrapper {
            position: relative;
        }

        .dots-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: background 0.2s;
        }

        .dots-btn:hover {
            background-color: rgba(0, 71, 171, 0.12);
        }

        .dot {
            width: 5px;
            height: 5px;
            background-color: var(--primary-blue);
            border-radius: 50%;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 40px;
            background-color: var(--white);
            border: 1px solid #D0E1F9;
            border-radius: 8px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
            min-width: 140px;
            z-index: 500;
            overflow: hidden;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-item {
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 11px 16px;
            font-size: 14px;
            color: var(--text-dark);
            cursor: pointer;
            display: block;
        }

        .dropdown-item:hover {
            background-color: var(--light-blue-bg);
            color: var(--primary-blue);
        }

        .dropdown-item.delete {
            color: #DC2626;
        }

        .dropdown-item.delete:hover {
            background-color: #FEF2F2;
        }

        /* Floating '+' Button (Bottom Right) */
        .fab-add {
            position: fixed;
            bottom: 32px;
            right: 36px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-blue);
            color: var(--white);
            border: none;
            font-size: 32px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 71, 171, 0.35);
            transition: transform 0.2s, background-color 0.2s;
            z-index: 900;
        }

        .fab-add:hover {
            background-color: var(--accent-blue);
            transform: scale(1.06);
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background-color: var(--white);
            border-radius: 12px;
            width: 100%;
            max-width: 440px;
            padding: 26px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            border-bottom: 1px solid #E2E8F0;
            padding-bottom: 10px;
        }

        .modal-header h2 {
            font-size: 19px;
            color: var(--primary-blue);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 22px;
            color: var(--text-muted);
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-dark);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-blue);
        }

        .btn-submit {
            width: 100%;
            padding: 11px;
            background-color: var(--primary-blue);
            color: var(--white);
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 6px;
        }

        .btn-submit:hover {
            background-color: var(--accent-blue);
        }

        /* LOGOUT */
        .logout-modal-box {
            max-width: 340px;
            text-align: center;
            padding: 28px 24px;
        }

        .logout-modal-box p {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 22px;
        }

        .logout-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
        }

        .btn-cancel-link {
            background: none;
            border: none;
            color: var(--primary-blue);
            text-decoration: underline;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            padding: 6px;
        }

        .btn-cancel-link:hover {
            color: var(--accent-blue);
        }

        .btn-confirm-logout {
            background-color: #EF4444;
            color: #000000;
            text-decoration: none;
            padding: 9px 22px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-confirm-logout:hover {
            background-color: #DC2626;
        }
    </style>
</head>
<body>

    <!-- Top Blue Header Bar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar">&#9776;</button>
            <span class="brand-title">e-Koperasi Superadmin</span>
        </div>
        <div class="topbar-right">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
            <button type="button" class="logout-btn" onclick="openModal('logoutModal')">Logout</button>
            <!-- <a href="logout.php" class="logout-btn">Logout</a> -->
        </div>
    </header>

    <div class="layout-wrapper">
        <!-- Collapsible Left Sidebar -->
        <aside class="sidebar" id="sidebar">
            <a href="?tab=outlets" class="nav-item <?php echo $active_tab === 'outlets' ? 'active' : ''; ?>">
                <span class="nav-icon">&#127978;</span>
                <span class="nav-text">Outlets</span>
            </a>
            <a href="?tab=staffs" class="nav-item <?php echo $active_tab === 'staffs' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128101;</span>
                <span class="nav-text">Staffs</span>
            </a>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $msg_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'outlets'): ?>
                <!-- OUTLETS VIEW -->
                <h1 class="page-title">Outlets</h1>

                <div class="card-list">
                    <?php if (empty($outlets)): ?>
                        <p style="color: var(--text-muted);">No outlets found. Click the + button to add your first branch.</p>
                    <?php else: ?>
                        <?php foreach ($outlets as $outlet): ?>
                            <div class="item-card">
                                <div class="item-info">
                                    <h3>
                                        <?php echo htmlspecialchars($outlet['outlet_name']); ?>
                                        <?php $status = $outlet['status'] ?? 'Active'; ?>
                                        <span class="badge <?php echo $status === 'Active' ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </h3>
                                    <p><?php echo htmlspecialchars($outlet['location'] ?? $outlet['location_description'] ?? 'No location specified'); ?></p>
                                </div>

                                <!-- 3-Dot Action Menu -->
                                <div class="menu-wrapper">
                                    <button class="dots-btn" onclick="toggleMenu(event, 'menu-outlet-<?php echo $outlet['outlet_id']; ?>')" title="Options">
                                        <span class="dot"></span>
                                        <span class="dot"></span>
                                        <span class="dot"></span>
                                    </button>
                                    <div class="dropdown-menu" id="menu-outlet-<?php echo $outlet['outlet_id']; ?>">
                                        <button type="button" class="dropdown-item"
                                            onclick='openEditOutletModal(<?php echo json_encode($outlet); ?>)'>
                                            Edit Branch
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete <?php echo htmlspecialchars($outlet['outlet_name']); ?>?');">
                                            <input type="hidden" name="action" value="delete_outlet">
                                            <input type="hidden" name="outlet_id" value="<?php echo $outlet['outlet_id']; ?>">
                                            <button type="submit" class="dropdown-item delete">Delete Branch</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Floating '+' Button to Add New Branch -->
                <button class="fab-add" onclick="openModal('addOutletModal')" title="Add New Outlet">+</button>

            <?php else: ?>
                <!-- STAFFS VIEW -->
                <h1 class="page-title">Staffs</h1>

                <div class="card-list">
                    <?php foreach ($staffs as $staff): ?>
                        <div class="item-card">
                            <div class="item-info">
                                <h3>
                                    <?php echo htmlspecialchars($staff['fullname']); ?>
                                    <span class="badge badge-role"><?php echo htmlspecialchars($staff['role']); ?></span>
                                </h3>
                                <p>
                                    @<?php echo htmlspecialchars($staff['username']); ?> &bull; 
                                    <?php echo htmlspecialchars($staff['email']); ?> &bull; 
                                    Assigned: <strong><?php echo $staff['outlet_name'] ? htmlspecialchars($staff['outlet_name']) : 'All Branches (System-wide)'; ?></strong>
                                </p>
                            </div>

                            <!-- 3-Dot Action Menu -->
                            <?php if (intval($staff['user_id']) !== intval($_SESSION['user_id'])): ?>
                                <div class="menu-wrapper">
                                    <button class="dots-btn" onclick="toggleMenu(event, 'menu-staff-<?php echo $staff['user_id']; ?>')" title="Options">
                                        <span class="dot"></span>
                                        <span class="dot"></span>
                                        <span class="dot"></span>
                                    </button>
                                    <div class="dropdown-menu" id="menu-staff-<?php echo $staff['user_id']; ?>">
                                        <form method="POST" onsubmit="return confirm('Remove staff account <?php echo htmlspecialchars($staff['username']); ?>?');">
                                            <input type="hidden" name="action" value="delete_staff">
                                            <input type="hidden" name="user_id" value="<?php echo $staff['user_id']; ?>">
                                            <button type="submit" class="dropdown-item delete">Remove Staff</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Floating '+' Button to Register New Staff -->
                <button class="fab-add" onclick="openModal('addStaffModal')" title="Register New Staff">+</button>
            <?php endif; ?>
        </main>
    </div>

    <!-- MODAL 1: ADD NEW OUTLET -->
    <div class="modal-overlay" id="addOutletModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Add New Outlet</h2>
                <button class="close-modal" onclick="closeModal('addOutletModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_outlet">
                <div class="form-group">
                    <label>Outlet Name *</label>
                    <input type="text" name="outlet_name" placeholder="e.g., Outlet 3 (Cafe C)" required>
                </div>
                <div class="form-group">
                    <label>Location *</label>
                    <input type="text" name="location" placeholder="e.g., Ground Floor, Cafeteria C" required>
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" placeholder="e.g., 06-2701234">
                </div>
                <div class="form-group">
                    <label>Operating Hours</label>
                    <div style="display: flex; gap: 12px;">
                        <div style="flex: 1;">
                            <small style="display: block; color: var(--text-muted); margin-bottom: 4px;">Open</small>
                            <input type="time" name="open_time" value="08:00">
                        </div>
                        <div style="flex: 1;">
                            <small style="display: block; color: var(--text-muted); margin-bottom: 4px;">Close</small>
                            <input type="time" name="close_time" value="22:00">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Save Outlet</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: EDIT OUTLET -->
    <div class="modal-overlay" id="editOutletModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Edit Outlet</h2>
                <button class="close-modal" onclick="closeModal('editOutletModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_outlet">
                <input type="hidden" name="outlet_id" id="edit_outlet_id">
                <div class="form-group">
                    <label>Outlet Name *</label>
                    <input type="text" name="outlet_name" id="edit_outlet_name" required>
                </div>
                <div class="form-group">
                    <label>Location *</label>
                    <input type="text" name="location" id="edit_location" required>
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" id="edit_contact_number">
                </div>
                <div class="form-group">
                    <label>Operating Hours</label>
                    <div style="display: flex; gap: 12px;">
                        <div style="flex: 1;">
                            <small style="display: block; color: var(--text-muted); margin-bottom: 4px;">Open</small>
                            <input type="time" name="open_time" id="edit_open_time">
                        </div>
                        <div style="flex: 1;">
                            <small style="display: block; color: var(--text-muted); margin-bottom: 4px;">Close</small>
                            <input type="time" name="close_time" id="edit_close_time">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Update Outlet</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: REGISTER NEW STAFF -->
    <div class="modal-overlay" id="addStaffModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Register New Staff</h2>
                <button class="close-modal" onclick="closeModal('addStaffModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_staff">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="fullname" placeholder="e.g., Ahmad Bin Ali" required>
                </div>
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" placeholder="e.g., staff_outlet1" required>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" placeholder="e.g., staff1@ekoperasi.edu.my" required>
                </div>
                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="text" name="phone_number" placeholder="e.g., 0123456789" required>
                </div>
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" minlength="6" required>
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" id="staff_role_select" onchange="toggleOutletDropdown()">
                        <option value="Staff">Staff (Assigned to Outlet)</option>
                        <option value="Admin">Superadmin (All Outlets)</option>
                    </select>
                </div>
                <div class="form-group" id="outlet_assign_group">
                    <label>Assign to Outlet *</label>
                    <select name="outlet_id">
                        <?php foreach ($outlets as $o): ?>
                            <option value="<?php echo $o['outlet_id']; ?>">
                                <?php echo htmlspecialchars($o['outlet_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Create Account</button>
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
        // Collapsible Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('sidebarToggle');
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });

        // 3-Dot Dropdown Menu Toggle
        function toggleMenu(event, menuId) {
            event.stopPropagation();
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== menuId) menu.classList.remove('show');
            });
            document.getElementById(menuId).classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        window.addEventListener('click', () => {
            document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
        });

        // Modal Controls
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Helper: Convert "8 am" or "08:00 AM" to "08:00" for <input type="time">
        function parseTo24Hour(timeStr) {
            if (!timeStr) return '';
            const match = timeStr.trim().match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i);
            if (!match) return '';

            let hours = parseInt(match[1], 10);
            const minutes = match[2] || '00';
            const period = match[3] ? match[3].toLowerCase() : null;

            if (period === 'pm' && hours < 12) hours += 12;
            if (period === 'am' && hours === 12) hours = 0;

            return String(hours).padStart(2, '0') + ':' + minutes;
        }

        // Pre-fill Edit Outlet Modal
        function openEditOutletModal(outlet) {
            document.getElementById('edit_outlet_id').value = outlet.outlet_id;
            document.getElementById('edit_outlet_name').value = outlet.outlet_name;
            document.getElementById('edit_location').value = outlet.location || outlet.location_description || '';
            document.getElementById('edit_contact_number').value = outlet.contact_number || '';
            document.getElementById('edit_status').value = outlet.status || 'Active';

            // Split "08:00 AM - 10:00 PM" into Open and Close time inputs
            let openVal = '', closeVal = '';
            if (outlet.operating_hours && outlet.operating_hours.includes('-')) {
                const parts = outlet.operating_hours.split('-');
                openVal = parseTo24Hour(parts[0]);
                closeVal = parseTo24Hour(parts[1]);
            }
            document.getElementById('edit_open_time').value = openVal;
            document.getElementById('edit_close_time').value = closeVal;

            openModal('editOutletModal');
        }

        // Hide Outlet Dropdown if 'Superadmin' role is selected
        function toggleOutletDropdown() {
            const role = document.getElementById('staff_role_select').value;
            const outletGroup = document.getElementById('outlet_assign_group');
            outletGroup.style.display = (role === 'Staff') ? 'block' : 'none';
        }
    </script>
</body>
</html>