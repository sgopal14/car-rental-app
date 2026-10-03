<?php
session_start();
require_once 'db.php';

// Strict Admin Access Control Guard
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';
$editCar = null;
$activeTab = $_GET['tab'] ?? 'view';

// 1. Handle Adding a New System User / Admin Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $email = trim($_POST['user_email'] ?? '');
    $password = $_POST['user_password'] ?? '';
    $role = $_POST['user_role'] ?? 'user';

    // Email Format Validation
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address format (e.g., name@domain.com).';
    } 
    // Strict Alphanumeric Password Check: Min 8 chars, ONLY letters and digits, at least 1 letter & 1 digit
    elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters long, contain ONLY letters and numbers, and include at least one letter and one number.';
    } 
    else {
        // Check for existing email address
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $checkStmt->execute(['email' => $email]);
        
        if ($checkStmt->fetch()) {
            $error = 'A user account with this email address already exists.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insertStmt = $pdo->prepare('INSERT INTO users (email, password, role) VALUES (:email, :password, :role)');
            
            if ($insertStmt->execute(['email' => $email, 'password' => $hashedPassword, 'role' => $role])) {
                $message = "New user account (" . htmlspecialchars($email) . ") created successfully!";
            } else {
                $error = 'Failed to create user account. Please try again.';
            }
        }
    }
    $activeTab = 'user';
}

// 2. Handle Car Return Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_car') {
    $rentalId = $_POST['return_rental_id'] ?? null;
    $carId = $_POST['car_id'] ?? null;

    if ($rentalId && $carId) {
        $pdo->beginTransaction();
        try {
            $updateRental = $pdo->prepare("UPDATE rentals SET status = 'returned', return_date = NOW() WHERE id = :id");
            $updateRental->execute(['id' => $rentalId]);

            $updateCar = $pdo->prepare("UPDATE cars SET status = 'available' WHERE id = :id");
            $updateCar->execute(['id' => $carId]);

            $pdo->commit();
            $message = "Vehicle returned successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error returning vehicle: " . $e->getMessage();
        }
    }
    $activeTab = 'view';
}

// 3. Handle Add / Edit Car Asset & Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_car') {
    $carId = $_POST['car_id'] ?? null;
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $dailyRate = (float)($_POST['daily_rate'] ?? 0);
    $status = $_POST['status'] ?? 'available';
    $purchaseDate = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
    
    // Financial and Expiry values
    $roadTax = (float)($_POST['road_tax'] ?? 0);
    $insurance = (float)($_POST['insurance'] ?? 0);
    $maintenance = (float)($_POST['maintenance'] ?? 0);
    $repair = (float)($_POST['repair'] ?? 0);

    if ($carId) {
        $stmt = $pdo->prepare("
            UPDATE cars 
            SET brand = :brand, model = :model, daily_rate = :daily_rate, status = :status,
                purchase_date = :purchase_date, purchase_price = :purchase_price, 
                road_tax = :road_tax, insurance = :insurance, 
                maintenance = :maintenance, repair = :repair 
            WHERE id = :id
        ");
        $stmt->execute([
            'brand' => $brand, 'model' => $model, 'daily_rate' => $dailyRate, 'status' => $status,
            'purchase_date' => $purchaseDate, 'purchase_price' => $purchasePrice,
            'road_tax' => $roadTax, 'insurance' => $insurance,
            'maintenance' => $maintenance, 'repair' => $repair, 'id' => $carId
        ]);

        if ($status !== 'rented') {
            $closeRentalStmt = $pdo->prepare("
                UPDATE rentals 
                SET status = 'returned', return_date = NOW() 
                WHERE car_id = :car_id AND status = 'active'
            ");
            $closeRentalStmt->execute(['car_id' => $carId]);
        }

        $message = "Car asset updated successfully!";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO cars (brand, model, daily_rate, status, purchase_date, purchase_price, road_tax, insurance, maintenance, repair) 
            VALUES (:brand, :model, :daily_rate, :status, :purchase_date, :purchase_price, :road_tax, :insurance, :maintenance, :repair)
        ");
        $stmt->execute([
            'brand' => $brand, 'model' => $model, 'daily_rate' => $dailyRate, 'status' => $status,
            'purchase_date' => $purchaseDate, 'purchase_price' => $purchasePrice,
            'road_tax' => $roadTax, 'insurance' => $insurance,
            'maintenance' => $maintenance, 'repair' => $repair
        ]);
        $message = "New car asset added successfully!";
    }
    $activeTab = 'view';
}

// Fetch car details if editing
if (isset($_GET['edit_id'])) {
    $editStmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id");
    $editStmt->execute(['id' => $_GET['edit_id']]);
    $editCar = $editStmt->fetch();
    if ($editCar) {
        $activeTab = 'edit';
    }
}

// Fetch all cars safely
$carsStmt = $pdo->query("SELECT * FROM cars ORDER BY id DESC");
$cars = $carsStmt->fetchAll();

// Fetch active rentals
$rentedCarsStmt = $pdo->query("
    SELECT r.id AS rental_id, r.car_id, u.email, c.brand, c.model, r.rental_date, r.total_price 
    FROM rentals r 
    JOIN users u ON r.user_id = u.id 
    JOIN cars c ON r.car_id = c.id 
    WHERE r.status = 'active'
");
$rentedCars = $rentedCarsStmt->fetchAll();

// Revenue analytics query
$revenueStmt = $pdo->query("
    SELECT 
        c.id, c.brand, c.model, 
        COALESCE(c.purchase_price, 0) AS purchase_price, 
        COALESCE(c.repair, 0) AS repair,
        COALESCE(SUM(r.total_price), 0) AS total_revenue,
        COUNT(r.id) AS total_rentals
    FROM cars c
    LEFT JOIN rentals r ON c.id = r.car_id
    GROUP BY c.id
    ORDER BY total_revenue DESC
");
$revenueStats = $revenueStmt->fetchAll();

$overallRevenue = array_sum(array_column($revenueStats, 'total_revenue'));
$overallRentals = array_sum(array_column($revenueStats, 'total_rentals'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Admin Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        header { background: #222; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { padding: 30px; max-width: 1250px; margin: auto; }
        .tab-nav { display: flex; border-bottom: 2px solid #ccc; margin-bottom: 25px; background: #fff; border-radius: 6px 6px 0 0; }
        .tab-btn { padding: 14px 24px; font-size: 15px; font-weight: bold; color: #555; text-decoration: none; border-bottom: 3px solid transparent; transition: 0.2s; }
        .tab-btn:hover { color: #0066cc; }
        .tab-btn.active { color: #0066cc; border-bottom-color: #0066cc; background: #f8f9fa; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; border: 1px solid #ddd; }
        .stat-card { background: #0066cc; color: white; padding: 20px; border-radius: 8px; width: 100%; max-width: 300px; margin-bottom: 20px; }
        .stat-card h3 { font-size: 14px; text-transform: uppercase; opacity: 0.9; margin-bottom: 8px; }
        .stat-card p { font-size: 28px; font-weight: bold; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-top: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px; color: #333; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .btn { padding: 10px 20px; background: #0066cc; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 15px; }
        .btn:hover { background: #0052a3; }
        .btn-cancel { background: #6c757d; text-decoration: none; display: inline-block; padding: 10px 15px; color: white; border-radius: 4px; font-size: 14px; margin-left: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: white; font-size: 14px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f4f7f6; }
        .btn-return { background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-edit { background: #ffc107; color: black; padding: 4px 8px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .btn-logout { background: #d9534f; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; }
        .msg-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
        .msg-error { background: #ffe6e6; color: #d9534f; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-available { background: #d4edda; color: #155724; }
        .badge-rented { background: #fff3cd; color: #856404; }
        .badge-idle { background: #e2e3e5; color: #383d41; }
        .badge-maintenance { background: #f8d7da; color: #721c24; }
        .profit-pos { color: #28a745; font-weight: bold; }
        .profit-neg { color: #d9534f; font-weight: bold; }
        .hint { font-size: 11px; color: #666; margin-top: 4px; display: block; }
    </style>
</head>
<body>

<header>
    <h1>DriveEase Admin Portal</h1>
    <div>
        <span>Admin: <?= htmlspecialchars($_SESSION['user_email'] ?? 'Administrator') ?></span>
        <a href="logout.php" class="btn-logout" style="margin-left: 15px;">Logout</a>
    </div>
</header>

<div class="container">

    <?php if ($message): ?>
        <div class="msg-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Navigation Bar -->
    <div class="tab-nav">
        <a href="admin_dashboard.php?tab=view" class="tab-btn <?= $activeTab === 'view' ? 'active' : '' ?>">🚘 View Fleet Details</a>
        <a href="admin_dashboard.php?tab=edit" class="tab-btn <?= $activeTab === 'edit' ? 'active' : '' ?>">✏️ <?= $editCar ? 'Edit Asset' : 'Add New Asset' ?></a>
        <a href="admin_dashboard.php?tab=user" class="tab-btn <?= $activeTab === 'user' ? 'active' : '' ?>">👤 Create Account</a>
        <a href="admin_dashboard.php?tab=revenue" class="tab-btn <?= $activeTab === 'revenue' ? 'active' : '' ?>">📊 Revenue Analytics</a>
    </div>

    <!-- TAB 1: VIEW FLEET DETAILS -->
    <?php if ($activeTab === 'view'): ?>
        <div class="card">
            <h2>Car Fleet & Asset Overview</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Status</th>
                            <th>Rental Rate</th>
                            <th>Purchase Date</th>
                            <th>Purchase Price</th>
                            <th>Road Tax</th>
                            <th>Insurance</th>
                            <th>Maintenance</th>
                            <th>Repair Cost</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cars as $car): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '')) ?></strong></td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($car['status'] ?? 'available') ?>">
                                        <?= ucfirst(htmlspecialchars($car['status'] ?? 'available')) ?>
                                    </span>
                                </td>
                                <td>$<?= number_format($car['daily_rate'] ?? 0, 2) ?></td>
                                <td><?= htmlspecialchars($car['purchase_date'] ?? 'N/A') ?></td>
                                <td>$<?= number_format($car['purchase_price'] ?? 0, 2) ?></td>
                                
                                <!-- Safely handle potential undefined key variations -->
                                <!-- FIXED CODE -->
                                <td>
                            <?php 
                                    $val = $car['road_tax'] ?? $car['road_tax_expiry'] ?? 'N/A';
                                    echo is_numeric($val) ? '$' . number_format((float)$val, 2) : htmlspecialchars($val);
                                 ?>
                                </td>
                                <td>
                         <?php 
                                $val = $car['insurance'] ?? $car['insurance_expiry'] ?? 'N/A';
                                echo is_numeric($val) ? '$' . number_format((float)$val, 2) : htmlspecialchars($val);
                                    ?>
                                </td>
                            <td>
                                <?php 
        $val = $car['maintenance'] ?? $car['next_maintenance_date'] ?? 'N/A';
        echo is_numeric($val) ? '$' . number_format((float)$val, 2) : htmlspecialchars($val);
    ?>
</td>
                                <td>$<?= number_format($car['repair'] ?? 0, 2) ?></td>
                                <td>
                                    <a href="admin_dashboard.php?tab=edit&edit_id=<?= $car['id'] ?>" class="btn-edit">Edit Asset</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Active Vehicle Rentals</h2>
            <?php if (count($rentedCars) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Customer Email</th>
                            <th>Vehicle</th>
                            <th>Rental Date</th>
                            <th>Total Charge</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rentedCars as $rental): ?>
                            <tr>
                                <td><?= htmlspecialchars($rental['email']) ?></td>
                                <td><?= htmlspecialchars($rental['brand'] . ' ' . $rental['model']) ?></td>
                                <td><?= $rental['rental_date'] ?></td>
                                <td>$<?= number_format($rental['total_price'], 2) ?></td>
                                <td>
                                    <form action="admin_dashboard.php?tab=view" method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="return_car">
                                        <input type="hidden" name="return_rental_id" value="<?= $rental['rental_id'] ?>">
                                        <input type="hidden" name="car_id" value="<?= $rental['car_id'] ?>">
                                        <button type="submit" class="btn-return">Mark Returned</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="margin-top: 10px; color: #666;">No vehicles are currently out on rent.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- TAB 2: ADD / EDIT CAR ASSETS -->
    <?php if ($activeTab === 'edit'): ?>
        <div class="card">
            <h2><?= $editCar ? 'Edit Vehicle Asset' : 'Add New Vehicle Asset' ?></h2>
            <form action="admin_dashboard.php?tab=edit" method="POST">
                <input type="hidden" name="action" value="save_car">
                <?php if ($editCar): ?>
                    <input type="hidden" name="car_id" value="<?= $editCar['id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Brand</label>
                        <input type="text" name="brand" value="<?= htmlspecialchars($editCar['brand'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Model</label>
                        <input type="text" name="model" value="<?= htmlspecialchars($editCar['model'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Rental Rate ($/day)</label>
                        <input type="number" step="0.01" name="daily_rate" value="<?= htmlspecialchars($editCar['daily_rate'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="available" <?= ($editCar['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="rented" <?= ($editCar['status'] ?? '') === 'rented' ? 'selected' : '' ?>>Rented</option>
                            <option value="idle" <?= ($editCar['status'] ?? '') === 'idle' ? 'selected' : '' ?>>Idle</option>
                            <option value="maintenance" <?= ($editCar['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" value="<?= htmlspecialchars($editCar['purchase_date'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Purchase Price ($)</label>
                        <input type="number" step="0.01" name="purchase_price" value="<?= htmlspecialchars($editCar['purchase_price'] ?? '0.00') ?>">
                    </div>
                    <div class="form-group">
                        <label>Road Tax ($)</label>
                        <input type="number" step="0.01" name="road_tax" value="<?= htmlspecialchars($editCar['road_tax'] ?? '0.00') ?>">
                    </div>
                    <div class="form-group">
                        <label>Insurance ($)</label>
                        <input type="number" step="0.01" name="insurance" value="<?= htmlspecialchars($editCar['insurance'] ?? '0.00') ?>">
                    </div>
                    <div class="form-group">
                        <label>Maintenance ($)</label>
                        <input type="number" step="0.01" name="maintenance" value="<?= htmlspecialchars($editCar['maintenance'] ?? '0.00') ?>">
                    </div>
                    <div class="form-group">
                        <label>Repair Cost ($)</label>
                        <input type="number" step="0.01" name="repair" value="<?= htmlspecialchars($editCar['repair'] ?? '0.00') ?>">
                    </div>
                </div>

                <button type="submit" class="btn"><?= $editCar ? 'Update Asset' : 'Save Asset' ?></button>
                <?php if ($editCar): ?>
                    <a href="admin_dashboard.php?tab=view" class="btn-cancel">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <!-- TAB 3: CREATE USER ACCOUNT -->
    <?php if ($activeTab === 'user'): ?>
        <div class="card">
            <h2>Create System User / Admin Account</h2>
            <form action="admin_dashboard.php?tab=user" method="POST" id="adminUserForm">
                <input type="hidden" name="action" value="add_user">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="admin_user_email" name="user_email" placeholder="e.g. user@driveease.com" required>
                        <span class="hint">Must be a valid email format.</span>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="admin_user_password" name="user_password" placeholder="••••••••" required>
                        <span class="hint">Min. 8 chars; ONLY letters and numbers (no symbols).</span>
                    </div>
                    <div class="form-group">
                        <label>Account Role</label>
                        <select name="user_role" required>
                            <option value="user">User / Customer</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn">Create Account</button>
            </form>
        </div>

        <script>
        document.getElementById('adminUserForm').addEventListener('submit', function(e) {
            const password = document.getElementById('admin_user_password').value;
            // Strict Alphanumeric Regex Check
            const passRegex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;

            if (!passRegex.test(password)) {
                e.preventDefault();
                alert('Password invalid: Must be at least 8 characters long, contain ONLY letters and digits, and have no special characters or symbols.');
            }
        });
        </script>
    <?php endif; ?>

    <!-- TAB 4: REVENUE ANALYTICS -->
    <?php if ($activeTab === 'revenue'): ?>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div class="stat-card">
                <h3>Overall Gross Revenue</h3>
                <p>$<?= number_format($overallRevenue, 2) ?></p>
            </div>
            <div class="stat-card" style="background: #28a745;">
                <h3>Total Completed Rentals</h3>
                <p><?= $overallRentals ?></p>
            </div>
        </div>

        <div class="card">
            <h2>Individual Vehicle Revenue & Profit Breakdown</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Total Rentals</th>
                            <th>Gross Revenue</th>
                            <th>Total Expenses (Purchase + Repair)</th>
                            <th>Net Profit / Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenueStats as $stat): 
                            $totalExpenses = ($stat['purchase_price'] ?? 0) + ($stat['repair'] ?? 0);
                            $netProfit = ($stat['total_revenue'] ?? 0) - $totalExpenses;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(($stat['brand'] ?? '') . ' ' . ($stat['model'] ?? '')) ?></strong></td>
                                <td><?= $stat['total_rentals'] ?? 0 ?> time(s)</td>
                                <td>$<?= number_format($stat['total_revenue'] ?? 0, 2) ?></td>
                                <td>$<?= number_format($totalExpenses, 2) ?></td>
                                <td>
                                    <span class="<?= $netProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>">
                                        $<?= number_format($netProfit, 2) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>