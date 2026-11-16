<?php

// login.php
require_once 'db_connect.php';

$error = "";
n 
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
                    header("Location: staff_dashboard.php");
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
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            margin-top: 0;
            text-align: center;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            border: none;
            border-radius: 4px;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .register-link {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="login-container">
    <h2>Login to e-Koperasi</h2>

    <?php if (!empty($error)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
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

    <div class="register-link">
        Don't have an account? <a href="register.php">Register here</a>
    </div>
</div>

</body>
</html>
