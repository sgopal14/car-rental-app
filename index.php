<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. REGISTRATION WORKFLOW
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
                    $check_stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                    $check_stmt->execute([$email]);
                    
                    if ($check_stmt->fetch()) {
                        $error = "An account with this email already exists.";
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $insert_stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'customer', NOW())");
                        $insert_stmt->execute([$name, $email, $hashed_password]);

                        $new_user_id = $pdo->lastInsertId();

                        log_audit_event($pdo, 'USER_REGISTERED', $email, 'New customer account created');

                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $new_user_id;
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['role'] = 'customer';
                        $_SESSION['user_role'] = 'customer';

                        header("Location: user_dashboard.php");
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

    // 2. INITIAL LOGIN STEP
    elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($email) && !empty($password)) {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    $code = sprintf("%06d", mt_rand(1, 999999));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    $update_stmt = $pdo->prepare("UPDATE users SET two_factor_code = ?, two_factor_expires_at = ? WHERE id = ?");
                    $update_stmt->execute([$code, $expires_at, $user['id']]);

                    $user_display_name = $user['name'] ?? $user['email'] ?? 'User';

                    if (send_2fa_email($user['email'], $user_display_name, $code)) {
                        $_SESSION['pending_2fa_user_id'] = $user['id'];
                        header("Location: verify_email_2fa.php");
                        exit();
                    } else {
                        $error = "Failed to send 2FA verification email. Please check server logs.";
                    }
                } else {
                    log_audit_event($pdo, 'LOGIN_FAILED', $email, 'Invalid password attempt or email not found');
                    $error = "Invalid email address or password.";
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
    <title>DriveEase - Secure Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .auth-card { background: #fff; padding: 35px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 420px; border-top: 5px solid #007bff; }
        .auth-card h2 { margin-top: 0; color: #1a252f; text-align: center; font-size: 24px; margin-bottom: 20px; }
        .tab-group { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; }
        .tab-btn { flex: 1; padding: 10px; text-align: center; font-weight: bold; cursor: pointer; color: #6c757d; border-bottom: 3px solid transparent; }
        .tab-btn.active { color: #007bff; border-bottom-color: #007bff; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #495057; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 5px; font-size: 14px; }
        .btn { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; text-align: center; font-weight: bold; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-toggle { display: none; }
        .form-toggle.active { display: block; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>DriveEase Portal</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="tab-group">
        <div class="tab-btn active" id="loginTab" onclick="switchTab('login')">Sign In</div>
        <div class="tab-btn" id="registerTab" onclick="switchTab('register')">Register</div>
    </div>

    <form id="loginForm" class="form-toggle active" action="index.php" method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
        
        <div class="form-group">
            <label for="login_email">Email Address</label>
            <input type="email" id="login_email" name="email" required placeholder="name@example.com" autofocus>
        </div>

        <div class="form-group">
            <label for="login_password">Password</label>
            <input type="password" id="login_password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn">Sign In</button>
    </form>

    <form id="registerForm" class="form-toggle" action="index.php" method="POST">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">

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

    if (type === 'login') {
        loginForm.classList.add('active');
        registerForm.classList.remove('active');
        loginTab.classList.add('active');
        registerTab.classList.remove('active');
    } else {
        registerForm.classList.add('active');
        loginForm.classList.remove('active');
        registerTab.classList.add('active');
        loginTab.classList.remove('active');
    }
}
</script>
</body>
</html>