PHP
<?php
//$host = 'localhost';
//$dbname = 'car_rental';
//$username = 'root'; // Update with your DB username
//$password = '';     // Update with your DB password

$host = 'driveease-db-driveease-stack.c50gqqyuw2dp.ap-southeast-1.rds.amazonaws.com';
$dbname = 'car_rental';
$username = 'dbadmin'; // Update with your DB username
$password = 'CUsecure2026';     // Update with your DB password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>