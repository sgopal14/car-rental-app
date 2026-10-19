<?php
// 1. Security Headers & CSP
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'none';");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// 2. Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Database Connection
$host    = getenv('DB_HOST') ?: 'localhost'; 
$db      = getenv('DB_NAME') ?: 'car_rental';
$user    = getenv('DB_USER') ?: 'dbadmin';
$pass    = getenv('DB_PASS') ?: ''; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

if (!function_exists('log_audit_event')) {
    function log_audit_event($pdo, $event, $user_email = null, $details = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (event, user_email, details) VALUES (?, ?, ?)");
            $stmt->execute([$event, $user_email, $details]);
        } catch (PDOException $e) {
            // Fallback to Apache error log if the database write fails
            error_log("Audit Log Failure: " . $e->getMessage());
        }
    }
}
// 4. Unified Anti-CSRF Token Helpers
if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        return get_csrf_token();
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $submitted_token = $_POST['csrf_token'] ?? $_POST['token'] ?? '';
            $session_token = $_SESSION['csrf_token'] ?? '';

            if (empty($submitted_token) || !hash_equals($session_token, $submitted_token)) {
                http_response_code(403);
                die("<h2 style='color:red; text-align:center;'>403 Forbidden: CSRF Token Validation Failed</h2>");
            }
        }
    }
}

// 5. Centralized Email 2FA Helper
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('send_2fa_email')) {
    function send_2fa_email($recipient_email, $recipient_name, $code) {
        $smtp_host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $smtp_user = getenv('SMTP_USER') ?: '';
        $smtp_pass = getenv('SMTP_PASS') ?: '';

        $master_testing_email = $smtp_user ?: $recipient_email;

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $smtp_host; 
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('no-reply@driveease.com', 'DriveEase Security');
            $mail->addAddress($master_testing_email, $recipient_name);

            $mail->isHTML(true);
            $mail->Subject = "2FA Code for {$recipient_name} ({$recipient_email})";
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f4f6f9;'>
                    <div style='background-color: #ffffff; padding: 20px; border-radius: 8px; max-width: 480px; margin: auto;'>
                        <h2 style='color: #007bff; text-align: center;'>DriveEase Security Verification</h2>
                        <p>Login attempt for account: <strong>" . htmlspecialchars($recipient_email) . "</strong> (" . htmlspecialchars($recipient_name) . ")</p>
                        <p>Your one-time 2FA login verification code is:</p>
                        <h1 style='background-color: #e9ecef; padding: 12px; text-align: center; letter-spacing: 6px; color: #1a252f; border-radius: 5px;'>" . $code . "</h1>
                        <p style='color: #6c757d; font-size: 13px;'>This code is valid for <strong>10 minutes</strong>.</p>
                    </div>
                </div>";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return false;
        }
    }
}
?>