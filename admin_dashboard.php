<?php
// admin_dashboard.php
require_once 'includes/db_connect.php';
require_once 'includes/upload_image.php';
require_once 'includes/csrf_helpers.php';

// 1. Strict RBAC Access Control: Only 'Admin' role can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";
$msg_type = ""; // 'success' or 'error'

/**
 * Remove a group row once it no longer has any variants.
 * Children are ungrouped before this is called, so this only cleans up
 * the now-empty group and its image (when no product still references it).
 */
function delete_group_if_empty($conn, int $group_id): void
{
    if ($group_id <= 0) {
        return;
    }

    $get = $conn->prepare("
        SELECT g.image_url,
               (SELECT COUNT(*) FROM product p WHERE p.group_id = g.group_id) AS variant_count
        FROM product_group g
        WHERE g.group_id = ?
    ");
    $get->bind_param("i", $group_id);
    $get->execute();
    $group = $get->get_result()->fetch_assoc();
    $get->close();

    if (!$group || intval($group['variant_count']) > 0) {
        return;
    }

    $del = $conn->prepare("DELETE FROM product_group WHERE group_id = ?");
    $del->bind_param("i", $group_id);
    $del->execute();
    $del->close();

    delete_group_image_if_unused($conn, $group['image_url'] ?? null);
}

/** Delete a group image file only when no product row points at it. */
function delete_group_image_if_unused($conn, ?string $path): void
{
    if ($path === null || $path === PRODUCT_IMAGE_DEFAULT) {
        return;
    }

    $chk = $conn->prepare("SELECT COUNT(*) AS uses FROM product WHERE image_url = ?");
    $chk->bind_param("s", $path);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (intval($row['uses'] ?? 0) === 0) {
        delete_product_image($path);
    }
}

// Active tab ('outlets', 'staffs' or 'inventories')
$allowed_tabs = ['outlets', 'staffs', 'inventories'];
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs, true) ? $_GET['tab'] : 'outlets';

// 2. Handle Form Submissions (Add / Edit / Delete Outlets & Staffs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Every dashboard POST must carry a valid CSRF token. Clearing $action
    // means no branch below matches, so nothing is written.
    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $action   = '';
        $message  = 'Your session expired. Please refresh the page and try again.';
        $msg_type = 'error';
    }

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

    // --- ADD MASTER CATALOG PRODUCT (NO BRANCH DISTRIBUTION) ---
    elseif ($action === 'add_master_product') {
        $product_name = trim($_POST['product_name'] ?? '');
        $category_id  = intval($_POST['category_id'] ?? 0);
        $price        = floatval($_POST['price'] ?? 0);
        $upload       = save_product_image($_FILES['product_image'] ?? []);

        if (!$upload['ok']) {
            $message  = $upload['error'];
            $msg_type = "error";
        } elseif ($product_name === '' || $category_id <= 0) {
            $message  = "Product name and category are required.";
            $msg_type = "error";
        } else {
            $image_url = $upload['path'] ?? PRODUCT_IMAGE_DEFAULT;

            try {
                $stmt = $conn->prepare("INSERT INTO product (category_id, product_name, price, image_url) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isds", $category_id, $product_name, $price, $image_url);
                $stmt->execute();
                $stmt->close();

                $message  = "Product '$product_name' added to the master catalog. Branch staff can now add it from the catalog.";
                $msg_type = "success";
            } catch (mysqli_sql_exception $e) {
                if ($upload['path'] !== null) {
                    delete_product_image($upload['path']);
                }
                $message  = "Failed to add product: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
    }

    // --- ADD A VARIANT TO A PRODUCT GROUP ---
    elseif ($action === 'add_product_variant') {
        $group_id       = intval($_POST['group_id'] ?? 0);
        $new_group_name = trim($_POST['new_group_name'] ?? '');
        $variant_name   = trim($_POST['variant_name'] ?? '');
        $category_id    = intval($_POST['category_id'] ?? 0);
        $price          = floatval($_POST['price'] ?? 0);
        $upload         = save_product_image($_FILES['product_image'] ?? []);

        if (!$upload['ok']) {
            $message  = $upload['error'];
            $msg_type = "error";
        } elseif ($variant_name === '') {
            $message  = "Flavour / variant name is required.";
            $msg_type = "error";
        } else {
            $new_upload_path = $upload['path'];

            try {
                $conn->begin_transaction();

                if ($group_id > 0) {
                    $get = $conn->prepare("SELECT group_name, category_id FROM product_group WHERE group_id = ?");
                    $get->bind_param("i", $group_id);
                    $get->execute();
                    $group = $get->get_result()->fetch_assoc();
                    $get->close();

                    if (!$group) {
                        throw new RuntimeException("Selected product group was not found.");
                    }

                    $group_name = $group['group_name'];
                    $category_id = intval($group['category_id']);
                } else {
                    if ($new_group_name === '') {
                        throw new RuntimeException("Group name is required when creating a new group.");
                    }
                    if ($category_id <= 0) {
                        throw new RuntimeException("Category is required for a new group.");
                    }

                    $chk = $conn->prepare("SELECT group_id FROM product_group WHERE group_name = ?");
                    $chk->bind_param("s", $new_group_name);
                    $chk->execute();
                    if ($chk->get_result()->fetch_assoc()) {
                        $chk->close();
                        throw new RuntimeException("A group named '$new_group_name' already exists.");
                    }
                    $chk->close();

                    $ins_group = $conn->prepare("INSERT INTO product_group (group_name, category_id) VALUES (?, ?)");
                    $ins_group->bind_param("si", $new_group_name, $category_id);
                    $ins_group->execute();
                    $group_id = $conn->insert_id;
                    $ins_group->close();

                    $group_name = $new_group_name;
                }

                $chk = $conn->prepare("SELECT product_id FROM product WHERE group_id = ? AND variant_name = ?");
                $chk->bind_param("is", $group_id, $variant_name);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $chk->close();
                    throw new RuntimeException("'$variant_name' already exists in this group.");
                }
                $chk->close();

                $product_name = $group_name . ' ' . $variant_name;
                $image_url    = $upload['path'] ?? PRODUCT_IMAGE_DEFAULT;

                $stmt = $conn->prepare("
                    INSERT INTO product (category_id, group_id, variant_name, product_name, price, image_url)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissds", $category_id, $group_id, $variant_name, $product_name, $price, $image_url);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $message  = "Variant '$product_name' added to the catalog.";
                $msg_type = "success";
            } catch (RuntimeException $e) {
                $conn->rollback();
                if ($new_upload_path !== null) {
                    delete_product_image($new_upload_path);
                }
                $message  = $e->getMessage();
                $msg_type = "error";
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($new_upload_path !== null) {
                    delete_product_image($new_upload_path);
                }
                $message  = "Failed to add variant: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
    }

    // --- EDIT MASTER CATALOG PRODUCT (STANDALONE OR GROUPED VARIANT) ---
    elseif ($action === 'edit_master_product') {
        $product_id      = intval($_POST['product_id'] ?? 0);
        $target_group_id = intval($_POST['group_id'] ?? 0);
        $variant_name    = trim($_POST['variant_name'] ?? '');
        $product_name    = trim($_POST['product_name'] ?? '');
        $category_id     = intval($_POST['category_id'] ?? 0);
        $price           = floatval($_POST['price'] ?? 0);
        $upload          = save_product_image($_FILES['product_image'] ?? []);

        if (!$upload['ok']) {
            $message  = $upload['error'];
            $msg_type = "error";
        } elseif ($product_id <= 0) {
            $message  = "Invalid product.";
            $msg_type = "error";
        } else {
            try {
                $get = $conn->prepare("SELECT image_url, group_id FROM product WHERE product_id = ?");
                $get->bind_param("i", $product_id);
                $get->execute();
                $current = $get->get_result()->fetch_assoc();
                $get->close();

                if (!$current) {
                    if ($upload['path'] !== null) {
                        delete_product_image($upload['path']);
                    }
                    throw new RuntimeException("Product not found.");
                }

                if ($target_group_id > 0) {
                    $get_group = $conn->prepare("SELECT group_name, category_id FROM product_group WHERE group_id = ?");
                    $get_group->bind_param("i", $target_group_id);
                    $get_group->execute();
                    $group = $get_group->get_result()->fetch_assoc();
                    $get_group->close();

                    if (!$group) {
                        throw new RuntimeException("Selected product group was not found.");
                    }
                    if ($variant_name === '') {
                        throw new RuntimeException("Flavour / variant name is required for a grouped product.");
                    }

                    $chk = $conn->prepare("SELECT product_id FROM product WHERE group_id = ? AND variant_name = ? AND product_id <> ?");
                    $chk->bind_param("isi", $target_group_id, $variant_name, $product_id);
                    $chk->execute();
                    if ($chk->get_result()->fetch_assoc()) {
                        $chk->close();
                        throw new RuntimeException("'$variant_name' already exists in this group.");
                    }
                    $chk->close();

                    $category_id  = intval($group['category_id']);
                    $product_name = $group['group_name'] . ' ' . $variant_name;
                } else {
                    if ($product_name === '' || $category_id <= 0) {
                        throw new RuntimeException("Product name and category are required.");
                    }
                    $variant_name = null;
                }

                $current_image = $current['image_url'] ?? null;
                $image_url     = $upload['path'] ?? $current_image ?? PRODUCT_IMAGE_DEFAULT;

                // NULLIF keeps standalone rows genuinely NULL rather than 0
                // (0 would violate the group foreign key).
                $stmt = $conn->prepare("
                    UPDATE product
                    SET category_id = ?, group_id = NULLIF(?, 0), variant_name = NULLIF(?, ''),
                        product_name = ?, price = ?, image_url = ?
                    WHERE product_id = ?
                ");
                $stmt->bind_param("iissdsi", $category_id, $target_group_id, $variant_name, $product_name, $price, $image_url, $product_id);
                $stmt->execute();
                $stmt->close();

                // Only drop the old file once the new path is safely saved.
                if ($upload['path'] !== null) {
                    delete_product_image($current_image);
                }

                // Moving the last variant out of a group should not leave an empty group behind.
                $old_group_id = intval($current['group_id'] ?? 0);
                if ($old_group_id > 0 && $old_group_id !== $target_group_id) {
                    delete_group_if_empty($conn, $old_group_id);
                }

                $message  = "Product '$product_name' updated. Changes apply to every branch that carries it.";
                $msg_type = "success";
            } catch (RuntimeException $e) {
                if ($upload['path'] !== null) {
                    delete_product_image($upload['path']);
                }
                $message  = $e->getMessage();
                $msg_type = "error";
            } catch (mysqli_sql_exception $e) {
                if ($upload['path'] !== null) {
                    delete_product_image($upload['path']);
                }
                $message  = "Failed to update product: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
    }

    // --- DELETE MASTER CATALOG PRODUCT ---
    elseif ($action === 'delete_master_product') {
        $product_id = intval($_POST['product_id'] ?? 0);

        if ($product_id > 0) {
            $chk = $conn->prepare("
                SELECT
                    (SELECT COUNT(*) FROM order_item WHERE product_id = ?) AS order_count,
                    (SELECT COUNT(*) FROM inventory_transaction WHERE product_id = ?) AS history_count
            ");
            $chk->bind_param("ii", $product_id, $product_id);
            $chk->execute();
            $refs = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (intval($refs['order_count'] ?? 0) > 0 || intval($refs['history_count'] ?? 0) > 0) {
                $message  = "Cannot delete this product because it has order records or stock history. Keep it for audit purposes instead.";
                $msg_type = "error";
            } else {
                try {
                    $get = $conn->prepare("SELECT image_url, group_id FROM product WHERE product_id = ?");
                    $get->bind_param("i", $product_id);
                    $get->execute();
                    $row = $get->get_result()->fetch_assoc();
                    $get->close();

                    $del = $conn->prepare("DELETE FROM product WHERE product_id = ?");
                    $del->bind_param("i", $product_id);
                    $del->execute();
                    $del->close();

                    if ($row) {
                        delete_product_image($row['image_url'] ?? null);
                        delete_group_if_empty($conn, intval($row['group_id'] ?? 0));
                    }

                    $message  = "Product removed from the master catalog.";
                    $msg_type = "success";
                } catch (mysqli_sql_exception $e) {
                    $message  = "Cannot delete this product: " . $e->getMessage();
                    $msg_type = "error";
                }
            }
        }
        $active_tab = 'inventories';
    }

    // --- EDIT A PRODUCT GROUP (NAME / CATEGORY / IMAGE) ---
    elseif ($action === 'edit_product_group') {
        $group_id   = intval($_POST['group_id'] ?? 0);
        $group_name = trim($_POST['group_name'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $upload     = save_product_image($_FILES['group_image'] ?? []);

        if (!$upload['ok']) {
            $message  = $upload['error'];
            $msg_type = "error";
        } elseif ($group_id <= 0 || $group_name === '' || $category_id <= 0) {
            $message  = "Group name and category are required.";
            $msg_type = "error";
        } else {
            $tx_started = false;
            try {
                $get = $conn->prepare("SELECT image_url FROM product_group WHERE group_id = ?");
                $get->bind_param("i", $group_id);
                $get->execute();
                $group = $get->get_result()->fetch_assoc();
                $get->close();

                if (!$group) {
                    throw new RuntimeException("Product group not found.");
                }

                $chk = $conn->prepare("SELECT group_id FROM product_group WHERE group_name = ? AND group_id <> ?");
                $chk->bind_param("si", $group_name, $group_id);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $chk->close();
                    throw new RuntimeException("A group named '$group_name' already exists.");
                }
                $chk->close();

                $current_image = $group['image_url'] ?? null;
                $image_url     = $upload['path'] ?? $current_image ?? PRODUCT_IMAGE_DEFAULT;

                $conn->begin_transaction();
                $tx_started = true;

                $upd_group = $conn->prepare("UPDATE product_group SET group_name = ?, category_id = ?, image_url = ? WHERE group_id = ?");
                $upd_group->bind_param("sisi", $group_name, $category_id, $image_url, $group_id);
                $upd_group->execute();
                $upd_group->close();

                // Keep children consistent with the group.
                $upd_children = $conn->prepare("
                    UPDATE product
                    SET category_id = ?, product_name = CONCAT(?, ' ', variant_name)
                    WHERE group_id = ?
                ");
                $upd_children->bind_param("isi", $category_id, $group_name, $group_id);
                $upd_children->execute();
                $upd_children->close();

                $conn->commit();
                $tx_started = false;

                if ($upload['path'] !== null) {
                    delete_group_image_if_unused($conn, $current_image);
                }

                $message  = "Group '$group_name' updated.";
                $msg_type = "success";
            } catch (RuntimeException $e) {
                if ($tx_started) {
                    $conn->rollback();
                }
                if ($upload['path'] !== null) {
                    delete_product_image($upload['path']);
                }
                $message  = $e->getMessage();
                $msg_type = "error";
            } catch (mysqli_sql_exception $e) {
                if ($tx_started) {
                    $conn->rollback();
                }
                if ($upload['path'] !== null) {
                    delete_product_image($upload['path']);
                }
                $message  = "Failed to update group: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
    }

    // --- DELETE A PRODUCT GROUP (UNGROUP ITS VARIANTS) ---
    elseif ($action === 'delete_product_group') {
        $group_id = intval($_POST['group_id'] ?? 0);

        if ($group_id > 0) {
            $tx_started = false;
            try {
                $get = $conn->prepare("SELECT group_name, image_url FROM product_group WHERE group_id = ?");
                $get->bind_param("i", $group_id);
                $get->execute();
                $group = $get->get_result()->fetch_assoc();
                $get->close();

                if (!$group) {
                    throw new RuntimeException("Product group not found.");
                }

                $conn->begin_transaction();
                $tx_started = true;

                $upd = $conn->prepare("UPDATE product SET group_id = NULL, variant_name = NULL WHERE group_id = ?");
                $upd->bind_param("i", $group_id);
                $upd->execute();
                $upd->close();

                $del = $conn->prepare("DELETE FROM product_group WHERE group_id = ?");
                $del->bind_param("i", $group_id);
                $del->execute();
                $del->close();

                $conn->commit();
                $tx_started = false;

                delete_group_image_if_unused($conn, $group['image_url'] ?? null);

                $message  = "Group '{$group['group_name']}' removed. Its products are now standalone.";
                $msg_type = "success";
            } catch (RuntimeException $e) {
                if ($tx_started) {
                    $conn->rollback();
                }
                $message  = $e->getMessage();
                $msg_type = "error";
            } catch (mysqli_sql_exception $e) {
                if ($tx_started) {
                    $conn->rollback();
                }
                $message  = "Failed to delete group: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
    }

    // --- REMOVE ONE PRODUCT FROM ITS GROUP ---
    elseif ($action === 'remove_from_group') {
        $product_id = intval($_POST['product_id'] ?? 0);

        if ($product_id > 0) {
            try {
                $get = $conn->prepare("SELECT group_id, product_name FROM product WHERE product_id = ?");
                $get->bind_param("i", $product_id);
                $get->execute();
                $product = $get->get_result()->fetch_assoc();
                $get->close();

                if (!$product) {
                    throw new RuntimeException("Product not found.");
                }

                $old_group_id = intval($product['group_id'] ?? 0);

                $upd = $conn->prepare("UPDATE product SET group_id = NULL, variant_name = NULL WHERE product_id = ?");
                $upd->bind_param("i", $product_id);
                $upd->execute();
                $upd->close();

                delete_group_if_empty($conn, $old_group_id);

                $message  = "'{$product['product_name']}' is now a standalone product.";
                $msg_type = "success";
            } catch (RuntimeException $e) {
                $message  = $e->getMessage();
                $msg_type = "error";
            } catch (mysqli_sql_exception $e) {
                $message  = "Failed to remove product from group: " . $e->getMessage();
                $msg_type = "error";
            }
        }
        $active_tab = 'inventories';
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

// 3b. Fetch Categories (used by inventory filters and product forms)
$categories = [];
$res_cat = $conn->query("SELECT category_id, category_name FROM category ORDER BY category_name ASC");
if ($res_cat) {
    while ($row = $res_cat->fetch_assoc()) {
        $categories[] = $row;
    }
}

// 3b2. Fetch product groups (variant families such as "Fanta").
$product_groups = [];
$res_groups = $conn->query("
    SELECT g.group_id, g.group_name, g.category_id, g.image_url,
           COUNT(p.product_id) AS variant_count
    FROM product_group g
    LEFT JOIN product p ON p.group_id = g.group_id
    GROUP BY g.group_id, g.group_name, g.category_id, g.image_url
    ORDER BY g.group_name ASC
");
if ($res_groups) {
    while ($row = $res_groups->fetch_assoc()) {
        $product_groups[] = $row;
    }
}

// 3c. Inventory filters. A non-zero outlet_id switches to branch-scoped inventory.
$filter_category = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$filter_outlet   = isset($_GET['outlet_id']) ? intval($_GET['outlet_id']) : 0;
$filter_group    = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
$filter_q        = trim($_GET['q'] ?? '');
$filter_state    = $_GET['state'] ?? 'all';
if (!in_array($filter_state, ['all', 'low', 'out', 'notcarried', 'unavailable'], true)) {
    $filter_state = 'all';
}

$current_outlet = null;
foreach ($outlets as $o) {
    if ($filter_outlet > 0 && intval($o['outlet_id']) === $filter_outlet) {
        $current_outlet = $o;
        break;
    }
}
if ($filter_outlet > 0 && $current_outlet === null) {
    $filter_outlet = 0;
}

$valid_group_ids = array_map('intval', array_column($product_groups, 'group_id'));
if ($filter_group !== 0 && $filter_group !== -1 && !in_array($filter_group, $valid_group_ids, true)) {
    $filter_group = 0;
}

$is_branch_scope = $filter_outlet > 0;
$outlet_total    = count($outlets);

// Drop state filters that only exist in the other scope (e.g. switching from
// "Not Carried" in all-branches mode to a single branch).
if ($is_branch_scope && $filter_state === 'notcarried') {
    $filter_state = 'all';
}
if (!$is_branch_scope && $filter_state === 'unavailable') {
    $filter_state = 'all';
}

// 3d. Fetch the inventory overview. Products come from the master catalog so
//     items that no branch carries yet are still visible (all-branches mode).
$inventory_products = [];
$stmt_inv = $conn->prepare("
    SELECT p.product_id, p.category_id, p.group_id, p.variant_name,
           p.product_name, p.price, p.image_url,
           c.category_name,
           g.group_name, g.category_id AS group_category_id, g.image_url AS group_image_url,
           COALESCE(SUM(oi.stock_quantity), 0) AS total_stock,
           COUNT(oi.outlet_id) AS branch_count,
           COALESCE(SUM(CASE WHEN oi.stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_branches,
           COALESCE(SUM(CASE WHEN oi.stock_quantity > 0 AND oi.stock_quantity <= oi.reorder_level THEN 1 ELSE 0 END), 0) AS low_branches,
           MAX(oi.status) AS branch_status,
           MAX(oi.reorder_level) AS branch_reorder
    FROM product p
    LEFT JOIN category c ON c.category_id = p.category_id
    LEFT JOIN product_group g ON g.group_id = p.group_id
    LEFT JOIN outlet_inventory oi
           ON oi.product_id = p.product_id
          AND (? = 0 OR oi.outlet_id = ?)
    WHERE (? = 0 OR oi.outlet_id IS NOT NULL)
      AND (? = 0 OR p.category_id = ?)
      AND (? = 0 OR (? = -1 AND p.group_id IS NULL) OR p.group_id = ?)
      AND (? = '' OR p.product_name LIKE CONCAT('%', ?, '%') OR g.group_name LIKE CONCAT('%', ?, '%'))
    GROUP BY p.product_id, p.category_id, p.group_id, p.variant_name,
             p.product_name, p.price, p.image_url, c.category_name,
             g.group_name, g.category_id, g.image_url
    ORDER BY c.category_name ASC, p.product_name ASC
");
if ($stmt_inv) {
    $stmt_inv->bind_param(
        "iiiiiiiisss",
        $filter_outlet, $filter_outlet,
        $filter_outlet,
        $filter_category, $filter_category,
        $filter_group, $filter_group, $filter_group,
        $filter_q, $filter_q, $filter_q
    );
    $stmt_inv->execute();
    $res_inv = $stmt_inv->get_result();
    while ($row = $res_inv->fetch_assoc()) {
        $inventory_products[] = $row;
    }
    $stmt_inv->close();
}

// 3e. Derive per-product state + summary counts, then apply the state filter.
$inventory_stats = [
    'products' => 0,
    'carried'  => 0,
    'low'      => 0,
    'out'      => 0,
    'notcarried' => 0,
    'unavailable' => 0,
];
$display_products = [];

foreach ($inventory_products as $p) {
    $branch_count = intval($p['branch_count']);
    $total_stock  = intval($p['total_stock']);
    $low_branches = intval($p['low_branches']);

    $state = 'ok';
    if (!$is_branch_scope && $branch_count === 0) {
        $state = 'notcarried';
    } elseif ($total_stock === 0) {
        $state = 'out';
    } elseif ($is_branch_scope ? ($total_stock <= intval($p['branch_reorder'])) : ($low_branches > 0)) {
        $state = 'low';
    }
    $p['_state'] = $state;

    $inventory_stats['products']++;
    if ($branch_count > 0)                 $inventory_stats['carried']++;
    if ($state === 'notcarried')           $inventory_stats['notcarried']++;
    if ($state === 'low')                  $inventory_stats['low']++;
    if ($state === 'out')                  $inventory_stats['out']++;
    if ($is_branch_scope && ($p['branch_status'] ?? '') === 'Inactive') {
        $inventory_stats['unavailable']++;
    }

    $state_match = ($filter_state === 'all')
        || ($filter_state === $state)
        || ($filter_state === 'unavailable' && $is_branch_scope && ($p['branch_status'] ?? '') === 'Inactive');

    if ($state_match) {
        $display_products[] = $p;
    }
}

// 3f. Per-branch stock map for the "View branches" modal.
$inventory_by_product = [];
$res_break = $conn->query("
    SELECT oi.product_id, o.outlet_id, o.outlet_name, oi.stock_quantity, oi.reorder_level, oi.status
    FROM outlet_inventory oi
    JOIN outlet o ON o.outlet_id = oi.outlet_id
    ORDER BY o.outlet_name ASC
");
if ($res_break) {
    while ($row = $res_break->fetch_assoc()) {
        $inventory_by_product[intval($row['product_id'])][] = $row;
    }
}

// Helper that preserves the active filters when building inventory links.
$filter_url = function (array $overrides = []) use ($filter_category, $filter_outlet, $filter_group, $filter_q, $filter_state) {
    $params = ['tab' => 'inventories'];
    if ($filter_category > 0) $params['category_id'] = $filter_category;
    if ($filter_outlet > 0)   $params['outlet_id'] = $filter_outlet;
    if ($filter_group !== 0)  $params['group_id'] = $filter_group;
    if ($filter_q !== '')     $params['q'] = $filter_q;
    if ($filter_state !== 'all') $params['state'] = $filter_state;

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return '?' . http_build_query($params);
};

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
    <link rel="stylesheet" href="css/inventory.css">
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
            <a href="?tab=inventories" class="nav-item <?php echo $active_tab === 'inventories' ? 'active' : ''; ?>">
                <span class="nav-icon">&#128230;</span>
                <span class="nav-text">Inventories</span>
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
                                        <a class="dropdown-item" href="?tab=inventories&amp;outlet_id=<?php echo intval($outlet['outlet_id']); ?>">
                                            View Inventory
                                        </a>
                                        <button type="button" class="dropdown-item"
                                            onclick='openEditOutletModal(<?php echo htmlspecialchars(json_encode($outlet, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            Edit Branch
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete <?php echo htmlspecialchars($outlet['outlet_name']); ?>?');">
                                            <?php echo csrf_field(); ?>
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

            <?php elseif ($active_tab === 'inventories'): ?>
                <!-- INVENTORIES VIEW -->
                <div class="header-row">
                    <h1 class="page-title" style="margin-bottom: 0;">
                        Inventories<?php echo $current_outlet ? ' &mdash; ' . htmlspecialchars($current_outlet['outlet_name']) : ''; ?>
                    </h1>
                    <?php if ($is_branch_scope): ?>
                        <a class="btn-outline" href="<?php echo htmlspecialchars($filter_url(['outlet_id' => null])); ?>">
                            View all branches
                        </a>
                    <?php endif; ?>
                </div>

                <div class="stats-bar">
                    <div class="stat-chip">
                        <?php echo $is_branch_scope ? 'Items' : 'Products'; ?>: <?php echo $inventory_stats['products']; ?>
                    </div>
                    <?php if ($is_branch_scope): ?>
                        <div class="stat-chip warn">Low Stock: <?php echo $inventory_stats['low']; ?></div>
                        <div class="stat-chip danger">Out of Stock: <?php echo $inventory_stats['out']; ?></div>
                        <div class="stat-chip">Unavailable: <?php echo $inventory_stats['unavailable']; ?></div>
                    <?php else: ?>
                        <div class="stat-chip">Carried: <?php echo $inventory_stats['carried']; ?></div>
                        <div class="stat-chip warn">Low Stock: <?php echo $inventory_stats['low']; ?></div>
                        <div class="stat-chip danger">Out of Stock: <?php echo $inventory_stats['out']; ?></div>
                        <div class="stat-chip">Not Carried: <?php echo $inventory_stats['notcarried']; ?></div>
                    <?php endif; ?>
                </div>

                <form method="GET" class="toolbar" id="inventoryFilters">
                    <input type="hidden" name="tab" value="inventories">
                    <input type="hidden" name="state" value="<?php echo htmlspecialchars($filter_state); ?>">
                    <input type="search" name="q" class="search-input"
                           placeholder="Search product name..."
                           value="<?php echo htmlspecialchars($filter_q); ?>">
                    <select name="category_id" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo intval($cat['category_id']); ?>"
                                <?php echo $filter_category === intval($cat['category_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="group_id" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All groups</option>
                        <option value="-1" <?php echo $filter_group === -1 ? 'selected' : ''; ?>>Ungrouped</option>
                        <?php foreach ($product_groups as $grp): ?>
                            <option value="<?php echo intval($grp['group_id']); ?>"
                                <?php echo $filter_group === intval($grp['group_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($grp['group_name']); ?> (<?php echo intval($grp['variant_count']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="outlet_id" class="filter-select" onchange="this.form.submit()">
                        <option value="0">All branches</option>
                        <?php foreach ($outlets as $o): ?>
                            <option value="<?php echo intval($o['outlet_id']); ?>"
                                <?php echo $filter_outlet === intval($o['outlet_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($o['outlet_name']); ?><?php echo ($o['status'] ?? 'Active') === 'Inactive' ? ' (Inactive)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="filter-btn">Search</button>
                </form>

                <?php
                $state_chips = [
                    'all' => 'All',
                    'low' => 'Low Stock',
                    'out' => 'Out of Stock',
                ];
                if ($is_branch_scope) {
                    $state_chips['unavailable'] = 'Unavailable';
                } else {
                    $state_chips['notcarried'] = 'Not Carried';
                }
                ?>
                <div class="filter-chips">
                    <?php foreach ($state_chips as $chip_key => $chip_label): ?>
                        <a class="filter-chip <?php echo $filter_state === $chip_key ? 'active' : ''; ?>"
                           href="<?php echo htmlspecialchars($filter_url(['state' => $chip_key === 'all' ? null : $chip_key])); ?>">
                            <?php echo htmlspecialchars($chip_label); ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($filter_category > 0 || $filter_outlet > 0 || $filter_group !== 0 || $filter_q !== '' || $filter_state !== 'all'): ?>
                        <a class="filter-chip filter-chip-clear" href="?tab=inventories">Clear filters</a>
                    <?php endif; ?>
                </div>

                <div class="card-list inventory-list" id="inventoryList">
                    <?php if (empty($display_products)): ?>
                        <p style="color: var(--text-muted);">
                            <?php echo empty($inventory_products) ? 'No products in the master catalog yet. Click + to add one.' : 'No products match the current filters.'; ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($display_products as $p): ?>
                            <?php
                            $state       = $p['_state'];
                            $branch_qty  = intval($p['total_stock']);
                            $is_inactive = $is_branch_scope && ($p['branch_status'] ?? 'Active') === 'Inactive';
                            $low_label   = 'Low stock';
                            if (!$is_branch_scope) {
                                $low_count = intval($p['low_branches']);
                                $low_label .= ' in ' . $low_count . ' branch' . ($low_count > 1 ? 'es' : '');
                            }
                            ?>
                            <div class="item-card <?php echo ($is_inactive || $state === 'notcarried') ? 'inactive-card' : ''; ?>">
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
                                            <?php if ($state === 'notcarried'): ?>
                                                <span class="badge badge-muted">Not in any branch</span>
                                            <?php elseif ($state === 'out'): ?>
                                                <span class="badge badge-out">Out of stock</span>
                                            <?php elseif ($state === 'low'): ?>
                                                <span class="badge badge-low"><?php echo htmlspecialchars($low_label); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-active">In stock</span>
                                            <?php endif; ?>
                                            <?php if ($is_branch_scope): ?>
                                                <span class="badge <?php echo $is_inactive ? 'badge-muted' : 'badge-active'; ?>">
                                                    <?php echo $is_inactive ? 'Unavailable' : 'Available'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p>
                                            Price: <strong>RM <?php echo number_format($p['price'], 2); ?></strong> &bull;
                                            <?php if ($is_branch_scope): ?>
                                                Branch stock: <strong><?php echo $branch_qty; ?> units</strong> &bull;
                                                Reorder at: <strong><?php echo intval($p['branch_reorder']); ?></strong>
                                            <?php else: ?>
                                                Total stock: <strong><?php echo $branch_qty; ?> units</strong> &bull;
                                                Carried in <strong><?php echo intval($p['branch_count']); ?> of <?php echo $outlet_total; ?></strong> branches
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="card-actions">
                                    <?php if (intval($p['branch_count']) > 0): ?>
                                        <button type="button" class="btn-outline"
                                                onclick="openProductBranches(<?php echo intval($p['product_id']); ?>, <?php echo htmlspecialchars(json_encode($p['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>)">
                                            View branches
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!$is_branch_scope): ?>
                                        <div class="menu-wrapper">
                                            <button type="button" class="dots-btn" onclick="toggleMenu(event, 'menu-prod-<?php echo intval($p['product_id']); ?>')">
                                                <span class="dot"></span>
                                                <span class="dot"></span>
                                                <span class="dot"></span>
                                            </button>
                                            <div class="dropdown-menu" id="menu-prod-<?php echo intval($p['product_id']); ?>">
                                                <button type="button" class="dropdown-item"
                                                        onclick='openEditMasterProductModal(<?php echo htmlspecialchars(json_encode($p, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES); ?>)'>
                                                    Edit Product
                                                </button>
                                                <button type="button" class="dropdown-item"
                                                        onclick="openAddVariantModal(<?php echo intval($p['group_id'] ?? 0); ?>)">
                                                    Add Variant
                                                </button>
                                                <?php if (!empty($p['group_id'])): ?>
                                                    <button type="button" class="dropdown-item"
                                                            onclick="openEditProductGroupModal(<?php echo intval($p['group_id']); ?>)">
                                                        Edit Group
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($p['product_name'])); ?> from its group? It becomes a standalone product.');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="remove_from_group">
                                                        <input type="hidden" name="product_id" value="<?php echo intval($p['product_id']); ?>">
                                                        <button type="submit" class="dropdown-item">Remove from Group</button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Delete the group &quot;<?php echo htmlspecialchars(addslashes($p['group_name'])); ?>&quot;? Its variants stay in the catalog as standalone products.');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_product_group">
                                                        <input type="hidden" name="group_id" value="<?php echo intval($p['group_id']); ?>">
                                                        <button type="submit" class="dropdown-item delete">Delete Group</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($p['product_name'])); ?> from the master catalog? Products with order records or stock history cannot be deleted.');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_master_product">
                                                    <input type="hidden" name="product_id" value="<?php echo intval($p['product_id']); ?>">
                                                    <button type="submit" class="dropdown-item delete">Delete Product</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$is_branch_scope): ?>
                    <!-- Floating '+' Button to Add a Master Product -->
                    <button class="fab-add" onclick="openModal('addMasterProductModal')" title="Add Master Product">+</button>
                <?php endif; ?>

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
                                            <?php echo csrf_field(); ?>
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
                <?php echo csrf_field(); ?>
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
                <?php echo csrf_field(); ?>
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

    <!-- MODAL 3: ADD MASTER PRODUCT (SINGLE OR VARIANT) -->
    <div class="modal-overlay" id="addMasterProductModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Add Master Product</h2>
                <button class="close-modal" onclick="closeModal('addMasterProductModal')">&times;</button>
            </div>

            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" id="addTabSingle"
                        onclick="switchAddProductMode('single')">Single Product</button>
                <button type="button" class="modal-tab" id="addTabVariant"
                        onclick="switchAddProductMode('variant')">Product Variant</button>
            </div>

            <!-- SINGLE PRODUCT -->
            <form method="POST" enctype="multipart/form-data" id="addMasterSingleForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_master_product">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" placeholder="e.g., Mineral Water 500ml" required>
                </div>
                <div class="form-group">
                    <label>Product Image</label>
                    <div class="dropzone" id="addMasterDropzone" data-dropzone tabindex="0" role="button"
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
                                <option value="<?php echo intval($cat['category_id']); ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price (RM) *</label>
                    <input type="number" name="price" step="0.01" min="0" placeholder="1.50" required>
                </div>
                <small class="form-hint" style="margin-bottom: 12px;">
                    This adds the product to the master catalog only. Branch staff add it to their outlet from the catalog.
                </small>
                <button type="submit" class="btn-submit">Save Product</button>
            </form>

            <!-- PRODUCT VARIANT -->
            <form method="POST" enctype="multipart/form-data" id="addMasterVariantForm" style="display: none;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_product_variant">
                <div class="form-group">
                    <label>Product Group *</label>
                    <select name="group_id" id="add_variant_group_id" onchange="toggleVariantGroupFields()">
                        <option value="0">+ Create a new group</option>
                        <?php foreach ($product_groups as $grp): ?>
                            <option value="<?php echo intval($grp['group_id']); ?>">
                                <?php echo htmlspecialchars($grp['group_name']); ?> (<?php echo intval($grp['variant_count']); ?> variant<?php echo intval($grp['variant_count']) === 1 ? '' : 's'; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="add_variant_new_group_fields">
                    <label>New Group Name *</label>
                    <input type="text" name="new_group_name" id="add_variant_new_group_name"
                           placeholder="e.g., Fanta">
                    <small class="form-hint">e.g., Fanta. Variants become "Fanta Orange", "Fanta Grape", etc.</small>
                </div>
                <div class="form-group" id="add_variant_new_group_category">
                    <label>Category for New Group *</label>
                    <select name="category_id" id="add_variant_category_id">
                        <?php if (empty($categories)): ?>
                            <option value="1">General</option>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo intval($cat['category_id']); ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <small class="form-hint">Ignored when an existing group is selected &mdash; the group's category is inherited.</small>
                </div>
                <div class="form-group">
                    <label>Variant / Flavour Name *</label>
                    <input type="text" name="variant_name" placeholder="e.g., Orange" required>
                </div>
                <div class="form-group">
                    <label>Variant Image</label>
                    <div class="dropzone" id="addVariantDropzone" data-dropzone tabindex="0" role="button"
                         aria-label="Upload variant image">
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="dropzone-preview">
                            <img alt="Variant image preview">
                            <span class="dropzone-hint">Drag &amp; drop an image here, or click to browse</span>
                        </div>
                    </div>
                    <small class="form-hint">JPG, PNG or WEBP &middot; max 5 MB</small>
                </div>
                <div class="form-group">
                    <label>Price (RM) *</label>
                    <input type="number" name="price" step="0.01" min="0" placeholder="2.50" required>
                </div>
                <small class="form-hint" style="margin-bottom: 12px;">
                    Each variant has its own price and image. Group name, category and image are managed via "Edit Group".
                </small>
                <button type="submit" class="btn-submit">Save Variant</button>
            </form>
        </div>
    </div>

    <!-- MODAL 4: EDIT MASTER PRODUCT -->
    <div class="modal-overlay" id="editMasterProductModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Edit Master Product</h2>
                <button class="close-modal" onclick="closeModal('editMasterProductModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editMasterProductForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit_master_product">
                <input type="hidden" name="product_id" id="edit_master_product_id">
                <div class="form-group">
                    <label>Product Group</label>
                    <select name="group_id" id="edit_master_group_id" onchange="toggleEditGroupFields()">
                        <option value="0">&mdash; Standalone product &mdash;</option>
                        <?php foreach ($product_groups as $grp): ?>
                            <option value="<?php echo intval($grp['group_id']); ?>">
                                <?php echo htmlspecialchars($grp['group_name']); ?> (<?php echo intval($grp['variant_count']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Choose a group to make this a variant, or standalone to detach it.</small>
                </div>
                <div class="form-group" id="edit_master_variant_field" style="display: none;">
                    <label>Variant / Flavour Name *</label>
                    <input type="text" name="variant_name" id="edit_master_variant_name" placeholder="e.g., Orange">
                    <small class="form-hint">The display name becomes "Group Name + Variant".</small>
                </div>
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" id="edit_master_product_name" required>
                </div>
                <div class="form-group">
                    <label>Product Image</label>
                    <div class="dropzone" id="editMasterDropzone" data-dropzone tabindex="0" role="button"
                         aria-label="Replace product image">
                        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="dropzone-preview">
                            <img alt="Product image preview">
                            <span class="dropzone-hint">Drag &amp; drop a new image to replace the current one</span>
                        </div>
                    </div>
                    <small class="form-hint">JPG, PNG or WEBP &middot; max 5 MB &middot; leave empty to keep the current photo</small>
                </div>
                <div class="form-group" id="edit_master_category_field">
                    <label>Category *</label>
                    <select name="category_id" id="edit_master_category_id" required>
                        <?php if (empty($categories)): ?>
                            <option value="1">General</option>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo intval($cat['category_id']); ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Price (RM) *</label>
                    <input type="number" name="price" id="edit_master_price" step="0.01" min="0" required>
                </div>
                <small class="form-hint" style="margin-bottom: 12px;">
                    Changes apply to every branch that carries this product. Grouped variants inherit the group's category.
                </small>
                <button type="submit" class="btn-submit">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- MODAL 5: EDIT PRODUCT GROUP -->
    <div class="modal-overlay" id="editProductGroupModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Edit Product Group</h2>
                <button class="close-modal" onclick="closeModal('editProductGroupModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editProductGroupForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="edit_product_group">
                <input type="hidden" name="group_id" id="edit_group_id">
                <div class="form-group">
                    <label>Group Name *</label>
                    <input type="text" name="group_name" id="edit_group_name" required>
                    <small class="form-hint">Renaming updates every variant's display name.</small>
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" id="edit_group_category_id" required>
                        <?php if (empty($categories)): ?>
                            <option value="1">General</option>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo intval($cat['category_id']); ?>">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Group Image</label>
                    <div class="dropzone" id="editGroupDropzone" data-dropzone tabindex="0" role="button"
                         aria-label="Replace group image">
                        <input type="file" name="group_image" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="dropzone-preview">
                            <img alt="Group image preview">
                            <span class="dropzone-hint">Drag &amp; drop a new image to replace the current one</span>
                        </div>
                    </div>
                    <small class="form-hint">JPG, PNG or WEBP &middot; max 5 MB &middot; leave empty to keep the current image</small>
                </div>
                <small class="form-hint" style="margin-bottom: 12px;">
                    Group image/category are shared; each variant keeps its own price and image.
                </small>
                <button type="submit" class="btn-submit">Save Group</button>
            </form>
        </div>
    </div>

    <!-- MODAL 6: PER-BRANCH STOCK BREAKDOWN -->
    <div class="modal-overlay" id="branchStockModal">
        <div class="modal-box modal-box-wide">
            <div class="modal-header">
                <h2 id="branchStockTitle">Branch Stock</h2>
                <button class="close-modal" onclick="closeModal('branchStockModal')">&times;</button>
            </div>
            <div id="branchStockBody"></div>
        </div>
    </div>

    <!-- MODAL 7: REGISTER NEW STAFF -->
    <div class="modal-overlay" id="addStaffModal">
        <div class="modal-box">
            <div class="modal-header">
                <h2>Register New Staff</h2>
                <button class="close-modal" onclick="closeModal('addStaffModal')">&times;</button>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
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

    <!-- MODAL 8: LOGOUT CONFIRMATION -->
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
        window.BRANCH_STOCK = <?php echo json_encode($inventory_by_product, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.PRODUCT_GROUPS = <?php echo json_encode($product_groups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="js/main.js"></script>
    <script src="js/upload.js"></script>
    <script src="js/dashboard.js"></script>
    <script src="js/admin.js"></script>
</body>
</html>