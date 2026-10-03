<?php
session_start();
require_once 'db.php';

// 1. Strict Session & Role Validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'fleet_manager') {
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'admin':
                header("Location: admin_dashboard.php");
                exit();
            case 'branch_agent':
                header("Location: agent_dashboard.php");
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

// 2. Handle CSV Report Exports
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'fleet_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="fleet_asset_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Car ID', 'Brand', 'Model', 'Year', 'Daily Rate ($)', 'Status']);
        
        $stmt = $pdo->query("SELECT id, brand, model, year, price_per_day, status FROM cars ORDER BY id ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    } elseif ($export_type === 'transactions_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sales_transactions_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Booking ID', 'Customer Name', 'Customer Email', 'Vehicle', 'Start Date', 'End Date', 'Total Price ($)', 'Status', 'Created At']);
        
        $sql = "SELECT b.id, u.name, u.email, CONCAT(c.brand, ' ', c.model) AS vehicle, b.start_date, b.end_date, b.total_price, b.status, b.created_at 
                FROM bookings b 
                JOIN users u ON b.user_id = u.id 
                JOIN cars c ON b.car_id = c.id 
                ORDER BY b.id DESC";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }
}

// 3. Handle Vehicle CRUD & Status POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Add New Vehicle
    if ($action === 'add_vehicle') {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = intval($_POST['year'] ?? 0);
        $price_per_day = floatval($_POST['price_per_day'] ?? 0);
        $image_url = trim($_POST['image_url'] ?? '');

        if (!empty($brand) && !empty($model) && $year > 1900 && $price_per_day > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO cars (brand, model, year, price_per_day, status, image_url) VALUES (?, ?, ?, ?, 'available', ?)");
                $stmt->execute([$brand, $model, $year, $price_per_day, $image_url]);
                $message = "Vehicle '{$brand} {$model}' added to asset inventory successfully.";
            } catch (PDOException $e) {
                $error = "Failed to add vehicle: " . $e->getMessage();
            }
        } else {
            $error = "Please provide valid vehicle specifications and daily rate.";
        }
    } 
    // Update Vehicle
    elseif ($action === 'edit_vehicle') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = intval($_POST['year'] ?? 0);
        $price_per_day = floatval($_POST['price_per_day'] ?? 0);
        $status = $_POST['status'] ?? 'available';

        if ($car_id > 0 && !empty($brand) && !empty($model) && $price_per_day > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cars SET brand = ?, model = ?, year = ?, price_per_day = ?, status = ? WHERE id = ?");
                $stmt->execute([$brand, $model, $year, $price_per_day, $status, $car_id]);
                $message = "Vehicle #{$car_id} updated successfully.";
            } catch (PDOException $e) {
                $error = "Failed to update vehicle: " . $e->getMessage();
            }
        } else {
            $error = "Invalid vehicle update data.";
        }
    } 
    // Quick Status Toggle (Maintenance / Available)
    elseif ($action === 'update_status') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';

        if ($car_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $stmt->execute([$status, $car_id]);
                $message = "Vehicle #{$car_id} status updated to " . strtoupper($status) . ".";
            } catch (PDOException $e) {
                $error = "Status update failed: " . $e->getMessage();
            }
        }
    } 
    // Delete Vehicle
    elseif ($action === 'delete_vehicle') {
        $car_id = intval($_POST['car_id'] ?? 0);
        if ($car_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM cars WHERE id = ?");
                $stmt->execute([$car_id]);
                $message = "Vehicle asset #{$car_id} removed from database.";
            } catch (PDOException $e) {
                $error = "Cannot delete vehicle with active booking references: " . $e->getMessage();
            }
        }
    }
}

// 4. Fetch Fleet, Sales Metrics, and Transactions Data
try {
    // Metrics
    $total_cars = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $available_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'available'")->fetchColumn();
    $maintenance_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'maintenance'")->fetchColumn();
    
    $total_revenue = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE status IN ('confirmed', 'completed')")->fetchColumn();
    $completed_tx = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed', 'completed')")->fetchColumn();
    $avg_tx_value = $completed_tx > 0 ? ($total_revenue / $completed_tx) : 0;

    // Fleet List
    $cars = $pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll();

    // Transactions List
    $tx_filter = $_GET['tx_status'] ?? 'all';
    $tx_sql = "
        SELECT b.id AS booking_id, b.start_date, b.end_date, b.total_price, b.status,
               u.name AS customer_name, u.email AS customer_email,
               c.brand, c.model, c.year
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN cars c ON b.car_id = c.id
    ";
    if ($tx_filter !== 'all') {
        $tx_sql .= " WHERE b.status = :status";
    }
    $tx_sql .= " ORDER BY b.id DESC";

    $tx_stmt = $pdo->prepare($tx_sql);
    if ($tx_filter !== 'all') {
        $tx_stmt->bindValue(':status', $tx_filter);
    }
    $tx_stmt->execute();
    $transactions = $tx_stmt->fetchAll();

    // Top Performing Vehicles by Revenue
    $top_vehicles_stmt = $pdo->query("
        SELECT c.brand, c.model, COUNT(b.id) AS total_rentals, COALESCE(SUM(b.total_price), 0) AS revenue_generated
        FROM cars c
        LEFT JOIN bookings b ON c.id = b.car_id AND b.status IN ('confirmed', 'completed')
        GROUP BY c.id
        ORDER BY revenue_generated DESC
        LIMIT 5
    ");
    $top_vehicles = $top_vehicles_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Fleet Operations Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #0d9488; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid #0d9488; }
        .stat-card.avail { border-bottom-color: #28a745; }
        .stat-card.maint { border-bottom-color: #dc3545; }
        .stat-card.rev { border-bottom-color: #0284c7; }
        .stat-card h3 { margin: 0 0 10px 0; color: #6c757d; font-size: 13px; text-transform: uppercase; }
        .stat-card .value { font-size: 26px; font-weight: bold; color: #212529; }

        /* Card Panels */
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
        .card-header h2 { margin: 0; font-size: 18px; color: #1a252f; }

        /* Form Inputs */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
        .btn-submit { background: #0d9488; color: white; border: none; padding: 10px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #0f766e; }
        
        .btn-export { background: #0284c7; color: white; text-decoration: none; padding: 8px 14px; border-radius: 4px; font-weight: bold; font-size: 13px; display: inline-block; }
        .btn-export:hover { background: #0369a1; }

        /* Filter Tabs */
        .filter-tabs { display: flex; gap: 8px; margin-bottom: 15px; }
        .tab-btn { padding: 6px 12px; border-radius: 4px; background: #e9ecef; color: #495057; text-decoration: none; font-size: 13px; font-weight: bold; }
        .tab-btn.active { background: #0d9488; color: white; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 14px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-available { background: #d4edda; color: #155724; }
        .badge-rented { background: #cce5ff; color: #004085; }
        .badge-maintenance { background: #f8d7da; color: #721c24; }

        .badge-confirmed { background: #cce5ff; color: #004085; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }

        .btn-edit { background: #ffc107; color: #212529; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }
        .btn-delete { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Fleet Management & Reporting</h1>
    <div class="user-info">
        <span>Fleet Manager: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Manager'); ?></strong></span>
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

    <!-- 1. Executive Metrics & Sales Reporting Summary -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Fleet Assets</h3>
            <div class="value"><?php echo number_format($total_cars); ?></div>
        </div>
        <div class="stat-card avail">
            <h3>Ready / Available</h3>
            <div class="value"><?php echo number_format($available_cars); ?></div>
        </div>
        <div class="stat-card maint">
            <h3>Under Maintenance</h3>
            <div class="value"><?php echo number_format($maintenance_cars); ?></div>
        </div>
        <div class="stat-card rev">
            <h3>Gross Sales Revenue</h3>
            <div class="value">$<?php echo number_format($total_revenue, 2); ?></div>
        </div>
        <div class="stat-card">
            <h3>Avg. Booking Value</h3>
            <div class="value">$<?php echo number_format($avg_tx_value, 2); ?></div>
        </div>
    </div>

    <!-- 2. Vehicle Asset Management - Add Asset -->
    <div class="card">
        <div class="card-header">
            <h2>Vehicle Asset Management - Add New Vehicle</h2>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_vehicle">
            <div class="form-grid">
                <div class="form-group">
                    <label>Brand / Make</label>
                    <input type="text" name="brand" placeholder="e.g., Toyota" required>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="model" placeholder="e.g., RAV4" required>
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="year" value="2024" required min="2000" max="2027">
                </div>
                <div class="form-group">
                    <label>Daily Rate ($)</label>
                    <input type="number" step="0.01" name="price_per_day" placeholder="65.00" required>
                </div>
                <div class="form-group">
                    <label>Image URL (Optional)</label>
                    <input type="url" name="image_url" placeholder="https://example.com/car.jpg">
                </div>
            </div>
            <button type="submit" class="btn-submit">Add Vehicle Asset</button>
        </form>
    </div>

    <!-- 3. Fleet Asset Inventory Table & Status Updates -->
    <div class="card">
        <div class="card-header">
            <h2>Fleet Asset Inventory</h2>
            <a href="fleet_dashboard.php?export=fleet_csv" class="btn-export">Export Fleet Report (CSV)</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Asset ID</th>
                    <th>Vehicle Description</th>
                    <th>Year</th>
                    <th>Daily Rate</th>
                    <th>Asset Status</th>
                    <th>Quick Maintenance Toggle</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <tr>
                        <td><strong>#<?php echo $car['id']; ?></strong></td>
                        <td><strong><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?></strong></td>
                        <td><?php echo $car['year']; ?></td>
                        <td>$<?php echo number_format($car['price_per_day'], 2); ?></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($car['status']); ?>">
                                <?php echo htmlspecialchars($car['status']); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="available" <?php if ($car['status'] === 'available') echo 'selected'; ?>>Available</option>
                                    <option value="rented" <?php if ($car['status'] === 'rented') echo 'selected'; ?>>Rented</option>
                                    <option value="maintenance" <?php if ($car['status'] === 'maintenance') echo 'selected'; ?>>Maintenance</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remove this vehicle asset permanently?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_vehicle">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <button type="submit" class="btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 4. Transaction Management & Sales Reporting -->
    <div class="card">
        <div class="card-header">
            <h2>Rental Transaction Management & Sales Ledger</h2>
            <a href="fleet_dashboard.php?export=transactions_csv" class="btn-export">Export Sales Report (CSV)</a>
        </div>

        <div class="filter-tabs">
            <a href="fleet_dashboard.php?tx_status=all" class="tab-btn <?php if ($tx_filter === 'all') echo 'active'; ?>">All Transactions</a>
            <a href="fleet_dashboard.php?tx_status=confirmed" class="tab-btn <?php if ($tx_filter === 'confirmed') echo 'active'; ?>">Confirmed / Active</a>
            <a href="fleet_dashboard.php?tx_status=completed" class="tab-btn <?php if ($tx_filter === 'completed') echo 'active'; ?>">Completed</a>
            <a href="fleet_dashboard.php?tx_status=cancelled" class="tab-btn <?php if ($tx_filter === 'cancelled') echo 'active'; ?>">Cancelled</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Tx ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Rental Period</th>
                    <th>Total Price</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td><strong>#<?php echo $tx['booking_id']; ?></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($tx['customer_name']); ?></strong><br>
                                <small style="color:#6c757d;"><?php echo htmlspecialchars($tx['customer_email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($tx['brand'] . ' ' . $tx['model']); ?></td>
                            <td><?php echo htmlspecialchars($tx['start_date'] . ' to ' . $tx['end_date']); ?></td>
                            <td><strong>$<?php echo number_format($tx['total_price'], 2); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($tx['status']); ?>">
                                    <?php echo htmlspecialchars($tx['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #6c757d; padding: 15px;">No transactions found for this filter.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 5. Vehicle Performance Reporting -->
    <div class="card">
        <div class="card-header">
            <h2>Top Performing Assets by Sales Revenue</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Vehicle Model</th>
                    <th>Total Completed Rentals</th>
                    <th>Gross Revenue Generated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_vehicles as $tv): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($tv['brand'] . ' ' . $tv['model']); ?></strong></td>
                        <td><?php echo number_format($tv['total_rentals']); ?> rental(s)</td>
                        <td><strong style="color:#0284c7;">$<?php echo number_format($tv['revenue_generated'], 2); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>