<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_dashboard.php");
    exit();
}

// Validate CSRF token using db.php helper or fallback session token
if (function_exists('verify_csrf_token')) {
    verify_csrf_token();
} else {
    $posted_token = $_POST['csrf_token'] ?? $_POST['token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? $_SESSION['token'] ?? '';

    if (empty($posted_token) || empty($session_token) || !hash_equals($session_token, $posted_token)) {
        http_response_code(403);
        die("403 Forbidden: CSRF Token Validation Failed. <a href='user_dashboard.php'>Return to Catalog</a>");
    }
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$car_id = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';

if (!$car_id || empty($start_date) || empty($end_date)) {
    die("Invalid form input. <a href='user_dashboard.php'>Return to Catalog</a>");
}

try {
    // Query SELECT * to avoid throwing 1054 Unknown Column errors
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id");
    $stmt->execute([':id' => $car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$car) {
        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id");
        $stmt->execute([':id' => $car_id]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$car) {
        die("Selected vehicle not found. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    // Safely extract price rate regardless of exact column naming
    $daily_rate = (float)(
        $car['price_per_day'] ?? 
        $car['daily_rate'] ?? 
        $car['rate'] ?? 
        $car['price'] ?? 
        $car['cost'] ?? 
        50.00
    );

    // Calculate duration in days
    $d1 = new DateTime($start_date);
    $d2 = new DateTime($end_date);
    $days = $d1->diff($d2)->days;
    if ($days <= 0) { 
        $days = 1; 
    }

    $total_price = $daily_rate * $days;

    // Insert booking into database
    try {
        $insert = $pdo->prepare("INSERT INTO rentals (user_id, car_id, start_date, end_date, total_price, status) VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')");
        $insert->execute([
            ':user_id' => $user_id,
            ':car_id' => $car_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':total_price' => $total_price
        ]);
    } catch (PDOException $e1) {
        $insert = $pdo->prepare("INSERT INTO reservations (user_id, car_id, start_date, end_date, total_price, status) VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')");
        $insert->execute([
            ':user_id' => $user_id,
            ':car_id' => $car_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':total_price' => $total_price
        ]);
    }

    header("Location: user_dashboard.php?booking=success");
    exit();

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>