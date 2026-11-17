<?php

// login.php
require_once 'includes/db_connect.php';

$error = "";

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $login_id = trim($_POST['login_id']); // Accepts either email or username
    $password = $_POST['password'];

    if (empty($login_id) || empty($password)) {
        $error = "Please enter both your email/username and password.";
    } else {
        // 3. Query the `user` table using prepared statements
        $stmt = $conn->prepare("SELECT user_id, outlet_id, username, fullname, email, password, role FROM user WHERE email = ? OR username = ? LIMIT 1");
        $stmt->bind_param("ss", $login_id, $login_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // 4. Verify the hashed password
            if (password_verify($password, $user['password'])) {
                // Regenerate session ID to prevent session fixation attacks
                session_regenerate_id(true);

                // Store user session variables
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['outlet_id'] = $user['outlet_id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['fullname']  = $user['fullname'];
                $_SESSION['role']      = $user['role'];

                // 5. Role-based redirection
                if ($user['role'] === 'Admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($user['role'] === 'Staff') {
                    header("Location: outlet_dashboard.php");
                } else {
                    // $message = "Welcome to my site!";
                    // echo "<script>alert('$message');</script>";
                    header("Location: index.php"); // Customer default
                }
                exit();
            } else {
                // Generic error message prevents user enumeration
                $error = "Invalid email/username or password.";
            }
        } else {
            $error = "Invalid email/username or password.";
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - e-Koperasi</title>
    <link rel="stylesheet" href="css/auth.css">
</head>
<body>

<div class="auth-container">
    <h2>Login to e-Koperasi</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="form-group">
            <label for="login_id">Email or Username</label>
            <input type="text" id="login_id" name="login_id" required 
                   value="<?php echo isset($_POST['login_id']) ? htmlspecialchars($_POST['login_id']) : ''; ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit">Login</button>
    </form>

    <div class="auth-link">
        Don't have an account? <a href="register.php">Register here</a>
    </div>
</div>

</body>
</html>
