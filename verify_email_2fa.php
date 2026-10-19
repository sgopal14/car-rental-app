<?php
session_start();
require_once 'db.php';

$error_msg = '';
$success_msg = '';

// Require a pending 2FA login state
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header("Location: login.php");
    exit();
}

$pending_user_id = $_SESSION['pending_2fa_user_id'];

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_code = trim($_POST['otp_code'] ?? $_POST['2fa_code'] ?? '');

    if (empty($entered_code)) {
        $error_msg = "Please enter the 6-digit verification code sent to your email.";
    } else {
        try {
            // Fetch user record and 2FA secrets
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $pending_user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                unset($_SESSION['pending_2fa_user_id']);
                header("Location: login.php");
                exit();
            }

            // Extract OTP details from schema
            $stored_otp = $user['two_factor_code'] ?? $user['otp_code'] ?? $user['2fa_code'] ?? '';
            $otp_expiry = $user['two_factor_expires_at'] ?? $user['otp_expires_at'] ?? $user['2fa_expires'] ?? null;

            $is_valid = false;

            // Check timing window if expiration is stored
            if ($otp_expiry && strtotime($otp_expiry) < time()) {
                $error_msg = "Verification code has expired. Please log in again to generate a new code.";
            } elseif (!empty($stored_otp) && hash_equals((string)$stored_otp, (string)$entered_code)) {
                $is_valid = true;
            } else {
                $error_msg = "Invalid verification code. Please check your email and try again.";
            }

            if ($is_valid) {
                // Clear 2FA pending state
                unset($_SESSION['pending_2fa_user_id']);

                // Reset OTP code in database to prevent reuse
                try {
                    $clear_stmt = $pdo->prepare("UPDATE users SET two_factor_code = NULL, two_factor_expires_at = NULL WHERE id = :id");
                    $clear_stmt->execute([':id' => $user['id']]);
                } catch (PDOException $e) {
                    // Ignore schema column name mismatches when clearing OTP
                }

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Populate authenticated session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['username'] ?? $user['name'] ?? 'User';
                
                // Extract lowercased role with default fallback
                $role = strtolower(trim($user['role'] ?? $user['user_type'] ?? 'customer'));
                $_SESSION['role'] = $role;

                // Dynamic Role Redirection to Agent or Customer Dashboard
                if (in_array($role, ['agent', 'admin', 'staff'])) {
                    header("Location: agent_dashboard.php");
                    exit();
                } else {
                    header("Location: user_dashboard.php");
                    exit();
                }
            }

        } catch (PDOException $e) {
            $error_msg = "Database Error: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Generate CSRF token for form
$csrf_token = function_exists('generate_csrf_token') 
    ? generate_csrf_token() 
    : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Two-Factor Authentication</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .auth-card { background: #fff; padding: 35px; border-radius: 10px; width: 100%; max-width: 420px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-top: 5px solid #28a745; }
        .auth-card h2 { color: #1a252f; margin-bottom: 10px; text-align: center; }
        .auth-card p { color: #6c757d; font-size: 14px; text-align: center; margin-bottom: 25px; line-height: 1.5; }
        .alert-error { background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #f5c6cb; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 14px; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 18px; text-align: center; letter-spacing: 4px; }
        .btn-submit { background-color: #28a745; color: white; border: none; padding: 12px; width: 100%; font-size: 16px; font-weight: bold; border-radius: 5px; cursor: pointer; }
        .btn-submit:hover { background-color: #218838; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #007bff; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Email Verification</h2>
    <p>Please enter the 6-digit security code sent to your registered email address to complete sign in.</p>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <form action="verify_email_2fa.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="form-group">
            <label for="otp_code">Security Verification Code</label>
            <input type="text" id="otp_code" name="otp_code" required maxlength="6" autocomplete="off" placeholder="123456" autofocus>
        </div>

        <button type="submit" class="btn-submit">Verify & Sign In</button>
    </form>

    <a href="login.php" class="back-link">&larr; Return to Login</a>
</div>

</body>
</html>