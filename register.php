<?php
// register.php

require_once 'includes/db_connect.php';

$error = "";
$success = "";

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username     = trim($_POST['username']);
    $fullname     = trim($_POST['fullname']);
    $email        = trim($_POST['email']);
    $phone_number = trim($_POST['phone_number']);
    $password     = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];
    
    // Default public registration role is 'Customer', and outlet_id is NULL
    $role      = 'Customer';
    $outlet_id = null;

    // Basic Validation
    if (empty($username) || empty($fullname) || empty($email) || empty($phone_number) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm_pass) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // 3. Check if Email already exists (email has a UNIQUE index in your SQL)
        $check_stmt = $conn->prepare("SELECT user_id FROM user WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $error = "An account with this email already exists.";
        } else {
            // 4. Hash the password securely (Never store plain text)
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // 5. Insert new user into the `user` table
            $insert_stmt = $conn->prepare(
                "INSERT INTO user (outlet_id, username, fullname, email, password, phone_number, role) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insert_stmt->bind_param(
                "issssss", 
                $outlet_id, 
                $username, 
                $fullname, 
                $email, 
                $hashed_password, 
                $phone_number, 
                $role
            );

            if ($insert_stmt->execute()) {
                $success = "Registration successful! You can now log in.";
            } else {
                error_log('Registration insert failed: ' . $insert_stmt->error);
                $error = "Registration failed. That username or email may already be in use.";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - e-Koperasi</title>
    <link rel="stylesheet" href="css/auth.css">
</head>
<body>

<div class="auth-container">
    <h2>Create an Account</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?><br>
            <a href="login.php">Click here to Login</a>
        </div>
    <?php endif; ?>

    <form action="register.php" method="POST">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" maxlength="100" required 
                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
        </div>

        <div class="form-group">
            <label for="fullname">Full Name</label>
            <input type="text" id="fullname" name="fullname" maxlength="150" required
                   value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>">
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" maxlength="150" required
                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
        </div>

        <div class="form-group">
            <label for="phone_number">Phone Number</label>
            <input type="tel" id="phone_number" name="phone_number" maxlength="20" placeholder="e.g., 0123456789" required
                   value="<?php echo isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : ''; ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>

        <button type="submit">Register</button>
    </form>

    <div class="auth-link">
        Already have an account? <a href="login.php">Login here</a>
    </div>
</div>

</body>
</html>