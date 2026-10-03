<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';

// Handle Form Submissions (Login or Registration)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    // 1. REGISTRATION WORKFLOW (Creates Customer Users Only)
    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!empty($name) && !empty($email) && !empty($password)) {
            if ($password !== $confirm_password) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 8) {
                $error = "Password must be at least 8 characters long.";
            } else {
                try {
                    // Check if email already exists
                    $check_stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                    $check_stmt->execute([$email]);
                    
                    if ($check_stmt->fetch()) {
                        $error = "An account with this email already exists.";
                    } else {
                        // Secure BCrypt Hashing
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        
                        // Insert as 'customer' role strictly
                        $insert_stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'customer', NOW())");
                        $insert_stmt->execute([$name, $email, $hashed_password]);

                        $new_user_id = $pdo->lastInsertId();

                        // Auto-login newly registered customer
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $new_user_id;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['role'] = 'customer';

                        header("Location: user_dashboard.php");
                        echo "<script>window.location.href='user_dashboard.php';</script>";
                        exit();
                    }
                } catch (PDOException $e) {
                    $error = "Registration error: " . $e->getMessage();
                }
            }
        } else {
            $error = "Please fill in all registration fields.";
        }
    } 

    // 2. LOGIN WORKFLOW
    elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($email) && !empty($password)) {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE LOWER(email) = LOWER(?)");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    // Role-Based Redirect Routing
                    switch ($user['role']) {
                        case 'admin':
                            $target = "admin_dashboard.php";
                            break;
                        case 'fleet_manager':
                            $target = "fleet_dashboard.php";
                            break;
                        case 'branch_agent':
                            $target = "agent_dashboard.php";
                            break;
                        case 'customer':
                        default:
                            $target = "user_dashboard.php";
                            break;
                    }

                    header("Location: " . $target);
                    echo "<script>window.location.href='" . $target . "';</script>";
                    echo "<noscript><meta http-equiv='refresh' content='0;url=" . $target . "'></noscript>";
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please enter your email and password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Portal Login & Registration</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .auth-card { background: #fff; padding: 35px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 420px; }
        .auth-card h2 { margin-top: 0; color: #1a252f; text-align: center; font-size: 24px; margin-bottom: 20px; }
        
        /* Toggle Tabs */
        .tab-group { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; }
        .tab-btn { flex: 1; padding: 10px; text-align: center; font-weight: bold; cursor: pointer; color: #6c757d; border-bottom: 3px solid transparent; }
        .tab-btn.active { color: #007bff; border-bottom-color: #007bff; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #495057; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 5px; font-size: 14px; }
        .form-group input:focus { border-color: #007bff; outline: none; }
        
        .btn { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .btn:hover { background: #0056b3; }
        
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; text-align: center; font-weight: bold; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        
        .form-toggle { display: none; }
        .form-toggle.active { display: block; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>DriveEase Car Rental</h2>

    <div class="tab-group">
        <div class="tab-btn active" id="loginTab" onclick="switchTab('login')">Sign In</div>
        <div class="tab-btn" id="registerTab" onclick="switchTab('register')">Register</div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- LOGIN FORM -->
    <form id="loginForm" class="form-toggle active" action="index.php" method="POST">
        <input type="hidden" name="action" value="login">
        
        <div class="form-group">
            <label for="login_email">Email Address</label>
            <input type="email" id="login_email" name="email" required placeholder="name@example.com">
        </div>

        <div class="form-group">
            <label for="login_password">Password</label>
            <input type="password" id="login_password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn">Sign In</button>
    </form>

    <!-- CUSTOMER REGISTRATION FORM -->
    <form id="registerForm" class="form-toggle" action="index.php" method="POST">
        <input type="hidden" name="action" value="register">

        <div class="form-group">
            <label for="reg_name">Full Name</label>
            <input type="text" id="reg_name" name="name" required placeholder="John Doe">
        </div>

        <div class="form-group">
            <label for="reg_email">Email Address</label>
            <input type="email" id="reg_email" name="email" required placeholder="john.doe@example.com">
        </div>

        <div class="form-group">
            <label for="reg_password">Password</label>
            <input type="password" id="reg_password" name="password" required placeholder="Min 8 characters">
        </div>

        <div class="form-group">
            <label for="reg_confirm">Confirm Password</label>
            <input type="password" id="reg_confirm" name="confirm_password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn" style="background:#28a745;">Create Customer Account</button>
    </form>
</div>

<script>
function switchTab(type) {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const loginTab = document.getElementById('loginTab');
    const registerTab = document.getElementById('registerTab');

    if (type === 'register') {
        loginForm.classList.remove('active');
        registerForm.classList.add('active');
        loginTab.classList.remove('active');
        registerTab.classList.add('active');
    } else {
        registerForm.classList.remove('active');
        loginForm.classList.add('active');
        registerTab.classList.remove('active');
        loginTab.classList.add('active');
    }
}

// Automatically open register tab if registration failed
<?php if (isset($_POST['action']) && $_POST['action'] === 'register' && !empty($error)): ?>
switchTab('register');
<?php endif; ?>
</script>

</body>
</html>