<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Server-side validation
    if (empty($email) || empty($password)) {
        $error = 'Both email and password fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Retrieve account credentials and role
        $stmt = $pdo->prepare('SELECT id, email, password, role FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            /* --- TEMPORARY DEV BYPASS (START) --- */
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

    header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php'));
    exit;
    /* --- TEMPORARY DEV BYPASS (END) --- */

    // ... (leave the PHPMailer & OTP logic below commented out for now)
            
            // Generate 6-digit Email OTP (Valid for 10 minutes)
            //$otp = random_int(100000, 999999);
            //$expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Store OTP code in the database
            //$updateStmt = $pdo->prepare('UPDATE users SET email_otp = :otp, email_otp_expires_at = :expires WHERE id = :id');
            //$updateStmt->execute([
            //    'otp' => $otp,
            //    'expires' => $expiresAt,
            //    'id' => $user['id']
            //]);

            // Send OTP email using PHPMailer via mailer.php
            //$emailSent = sendOTPCode($user['email'], $otp);

            //if ($emailSent) {
                // Hold details in temporary session until 2FA code is entered
            //    $_SESSION['2fa_pending_id'] = $user['id'];
             //   $_SESSION['2fa_pending_email'] = $user['email'];
             //   $_SESSION['2fa_pending_role'] = $user['role'];

               // header('Location: verify_email_2fa.php');
                //exit;
            //} else {
            //    $error = 'Failed to send 2FA verification code. Please try again.';
           // }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Car Rental Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-card { background: #ffffff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-card h2 { text-align: center; margin-bottom: 8px; color: #333; }
        .login-card p { text-align: center; margin-bottom: 24px; color: #666; font-size: 14px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #333; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; outline: none; }
        .form-group input:focus { border-color: #0066cc; box-shadow: 0 0 4px rgba(0,102,204,0.3); }
        .btn { width: 100%; padding: 12px; background-color: #0066cc; color: #fff; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .btn:hover { background-color: #0052a3; }
        .msg { padding: 10px; border-radius: 4px; font-size: 14px; margin-bottom: 16px; text-align: center; }
        .error { background-color: #ffe6e6; color: #d9534f; }
        .footer-link { text-align: center; margin-top: 20px; font-size: 14px; color: #666; }
        .footer-link a { color: #0066cc; text-decoration: none; font-weight: bold; }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>DriveEase Rentals</h2>
    <p>Sign in to manage your account</p>

    <div id="jsError" class="msg error" style="display: none;"></div>

    <?php if (!empty($error)): ?>
        <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form action="index.php" method="POST" id="loginForm" novalidate>
        <div class="form-group">
            <label for="email">Email Address</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                value="<?= htmlspecialchars($email) ?>" 
                placeholder="name@example.com"
                required
            >
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                placeholder="Enter password"
                required
            >
        </div>
        <button type="submit" class="btn">Sign In</button>
    </form>

    <div class="footer-link">
        Don't have an account? <a href="register.php">Register here</a>
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const errorBox = document.getElementById('jsError');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    let errorMessage = '';

    if (!emailRegex.test(emailInput.value.trim())) {
        errorMessage = 'Please enter a valid email address.';
    } else if (passwordInput.value === '') {
        errorMessage = 'Password cannot be empty.';
    }

    if (errorMessage !== '') {
        e.preventDefault();
        errorBox.textContent = errorMessage;
        errorBox.style.display = 'block';
    } else {
        errorBox.style.display = 'none';
    }
});
</script>

</body>
</html>