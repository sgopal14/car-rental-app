PHP

<?php
// 1. Content Security Policy (CSP) & Security Headers
// Resolves: Content Security Policy (CSP) Header Not Set
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'none';");

// Additional essential security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// 2. Session Management & Database Connection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'driveease-db-driveease-stack.c50gqqyuw2dp.ap-southeast-1.rds.amazonaws.com'; // Replace with your actual RDS Endpoint
$db   = 'car_rental';
$user = 'dbadmin';
$pass = 'CUSecure2026'; // Replace with your actual RDS Password
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

// 3. Anti-CSRF Token Helpers
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted_token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';

        if (empty($submitted_token) || !hash_equals($session_token, $submitted_token)) {
            http_response_code(403);
            die("<h2 style='color:red; text-align:center;'>403 Forbidden: CSRF Token Validation Failed</h2>");
        }
    }
}
?>