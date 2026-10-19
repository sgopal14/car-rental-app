<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Enforce CSRF token validation on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
}

$error = '';$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    // 1. REGISTRATION WORKFLOW
    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password =$_POST['password'] ?? '';
        $confirm_password =$_POST['confirm_password'] ?? '';

        if (!empty($name) && !empty($email) && !empty($password)) {
            if ($password !== $confirm_password) {$error = "Passwords do not match.";
            } elseif (strlen($password) < 8) {$error = "Password must be at least 8 characters long.";
            } else {
                try {
                    $check_stmt =$pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                    $check_stmt->execute([$email]);
                    
                    if ($check_stmt->fetch()) {$error = "An account with this email already exists.";
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $insert_stmt =$pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, 'customer', NOW())");
                        $insert_stmt->execute([$name, $email,$hashed_password]);

                        $new_user_id =$pdo->lastInsertId();

                        session_regenerate_id(true);
                        $_SESSION['user_id'] =$new_user_id;
                        $_SESSION['user_name'] =$name;
                        $_SESSION['user_email'] =$email;
                        $_SESSION['role'] = 'customer';$_SESSION['user_role'] = 'customer';

                        header("Location: user_dashboard.php");
                        exit();
                    }
                } catch (PDOException $e) {$error = "Registration error: " . $e->getMessage();
                }
            }
        } else {
            $error = "Please fill in all registration fields.";
        }
    } 

    // 2. INITIAL LOGIN STEP (PASSWORD CHECK & TRIGGER 2FA)
    elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password =$_POST['password'] ?? '';

        if (!empty($email) && !empty($password)) {
            try {
                // Querying name, email, password, role without hardcoded username column
                $stmt =$pdo->prepare("SELECT id, name, email, password, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$email]);
                $user =$stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password,$user['password'])) {
                    // Generate random 6-digit code valid for 10 minutes
                    $code = sprintf("%06d", mt_rand(1, 999999));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    // Save code and timestamp in RDS
                    $update_stmt =$pdo->prepare("UPDATE users SET two_factor_code = ?, two_factor_expires_at = ? WHERE id = ?");
                    $update_stmt->execute([$code, $expires_at,$user['id']]);

                    $user_display_name = $user['name'] ?? $user['email'] ?? 'User';

                    if (send_2fa_email($user['email'], $user_display_name,$code)) {
                        $_SESSION['pending_2fa_user_id'] =$user['id'];
                        header("Location: verify_email_2fa.php");
                        exit();
                    } else {
                        $error = "Failed to send 2FA verification email. Please check server logs.";
                    }
                } else {
                    $error = "Invalid email address or password.";
                }
            } catch (PDOException $e) {