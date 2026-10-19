<?php
ob_start();
session_start();
require_once 'db.php';

// Safe CSRF verification check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// 1. Authenticate Session & Role Access Control for Branch Agents
$allowed_agent_roles = ['agent', 'branch_agent', 'staff', 'admin'];
$current_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');

if (!isset($_SESSION['user_id']) || !in_array($current_role, $allowed_agent_roles, true)) {
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

// 2. Handle POST Actions (Processing Check-outs/Returns & Updating Vehicle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // BRANCH OPERATIONS: Update Rental Transaction Status
    if ($action === 'process_booking_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $car_id = intval($_POST['car_id'] ?? 0);
        $target_status = strtolower(trim($_POST['target_status'] ?? ''));

        if ($booking_id > 0 && in_array($target_status, ['confirmed', 'completed', 'cancelled'], true)) {
            try {
                $pdo->beginTransaction();
                
                // Update transaction status
                $b_stmt = $pdo->prepare("UPDATE {$rental_table} SET status = ? WHERE id = ?");
                $b_stmt->execute([$target_status, $booking_id]);

                // Automatically synchronize vehicle availability in database
                if ($car_id > 0) {
                    $car_new_status = ($target_status === 'confirmed') ? 'rented' : 'available';
                    $c_stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                    $c_stmt->execute([$car_new_status, $car_id]);
                }

                $pdo->commit();
                $message = "Transaction #" . $booking_id . " updated to '" . strtoupper($target_status) . "' successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = "Failed to update rental status: " . $e->getMessage();
            }
        }
    }
    // FLEET CONTROL: Update Vehicle Operational Status directly
    elseif ($action === 'update_car_status') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $status = strtolower(trim($_POST['status'] ?? 'available'));

        if ($car_id > 0 && in_array($status, ['available', 'rented', 'maintenance'], true)) {
            try {
                $stmt = $pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $stmt->execute([$status, $car_id]);
                $message = "Vehicle #" . $car_id . " status set to '" . strtoupper($status) . "'.";
            } catch (PDOException $e) {
                $error = "Failed to update vehicle status: " . $e->getMessage();
            }
        }
    }
}

// 3. Fetch Data for Branch Agent Desk
$transactions = [];
$cars = [];

try {
    // Transaction Query (Fixes SQL 1054 Error: Uses COALESCE on u.name and u.email instead of u.username)
    $transactions = $pdo->query("
        SELECT r.*, 
               COALESCE(u.name, u.email, 'Customer') AS customer_name, 
               u.email AS customer_email,
               c.id AS car_id, 
               COALESCE(c.brand, c.make, c.title, 'Vehicle') AS vehicle_brand, 
               c.model AS vehicle_model
        FROM {$rental_table} r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN cars c ON r.car_id = c.id
        ORDER BY r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fleet Query (Safe SELECT *)
    $cars = $pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}

$csrf_token = function_exists('get_csrf_token') 
    ? get_csrf_token() 
    : (function_exists('generate_csrf_token') 
        ? generate_csrf_token() 
        : ($_SESSION['csrf_token'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Branch Operations Agent Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; color: #333; }
        .navbar { background-color: #1a252f; color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn { background-color: #dc3545; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: bold; }
        .logout-btn:hover { background-color: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        .alert { padding: 12px 20px; border-radius: 5px; margin-bottom: 25px; font-weight: bold; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .card { background: #fff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 35px; }
        .card-title { font-size: 20px; font-weight: bold; color: #1a252f; margin-bottom: 15px; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background-color: #1a252f; color: white; font-weight: 600; }
        
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-confirmed, .badge-available { background-color: #28a745; color: white; }
        .badge-completed, .badge-rented { background-color: #007bff; color: white; }
        .badge-cancelled, .badge-maintenance { background-color: #dc3545; color: white; }

        select { padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; font-size: 13px; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Branch Agent Desk</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?></strong> (AGENT)</span>
        <a href="logout.php" class="logout-btn">Sign Out</a>
    </div>
</nav>

<div class="container">

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- 1. Customer Rental Transactions & Processing Desk -->
    <div class="card">
        <h2 class="card-title">Customer Rental Orders & Check-Out Desk</h2>
        <table>
            <thead>
                <tr>
                    <th>Tx ID</th>
                    <th>Customer Name</th>
                    <th>Email Address</th>
                    <th>Reserved Vehicle</th>
                    <th>Rental Dates</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Operations Control</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $tx): ?>
                        <?php 
                            $tx_status = strtolower($tx['status'] ?? $tx['booking_status'] ?? 'confirmed');
                            $veh_name = trim(($tx['vehicle_brand'] ?? 'Vehicle') . ' ' . ($tx['vehicle_model'] ?? ''));
                        ?>
                        <tr>
                            <td><strong>#<?php echo $tx['id']; ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($tx['customer_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($tx['customer_email'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($veh_name); ?></td>
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
                    <tr><td colspan="8" style="text-align:center; color:#6c757d;">No customer rental orders currently found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 2. Fleet Overview & Live Operational Status Desk -->
    <div class="card">
        <h2 class="card-title">Fleet Asset Overview & Availability Monitoring</h2>
        <table>
            <thead>
                <tr>
                    <th>Asset ID</th>
                    <th>Vehicle Description</th>
                    <th>Category</th>
                    <th>Daily Rate</th>
                    <th>Current Status</th>
                    <th>Update Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <?php 
                        $brand = $car['brand'] ?? $car['make'] ?? $car['title'] ?? 'Vehicle';
                        $model = $car['model'] ?? '';
                        $price = $car['price_per_day'] ?? $car['daily_rate'] ?? $car['price'] ?? 0.00;
                        $car_status = strtolower($car['status'] ?? 'available');
                    ?>
                    <tr>
                        <td>#<?php echo $car['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars(trim($brand . ' ' . $model)); ?></strong></td>
                        <td><?php echo htmlspecialchars($car['category'] ?? $car['type'] ?? 'Standard'); ?></td>
                        <td>$<?php echo number_format((float)$price, 2); ?> / day</td>
                        <td>
                            <span class="badge badge-<?php echo htmlspecialchars($car_status); ?>">
                                <?php echo htmlspecialchars(strtoupper($car_status)); ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_car_status">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="available" <?php if ($car_status === 'available') echo 'selected'; ?>>Available</option>
                                    <option value="rented" <?php if ($car_status === 'rented') echo 'selected'; ?>>Rented</option>
                                    <option value="maintenance" <?php if ($car_status === 'maintenance') echo 'selected'; ?>>Maintenance</option>
                                </select>
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