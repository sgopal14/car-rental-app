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

$user_id = $_SESSION['user_id'];
$car_id  = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
$days    = filter_input(INPUT_POST, 'days', FILTER_VALIDATE_INT);

// -------------------------------------------------------------
// SERVER-SIDE INPUT & BILLING SANITIZATION
// -------------------------------------------------------------

// 1. Validate Car ID
if (!$car_id) {
    die("Error: Invalid vehicle selection. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// 2. Validate Duration Integer Bounds (Nonnegative & Non-zero check)
if ($days === false || $days <= 0) {
    log_audit_event($pdo, 'PAYMENT_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Invalid day duration submitted: " . ($_POST['days'] ?? 'N/A'));
    die("Error: Invalid rental duration submitted for billing computation. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// 3. Enforce Upper Limit Cap
$max_allowed_days = 30;
if ($days > $max_allowed_days) {
    log_audit_event($pdo, 'PAYMENT_REJECTED', $_SESSION['user_name'] ?? "User #{$user_id}", "Exceeded maximum rental window: {$days} days");
    die("Error: Rental duration exceeds maximum limit of {$max_allowed_days} days. <a href='user_dashboard.php'>Return to Catalog</a>");
}

// -------------------------------------------------------------
// AUTHORITATIVE RATE LOOKUP & BILLING CALCULATION
// -------------------------------------------------------------
try {
    // Re-fetch price rate directly from DB to prevent client-side rate tampering
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = ? AND (LOWER(status) = 'available' OR status IS NULL)");
    $stmt->execute([$car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$car) {
        die("Selected vehicle is not available for payment processing. <a href='user_dashboard.php'>Return to Catalog</a>");
    }

    $daily_rate = (float)(
        $car['price_per_day'] ?? 
        $car['daily_rate'] ?? 
        $car['price'] ?? 
        50.00
    );

    // Calculate derived total price server-side
    $total_price = $daily_rate * $days;

    // Dates calculation for billing record
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d', strtotime("+{$days} days"));

    $pdo->beginTransaction();

    // Persist payment transaction record
    $insert = $pdo->prepare("
        INSERT INTO rentals (user_id, car_id, start_date, end_date, total_price, status) 
        VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')
    ");

    $insert->execute([
        ':user_id'     => $user_id,
        ':car_id'      => $car_id,
        ':start_date'  => $start_date,
        ':end_date'    => $end_date,
        ':total_price' => $total_price
    ]);

    // Update vehicle operational status
    $update_car = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
    $update_car->execute([$car_id]);

    $pdo->commit();

    log_audit_event($pdo, 'PAYMENT_SUCCESS', $_SESSION['user_name'] ?? "User #{$user_id}", "Processed payment for Car #{$car_id} across {$days} days - Total Billed: \${$total_price}");

    header("Location: user_dashboard.php?payment=success");
    exit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    log_audit_event($pdo, 'PAYMENT_FAILURE', $_SESSION['user_name'] ?? "User #{$user_id}", "Payment execution error: " . $e->getMessage());
    die("Payment Processing Failed! Database Error: " . htmlspecialchars($e->getMessage()));
}
?>