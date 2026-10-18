<?php
session_start();
require_once 'db.php';

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_dashboard.php");
    exit();
}

if (function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Authenticate session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$car_id = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';

if (!$car_id || empty($start_date) || empty($end_date)) {
    die("Invalid form submission. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// Calculate total rental cost
try {
    // 1. Fetch daily rate
    $stmt = $pdo->prepare("SELECT daily_rate, price, price_per_day FROM cars WHERE id = :id");
    $stmt->execute([':id' => $car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$car) {
        $stmt = $pdo->prepare("SELECT daily_rate, price, price_per_day FROM vehicles WHERE id = :id");
        $stmt->execute([':id' => $car_id]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$car) {
        die("Vehicle not found. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    $daily_rate = (float)($car['daily_rate'] ?? $car['price_per_day'] ?? $car['price'] ?? 0.00);

    // 2. Calculate duration in days
    $d1 = new DateTime($start_date);
    $d2 = new DateTime($end_date);
    $days = $d1->diff($d2)->days;
    if ($days <= 0) {
        $days = 1;
    }

    $total_price = $daily_rate * $days;

    // 3. Insert reservation record into database
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
        // Fallback for 'reservations' or 'bookings' table
        $insert = $pdo->prepare("INSERT INTO reservations (user_id, car_id, start_date, end_date, total_price, status) VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')");
        $insert->execute([
            ':user_id' => $user_id,
            ':car_id' => $car_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':total_price' => $total_price
        ]);
    }

    // Redirect user to dashboard with success parameter
    header("Location: user_dashboard.php?booking=success");
    exit();

} catch (PDOException $e) {
    die("Database Error processing rental: " . htmlspecialchars($e->getMessage()));
}
?>