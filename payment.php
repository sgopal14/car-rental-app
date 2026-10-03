<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'user') {
    header('Location: index.php');
    exit;
}

$carId = $_GET['car_id'] ?? null;
$error = '';

if (!$carId) {
    header('Location: user_dashboard.php');
    exit;
}

// Fetch car details and confirm availability
$stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id AND status = 'available'");
$stmt->execute(['id' => $carId]);
$car = $stmt->fetch();

if (!$car) {
    die("Selected vehicle is either unavailable or does not exist. <a href='user_dashboard.php'>Return to Dashboard</a>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $days = (int)($_POST['days'] ?? 1);
    $cardNumber = trim($_POST['card_number'] ?? '');
    $totalPrice = $car['daily_rate'] * $days;

    // Server-side credit card 16-digit validation
    if (!preg_match('/^\d{16}$/', $cardNumber)) {
        $error = 'Please enter a valid 16-digit credit card number (numbers only).';
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Create Rental record
            $rentalStmt = $pdo->prepare("INSERT INTO rentals (user_id, car_id, total_price, status) VALUES (:user_id, :car_id, :total_price, 'active')");
            $rentalStmt->execute([
                'user_id' => $_SESSION['user_id'],
                'car_id' => $car['id'],
                'total_price' => $totalPrice
            ]);

            // 2. Mark car as rented
            $carStmt = $pdo->prepare("UPDATE cars SET status = 'rented' WHERE id = :id");
            $carStmt->execute(['id' => $car['id']]);

            $pdo->commit();

            header('Location: user_dashboard.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Transaction failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout & Payment - DriveEase</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 450px; }
        .summary-box { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; }
        input[type="number"], input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; outline: none; }
        input[type="text"]:focus { border-color: #0066cc; }
        .btn { width: 100%; padding: 12px; background: #28a745; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        .btn:hover { background: #218838; }
        .cancel-link { display: block; text-align: center; margin-top: 15px; color: #666; text-decoration: none; font-size: 14px; }
        .error { background-color: #ffe6e6; color: #d9534f; padding: 10px; border-radius: 4px; font-size: 14px; margin-bottom: 16px; text-align: center; }
        .hint { font-size: 12px; color: #666; margin-top: 4px; display: block; }
    </style>
</head>
<body>

<div class="card">
    <h2>Vehicle Checkout</h2>

    <div id="jsError" class="error" style="display: none;"></div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="summary-box">
        <h3><?= htmlspecialchars($car['brand'] . ' ' . $car['model']) ?></h3>
        <p style="margin-top: 5px;">Rate: <strong>$<?= number_format($car['daily_rate'], 2) ?></strong> / day</p>
    </div>

    <form action="payment.php?car_id=<?= $car['id'] ?>" method="POST" id="paymentForm" novalidate>
        <div class="form-group">
            <label for="days">Rental Duration (Days)</label>
            <input type="number" id="days" name="days" min="1" max="30" value="1" required>
        </div>

        <div style="border-top: 1px solid #ccc; padding-top: 15px; margin-top: 15px;">
            <h4>Dummy Payment Details</h4>
            <div class="form-group" style="margin-top: 10px;">
                <label for="card_number">Credit Card Number</label>
                <input 
                    type="text" 
                    id="card_number" 
                    name="card_number" 
                    placeholder="4000123456789010" 
                    maxlength="16"
                    required
                    autocomplete="off"
                >
                <span class="hint">Must contain exactly 16 numbers with no letters or special characters.</span>
            </div>
        </div>

        <button type="submit" class="btn">Confirm Payment & Checkout</button>
    </form>
    <a href="user_dashboard.php" class="cancel-link">Cancel and return</a>
</div>

<script>
const cardInput = document.getElementById('card_number');
const paymentForm = document.getElementById('paymentForm');
const jsError = document.getElementById('jsError');

// Strip non-digit characters in real-time as user types
cardInput.addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '').slice(0, 16);
});

// Client-side submission check
paymentForm.addEventListener('submit', function(e) {
    const cardValue = cardInput.value.trim();

    if (!/^\d{16}$/.test(cardValue)) {
        e.preventDefault();
        jsError.textContent = 'Please enter a valid 16-digit credit card number.';
        jsError.style.display = 'block';
        cardInput.focus();
    } else {
        jsError.style.display = 'none';
    }
});
</script>

</body>
</html>