<?php
session_start();
require_once 'db.php';

$error = '';
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Server-side validations
    if (empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } 
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } 
    elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } 
    // Strict Alphanumeric Password Check: Min 8 chars, ONLY letters and digits (no symbols allowed)
    elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters long, contain ONLY letters and numbers, and include at least one letter and one number.';
    } 
    else {
        // Check if email already exists
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        
        if ($stmt->fetch()) {
            $error = 'An account with this email address already exists.';
        } else {
            // Hash password securely
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insertStmt = $pdo->prepare('INSERT INTO users (email, password, role) VALUES (:email, :password, "user")');
            if ($insertStmt->execute(['email' => $email, 'password' => $hashedPassword])) {
                $success = 'Account created successfully! You can now <a href="index.php">login</a>.';
                $email = ''; // Clear form input on success
            } else {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Register Account</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .card { background: #ffffff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .card h2 { text-align: center; margin-bottom: 8px; color: #333; }
        .card p { text-align: center; margin-bottom: 24px; color: #666; font-size: 14px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; color: #333; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; outline: none; }
        .form-group input:focus { border-color: #28a745; box-shadow: 0 0 4px rgba(40,167,69,0.3); }
        .btn { width: 100%; padding: 12px; background-color: #28a745; color: #fff; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; font-weight: bold; }
        .btn:hover { background-color: #218838; }
        .msg { padding: 10px; border-radius: 4px; font-size: 14px; margin-bottom: 16px; text-align: center; }
        .error { background-color: #ffe6e6; color: #d9534f; }
        .success { background-color: #d4edda; color: #155724; }
        .footer-link { text-align: center; margin-top: 16px; font-size: 14px; }
        .footer-link a { color: #0066cc; text-decoration: none; font-weight: bold; }
        .footer-link a:hover { text-decoration: underline; }
        .hint { font-size: 11px; color: #666; margin-top: 4px; display: block; }
    </style>
</head>
<body>

<div class="card">
    <h2>Create Account</h2>
    <p>Join DriveEase to start renting vehicles</p>

    <div id="jsError" class="msg error" style="display: none;"></div>

    <?php if (!empty($error)): ?>
        <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="msg success"><?= $success ?></div>
    <?php endif; ?>

    <form action="register.php" method="POST" id="registerForm" novalidate>
        <div class="form-group">
            <label for="email">Email Address</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                value="<?= htmlspecialchars($email) ?>" 
                placeholder="name@example.com" 
                required
            >
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                placeholder="••••••••" 
                required
            >
            <span class="hint">Min 8 characters; letters & numbers ONLY (no symbols).</span>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input 
                type="password" 
                id="confirm_password" 
                name="confirm_password" 
                placeholder="••••••••" 
                required
            >
        </div>
        <button type="submit" class="btn">Register</button>
    </form>

    <div class="footer-link">
        Already have an account? <a href="index.php">Sign In</a>
    </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', function(e) {
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const errorBox = document.getElementById('jsError');

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    // Strict Alphanumeric Regex
    const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;

    let errorMessage = '';

    if (!emailRegex.test(email)) {
        errorMessage = 'Please enter a valid email address.';
    } else if (!passwordRegex.test(password)) {
        errorMessage = 'Password must be at least 8 characters long, contain ONLY letters and digits, and have no special characters.';
    } else if (password !== confirmPassword) {
        errorMessage = 'Passwords do not match.';
    }

    if (errorMessage !== '') {
        e.preventDefault();
        errorBox.textContent = errorMessage;
        errorBox.style.display = 'block';
    } else {
        errorBox.style.display = 'none';
    }
});
</script>

</body>
</html>