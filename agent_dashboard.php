<?php
session_start();
require_once 'db.php';

// Safe CSRF verification check for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// Authenticate agent/admin session
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Ensure the user has staff/admin privileges if roles are configured
if (isset($_SESSION['role']) && !in_array($_SESSION['role'], ['agent', 'admin', 'staff'])) {
    header("Location: index.php");
    exit();
}

$agent_name = $_SESSION['user_name'] ?? 'Agent';
$cars = [];
$rentals = [];
$error_msg = '';
$success_msg = '';

// Handle actions (e.g., updating rental status or removing vehicles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'update_status') {
        $rental_id = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
        $new_status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
        
        if ($rental_id && $new_status) {
            try {
                $u_stmt = $pdo->prepare("UPDATE rentals SET status = :status WHERE id = :id");
                $u_stmt->execute([':status' => $new_status, ':id' => $rental_id]);
                $success_msg = "Rental status updated successfully.";
            } catch (PDOException $e) {
                $error_msg = "Failed to update status: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

try {
    // 1. Fetch all fleet vehicles using SELECT * to avoid hardcoded column name mismatches
    $stmt = $pdo->prepare("SELECT * FROM cars ORDER BY id DESC");
    $stmt->execute();
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch all customer rentals using SELECT * joins
    $rental_query = "
        SELECT r.*, 
               c.*, 
               u.username, u.email
        FROM rentals r
        LEFT JOIN cars c ON r.car_id = c.id
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.created_at DESC
    ";
    
    $r_stmt = $pdo->prepare($rental_query);
    $r_stmt->execute();
    $rentals = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msg = "Database Error: " . htmlspecialchars($e->getMessage());
}

// Generate CSRF token for forms
$csrf_token = function_exists('generate_csrf_token') 
    ? generate_csrf_token() 
    : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Agent Management Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; color: #333; }
        .navbar { background-color: #1a252f; color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .navbar .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn { background-color: #dc3545; color: white; padding: 8px 16px; text-decoration: none; border-radius: 5px; font-size: 14px; font-weight: bold; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .header-title { margin: 30px 0 15px 0; color: #1a252f; }
        .alert-success { background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 25px; border: 1px solid #c3e6cb; font-weight: bold; }
        .alert-error { background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 25px; }
        
        /* Table Styles */
        .data-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 40px; }
        .data-table th, .data-table td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #eee; }
        .data-table th { background-color: #1a252f; color: white; font-weight: 600; }
        .status-badge { background-color: #007bff; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-confirmed { background-color: #28a745; }
        .status-cancelled { background-color: #dc3545; }
        .status-completed { background-color: #6c757d; }

        .select-status { padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; font-size: 14px; }
        .btn-update { background-color: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-update:hover { background-color: #218838; }
    </style>
</head>
<body>

<nav class="navbar">
    <h1>DriveEase Agent Portal</h1>
    <div class="user-info">
        <span>Logged in as: <strong><?php echo htmlspecialchars($agent_name); ?></strong></span>
        <a href="logout.php" class="logout-btn">Sign Out</a>
    </div>
</nav>

<div class="container">

    <?php if (!empty($success_msg)): ?>
        <div class="alert-success">✓ <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Section 1: Customer Rental Orders -->
    <h2 class="header-title">Customer Rental Orders</h2>
    <?php if (!empty($rentals)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rental ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Dates</th>
                    <th>Total Price</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rentals as $rental): ?>
                    <?php 
                        $vehicle_make = $rental['make'] ?? $rental['car_make'] ?? $rental['brand'] ?? $rental['title'] ?? 'Vehicle';
                        $vehicle_model = $rental['model'] ?? $rental['car_model'] ?? '';
                        $customer = $rental['username'] ?? $rental['email'] ?? ('User #' . $rental['user_id']);
                        $status = strtolower($rental['status'] ?? 'confirmed');
                    ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($rental['id']); ?></td>
                        <td><strong><?php echo htmlspecialchars($customer); ?></strong></td>
                        <td><?php echo htmlspecialchars(trim($vehicle_make . ' ' . $vehicle_model)); ?></td>
                        <td><?php echo htmlspecialchars($rental['start_date'] . ' to ' . $rental['end_date']); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format((float)($rental['total_price'] ?? 0), 2)); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($status); ?>">
                                <?php echo htmlspecialchars(ucfirst($status)); ?>
                            </span>
                        </td>
                        <td>
                            <form action="agent_dashboard.php" method="POST" style="display: flex; gap: 8px; align-items: center;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="rental_id" value="<?php echo htmlspecialchars($rental['id']); ?>">
                                
                                <select name="status" class="select-status">
                                    <option value="confirmed" <?php echo $status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                                <button type="submit" class="btn-update">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="background: #fff; padding: 20px; border-radius: 8px; color: #6c757d; margin-bottom: 40px;">
            No customer rental requests currently found in the system.
        </p>
    <?php endif; ?>

    <!-- Section 2: Managed Fleet Overview -->
    <h2 class="header-title">Managed Vehicle Fleet</h2>
    <?php if (!empty($cars)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Car ID</th>
                    <th>Vehicle Name</th>
                    <th>Category</th>
                    <th>Daily Rate</th>
                    <th>Transmission</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                    <?php 
                        $make = $car['make'] ?? $car['car_make'] ?? $car['brand'] ?? $car['title'] ?? 'Vehicle';
                        $model = $car['model'] ?? $car['car_model'] ?? '';
                        $price = $car['price_per_day'] ?? $car['daily_rate'] ?? $car['price'] ?? $car['rate'] ?? '0.00';
                    ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($car['id']); ?></td>
                        <td><strong><?php echo htmlspecialchars(trim($make . ' ' . $model)); ?></strong></td>
                        <td><?php echo htmlspecialchars($car['category'] ?? $car['type'] ?? 'Standard'); ?></td>
                        <td>$<?php echo htmlspecialchars(number_format((float)$price, 2)); ?> / day</td>
                        <td><?php echo htmlspecialchars($car['transmission'] ?? 'Automatic'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="background: #fff; padding: 20px; border-radius: 8px; color: #6c757d; margin-bottom: 40px;">
            No vehicles currently registered in the database catalog.
        </p>
    <?php endif; ?>

</div>

</body>
</html>