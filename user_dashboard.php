<?php
ob_start();
session_start();
require_once 'db.php';

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Authenticate user session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Extract role and prevent administrative users from accidentally landing on customer dashboard
$user_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');

if (in_array($user_role, ['admin', 'agent', 'branch_agent', 'fleet_manager', 'staff'], true)) {
    if ($user_role === 'admin') {
        header("Location: admin_dashboard.php");
    } elseif ($user_role === 'fleet_manager') {
        header("Location: fleet_dashboard.php");
    } else {
        header("Location: agent_dashboard.php");
    }
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Valued Customer';
$cars      = [];
$my_rentals = [];
$error_msg = '';

try {
    // 1. Fetch available fleet safely from cars table
    $stmt = $pdo->prepare("SELECT * FROM cars");
    $stmt->execute();
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch active rentals for the logged-in user
    $rental_query = "
        SELECT r.*, c.*
        FROM rentals r
        LEFT JOIN cars c ON r.car_id = c.id
        WHERE r.user_id = :user_id
        ORDER BY r.created_at DESC
    ";
    
    $r_stmt = $pdo->prepare($rental_query);
    $r_stmt->execute([':user_id' => $user_id]);
    $my_rentals = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msg = "Database Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Vehicle Catalog</title>
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
        .rentals-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 40px; }
        .rentals-table th, .rentals-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #eee; }
        .rentals-table th { background-color: #1a252f; color: white; font-weight: 600; }
        .status-badge { background-color: #28a745; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 25px; }
        .card { background: #fff; border-radius: 10px; overflow: hidden; border-top: 4px solid #007bff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .card-body { padding: 25px; }
        .card-title { font-size: 20px; font-weight: bold; color: #1a252f; margin-bottom: 12px; }
        .card-details { font-size: 14px; color: #6c757d; margin-bottom: 20px; line-height: 1.6; }
        .card-price { font-size: 18px; font-weight: bold; color: #28a745; margin-bottom: 20px; }
        .btn-rent { display: block; width: 100%; padding: 12px; text-align: center; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Portal</h1>
    <div class="user-info">
        <span>Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
        <a href="logout.php" class="logout-btn">Sign Out</a>
    </div>
</nav>

<div class="container">

    <?php if (isset($_GET['booking']) && $_GET['booking'] === 'success'): ?>
        <div class="alert-success">✓ Your vehicle reservation has been successfully confirmed!</div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Section: My Reserved Vehicles -->
    <h2 class="header-title">My Rented Vehicles</h2>
    <?php if (!empty($my_rentals)): ?>
        <table class="rentals-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Category</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Total Price</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($my_rentals as $rental): ?>
                    <?php 
                        $vehicle_make  = $rental['make'] ?? $rental['car_make'] ?? $rental['brand'] ?? $rental['title'] ?? 'Vehicle';
                        $vehicle_model = $rental['model'] ?? $rental['car_model'] ?? '';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars(trim($vehicle_make . ' ' . $vehicle_model)); ?></strong></td>
                        <td><?php echo htmlspecialchars($rental['category'] ?? $rental['type'] ?? 'Standard'); ?></td>
                        <td><?php echo htmlspecialchars($rental['start_date']); ?></td>
                        <td><?php echo htmlspecialchars($rental['end_date']); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format((float)($rental['total_price'] ?? 0), 2)); ?></td>
                        <td><span class="status-badge"><?php echo htmlspecialchars($rental['status'] ?? 'Confirmed'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="background: #fff; padding: 20px; border-radius: 8px; color: #6c757d; margin-bottom: 40px;">
            You have no active or previous vehicle reservations.
        </p>
    <?php endif; ?>

    <!-- Section: Available Vehicles Fleet -->
    <h2 class="header-title">Available Fleet</h2>
    <div class="catalog-grid">
        <?php if (!empty($cars)): ?>
            <?php foreach ($cars as $car): ?>
                <?php 
                    $make  = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? $car['title'] ?? 'Vehicle';
                    $model = $car['model'] ?? $car['car_model'] ?? '';
                    $price = $car['daily_rate'] ?? $car['price_per_day'] ?? $car['price'] ?? '0.00';
                ?>
                <div class="card">
                    <div class="card-body">
                        <div class="card-title"><?php echo htmlspecialchars(trim($make . ' ' . $model)); ?></div>
                        
                        <div class="card-details">
                            <strong>Category:</strong> <?php echo htmlspecialchars($car['category'] ?? $car['type'] ?? 'Standard'); ?><br>
                            <strong>Transmission:</strong> <?php echo htmlspecialchars($car['transmission'] ?? 'Automatic'); ?>
                        </div>

                        <div class="card-price">
                            $<?php echo htmlspecialchars(number_format((float)$price, 2)); ?> / day
                        </div>

                        <a href="rent.php?id=<?php echo htmlspecialchars($car['id']); ?>" class="btn-rent">Reserve Vehicle</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center; grid-column: 1 / -1; color: #6c757d;">No vehicles currently available in the catalog.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>