<?php
ob_start();
session_start();
require_once 'db.php';

// Auth Guard: Only authenticated customers/users can access
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Customer';

// Filter date range selection (Default: today to tomorrow)
$search_start = trim($_GET['start_date'] ?? date('Y-m-d'));
$search_end   = trim($_GET['end_date'] ?? date('Y-m-d', strtotime('+1 day')));

// Validate search dates
if ($search_start < date('Y-m-d')) {
    $search_start = date('Y-m-d');
}
if ($search_end <= $search_start) {
    $search_end = date('Y-m-d', strtotime($search_start . ' +1 day'));
}

$available_cars = [];
$my_rentals     = [];
$error          = '';

try {
    // -------------------------------------------------------------
    // DYNAMIC CATALOG QUERY: Exclude cars in maintenance OR booked during search dates
    // -------------------------------------------------------------
    $catalog_stmt = $pdo->prepare("
        SELECT c.* 
        FROM cars c
        WHERE LOWER(COALESCE(c.status, 'available')) != 'maintenance'
          AND NOT EXISTS (
              SELECT 1 FROM rentals r
              WHERE r.car_id = c.id
                AND r.status IN ('confirmed', 'active')
                AND NOT (r.end_date < :search_start OR r.start_date > :search_end)
          )
        ORDER BY c.id ASC
    ");

    $catalog_stmt->execute([
        ':search_start' => $search_start,
        ':search_end'   => $search_end
    ]);
    $available_cars = $catalog_stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // USER RENTAL HISTORY: Strictly isolated to current user_id
    // -------------------------------------------------------------
    $history_stmt = $pdo->prepare("
        SELECT r.*, c.brand, c.make, c.model, c.category 
        FROM rentals r
        LEFT JOIN cars c ON r.car_id = c.id
        WHERE r.user_id = :user_id
        ORDER BY r.id DESC
    ");
    $history_stmt->execute([':user_id' => $user_id]);
    $my_rentals = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error loading portal data: " . $e->getMessage();
}

$csrf_token = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Customer Portal</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #007bff; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .search-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .form-group label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 9px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .btn-search { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; width: 100%; }

        .section-title { font-size: 20px; color: #1a252f; margin-bottom: 15px; border-bottom: 2px solid #dee2e6; padding-bottom: 8px; }

        .car-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .car-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 20px; border-top: 4px solid #28a745; display: flex; flex-direction: column; justify-content: space-between; }
        .car-card h3 { margin: 0 0 8px 0; font-size: 18px; color: #1a252f; }
        .car-card .category { display: inline-block; background: #e9ecef; color: #495057; font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; margin-bottom: 15px; }
        .car-card .price { font-size: 22px; font-weight: bold; color: #28a745; margin-bottom: 15px; }
        .btn-book { background: #28a745; color: white; border: none; padding: 10px; width: 100%; border-radius: 4px; font-weight: bold; cursor: pointer; text-align: center; }
        .btn-book:hover { background: #218838; }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 14px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-confirmed { background: #d4edda; color: #155724; }
        .badge-completed { background: #cce5ff; color: #004085; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Vehicle Reservations</h1>
    <div>
        <span>Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></span> &nbsp;|&nbsp;
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">

    <?php if (isset($_GET['booking']) && $_GET['booking'] === 'success'): ?>
        <div class="alert alert-success">Vehicle reservation confirmed successfully!</div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- SEARCH & FILTER FORM -->
    <div class="search-card">
        <form method="GET" action="user_dashboard.php">
            <div class="search-grid">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($search_start); ?>" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($search_end); ?>" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn-search">Filter Available Vehicles</button>
                </div>
            </div>
        </form>
    </div>

    <!-- VEHICLE CATALOG -->
    <h2 class="section-title">Available Vehicles (<?php echo htmlspecialchars($search_start); ?> to <?php echo htmlspecialchars($search_end); ?>)</h2>
    <div class="car-grid">
        <?php if (!empty($available_cars)): ?>
            <?php foreach ($available_cars as $car): ?>
                <?php 
                    $brand = $car['brand'] ?? $car['make'] ?? 'Vehicle';
                    $model = $car['model'] ?? '';
                    $rate  = $car['price_per_day'] ?? $car['daily_rate'] ?? $car['price'] ?? 0.00;
                ?>
                <div class="car-card">
                    <div>
                        <h3><?php echo htmlspecialchars(trim($brand . ' ' . $model)); ?></h3>
                        <span class="category"><?php echo htmlspecialchars($car['category'] ?? 'Standard'); ?></span>
                        <div class="price">$<?php echo number_format((float)$rate, 2); ?> <small style="font-size: 12px; color: #6c757d;">/ day</small></div>
                    </div>
                    <form method="POST" action="process_rental.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($search_start); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($search_end); ?>">
                        <button type="submit" class="btn-book">Reserve Vehicle</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #6c757d; grid-column: 1 / -1; padding: 20px; text-align: center; background: #fff; border-radius: 8px;">
                No vehicles are available for the selected date range. Please try different reservation dates.
            </p>
        <?php endif; ?>
    </div>

    <!-- PERSONAL RENTAL HISTORY -->
    <h2 class="section-title">My Reservation History</h2>
    <table>
        <thead>
            <tr>
                <th>Booking ID</th>
                <th>Vehicle Asset</th>
                <th>Rental Dates</th>
                <th>Total Billed</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($my_rentals)): ?>
                <?php foreach ($my_rentals as $r): ?>
                    <?php 
                        $car_name = trim(($r['brand'] ?? $r['make'] ?? '') . ' ' . ($r['model'] ?? 'Vehicle'));
                        $status   = strtolower($r['status'] ?? 'confirmed');
                    ?>
                    <tr>
                        <td><strong>#<?php echo $r['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($car_name); ?></td>
                        <td><?php echo htmlspecialchars(($r['start_date'] ?? 'N/A') . ' to ' . ($r['end_date'] ?? 'N/A')); ?></td>
                        <td><strong>$<?php echo number_format((float)($r['total_price'] ?? 0), 2); ?></strong></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($status); ?>">
                                <?php echo htmlspecialchars(strtoupper($status)); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #6c757d; padding: 20px;">
                        You have no past or upcoming vehicle reservations.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>

</body>
</html>