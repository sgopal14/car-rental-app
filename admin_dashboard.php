<?php
ob_start();
session_start();
require_once 'db.php';

// Safe CSRF verification check for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// 1. Session & Role Access Control
$allowed_admin_roles = ['admin', 'agent', 'branch_agent', 'fleet_manager', 'staff'];
$current_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || !in_array($current_role, $allowed_admin_roles, true)) {
    header("Location: index.php");
    exit();
}

$message = '';
$error = '';

// Helper function to check if a table exists in MySQL
function table_exists($pdo, $table_name) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table_name));
        return $result && $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Detect whether system uses 'rentals' or 'bookings' table
$rental_table = table_exists($pdo, 'rentals') ? 'rentals' : 'bookings';
$has_logs_table = table_exists($pdo, 'audit_logs') || table_exists($pdo, 'logs') || table_exists($pdo, 'security_logs');
$log_table = table_exists($pdo, 'audit_logs') ? 'audit_logs' : (table_exists($pdo, 'logs') ? 'logs' : 'security_logs');

// 2. Handle CSV Report Exports
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'fleet_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="admin_fleet_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Car ID', 'Vehicle Details', 'Daily Rate ($)', 'Status']);
        
        $stmt =$pdo->query("SELECT * FROM cars ORDER BY id ASC");
        while ($row =$stmt->fetch(PDO::FETCH_ASSOC)) {
            $make  =$row['brand'] ?? $row['make'] ?? $row['title'] ?? 'Vehicle';
            $model =$row['model'] ?? '';
            $rate  =$row['price_per_day'] ?? $row['daily_rate'] ?? $row['price'] ?? 0;
            fputcsv($output, [$row['id'], trim($make . ' ' .$model), $rate,$row['status'] ?? 'available']);
        }
        fclose($output);
        exit();
    } elseif ($export_type === 'sales_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="admin_sales_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Booking ID', 'User ID', 'Start Date', 'End Date', 'Total Price ($)', 'Status']);
        
        $sql = "SELECT id, user_id, start_date, end_date, total_price, status FROM {$rental_table} ORDER BY id DESC";
        $stmt = $pdo->query($sql);
        while ($row =$stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output,$row);
        }
        fclose($output);
        exit();
    }
}

// 3. Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action =$_POST['action'];

    // USER MANAGEMENT: Update Role
    if ($action === 'update_role') {$target_user_id = intval($_POST['user_id'] ?? 0);$new_role = $_POST['role'] ?? 'customer';$valid_roles = ['customer', 'admin', 'fleet_manager', 'branch_agent', 'agent', 'staff'];

        if (in_array($new_role,$valid_roles, true)) {
            try {
                $stmt =$pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $target_user_id]);$message = "User role updated successfully to " . strtoupper($new_role) . ".";
            } catch (PDOException $e) {$error = "Failed to update user role: " . $e->getMessage();
            }
        }
    }
    // USER MANAGEMENT: Delete User
    elseif ($action === 'delete_user') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id === $_SESSION['user_id']) {$error = "You cannot delete your own active administrator account.";
        } else {
            try {
                $stmt =$pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);$message = "User deleted successfully.";
            } catch (PDOException $e) {$error = "Failed to delete user: " . $e->getMessage();
            }
        }
    }
    // FLEET MANAGEMENT: Add Vehicle Asset
    elseif ($action === 'add_vehicle') {
        $make = trim($_POST['brand'] ?? $_POST['make'] ?? '');$model = trim($_POST['model'] ?? '');$category = trim($_POST['category'] ?? 'Standard');$price = floatval($_POST['price_per_day'] ?? $_POST['daily_rate'] ?? 0);

        if (!empty($make) && !empty($model) &&$price > 0) {
            try {
                $cols =$pdo->query("SHOW COLUMNS FROM cars")->fetchAll(PDO::FETCH_COLUMN);
                $make_col = in_array('brand',$cols, true) ? 'brand' : (in_array('make', $cols, true) ? 'make' : 'brand');$price_col = in_array('price_per_day', $cols, true) ? 'price_per_day' : (in_array('daily_rate',$cols, true) ? 'daily_rate' : 'price');

                $stmt =$pdo->prepare("INSERT INTO cars ({$make_col}, model, category, {$price_col}, status) VALUES (?, ?, ?, ?, 'available')");
                $stmt->execute([$make, $model,$category, $price]);$message = "Vehicle asset '{$make} {$model}' added to fleet successfully.";
            } catch (PDOException $e) {$error = "Failed to add vehicle: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all vehicle details correctly.";
        }
    }
    // FLEET MANAGEMENT: Update Vehicle Status
    elseif ($action === 'update_car_status') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $status =$_POST['status'] ?? 'available';
        if ($car_id > 0) {
            try {
                $stmt =$pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $stmt->execute([$status,$car_id]);
                $message = "Vehicle #{$car_id} status updated to " . strtoupper($status) . ".";
            } catch (PDOException $e) {$error = "Failed to update vehicle status: " . $e->getMessage();
            }
        }
    }
    // FLEET MANAGEMENT: Delete Vehicle
    elseif ($action === 'delete_vehicle') {
        $car_id = intval($_POST['car_id'] ?? 0);
        if ($car_id > 0) {
            try {
                $stmt =$pdo->prepare("DELETE FROM cars WHERE id = ?");
                $stmt->execute([$car_id]);
                $message = "Vehicle #{$car_id} removed from fleet.";
            } catch (PDOException $e) {$error = "Cannot delete vehicle associated with active bookings: " . $e->getMessage();
            }
        }
    }
    // BRANCH OPERATIONS: Process Check-out or Return
    elseif ($action === 'process_booking_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $target_status =$_POST['target_status'] ?? '';

        if ($booking_id > 0) {
            try {
                $pdo->beginTransaction();$b_stmt = $pdo->prepare("UPDATE {$rental_table} SET status = ? WHERE id = ?");
                $b_stmt->execute([$target_status,$booking_id]);

                if ($car_id > 0) {
                    $car_new_status = ($target_status === 'confirmed') ? 'rented' : 'available';
                    $c_stmt =$pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                    $c_stmt->execute([$car_new_status,$car_id]);
                }

                $pdo->commit();
                $message = "Transaction #{$booking_id} updated to " . strtoupper($target_status) . ".";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }$error = "Failed to process booking transaction: " . $e->getMessage();
            }
        }
    }
}

// 4. Fetch All Comprehensive Admin Data Safely
$total_users = 0; $total_cars = 0; $available_cars = 0; $maintenance_cars = 0; $total_revenue = 0.00;
$users = [];$cars = []; $transactions = [];$audit_logs = [];

try {
    // Executive Metrics
    $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $available_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'available' OR status IS NULL")->fetchColumn();
    $maintenance_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'maintenance'")->fetchColumn();
    $total_revenue = (float)$pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM {$rental_table} WHERE status IN ('confirmed', 'completed', 'active')")->fetchColumn();

    // User Records
    $users =$pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fleet Records (Safe SELECT *)
    $cars =$pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Transaction Records (Queries rentals and users directly without column-name dependencies)
    $transactions =$pdo->query("
        SELECT r.*, u.email AS user_email, u.name AS user_name
        FROM {$rental_table} r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Security / Audit Logs if table exists
    if ($has_logs_table) {$audit_logs = $pdo->query("SELECT * FROM {$log_table} ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {$error = "Database Notification: " . $e->getMessage();
}

$csrf_token = function_exists('get_csrf_token') ? get_csrf_token() : ($_SESSION['csrf_token'] ?? '');
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

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid #007bff; }
        .stat-card.cars { border-bottom-color: #28a745; }
        .stat-card.maint { border-bottom-color: #dc3545; }
        .stat-card.rev { border-bottom-color: #17a2b8; }
        .stat-card h3 { margin: 0 0 10px 0; color: #6c757d; font-size: 12px; text-transform: uppercase; }
        .stat-card .value { font-size: 24px; font-weight: bold; color: #212529; }

        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
        .card-header h2 { margin: 0; font-size: 18px; color: #1a252f; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
        .btn-submit { background: #007bff; color: white; border: none; padding: 10px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #0056b3; }
        
        .btn-export { background: #17a2b8; color: white; text-decoration: none; padding: 8px 14px; border-radius: 4px; font-weight: bold; font-size: 13px; display: inline-block; }
        .btn-export:hover { background: #138496; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 13px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-admin { background: #cce5ff; color: #004085; }
        .badge-fleet_manager { background: #d4edda; color: #155724; }
        .badge-branch_agent, .badge-agent { background: #fff3cd; color: #856404; }
        .badge-customer { background: #e2e3e5; color: #383d41; }

        .btn-action { padding: 5px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: bold; cursor: pointer; }
        .btn-update { background: #007bff; color: white; }
        .btn-delete { background: #dc3545; color: white; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Executive Master Control</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?></strong> (<?php echo htmlspecialchars(strtoupper($current_role)); ?>)</span>
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
            <h3>Total Fleet Assets</h3>
            <div class="value"><?php echo number_format($total_cars); ?></div>
        </div>
        <div class="stat-card">
            <h3>Available Vehicles</h3>
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

    <!-- 2. User Management & Role Control Module -->
    <div class="card">
        <div class="card-header">
            <h2>User Management & Access Control</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User / Name</th>
                    <th>Email Address</th>
                    <th>Current Role</th>
                    <th>Change Privilege</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as$u): ?>
                    <?php 
                        $disp_name =$u['name'] ?? $u['username'] ?? $u['email'];
                        $role_name = strtolower($u['role'] ?? 'customer');
                    ?>
                    <tr>
                        <td>#<?php echo $u['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($disp_name); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($role_name); ?>">
                                <?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($role_name))); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display: flex; gap: 5px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <select name="role">
                                    <option value="customer" <?php if ($role_name === 'customer') echo 'selected'; ?>>Customer</option>
                                    <option value="admin" <?php if ($role_name === 'admin') echo 'selected'; ?>>Admin</option>
                                    <option value="fleet_manager" <?php if ($role_name === 'fleet_manager') echo 'selected'; ?>>Fleet Manager</option>
                                    <option value="branch_agent" <?php if (in_array($role_name, ['branch_agent', 'agent'])) echo 'selected'; ?>>Branch Agent</option>
                                </select>
                                <button type="submit" class="btn-action btn-update">Update</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($u['id'] !==$_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="return confirm('Delete this user account?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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

    <!-- 3. Fleet Asset Management Module -->
    <div class="card">
        <div class="card-header">
            <h2>Fleet Asset Management - Add & Control Vehicles</h2>
            <a href="admin_dashboard.php?export=fleet_csv" class="btn-export">Export Fleet CSV</a>
        </div>

        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                    <label>Category</label>
                    <select name="category">
                        <option value="SUV">SUV</option>
                        <option value="Sedan">Sedan</option>
                        <option value="Luxury">Luxury</option>
                        <option value="Economy">Economy</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Daily Rate ($)</label>
                    <input type="number" step="0.01" name="price_per_day" placeholder="65.00" required>
                </div>
            </div>
            <button type="submit" class="btn-submit">+ Add Vehicle Asset</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Asset ID</th>
                    <th>Vehicle Details</th>
                    <th>Category</th>
                    <th>Daily Rate</th>
                    <th>Status</th>
                    <th>Status Control</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as$car): ?>
                    <?php 
                        $brand =$car['brand'] ?? $car['make'] ?? $car['title'] ?? 'Vehicle';
                        $model =$car['model'] ?? '';
                        $price =$car['price_per_day'] ?? $car['daily_rate'] ?? $car['price'] ?? 0.00;
                        $status = strtolower($car['status'] ?? 'available');
                    ?>
                    <tr>
                        <td>#<?php echo $car['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars(trim($brand . ' ' .$model)); ?></strong></td>
                        <td><?php echo htmlspecialchars($car['category'] ?? $car['type'] ?? 'Standard'); ?></td>
                        <td>$<?php echo number_format((float)$price, 2); ?></td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($status); ?>">
                                <?php echo htmlspecialchars(strtoupper($status)); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_car_status">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="available" <?php if ($status === 'available') echo 'selected'; ?>>Available</option>
                                    <option value="rented" <?php if ($status === 'rented') echo 'selected'; ?>>Rented</option>
                                    <option value="maintenance" <?php if ($status === 'maintenance') echo 'selected'; ?>>Maintenance</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remove vehicle asset from fleet?');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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

    <!-- 4. Branch Operations & Rental Transaction Desk -->
    <div class="card">
        <div class="card-header">
            <h2>Branch Operations & Rental Desk</h2>
            <a href="admin_dashboard.php?export=sales_csv" class="btn-export">Export Sales CSV</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Tx ID</th>
                    <th>Customer</th>
                    <th>Rental Dates</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Status Control</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as$tx): ?>
                        <?php 
                            $tx_status