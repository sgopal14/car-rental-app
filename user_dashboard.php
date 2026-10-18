<?php
session_start();
require_once 'db.php';

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Enforce customer role access control
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'Valued Customer';
$cars = [];
$error_msg = '';

// Retrieve fleet data safely from RDS database
try {
    $stmt = $pdo->prepare("SELECT * FROM cars");
    $stmt->execute();
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM vehicles");
        $stmt->execute();
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $error_msg = "Database Error: " . htmlspecialchars($e2->getMessage());
    }
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
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #f4f6f9; 
            color: #333; 
        }
        .navbar { 
            background-color: #1a252f; 
            color: #fff; 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
        }
        .navbar h1 { font-size: 22px; font-weight: bold; }
        .navbar .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn { 
            background-color: #dc3545; 
            color: white; 
            padding: 8px 16px; 
            text-decoration: none; 
            border-radius: 5px; 
            font-size: 14px; 
            font-weight: bold; 
        }
        .logout-btn:hover { background-color: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .header-title { margin-bottom: 25px; color: #1a252f; text-align: center; }
        .alert { 
            background-color: #f8d7da; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            text-align: center; 
        }
        .catalog-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); 
            gap: 25px; 
        }
        .card { 
            background: #fff; 
            border-radius: 10px; 
            overflow: hidden; 
            border-top: 4px solid #007bff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08); 
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
        }
        .card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 6px 15px rgba(0,0,0,0.12); 
        }
        .card-body { padding: 25px; }
        .card-title { 
            font-size: 20px; 
            font-weight: bold; 
            color: #1a252f; 
            margin-bottom: 12px; 
        }
        .card-details { 
            font-size: 14px; 
            color: #6c757d; 
            margin-bottom: 20px; 
            line-height: 1.6; 
        }
        .card-price { 
            font-size: 18px; 
            font-weight: bold; 
            color: #28a745; 
            margin-bottom: 20px; 
        }
        .btn-rent { 
            display: block; 
            width: 100%; 
            padding: 12px; 
            text-align: center; 
            background-color: #007bff; 
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            font-weight: bold; 
        }
        .btn-rent:hover { background-color: #0056b3; }
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
    <h2 class="header-title">Available Vehicle Fleet</h2>

    <?php if (!empty($error_msg)): ?>
        <div class="alert"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="catalog-grid">
        <?php if (!empty($cars)): ?>
            <?php foreach ($cars as $car): ?>
                <?php 
                    $make = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? 'Vehicle';
                    $model = $car['model'] ?? $car['car_model'] ?? '';
                    $price = $car['daily_rate'] ?? $car['price_per_day'] ?? $car['price'] ?? '0.00';
                ?>
                <div class="card">
                    <div class="card-body">
                        <div class="card-title"><?php echo htmlspecialchars($make . ' ' . $model); ?></div>
                        
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