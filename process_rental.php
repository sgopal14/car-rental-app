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
// 1. SERVER-SIDE DATE & BUSINESS LOGIC VALIDATION
// -------------------------------------------------------------

// Syntax & Strict Date Format Validation
$d1 = DateTime::createFromFormat('Y-m-d', $start_date_raw);
$d2 = DateTime::createFromFormat('Y-m-d', $end_date_raw);

if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $start_date_raw || $d2->format('Y-m-d') !== $end_date_raw) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Invalid date format submitted: {$start_date_raw} / {$end_date_raw}");
    die("Error: Invalid date format provided. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// Strip time component for accurate date comparison
$today = new DateTime('today');

// Timeline Boundary Check (Start date cannot be in the past)
if ($d1 < $today) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Attempted past start date: {$start_date_raw}");
    die("Error: Start date cannot be in the past. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// Chronological Ordering Check (End date must be >= Start date)
if ($d2 < $d1) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Reversed date range submitted: {$start_date_raw} to {$end_date_raw}");
    die("Error: End date must be on or after the start date. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// Calculate Duration & Enforce Booking Window Cap
$interval = $d1->diff($d2);
$days = $interval->days;

if ($days === 0) {
    $days = 1; // Same-day rental counts as 1 day
}

$max_allowed_days = 30;
if ($days > $max_allowed_days) {
    log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Exceeded maximum rental duration: {$days} days");
    die("Error: Rental duration cannot exceed {$max_allowed_days} days per transaction. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// -------------------------------------------------------------
// 2. DYNAMIC COLUMN DETECTION & TEMPORAL OVERLAP VERIFICATION
// -------------------------------------------------------------
try {
    // Dynamically inspect 'cars' table columns to detect rate column variant safely
    $car_cols = $pdo->query("SHOW COLUMNS FROM cars")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('price_per_day', $car_cols, true)) {
        $price_expression = 'price_per_day';
    } elseif (in_array('daily_rate', $car_cols, true)) {
        $price_expression = 'daily_rate';
    } elseif (in_array('price', $car_cols, true)) {
        $price_expression = 'price';
    } else {
        $price_expression = '50.00'; // Default safety fallback
    }

    // Fetch vehicle details using detected price expression
    $maint_check = $pdo->prepare("SELECT status, {$price_expression} AS calculated_rate FROM cars WHERE id = ?");
    $maint_check->execute([$car_id]);
    $car = $maint_check->fetch(PDO::FETCH_ASSOC);

    if (!$car || strtolower($car['status'] ?? '') === 'maintenance') {
        die("Error: Selected vehicle is currently out of service or in maintenance. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    // Check if the car is already reserved during ANY portion of the requested window
    $overlap_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM rentals 
        WHERE car_id = :car_id 
          AND status IN ('confirmed', 'active')
          AND NOT (end_date < :start_date OR start_date > :end_date)
    ");

    $overlap_stmt->execute([
        ':car_id'     => $car_id,
        ':start_date' => $start_date_raw,
        ':end_date'   => $end_date_raw
    ]);

    if ($overlap_stmt->fetchColumn() > 0) {
        log_audit_event($pdo, 'RENTAL_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Overlapping reservation attempt for car #{$car_id} ({$start_date_raw} to {$end_date_raw})");
        die("Error: This vehicle is already reserved for all or part of your selected dates. Please select a different date range or vehicle. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    $daily_rate = (float)($car['calculated_rate'] ?? 50.00);
    $total_price = $daily_rate * $days;
    $is_starting_today = ($d1->format('Y-m-d') === $today->format('Y-m-d'));

    // -------------------------------------------------------------
    // 3. TRANSACTION PERSISTENCE
    // -------------------------------------------------------------
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

    // ONLY update car status to 'rented' if the rental starts TODAY
    if ($is_starting_today) {
        $update_car = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
        $update_car->execute([$car_id]);
    }

    $pdo->commit();

    log_audit_event($pdo, 'RENTAL_RESERVED', $_SESSION['user_name'] ?? "User #{$user_id}", "Reserved car #{$car_id} for {$days} days ({$start_date_raw} to {$end_date_raw}) - Total: \${$total_price}");

    header("Location: user_dashboard.php?booking=success");
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { 
        $pdo->rollBack(); 
    }
    log_audit_event($pdo, 'RENTAL_ERROR', $_SESSION['user_name'] ?? "User #{$user_id}", "Database transaction failed: " . $e->getMessage());
    die("Reservation Failed! Database Error: " . htmlspecialchars($e->getMessage()));
}
?>