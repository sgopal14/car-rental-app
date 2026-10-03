<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header('Location: index.php');
    exit;
}

// Fetch cars that are either available or idle (exclude 'rented' and 'maintenance')
$allCarsStmt = $pdo->prepare("SELECT * FROM cars WHERE status IN ('available', 'idle') ORDER BY status ASC");
$allCarsStmt->execute();
$allCars = $allCarsStmt->fetchAll();

// Fetch current user's active rentals ONLY if the car is currently marked as 'rented'
$myRentalsStmt = $pdo->prepare("
    SELECT r.id AS rental_id, c.brand, c.model, c.daily_rate, r.rental_date 
    FROM rentals r 
    JOIN cars c ON r.car_id = c.id 
    WHERE r.user_id = :user_id 
      AND r.status = 'active' 
      AND c.status = 'rented'
");
$myRentalsStmt->execute(['user_id' => $_SESSION['user_id']]);
$myRentals = $myRentalsStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Dashboard - DriveEase</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        header { background: #0066cc; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { padding: 30px; max-width: 1100px; margin: auto; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 15px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #eee; position: relative; }
        .card h3 { margin-bottom: 10px; color: #333; }
        .price { color: #28a745; font-size: 18px; font-weight: bold; margin-bottom: 15px; }
        .btn { display: inline-block; width: 100%; text-align: center; background: #0066cc; color: white; padding: 10px; text-decoration: none; border-radius: 4px; font-weight: bold; }
        .btn:hover { background: #0052a3; }
        .btn-disabled { background: #ccc !important; cursor: not-allowed; pointer-events: none; }
        .btn-logout { background: #d9534f; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; }
        .section-title { margin: 30px 0 15px; border-bottom: 2px solid #ccc; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f4f7f6; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; float: right; }
        .badge-available { background: #d4edda; color: #155724; }
        .badge-idle { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>

<header>
    <h1>DriveEase Rentals</h1>
    <div>
        <span>Welcome, <?= htmlspecialchars($_SESSION['user_email']) ?></span>
        <a href="logout.php" class="btn-logout" style="margin-left: 15px;">Logout</a>
    </div>
</header>

<div class="container">
    <h2>My Active Rentals</h2>
    <?php if (count($myRentals) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Daily Rate</th>
                    <th>Rental Started</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myRentals as $rental): ?>
                    <tr>
                        <td><?= htmlspecialchars($rental['brand'] . ' ' . $rental['model']) ?></td>
                        <td>$<?= number_format($rental['daily_rate'], 2) ?>/day</td>
                        <td><?= $rental['rental_date'] ?></td>
                        <td><span style="color: orange; font-weight: bold;">Rented Out</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="margin-top: 10px; color: #666;">You currently have no active vehicle rentals.</p>
    <?php endif; ?>

    <h2 class="section-title">Fleet Vehicles</h2>
    <div class="grid">
        <?php if (count($allCars) > 0): ?>
            <?php foreach ($allCars as $car): ?>
                <div class="card">
                    <span class="badge badge-<?= htmlspecialchars($car['status']) ?>">
                        <?= ucfirst($car['status']) ?>
                    </span>
                    <h3><?= htmlspecialchars($car['brand'] . ' ' . $car['model']) ?></h3>
                    <div class="price">$<?= number_format($car['daily_rate'], 2) ?> / day</div>

                    <?php if ($car['status'] === 'available'): ?>
                        <a href="payment.php?car_id=<?= $car['id'] ?>" class="btn">Rent Now</a>
                    <?php else: ?>
                        <a href="#" class="btn btn-disabled">Currently Idle</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No vehicles are available for rent right now.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>