<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// Safe CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Unified Role Access Control
$allowed_admin_roles = ['admin', 'agent', 'branch_agent', 'fleet_manager', 'staff'];
$current_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || !in_array($current_role, $allowed_admin_roles, true)) {
    header("Location: index.php");
    exit();
}

$message = '';
$error = '';

// Handle CSV Report Exports
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

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_role') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_role = $_POST['role'] ?? 'customer';
        $valid_roles = ['customer', 'admin', 'fleet_manager', 'branch_agent', 'agent', 'staff'];

        if (in_array($new_role, $valid_roles, true)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $target_user_id]);
                $message = "User role updated successfully to " . strtoupper($new_role) . ".";
            } catch (PDOException $e) {
                $error = "Failed to update user role: " . $e->getMessage();
            }
        }
    }
}

try {
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_cars = $pdo->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $available_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'available'")->fetchColumn();
    $maintenance_cars = $pdo->query("SELECT COUNT(*) FROM cars WHERE status = 'maintenance'")->fetchColumn();
    $total_revenue = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE status IN ('confirmed', 'completed')")->fetchColumn();

    $users = $pdo->query("SELECT id, COALESCE(name, username, email) AS name, email, role, created_at FROM users ORDER BY id ASC")->fetchAll();
    $cars = $pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll();

} catch (PDOException $e) {
    $error = "Database query error: " . $e->getMessage();
}

$csrf_token = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Master Control Panel</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 0; color: #333; }
        .header { background: #1a252f; color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .header .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: #dc3545; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .container { max-width: 1250px; margin: 30px auto; padding: 0 20px; }
        .alert { padding: 12px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; font-size: 13px; }
        th { background-color: #f8f9fa; color: #495057; font-weight: 600; }
        .btn-update { background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
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

    <div class="card">
        <h2>User Management & Privilege Control</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Current Role</th>
                    <th>Change Role</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>#<?php echo $u['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><strong><?php echo htmlspecialchars(str_replace('_', ' ', strtoupper($u['role']))); ?></strong></td>
                        <td>
                            <form method="POST" style="display: flex; gap: 5px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_role">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <select name="role">
                                    <option value="customer" <?php if ($u['role'] === 'customer') echo 'selected'; ?>>Customer</option>
                                    <option value="admin" <?php if ($u['role'] === 'admin') echo 'selected'; ?>>Admin</option>
                                    <option value="branch_agent" <?php if (in_array($u['role'], ['branch_agent', 'agent'])) echo 'selected'; ?>>Branch Agent</option>
                                    <option value="fleet_manager" <?php if ($u['role'] === 'fleet_manager') echo 'selected'; ?>>Fleet Manager</option>
                                </select>
                                <button type="submit" class="btn-update">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>