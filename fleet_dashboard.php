<?php
session_start();
require_once 'db.php';

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Authenticate session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Restrict access to staff / agent accounts
if (isset($_SESSION['role']) && !in_array(strtolower($_SESSION['role']), ['agent', 'admin', 'staff', 'fleet_manager'])) {
    header("Location: user_dashboard.php");
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Fleet Administrator';
$cars = [];
$error_msg = '';
$success_msg = '';

// Handle new vehicle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_car') {
    $make = trim($_POST['make'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $category = trim($_POST['category'] ?? 'Standard');
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $transmission = trim($_POST['transmission'] ?? 'Automatic');

    if (empty($make) || empty($model) || $price === false || $price <= 0) {
        $error_msg = "Please provide valid vehicle make, model, and daily price rate.";
    } else {
        try {
            $add_stmt = $pdo->prepare("
                INSERT INTO cars (make, model, category, price_per_day, daily_rate, transmission) 
                VALUES (:make, :model, :category, :price, :price, :transmission)
            ");
            $add_stmt->execute([
                ':make'         => $make,
                ':model'        => $model,
                ':category'     => $category,
                ':price'        => $price,
                ':transmission' => $transmission
            ]);
            $success_msg = "Vehicle '{$make} {$model}' added to the fleet successfully!";
        } catch (PDOException $e) {
            $error_msg = "Failed to add vehicle: " . htmlspecialchars($e->getMessage());
        }
    }
}

try {
    // Retrieve all vehicles cleanly
    $stmt = $pdo->prepare("SELECT * FROM cars ORDER BY id DESC");
    $stmt->execute();
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_msg = "Database Error: " . htmlspecialchars($e->getMessage());
}

$csrf_token = function_exists('generate_csrf_token') 
    ? generate_csrf_token() 
    : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Fleet Management Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; color: #333; }
        .navbar { background-color: #1a252f; color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn { background-color: #dc3545; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: bold; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .header-title { margin: 30px 0 15px 0; color: #1a252f; }
        .alert-success { background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 25px; border: 1px solid #c3e6cb; font-weight: bold; }
        .alert-error { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 25px; }
        
        .form-card { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 40px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn-submit { background-color: #007bff; color: white; border: none; padding: 12px 20px; font-size: 15px; font-weight: bold; border-radius: 4px; cursor: pointer; }
        .btn-submit:hover { background-color: #0056b3; }

        .data-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 40px; }
        .data-table th, .data-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #eee; }
        .data-table th { background-color: #1a252f; color: white; font-weight: 600; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Fleet Management</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
        <a href="agent_dashboard.php" style="color: white; text-decoration: none; font-weight: bold; margin-right: 15px;">Agent Portal &rarr;</a>
        <a href="logout.php" class="logout-btn">Sign Out</a>
    </div>
</nav>

<div class="container">

    <?php if (!empty($success_msg)): ?>
        <div class="alert-success">✓ <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Section 1: Add New Vehicle Form -->
    <div class="form-card">
        <h2 style="margin-bottom: 15px; color: #1a252f;">Register New Vehicle</h2>
        <form action="fleet_dashboard.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="add_car">

            <div class="form-row">
                <div class="form-group">
                    <label for="make">Vehicle Make / Brand</label>
                    <input type="text" id="make" name="make" placeholder="e.g. Toyota" required>
                </div>

                <div class="form-group">
                    <label for="model">Vehicle Model</label>
                    <input type="text" id="model" name="model" placeholder="e.g. RAV4" required>
                </div>

                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="SUV">SUV</option>
                        <option value="Sedan">Sedan</option>
                        <option value="Luxury">Luxury</option>
                        <option value="Economy">Economy</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="price">Daily Rate ($ USD)</label>
                    <input type="number" step="0.01" id="price" name="price" placeholder="65.00" required>
                </div>

                <div class="form-group">
                    <label for="transmission">Transmission</label>
                    <select id="transmission" name="transmission">
                        <option value="Automatic">Automatic</option>
                        <option value="Manual">Manual</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-submit">+ Add Vehicle to Catalog</button>
        </form>
    </div>

    <!-- Section 2: Fleet Inventory Table -->
    <h2 class="header-title">Active Fleet Catalog</h2>
    <?php if (!empty($cars)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Car ID</th>
                    <th>Vehicle Details</th>
                    <th>Category</th>
                    <th>Daily Rate</th>
                    <th>Transmission</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <?php 
                        // Safe extraction using null-coalescing fallbacks
                        $make  = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? $car['title'] ?? 'Vehicle';
                        $model = $car['model'] ?? $car['car_model'] ?? '';
                        $price = $car['price_per_day'] ?? $car['daily_rate'] ?? $car['price'] ?? $car['rate'] ?? '0.00';
                        $category = $car['category'] ?? $car['type'] ?? 'Standard';
                        $transmission = $car['transmission'] ?? 'Automatic';
                    ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($car['id']); ?></td>
                        <td><strong><?php echo htmlspecialchars(trim($make . ' ' . $model)); ?></strong></td>
                        <td><?php echo htmlspecialchars($category); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format((float)$price, 2)); ?> / day</td>
                        <td><?php echo htmlspecialchars($transmission); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="background: #fff; padding: 20px; border-radius: 8px; color: #6c757d; margin-bottom: 40px;">
            No vehicles currently registered in the database catalog.
        </p>
    <?php endif; ?>

</div>

</body>
</html>