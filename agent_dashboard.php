<?php
session_start();
require_once 'db.php';

// 1. Strict Session & Role Validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'branch_agent') {
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'admin':
                header("Location: admin_dashboard.php");
                exit();
            case 'fleet_manager':
                header("Location: fleet_dashboard.php");
                exit();
            case 'customer':
                header("Location: user_dashboard.php");
                exit();
        }
    } else {
        header("Location: index.php");
        exit();
    }
}

$message = '';
$error = '';
$search_query = trim($_GET['search'] ?? '');

// 2. Handle Transaction Operations (Check-out & Return/Check-in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $car_id = intval($_POST['car_id'] ?? 0);
    $action = $_POST['action'];

    // Manual Transaction Creation (Walk-in / Agent Entry)
    if ($action === 'create_transaction') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $selected_car_id = intval($_POST['car_id'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        if ($customer_id > 0 && $selected_car_id > 0 && !empty($start_date) && !empty($end_date)) {
            if ($start_date > $end_date) {
                $error = "Return date must be on or after the check-out date.";
            } else {
                try {
                    $car_stmt = $pdo->prepare("SELECT price_per_day, status FROM cars WHERE id = ?");
                    $car_stmt->execute([$selected_car_id]);
                    $car = $car_stmt->fetch();

                    if ($car && $car['status'] === 'available') {
                        $d1 = new DateTime($start_date);
                        $d2 = new DateTime($end_date);
                        $days = $d1->diff($d2)->days + 1;
                        $total_price = $days * $car['price_per_day'];

                        $pdo->beginTransaction();

                        // Insert confirmed rental transaction
                        $stmt = $pdo->prepare("INSERT INTO bookings (user_id, car_id, start_date, end_date, total_price, status) VALUES (?, ?, ?, ?, ?, 'confirmed')");
                        $stmt->execute([$customer_id, $selected_car_id, $start_date, $end_date, $total_price]);

                        // Update car status to rented
                        $update_car = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
                        $update_car->execute([$selected_car_id]);

                        $pdo->commit();
                        $message = "New rental transaction registered successfully! Total: $" . number_format($total_price, 2);
                    } else {
                        $error = "Selected car is not available for rental.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = "Transaction creation failed: " . $e->getMessage();
                }
            }
        } else {
            $error = "Please select a valid customer, car, and date range.";
        }
    } 
    // Process Check-Out (Vehicle Pick-Up)
    elseif ($action === 'process_checkout') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
            $stmt->execute([$booking_id]);

            $car_stmt = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = ?");
            $car_stmt->execute([$car_id]);

            $pdo->commit();
            $message = "Check-out processed for Transaction #{$booking_id}. Vehicle marked as Rented.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = "Check-out failed: " . $e->getMessage();
        }
    } 
    // Process Return (Vehicle Check-In)
    elseif ($action === 'process_return') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?");
            $stmt->execute([$booking_id]);

            $car_stmt = $pdo->prepare("UPDATE cars SET status = 'available' WHERE id = ?");
            $car_stmt->execute([$car_id]);

            $pdo->commit();
            $message = "Vehicle returned for Transaction #{$booking_id}. Vehicle status updated to Available.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = "Check-in failed: " . $e->getMessage();
        }
    }
}

// 3. Fetch Data (Transactions, Customers, Available Vehicles)
try {
    // Fetch Available Cars for Walk-in Entry
    $avail_cars = $pdo->query("SELECT id, brand, model, year, price_per_day FROM cars WHERE status = 'available' ORDER BY brand ASC")->fetchAll();

    // Fetch Customers
    $customers = $pdo->query("SELECT id, name, email FROM users WHERE role = 'customer' ORDER BY name ASC")->fetchAll();

    // Fetch Transactions Log
    $sql = "
        SELECT b.id AS booking_id, b.start_date, b.end_date, b.total_price, b.status AS booking_status,
               u.name AS customer_name, u.email AS customer_email,
               c.id AS car_id, c.brand, c.model, c.year, c.status AS car_status
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN cars c ON b.car_id = c.id
    ";

    if (!empty($search_query)) {
        $sql .= " WHERE b.id = :search_id OR LOWER(u.email) LIKE LOWER(:search_email) OR LOWER(u.name) LIKE LOWER(:search_name)";
    }

    $sql .= " ORDER BY b.created_at DESC";
    $stmt = $pdo->prepare($sql);

    if (!empty($search_query)) {
        $stmt->bindValue(':search_id', is_numeric($search_query) ? intval($search_query) : 0, PDO::PARAM_INT);
        $stmt->bindValue(':search_email', "%{$search_query}%", PDO::PARAM_STR);
        $stmt->bindValue(':search_name', "%{$search_query}%", PDO::PARAM_STR);
    }

    $stmt->execute();
    $transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Database query error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Branch Agent Counter</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #d97706; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .card h2 { margin-top: 0; font-size: 18px; color: #1a252f; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }

        /* Form styling */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 5px; }
        .form-group select, .form-group input { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .btn-submit { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #218838; }

        /* Search Bar */
        .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-box input { flex-grow: 1; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .btn-search { background: #d97706; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-reset { background: #6c757d; color: white; text-decoration: none; padding: 10px 15px; border-radius: 4px; font-size: 14px; display: inline-block; }

        /* Table */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-confirmed { background: #cce5ff; color: #004085; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }

        .btn-action { padding: 6px 12px; border-radius: 4px; border: none; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-checkout { background: #007bff; color: white; }
        .btn-checkout:hover { background: #0056b3; }
        .btn-return { background: #28a745; color: white; }
        .btn-return:hover { background: #218838; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Branch Desk - Rental Transactions</h1>
    <div class="user-info">
        <span>Branch Agent: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?></strong></span>
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

    <!-- 1. Enter New Rental Transaction (Walk-in Counter Entry) -->
    <div class="card">
        <h2>Enter Walk-in Rental Transaction</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create_transaction">
            <div class="form-grid">
                <div class="form-group">
                    <label>Select Customer</label>
                    <select name="customer_id" required>
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ' (' . $c['email'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select Vehicle</label>
                    <select name="car_id" required>
                        <option value="">-- Available Fleet --</option>
                        <?php foreach ($avail_cars as $car): ?>
                            <option value="<?php echo $car['id']; ?>"><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model'] . ' ($' . $car['price_per_day'] . '/day)'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Check-out Date</label>
                    <input type="date" name="start_date" required min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Expected Return Date</label>
                    <input type="date" name="end_date" required min="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <button type="submit" class="btn-submit">Register & Check-Out</button>
        </form>
    </div>

    <!-- 2. View & Update Active Transactions -->
    <div class="card">
        <h2>View & Update Rental Transactions</h2>

        <form method="GET" class="search-box">
            <input type="text" name="search" placeholder="Search by Transaction ID, Customer Name, or Email..." value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit" class="btn-search">Search</button>
            <?php if (!empty($search_query)): ?>
                <a href="agent_dashboard.php" class="btn-reset">Reset Filter</a>
            <?php endif; ?>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Tx ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Rental Dates</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Update Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><strong>#<?php echo $t['booking_id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($t['customer_name']); ?></strong><br>
                                <small style="color:#6c757d;"><?php echo htmlspecialchars($t['customer_email']); ?></small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($t['brand'] . ' ' . $t['model']); ?></strong> (<?php echo $t['year']; ?>)
                            </td>
                            <td>
                                <strong>Out:</strong> <?php echo htmlspecialchars($t['start_date']); ?><br>
                                <strong>Due:</strong> <?php echo htmlspecialchars($t['end_date']); ?>
                            </td>
                            <td>$<?php echo number_format($t['total_price'], 2); ?></td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($t['booking_status']); ?>">
                                    <?php echo htmlspecialchars($t['booking_status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($t['booking_status'] === 'pending'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="process_checkout">
                                        <input type="hidden" name="booking_id" value="<?php echo $t['booking_id']; ?>">
                                        <input type="hidden" name="car_id" value="<?php echo $t['car_id']; ?>">
                                        <button type="submit" class="btn-action btn-checkout">Check-Out (Pick-Up)</button>
                                    </form>
                                <?php elseif ($t['booking_status'] === 'confirmed'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="process_return">
                                        <input type="hidden" name="booking_id" value="<?php echo $t['booking_id']; ?>">
                                        <input type="hidden" name="car_id" value="<?php echo $t['car_id']; ?>">
                                        <button type="submit" class="btn-action btn-return">Return Vehicle (Check-In)</button>
                                    </form>
                                <?php else: ?>
                                    <em style="color:#6c757d; font-size:12px;">Completed</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #6c757d; padding: 20px;">No rental transactions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>