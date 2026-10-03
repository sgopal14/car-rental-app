<?php
session_start();
require_once 'db.php';

// 1. Strict Session & Role Validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$message = '';
$error = '';

// 2. Handle CSV Report Exports
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'fleet_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="admin_fleet_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Car ID', 'Brand', 'Model', 'Year', 'Daily Rate ($)', 'Status']);
        
        $stmt = $pdo->query("SELECT id, brand, model, year, price_per_day, status FROM cars ORDER BY id ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    } elseif ($export_type === 'sales_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="admin_sales_report_' . date('Y-m-d') . '.csv"');
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

// 3. Handle Admin Actions (User Management, Fleet CRUD, Branch Operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // USER MANAGEMENT: Update Role
    if ($action === 'update_role') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_role = $_POST['role'] ?? 'customer';
        $valid_roles = ['customer', 'admin', 'fleet_manager', 'branch_agent'];

        if (in_array($new_role, $valid_roles)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $target_user_id]);
                $message = "User role updated successfully.";
            } catch (PDOException $e) {
                $error = "Failed to update user role: " . $e->getMessage();
            }
        }
    }
    // USER MANAGEMENT: Delete User
    elseif ($action === 'delete_user') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id === $_SESSION['user_id']) {
            $error = "You cannot delete your own active administrator account.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $message = "User deleted successfully.";
            } catch (PDOException $e) {
                $error = "Failed to delete user: " . $e->getMessage();
            }
        }
    }
    // FLEET MANAGEMENT: Add Vehicle Asset
    elseif ($action === 'add_vehicle') {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = intval($_POST['year'] ?? 0);
        $price_per_day = floatval($_POST['price_per_day'] ?? 0);
        $image_url = trim($_POST['image_url'] ?? '');

        if (!empty($brand) && !empty($model) && $year > 1900 && $price_per_day > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO cars (brand, model, year, price_per_day, status, image_url) VALUES (?, ?, ?, ?, 'available', ?)");
                $stmt->execute([$brand, $model, $year, $price_per_day, $image_url]);
                $message = "Vehicle asset '{$brand} {$model}' added successfully.";
            } catch (PDOException $e) {
                $error = "Failed to add vehicle: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all vehicle details correctly.";
        }
    }
    // FLEET MANAGEMENT: Update Vehicle Status
    elseif ($action === 'update_car_status') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        if ($car_id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $stmt->execute([$status, $car_id]);
                $message = "Vehicle #{$car_id} status updated to " . strtoupper($status) . ".";
            } catch (PDOException $e) {
                $error = "Failed to update vehicle status: " . $e->getMessage();
            }
        }
    }
    // FLEET MANAGEMENT: Delete Vehicle Asset
    elseif ($action === 'delete_vehicle') {
        $car_id = intval($_POST['car_id'] ?? 0);
        if ($car_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM cars WHERE id = ?");
                $stmt->execute([$car_id]);
                $message = "Vehicle #{$car_id} removed from fleet.";
            } catch (PDOException $e) {
                $error = "Cannot delete vehicle associated with active bookings: " . $e->getMessage();
            }
        }
    }
    // BRANCH OPERATIONS: Process Check-out or Check-in
    elseif ($action === 'process_booking_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $target_status = $_POST['target_status'] ?? '';

        if ($booking_id > 0 && $car_id > 0) {
            try {
                $pdo->beginTransaction();
                
                $b_stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $b_stmt->execute([$target_status, $booking_id]);

                $car_new_status = ($target_status === 'confirmed') ? 'rented' : (($target_status === 'completed' || $target_status === 'cancelled') ? 'available' : 'available');
                $c_stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $c_stmt->execute([$car_new_status, $car_id]);

                $pdo->commit();
                $message = "Transaction #{$booking_id} updated to " . strtoupper($target_status) . ".";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Failed to process booking transaction: " . $e->getMessage();
            }
        }
    }
}

// 4. Fetch All Comprehensive Admin Data
try {
    // Executive Metrics
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_cars = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $available_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'available'")->fetchColumn();
    $maintenance_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'maintenance'")->fetchColumn();
    $total_revenue = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE status IN ('confirmed', 'completed')")->fetchColumn();
    $active_bookings = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn();

    // User Records
    $users = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id ASC")->fetchAll();

    // Fleet Records
    $cars = $pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll();

    // Transaction Records
    $transactions = $pdo->query("
        SELECT b.id AS booking_id, b.start_date, b.end_date, b.total_price, b.status AS booking_status,
               u.name AS customer_name, u.email AS customer_email,
               c.id AS car_id, c.brand, c.model, c.year
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN cars c ON b.car_id = c.id
        ORDER BY b.id DESC
    ")->fetchAll();

    // Sales Performance Reporting
    $top_vehicles = $pdo->query("
        SELECT c.brand, c.model, COUNT(b.id) AS total_rentals, COALESCE(SUM(b.total_price), 0) AS revenue_generated
        FROM cars c
        LEFT JOIN bookings b ON c.id = b.car_id AND b.status IN ('confirmed', 'completed')
        GROUP BY c.id
        ORDER BY revenue_generated DESC
        LIMIT 5
    ")->fetchAll();

} catch (PDOException $e) {
    $error = "Database query error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Executive Administration Master Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #1a252f; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1250px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Executive Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid #007bff; }
        .stat-card.cars { border-bottom-color: #28a745; }
        .stat-card.maint { border-bottom-color: #dc3545; }
        .stat-card.rev { border-bottom-color: #17a2b8; }
        .stat-card h3 { margin: 0 0 10px 0; color: #6c757d; font-size: 12px; text-transform: uppercase; }
        .stat-card .value { font-size: 24px; font-weight: bold; color: #212529; }

        /* Card Sections */
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
        .card-header h2 { margin: 0; font-size: 18px; color: #1a252f; }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
        .btn-submit { background: #007bff; color: white; border: none; padding: 10px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #0056b3; }
        
        .btn-export { background: #17a2b8; color: white; text-decoration: none; padding: 8px 14px; border-radius: 4px; font-weight: bold; font-size: 13px; display: inline-block; }
        .btn-export:hover { background: #138496; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 13px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-admin { background: #cce5ff; color: #004085; }
        .badge-fleet_manager { background: #d4edda; color: #155724; }
        .badge-branch_agent { background: #fff3cd; color: #856404; }
        .badge-customer { background: #e2e3e5; color: #383d41; }

        .badge-available { background: #d4edda; color: #155724; }
        .badge-rented { background: #cce5ff; color: #004085; }
        .badge-maintenance { background: #f8d7da; color: #721c24; }

        .badge-confirmed { background: #cce5ff; color: #004085; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }

        .btn-action { padding: 5px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: bold; cursor: pointer; }
        .btn-update { background: #007bff; color: white; }
        .btn-delete { background: #dc3545; color: white; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Executive Master Control</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?></strong> (Admin)</span>
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

    <!-- 1. Executive Key Performance Indicators (KPIs) -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Users</h3>
            <div class="value"><?php echo number_format($total_users); ?></div>
        </div>
        <div class="stat-card cars">
            <h3>Total Fleet</h3>
            <div class="value"><?php echo number_format($total_cars); ?></div>
        </div>
        <div class="stat-card">
            <h3>Available Cars</h3>
            <div class="value"><?php echo number_format($available_cars); ?></div>
        </div>
        <div class="stat-card maint">
            <h3>In Maintenance</h3>
            <div class="value"><?php echo number_format($maintenance_cars); ?></div>
        </div>
        <div class="stat-card rev">
            <h3>Gross Revenue</h3>
            <div class="value">$<?php echo number_format($total_revenue, 2); ?></div>
        </div>
    </div>

    <!-- 2. Full User Management Module -->
    <div class="card">
        <div class="card-header">
            <h2>User Management & Privilege Control</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Current Role</th>
                    <th>Change Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?php echo $u['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($u['role']); ?>">
                                <?php echo htmlspecialchars(str_replace('_', ' ', $u['role'])); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display: flex; gap: 5px;">
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <select name="role">
                                    <option value="customer" <?php if ($u['role'] === 'customer') echo 'selected'; ?>>Customer</option>
                                    <option value="admin" <?php if ($u['role'] === 'admin') echo 'selected'; ?>>Admin</option>
                                    <option value="fleet_manager" <?php if ($u['role'] === 'fleet_manager') echo 'selected'; ?>>Fleet Manager</option>
                                    <option value="branch_agent" <?php if ($u['role'] === 'branch_agent') echo 'selected'; ?>>Branch Agent</option>
                                </select>
                                <button type="submit" class="btn-action btn-update">Update</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="return confirm('Delete this user account?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn-action btn-delete">Delete</button>
                                </form>
                            <?php else: ?>
                                <em style="color:#6c757d;">(Active Session)</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 3. Fleet Manager Capability: Add & Manage Vehicle Assets -->
    <div class="card">
        <div class="card-header">
            <h2>Fleet Asset Management - Add & Control Inventory</h2>
            <a href="admin_dashboard.php?export=fleet_csv" class="btn-export">Export Fleet CSV</a>
        </div>

        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="add_vehicle">
            <div class="form-grid">
                <div class="form-group">
                    <label>Brand / Make</label>
                    <input type="text" name="brand" placeholder="e.g., Honda" required>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="model" placeholder="e.g., Civic" required>
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <input type="number" name="year" value="2024" required min="2000" max="2027">
                </div>
                <div class="form-group">
                    <label>Daily Rate ($)</label>
                    <input type="number" step="0.01" name="price_per_day" placeholder="55.00" required>
                </div>
                <div class="form-group">
                    <label>Image URL (Optional)</label>
                    <input type="url" name="image_url" placeholder="https://example.com/car.jpg">
                </div>
            </div>
            <button type="submit" class="btn-submit">Add New Vehicle Asset</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Asset ID</th>
                    <th>Vehicle</th>
                    <th>Year</th>
                    <th>Daily Rate</th>
                    <th>Status</th>
                    <th>Status Control</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <tr>
                        <td>#<?php echo $car['id']; ?></td>
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
                                <input type="hidden" name="action" value="update_car_status">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="available" <?php if ($car['status'] === 'available') echo 'selected'; ?>>Available</option>
                                    <option value="rented" <?php if ($car['status'] === 'rented') echo 'selected'; ?>>Rented</option>
                                    <option value="maintenance" <?php if ($car['status'] === 'maintenance') echo 'selected'; ?>>Maintenance</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remove vehicle asset from fleet?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_vehicle">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <button type="submit" class="btn-action btn-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 4. Branch Manager Capability: Transaction Management & Desk Control -->
    <div class="card">
        <div class="card-header">
            <h2>Branch Operations & Rental Transaction Desk</h2>
            <a href="admin_dashboard.php?export=sales_csv" class="btn-export">Export Sales CSV</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Tx ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Rental Dates</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Operations Control</th>
                </tr>
            </thead>
            <tbody>
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
                            <span class="badge badge-<?php echo htmlspecialchars($tx['booking_status']); ?>">
                                <?php echo htmlspecialchars($tx['booking_status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($tx['booking_status'] === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="process_booking_status">
                                    <input type="hidden" name="booking_id" value="<?php echo $tx['booking_id']; ?>">
                                    <input type="hidden" name="car_id" value="<?php echo $tx['car_id']; ?>">
                                    <input type="hidden" name="target_status" value="confirmed">
                                    <button type="submit" class="btn-action btn-update">Check-Out (Pick-Up)</button>
                                </form>
                            <?php elseif ($tx['booking_status'] === 'confirmed'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="process_booking_status">
                                    <input type="hidden" name="booking_id" value="<?php echo $tx['booking_id']; ?>">
                                    <input type="hidden" name="car_id" value="<?php echo $tx['car_id']; ?>">
                                    <input type="hidden" name="target_status" value="completed">
                                    <button type="submit" class="btn-action" style="background:#28a745; color:white;">Process Return</button>
                                </form>
                            <?php else: ?>
                                <em style="color:#6c757d; font-size:11px;">Completed</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 5. Sales & Revenue Financial Reporting -->
    <div class="card">
        <div class="card-header">
            <h2>Top Revenue-Generating Assets Report</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Vehicle Description</th>
                    <th>Completed Rentals</th>
                    <th>Gross Revenue Contributed</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_vehicles as $tv): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($tv['brand'] . ' ' . $tv['model']); ?></strong></td>
                        <td><?php echo number_format($tv['total_rentals']); ?> rental(s)</td>
                        <td><strong style="color:#28a745;">$<?php echo number_format($tv['revenue_generated'], 2); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>