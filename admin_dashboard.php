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

// 2. Handle Admin Actions (e.g., Delete User or Change User Role)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_role') {
        $target_user_id = intval($_POST['user_id']);
        $new_role = $_POST['role'] ?? 'customer';
        
        $valid_roles = ['customer', 'admin', 'fleet_manager', 'branch_agent'];
        if (in_array($new_role, $valid_roles)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $target_user_id]);
                $message = "User role updated successfully.";
            } catch (PDOException $e) {
                $error = "Failed to update role: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_user') {
        $target_user_id = intval($_POST['user_id']);
        
        // Prevent admin from deleting themselves
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
}

// 3. Fetch Dashboard Metrics
try {
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_cars = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $active_bookings = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn();
    $total_revenue = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE status = 'confirmed'")->fetchColumn();

    // Fetch all users
    $users_stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id ASC");
    $users = $users_stmt->fetchAll();

    // Fetch car fleet overview
    $cars_stmt = $pdo->query("SELECT id, brand, model, year, price_per_day, status FROM cars ORDER BY id ASC");
    $cars = $cars_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Database query error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - System Administrator Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #1a252f; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .btn-logout:hover { background: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border-bottom: 4px solid #007bff; }
        .stat-card.cars { border-bottom-color: #28a745; }
        .stat-card.bookings { border-bottom-color: #ffc107; }
        .stat-card.revenue { border-bottom-color: #17a2b8; }
        .stat-card h3 { margin: 0 0 10px 0; color: #6c757d; font-size: 14px; text-transform: uppercase; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #212529; }

        /* Tables */
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        .card h2 { margin-top: 0; font-size: 18px; color: #1a252f; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .badge-admin { background: #cce5ff; color: #004085; }
        .badge-fleet_manager { background: #d4edda; color: #155724; }
        .badge-branch_agent { background: #fff3cd; color: #856404; }
        .badge-customer { background: #e2e3e5; color: #383d41; }

        .badge-available { background: #d4edda; color: #155724; }
        .badge-rented { background: #cce5ff; color: #004085; }
        .badge-maintenance { background: #f8d7da; color: #721c24; }

        select, button { padding: 6px 10px; border-radius: 4px; border: 1px solid #ced4da; font-size: 13px; }
        .btn-action { background: #007bff; color: white; border: none; cursor: pointer; }
        .btn-action:hover { background: #0056b3; }
        .btn-delete { background: #dc3545; color: white; border: none; cursor: pointer; }
        .btn-delete:hover { background: #bd2130; }
    </style>
</head>
<body>

<div class="header">
    <h1>DriveEase Admin Console</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></strong></span>
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

    <!-- System Overview Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>Registered Users</h3>
            <div class="value"><?php echo number_format($total_users); ?></div>
        </div>
        <div class="stat-card cars">
            <h3>Total Vehicles</h3>
            <div class="value"><?php echo number_format($total_cars); ?></div>
        </div>
        <div class="stat-card bookings">
            <h3>Active Bookings</h3>
            <div class="value"><?php echo number_format($active_bookings); ?></div>
        </div>
        <div class="stat-card revenue">
            <h3>Total Revenue</h3>
            <div class="value">$<?php echo number_format($total_revenue, 2); ?></div>
        </div>
    </div>

    <!-- User Management Table -->
    <div class="card">
        <h2>User Management & Access Control</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Change Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
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
                                <button type="submit" class="btn-action">Update</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            <?php else: ?>
                                <em>(Current User)</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Fleet Status Overview -->
    <div class="card">
        <h2>Fleet Overview</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle</th>
                    <th>Year</th>
                    <th>Daily Rate</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <tr>
                        <td><?php echo $car['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?></strong></td>
                        <td><?php echo $car['year']; ?></td>
                        <td>$<?php echo number_format($car['price_per_day'], 2); ?>/day</td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($car['status']); ?>">
                                <?php echo htmlspecialchars($car['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>