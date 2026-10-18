<?php
session_start();
require_once 'db.php';

// Authenticate session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Validate vehicle ID passed in URL query string
$car_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$car_id) {
    die("Invalid vehicle selection. <a href='user_dashboard.php'>Return to Catalog</a>");
}

$car = null;
$error_msg = '';

// Fetch car record from MySQL RDS
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

// Map database column names safely
$make = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? 'Vehicle';
$model = $car['model'] ?? $car['car_model'] ?? '';
$price = $car['daily_rate'] ?? $car['price_per_day'] ?? $car['price'] ?? '0.00';
$image_file = $car['car_image'] ?? $car['image_url'] ?? $car['image'] ?? 'uploads/default_car.jpg';

if (strpos($image_file, 'uploads/') === false) {
    $image_file = 'uploads/' . ltrim($image_file, '/');
}
$image_src = '/' . ltrim($image_file, '/');
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
        .container { max-width: 800px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .car-summary { display: flex; gap: 30px; margin-bottom: 30px; align-items: center; }
        .car-summary img { width: 300px; height: 180px; object-fit: cover; border-radius: 8px; }
        .car-details h2 { color: #1a252f; margin-bottom: 10px; }
        .price-tag { font-size: 20px; color: #28a745; font-weight: bold; margin-top: 10px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 16px; }
        .btn-submit { background-color: #28a745; color: white; border: none; padding: 12px 20px; font-size: 16px; font-weight: bold; border-radius: 5px; cursor: pointer; width: 100%; }
        .btn-submit:hover { background-color: #218838; }
        .back-link { display: inline-block; margin-top: 15px; color: #007bff; text-decoration: none; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Reservation</h1>
    <a href="user_dashboard.php" style="color: white; text-decoration: none;">&larr; Back to Catalog</a>
</nav>

<div class="container">
    <?php if (!empty($error_msg)): ?>
        <p style="color: red;"><?php echo $error_msg; ?></p>
    <?php else: ?>
        <div class="car-summary">
            <img src="<?php echo htmlspecialchars($image_src); ?>" 
                 alt="Vehicle" 
                 onerror="this.onerror=null; this.src='/uploads/default_car.jpg';">
            
            <div class="car-details" style="margin-bottom: 25px;">
    <h2><?php echo htmlspecialchars($make . ' ' . $model); ?></h2>
    <p><strong>Category:</strong> <?php echo htmlspecialchars($car['category'] ?? 'Standard'); ?></p>
    <p><strong>Transmission:</strong> <?php echo htmlspecialchars($car['transmission'] ?? 'Automatic'); ?></p>
    <div class="price-tag" style="font-size: 20px; color: #28a745; font-weight: bold; margin-top: 10px;">
        $<?php echo htmlspecialchars(number_format((float)$price, 2)); ?> / day
    </div>
</div>

        <form action="process_rental.php" method="POST">
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