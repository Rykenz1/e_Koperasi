<?php
// admin_dashboard.php
require_once 'includes/db_connect.php';

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
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
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

    <script src="js/main.js"></script>
    <script src="js/dashboard.js"></script>
    <script src="js/admin.js"></script>
</body>
</html>