<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['2fa_pending_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userCode = trim($_POST['otp_code'] ?? '');

    $stmt = $pdo->prepare('SELECT email_otp, email_otp_expires_at FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['2fa_pending_id']]);
    $user = $stmt->fetch();

    if ($user) {
        $currentTime = date('Y-m-d H:i:s');

        if ($user['email_otp_expires_at'] < $currentTime) {
            $error = 'The verification code has expired. Please log in again.';
        } elseif ($user['email_otp'] === $userCode) {
            // Code matches & is valid — clear used OTP
            $clearStmt = $pdo->prepare('UPDATE users SET email_otp = NULL, email_otp_expires_at = NULL WHERE id = :id');
            $clearStmt->execute(['id' => $_SESSION['2fa_pending_id']]);

            // Establish full active user session
            $_SESSION['user_id'] = $_SESSION['2fa_pending_id'];
            $_SESSION['user_email'] = $_SESSION['2fa_pending_email'];
            $_SESSION['user_role'] = $_SESSION['2fa_pending_role'];

            unset($_SESSION['2fa_pending_id'], $_SESSION['2fa_pending_email'], $_SESSION['2fa_pending_role']);

            header('Location: ' . ($_SESSION['user_role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php'));
            exit;
        } else {
            $error = 'Invalid code. Please check your inbox and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email 2FA Verification - DriveEase</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 360px; text-align: center; }
        .form-group { margin: 20px 0; }
        input[type="text"] { width: 100%; padding: 12px; font-size: 20px; text-align: center; letter-spacing: 4px; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; background: #0066cc; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .error { background: #ffe6e6; color: #d9534f; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>
<div class="card">
    <h2>Email Verification</h2>
    <p>We've sent a 6-digit code to <strong><?= htmlspecialchars($_SESSION['2fa_pending_email']) ?></strong></p>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form action="verify_email_2fa.php" method="POST">
        <div class="form-group">
            <input type="text" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="000000" required autofocus autocomplete="off">
        </div>
        <button type="submit" class="btn">Verify Code</button>
    </form>
</div>
</body>
</html>