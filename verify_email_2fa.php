<?php
session_start();
require_once 'db.php';

// Enforce CSRF token verification on all POST submissions
verify_csrf_token();

// Ensure the user has completed Step 1 (Password Authentication)
if (!isset($_SESSION['pending_2fa_user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['pending_2fa_user_id'] ?? 0;
    $input_code = trim($_POST['two_factor_code'] ?? '');

    if ($user_id > 0 && !empty($input_code)) {
        // Query temporary 2FA token details from RDS
        $stmt = $pdo->prepare("SELECT id, name, email, role, two_factor_code, two_factor_expires_at FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user && $user['two_factor_code'] === $input_code) {
            if (strtotime($user['two_factor_expires_at']) >= time()) {
                // Success: Clear temporary 2FA code and expiration from RDS
                $clear_stmt = $pdo->prepare("UPDATE users SET two_factor_code = NULL, two_factor_expires_at = NULL WHERE id = ?");
                $clear_stmt->execute([$user['id']]);

                // Regenerate Session ID to prevent Session Fixation attacks
                session_regenerate_id(true);
                unset($_SESSION['pending_2fa_user_id']);

                // Initialize fully authenticated session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                // Route user to their designated dashboard role
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
                exit();
            } else {
                $error = "Verification code has expired. Please sign in again.";
            }
        } else {
            $error = "Invalid 6-digit verification code. Please check your email.";
        }
    } else {
        $error = "Please enter the 6-digit verification code.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - 2FA Security Verification</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #f4f6f9; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            padding: 20px; 
        }
        .auth-card { 
            background: #fff; 
            padding: 35px; 
            border-radius: 10px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
            width: 100%; 
            max-width: 420px; 
        }
        .auth-card h2 { 
            margin-top: 0; 
            color: #1a252f; 
            text-align: center; 
            font-size: 24px; 
            margin-bottom: 20px; 
        }
        .form-group { margin-bottom: 20px; }
        .form-group input { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #ced4da; 
            border-radius: 5px; 
            font-size: 22px; 
            text-align: center; 
            letter-spacing: 6px; 
            font-weight: bold; 
        }
        .btn { 
            width: 100%; 
            padding: 12px; 
            background: #28a745; 
            color: white; 
            border: none; 
            border-radius: 5px; 
            font-size: 16px; 
            font-weight: bold; 
            cursor: pointer; 
        }
        .btn:hover { background: #218838; }
        .alert { 
            padding: 12px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            font-size: 14px; 
            text-align: center; 
            font-weight: bold; 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb; 
        }
        .cancel-link { 
            display: block; 
            text-align: center; 
            margin-top: 15px; 
            color: #6c757d; 
            text-decoration: none; 
            font-size: 14px; 
        }
        .cancel-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Two-Factor Security</h2>

    <?php if (!empty($error)): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="verify_email_2fa.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
        
        <div style="text-align:center; margin-bottom:20px;">
            <h3 style="margin-bottom:8px; color:#333;">Email Verification</h3>
            <p style="color:#6c757d; font-size:13px; margin:0;">Enter the 6-digit security code sent to your registered email address.</p>
        </div>

        <div class="form-group">
            <input type="text" name="two_factor_code" maxlength="6" required placeholder="123456" autofocus autocomplete="off">
        </div>

        <button type="submit" class="btn">Verify & Complete Sign In</button>
    </form>

    <a href="index.php" class="cancel-link">&larr; Cancel and return to Login</a>
</div>

</body>
</html>