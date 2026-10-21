<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Verify POST request and user session
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_dashboard.php");
    exit();
}

verify_csrf_token();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id        = $_SESSION['user_id'];
$car_id         = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
$start_date_raw = trim($_POST['start_date'] ?? '');
$end_date_raw   = trim($_POST['end_date'] ?? '');

if (!$car_id || empty($start_date_raw) || empty($end_date_raw)) {
    die("Invalid form input. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// -------------------------------------------------------------
// SERVER-SIDE DATE & BUSINESS LOGIC VALIDATION
// -------------------------------------------------------------

// 1. Syntax & Strict Date Format Validation
$d1 = DateTime::createFromFormat('Y-m-d', $start_date_raw);
$d2 = DateTime::createFromFormat('Y-m-d', $end_date_raw);

if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $start_date_raw || $d2->format('Y-m-d') !== $end_date_raw) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Invalid date format submitted: {$start_date_raw} / {$end_date_raw}");
    die("Error: Invalid date format provided. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// Strip time component for accurate date comparison
$today = new DateTime('today');

// 2. Timeline Boundary Check (Start date cannot be in the past)
if ($d1 < $today) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Attempted past start date: {$start_date_raw}");
    die("Error: Start date cannot be in the past. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// 3. Chronological Ordering Check (End date must be >= Start date)
if ($d2 < $d1) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Reversed date range submitted: {$start_date_raw} to {$end_date_raw}");
    die("Error: End date must be on or after the start date. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// 4. Calculate Duration & Enforce Booking Window Cap
$interval = $d1->diff($d2);
$days = $interval->days;

if ($days === 0) {
    $days = 1; // Same-day rental count as 1 day
}

$max_allowed_days = 30;
if ($days > $max_allowed_days) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Exceeded maximum rental duration: {$days} days");
    die("Error: Rental duration cannot exceed {$max_allowed_days} days per transaction. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// -------------------------------------------------------------
// DATABASE TRANSACTION & PERSISTENCE
// -------------------------------------------------------------
try {
    // Verify car availability and retrieve authoritative price rate from DB
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id AND (LOWER(status) = 'available' OR status IS NULL)");
    $stmt->execute([':id' => $car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$car) {
        die("Selected vehicle is not currently available. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    $daily_rate = (float)(
        $car['price_per_day'] ?? 
        $car['daily_rate'] ?? 
        $car['price'] ?? 
        50.00
    );

    // Compute total price server-side
    $total_price = $daily_rate * $days;

    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO rentals (user_id, car_id, start_date, end_date, total_price, status) 
        VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')
    ");
    
    $insert->execute([
        ':user_id'     => $user_id,
        ':car_id'      => $car_id,
        ':start_date'  => $start_date_raw,
        ':end_date'    => $end_date_raw,
        ':total_price' => $total_price
    ]);

    // Update car status to rented
    $update_car = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
    $update_car->execute([$car_id]);

    $pdo->commit();

    log_audit_event($pdo, 'RENTAL_RESERVED', $_SESSION['user_name'] ?? "User #{$user_id}", "Reserved car #{$car_id} for {$days} days ({$start_date_raw} to {$end_date_raw}) - Total: \${$total_price}");

    header("Location: user_dashboard.php?booking=success");
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    log_audit_event($pdo, 'RENTAL_ERROR', $_SESSION['user_name'] ?? "User #{$user_id}", "Database transaction failed: " . $e->getMessage());
    die("Reservation Failed! Database Error: " . htmlspecialchars($e->getMessage()));
}
?>