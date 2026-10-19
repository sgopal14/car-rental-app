<?php
ob_start();
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

$allowed_admin_roles = ['admin', 'agent', 'fleet_manager'];
$current_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');

if (!isset($_SESSION['user_id']) || !in_array($current_role, $allowed_admin_roles, true)) {
    header("Location: index.php");
    exit();
}

$message = '';
$error = '';

function table_exists($pdo, $table_name) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table_name));
        return $result && $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

$rental_table = table_exists($pdo, 'rentals') ? 'rentals' : 'bookings';
$has_logs_table = table_exists($pdo, 'audit_logs') || table_exists($pdo, 'logs');
$log_table = table_exists($pdo, 'audit_logs') ? 'audit_logs' : 'logs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_role') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_role = strtolower(trim($_POST['role'] ?? 'customer'));
        $valid_roles = ['customer', 'agent', 'fleet_manager', 'admin'];

        if ($new_role === 'user') { $new_role = 'customer'; }
        if (in_array($new_role, ['branch_agent', 'staff'], true)) { $new_role = 'agent'; }

        if ($target_user_id > 0 && in_array($new_role, $valid_roles, true)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
                $stmt->execute([
                    ':role' => $new_role,
                    ':id'   => $target_user_id
                ]);

                if ($target_user_id === $_SESSION['user_id']) {
                    $_SESSION['role']      = $new_role;
                    $_SESSION['user_role'] = $new_role;
                }

                log_audit_event($pdo, 'ROLE_UPDATED', $_SESSION['user_name'] ?? 'Admin', "Updated user #{$target_user_id} role to {$new_role}");

                $message = "User #" . $target_user_id . " role successfully updated to '" . strtoupper($new_role) . "'.";
            } catch (PDOException $e) {
                $error = "Failed to update user role: " . $e->getMessage();
            }
        } else {
            $error = "Invalid user ID or unrecognized role type selected.";
        }
    }
    elseif ($action === 'delete_user') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id === $_SESSION['user_id']) {
            $error = "You cannot delete your own active administrator account.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);

                log_audit_event($pdo, 'USER_DELETED', $_SESSION['user_name'] ?? 'Admin', "Deleted user account #{$target_user_id}");

                $message = "User deleted successfully.";
            } catch (PDOException $e) {
                $error = "Failed to delete user: " . $e->getMessage();
            }
        }
    }
    elseif ($action === 'add_vehicle') {
        $make = trim($_POST['brand'] ?? $_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $category = trim($_POST['category'] ?? 'Standard');
        $price = floatval($_POST['price_per_day'] ?? $_POST['daily_rate'] ?? 0);

        if (!empty($make) && !empty($model) && $price > 0) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM cars")->fetchAll(PDO::FETCH_COLUMN);
                $make_col = in_array('brand', $cols, true) ? 'brand' : 'make';
                $price_col = in_array('price_per_day', $cols, true) ? 'price_per_day' : 'daily_rate';

                $stmt = $pdo->prepare("INSERT INTO cars ({$make_col}, model, category, {$price_col}, status) VALUES (?, ?, ?, ?, 'available')");
                $stmt->execute([$make, $model, $category, $price]);

                log_audit_event($pdo, 'VEHICLE_ADDED', $_SESSION['user_name'] ?? 'Admin', "Added vehicle asset {$make} {$model}");

                $message = "Vehicle asset '{$make} {$model}' added to fleet successfully.";
            } catch (PDOException $e) {
                $error = "Failed to add vehicle: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all vehicle details correctly.";
        }
    }
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
    elseif ($action === 'process_booking_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $target_status = $_POST['target_status'] ?? '';

        if ($booking_id > 0) {
            try {
                $pdo->beginTransaction();
                
                $b_stmt = $pdo->prepare("UPDATE {$rental_table} SET status = ? WHERE id = ?");
                $b_stmt->execute([$target_status, $booking_id]);

                if ($car_id > 0) {
                    $car_new_status = ($target_status === 'confirmed') ? 'rented' : 'available';
                    $c_stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                    $c_stmt->execute([$car_new_status, $car_id]);
                }

                $pdo->commit();
                $message = "Transaction #{$booking_id} updated to " . strtoupper($target_status) . ".";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Failed to process booking transaction: " . $e->getMessage();
            }
        }
    }
}

$total_users = 0; $total_cars = 0; $available_cars = 0; $maintenance_cars = 0; $total_revenue = 0.00;
$users = []; $cars = []; $transactions = []; $audit_logs = [];

try {
    $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $available_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'available' OR status IS NULL")->fetchColumn();
    $maintenance_cars = (int)$pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'maintenance'")->fetchColumn();
    $total_revenue = (float)$pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM {$rental_table} WHERE status IN ('confirmed', 'completed', 'active')")->fetchColumn();

    $users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $cars = $pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $transactions = $pdo->query("
        SELECT r.*, u.email AS user_email, u.name AS user_name
        FROM {$rental_table} r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($has_logs_table) {
        $audit_logs = $pdo->query("SELECT * FROM {$log_table} ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}

$csrf_token = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Executive Master Control Panel</title>
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

        .tab-nav { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 3px solid #dee2e6; }
        .tab-btn { background: #e9ecef; color: #495057; border: none; padding: 12px 20px; border-radius: 6px 6px 0 0; font-weight: bold; font-size: 14px; cursor: pointer; transition: all 0.2s ease; }
        .tab-btn:hover { background: #ced4da; }
        .tab-btn.active { background: #007bff; color: white; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

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

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 13px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-admin { background: #cce5ff; color: #004085; }
        .badge-fleet_manager { background: #d4edda; color: #155724; }
        .badge-agent { background: #fff3cd; color: #856404; }
        .badge-customer { background: #e2e3e5; color: #383d41; }

        .btn-action { padding: 5px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: bold; cursor: pointer; }
        .btn-update { background: #007bff; color: white; }
        .btn-delete { background: #dc3545; color: white; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Master Control</h1>
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

    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('overview', this)">Overview & KPIs</button>
        <button class="tab-btn" onclick="switchTab('users', this)">User Management</button>
        <button class="tab-btn" onclick="switchTab('fleet', this)">Fleet Management</button>
        <button class="tab-btn" onclick="switchTab('rentals', this)">Branch Rental Desk</button>
        <button class="tab-btn" onclick="switchTab('audit', this)">Security & Audit Logs</button>
    </div>

    <!-- TAB 1: OVERVIEW -->
    <div id="overview" class="tab-content active">
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
    </div>

    <!-- TAB 2: USER MANAGEMENT -->
    <div id="users" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h2>User Management & Privilege Control</h2>
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
                    <?php foreach ($users as $u): ?>
                        <?php 
                            $disp_name = $u['name'] ?? $u['email'];
                            $raw_role  = strtolower($u['role'] ?? 'customer');
                            if ($raw_role === 'user') { $raw_role = 'customer'; }
                            if (in_array($raw_role, ['branch_agent', 'staff'], true)) { $raw_role = 'agent'; }
                        ?>
                        <tr>
                            <td>#<?php echo $u['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($disp_name); ?></strong></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($raw_role); ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($raw_role))); ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="display: flex; gap: 5px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <select name="role">
                                        <option value="customer" <?php if ($raw_role === 'customer') echo 'selected'; ?>>Customer</option>
                                        <option value="agent" <?php if ($raw_role === 'agent') echo 'selected'; ?>>Agent</option>
                                        <option value="fleet_manager" <?php if ($raw_role === 'fleet_manager') echo 'selected'; ?>>Fleet Manager</option>
                                        <option value="admin" <?php if ($raw_role === 'admin') echo 'selected'; ?>>Admin</option>
                                    </select>
                                    <button type="submit" class="btn-action btn-update">Update</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
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
    </div>

    <!-- TAB 3: FLEET MANAGEMENT -->
    <div id="fleet" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h2>Fleet Asset Management</h2>
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
                    <?php foreach ($cars as $car): ?>
                        <?php 
                            $brand = $car['brand'] ?? $car['make'] ?? 'Vehicle';
                            $model = $car['model'] ?? '';
                            $price = $car['price_per_day'] ?? $car['daily_rate'] ?? 0.00;
                            $status = strtolower($car['status'] ?? 'available');
                        ?>
                        <tr>
                            <td>#<?php echo $car['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars(trim($brand . ' ' . $model)); ?></strong></td>
                            <td><?php echo htmlspecialchars($car['category'] ?? 'Standard'); ?></td>
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
    </div>

    <!-- TAB 4: RENTALS DESK -->
    <div id="rentals" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h2>Branch Operations & Rental Transaction Desk</h2>
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
                        <?php foreach ($transactions as $tx): ?>
                            <?php 
                                $tx_status = strtolower($tx['status'] ?? 'confirmed');
                                $cust_info = $tx['user_name'] ?? $tx['user_email'] ?? ('User #' . ($tx['user_id'] ?? ''));
                            ?>
                            <tr>
                                <td><strong>#<?php echo $tx['id']; ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($cust_info); ?></strong></td>
                                <td><?php echo htmlspecialchars(($tx['start_date'] ?? 'N/A') . ' to ' . ($tx['end_date'] ?? 'N/A')); ?></td>
                                <td><strong>$<?php echo number_format((float)($tx['total_price'] ?? 0), 2); ?></strong></td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars($tx_status); ?>">
                                        <?php echo htmlspecialchars(strtoupper($tx_status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="process_booking_status">
                                        <input type="hidden" name="booking_id" value="<?php echo $tx['id']; ?>">
                                        <input type="hidden" name="car_id" value="<?php echo $tx['car_id'] ?? 0; ?>">
                                        <select name="target_status" onchange="this.form.submit()">
                                            <option value="confirmed" <?php if ($tx_status === 'confirmed') echo 'selected'; ?>>Confirmed</option>
                                            <option value="completed" <?php if ($tx_status === 'completed') echo 'selected'; ?>>Completed</option>
                                            <option value="cancelled" <?php if ($tx_status === 'cancelled') echo 'selected'; ?>>Cancelled</option>
                                        </select>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center; color:#6c757d;">No customer rental records currently present.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 5: AUDIT LOGS -->
    <div id="audit" class="tab-content">
        <div class="card">
            <div class="card-header">
                <h2>System Security & Audit Trail Logs</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Event / Action</th>
                        <th>User Context</th>
                        <th>Details</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($audit_logs)): ?>
                        <?php foreach ($audit_logs as $log): ?>
                            <tr>
                                <td>#<?php echo $log['id'] ?? 'N/A'; ?></td>
                                <td><strong><?php echo htmlspecialchars($log['event'] ?? $log['action'] ?? 'System Event'); ?></strong></td>
                                <td><?php echo htmlspecialchars($log['user_email'] ?? $log['username'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($log['details'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($log['created_at'] ?? $log['timestamp'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:#6c757d; padding: 30px;">
                                <em>No database audit records present.</em>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function switchTab(tabId, element) {
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(content => content.classList.remove('active'));

    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));

    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
}
</script>

</body>
</html>