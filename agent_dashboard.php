<?php
session_start();
require_once 'db.php';

// 1. Strict Session & Role Validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    // If an admin or staff member lands here, allow or redirect appropriately
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'admin':
                header("Location: admin_dashboard.php");
                exit();
            case 'fleet_manager':
                header("Location: fleet_dashboard.php");
                exit();
            case 'branch_agent':
                header("Location: agent_dashboard.php");
                exit();
        }
    } else {
        header("Location: index.php");
        exit();
    }
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// 2. Handle Booking Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_booking') {
    $car_id = intval($_POST['car_id']);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    if (!empty($car_id) && !empty($start_date) && !empty($end_date)) {
        if ($start_date > $end_date) {
            $error = "End date must be after the start date.";
        } else {
            try {
                // Fetch daily rate for cost calculation
                $car_stmt = $pdo->prepare("SELECT price_per_day, status FROM cars WHERE id = ?");
                $car_stmt->execute([$car_id]);
                $car = $car_stmt->fetch();

                if ($car && $car['status'] === 'available') {
                    // Calculate total days & total price
                    $d1 = new DateTime($start_date);
                    $d2 = new DateTime($end_date);
                    $days = $d1->diff($d2)->days + 1; // Inclusive of start day
                    $total_price = $days * $car['price_per_day'];

                    // Begin Transaction
                    $pdo->beginTransaction();

                    // Insert Booking
                    $book_stmt = $pdo->prepare("INSERT INTO bookings (user_id, car_id, start_date, end_date, total_price, status) VALUES (?, ?, ?, ?, ?, 'confirmed')");
                    $book_stmt->execute([$user_id, $car_id, $start_date, $end_date, $total_price]);

                    // Update Car Status to Rented
                    $update_car = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
                    $update_car->execute([$car_id]);

                    $pdo->commit();
                    $message = "Reservation successful! Total cost for {$days} day(s): $" . number_format($total_price, 2);
                } else {
                    $error = "Selected vehicle is currently unavailable.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Booking failed: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please fill in all booking details.";
    }
}

// 3. Fetch Data for Display
try {
    // Fetch Available Fleet
    $cars_stmt = $pdo->query("SELECT * FROM cars ORDER BY status ASC, id ASC");
    $cars = $cars_stmt->fetchAll();

    // Fetch User's Existing Bookings
    $bookings_stmt = $pdo->prepare("
        SELECT b.id, b.start_date, b.end_date, b.total_price, b.status, c.brand, c.model, c.year, c.image_url
        FROM bookings b
        JOIN cars c ON b.car_id = c.id
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
    ");
    $bookings_stmt->execute([$user_id]);
    $user_bookings = $bookings_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Customer Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #007bff; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .section-title { font-size: 20px; margin-bottom: 15px; color: #1a252f; border-bottom: 2px solid #e9ecef; padding-bottom: 8px; }

        /* Vehicle Grid */
        .cars-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .car-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; flex-direction: column; }
        .car-card img { width: 100%; height: 180px; object-fit: cover; background-color: #e9ecef; }
        .car-details { padding: 15px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .car-title { font-size: 18px; font-weight: bold; margin: 0 0 5px 0; }
        .car-price { color: #28a745; font-size: 16px; font-weight: bold; margin-bottom: 10px; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; display: inline-block; }
        .badge-available { background: #d4edda; color: #155724; }
        .badge-rented { background: #cce5ff; color: #004085; }
        .badge-maintenance { background: #f8d7da; color: #721c24; }

        .booking-form { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
        .booking-form label { font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px; }
        .booking-form input[type="date"] { width: 100%; padding: 6px; margin-bottom: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-book { width: 100%; background: #28a745; color: white; border: none; padding: 8px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-book:hover { background: #218838; }
        .btn-disabled { width: 100%; background: #6c757d; color: white; border: none; padding: 8px; border-radius: 4px; font-weight: bold; cursor: not-allowed; }

        /* Bookings Table */
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Customer Portal</h1>
    <div class="user-info">
        <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Customer'); ?></strong></span>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Vehicle Catalog -->
    <h2 class="section-title">Available Vehicle Catalog</h2>
    <div class="cars-grid">
        <?php foreach ($cars as $car): ?>
            <div class="car-card">
                <img src="<?php echo htmlspecialchars($car['image_url'] ?? 'https://via.placeholder.com/300x180?text=Car+Image'); ?>" alt="<?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?>">
                <div class="car-details">
                    <div>
                        <div class="car-title"><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?> (<?php echo $car['year']; ?>)</div>
                        <div class="car-price">$<?php echo number_format($car['price_per_day'], 2); ?> <small>/ day</small></div>
                        <span class="badge badge-<?php echo htmlspecialchars($car['status']); ?>">
                            <?php echo htmlspecialchars($car['status']); ?>
                        </span>
                    </div>

                    <?php if ($car['status'] === 'available'): ?>
                        <form method="POST" class="booking-form">
                            <input type="hidden" name="action" value="create_booking">
                            <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                            
                            <label for="start_<?php echo $car['id']; ?>">Pick-up Date:</label>
                            <input type="date" id="start_<?php echo $car['id']; ?>" name="start_date" required min="<?php echo date('Y-m-d'); ?>">

                            <label for="end_<?php echo $car['id']; ?>">Return Date:</label>
                            <input type="date" id="end_<?php echo $car['id']; ?>" name="end_date" required min="<?php echo date('Y-m-d'); ?>">

                            <button type="submit" class="btn-book">Reserve Vehicle</button>
                        </form>
                    <?php else: ?>
                        <div class="booking-form">
                            <button type="button" class="btn-disabled" disabled>Currently Unavailable</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Active & Past Reservations -->
    <div class="card">
        <h2 class="section-title">My Rental History</h2>
        <?php if (!empty($user_bookings)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Vehicle</th>
                        <th>Pick-up Date</th>
                        <th>Return Date</th>
                        <th>Total Price</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($user_bookings as $booking): ?>
                        <tr>
                            <td>#<?php echo $booking['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($booking['brand'] . ' ' . $booking['model'] . ' (' . $booking['year'] . ')'); ?></strong></td>
                            <td><?php echo htmlspecialchars($booking['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($booking['end_date']); ?></td>
                            <td>$<?php echo number_format($booking['total_price'], 2); ?></td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($booking['status'] === 'confirmed' ? 'available' : 'maintenance'); ?>">
                                    <?php echo htmlspecialchars($booking['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>You have no active or previous reservations.</p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>