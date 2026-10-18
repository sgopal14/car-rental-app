<?php
session_start();
require_once 'db.php';

// Enforce POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user_dashboard.php");
    exit();
}

// 1. CSRF Token Validation
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

// 2. Authentication Check
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
    // 3. Fetch vehicle details using SELECT * to safely support schema variations
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

    // Safely extract price rate
    $daily_rate = (float)(
        $car['price_per_day'] ?? 
        $car['daily_rate'] ?? 
        $car['rate'] ?? 
        $car['price'] ?? 
        $car['cost'] ?? 
        50.00
    );

    // Calculate total duration in days
    $d1 = new DateTime($start_date);
    $d2 = new DateTime($end_date);
    $days = $d1->diff($d2)->days;
    if ($days <= 0) { 
        $days = 1; 
    }

    $total_price = $daily_rate * $days;

    // 4. Ensure target table exists and perform insertion with explicit error reporting
    $inserted = false;
    $last_error = '';
    $target_tables = ['rentals', 'bookings', 'car_rentals'];

    foreach ($target_tables as $table) {
        try {
            // Check if table exists in schema
            $check = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($check->rowCount() === 0) {
                continue;
            }

            $insert = $pdo->prepare("
                INSERT INTO {$table} (user_id, car_id, start_date, end_date, total_price, status) 
                VALUES (:user_id, :car_id, :start_date, :end_date, :total_price, 'confirmed')
            ");
            
            $insert->execute([
                ':user_id'     => $user_id,
                ':car_id'      => $car_id,
                ':start_date'  => $start_date,
                ':end_date'    => $end_date,
                ':total_price' => $total_price
            ]);

            $inserted = true;
            break; // Insertion succeeded
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }

    if (!$inserted) {
        die("Reservation Failed! SQL Error: " . htmlspecialchars($last_error ?: "No valid target table found in database."));
    }

    header("Location: user_dashboard.php?booking=success");
    exit();

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
EOF
File 2: /var/www/html/rent.php
Deploy via terminal:

Bash
sudo cat << 'EOF' > /var/www/html/rent.php
<?php
session_start();
require_once 'db.php';

// Authenticate session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Generate token using db.php helper or fallback session variable
$csrf_token = function_exists('generate_csrf_token') 
    ? generate_csrf_token() 
    : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))));

$car_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$car_id) {
    die("Invalid vehicle selection. <a href='user_dashboard.php'>Return to Catalog</a>");
}

$car = null;
$error_msg = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id");
    $stmt->execute([':id' => $car_id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$car) {
        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id");
        $stmt->execute([':id' => $car_id]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error_msg = "Database Error: " . htmlspecialchars($e->getMessage());
}

if (!$car && empty($error_msg)) {
    die("Vehicle not found. <a href='user_dashboard.php'>Return to Catalog</a>");
}

$make = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? 'Vehicle';
$model = $car['model'] ?? $car['car_model'] ?? '';
$price = $car['daily_rate'] ?? $car['price_per_day'] ?? $car['price'] ?? '0.00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve Vehicle - DriveEase</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; color: #333; }
        .navbar { background-color: #1a252f; color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 650px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); border-top: 4px solid #28a745; }
        .car-details { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .car-details h2 { color: #1a252f; margin-bottom: 10px; font-size: 24px; }
        .price-tag { font-size: 22px; color: #28a745; font-weight: bold; margin-top: 15px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #1a252f; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 16px; }
        .btn-submit { background-color: #28a745; color: white; border: none; padding: 12px 20px; font-size: 16px; font-weight: bold; border-radius: 5px; cursor: pointer; width: 100%; }
        .btn-submit:hover { background-color: #218838; }
        .back-link { display: inline-block; margin-top: 20px; color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Reservation</h1>
    <a href="user_dashboard.php" style="color: white; text-decoration: none; font-weight: bold;">&larr; Back to Catalog</a>
</nav>

<div class="container">
    <?php if (!empty($error_msg)): ?>
        <p style="color: red; text-align: center;"><?php echo $error_msg; ?></p>
    <?php else: ?>
        <div class="car-details">
            <h2><?php echo htmlspecialchars($make . ' ' . $model); ?></h2>
            <p><strong>Category:</strong> <?php echo htmlspecialchars($car['category'] ?? $car['type'] ?? 'Standard'); ?></p>
            <p><strong>Transmission:</strong> <?php echo htmlspecialchars($car['transmission'] ?? 'Automatic'); ?></p>
            <div class="price-tag">$<?php echo htmlspecialchars(number_format((float)$price, 2)); ?> / day</div>
        </div>

        <form action="process_rental.php" method="POST">
            <!-- CSRF Token Hidden Inputs -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="car_id" value="<?php echo htmlspecialchars($car['id']); ?>">
            
            <div class="form-group">
                <label for="start_date">Start Date:</label>
                <input type="date" id="start_date" name="start_date" required min="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="form-group">
                <label for="end_date">End Date:</label>
                <input type="date" id="end_date" name="end_date" required min="<?php echo date('Y-m-d'); ?>">
            </div>

            <button type="submit" class="btn-submit">Confirm Reservation</button>
        </form>

        <a href="user_dashboard.php" class="back-link">&larr; Cancel and return to catalog</a>
    <?php endif; ?>
</div>

</body>
</html>