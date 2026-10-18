<?php
session_start();
require_once 'db.php';

// Enforce CSRF token validation for non-GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    verify_csrf_token();
}

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Enforce customer role access control
if (isset($_SESSION['role']) &&$_SESSION['role'] !== 'customer') {
    header("Location: index.php");
    exit();
}

$user_name =$_SESSION['user_name'] ?? 'Valued Customer';
$cars = [];$error_msg = '';

// Retrieve fleet data safely from RDS database
try {
    $stmt =$pdo->prepare("SELECT * FROM cars");
    $stmt->execute();
    $cars =$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table 'cars' fails, attempt fallback to 'vehicles'
    try {
        $stmt =$pdo->prepare("SELECT * FROM vehicles");
        $stmt->execute();
        $cars =$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {$error_msg = "Unable to retrieve vehicle fleet data: " . htmlspecialchars($e2->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Vehicle Catalog</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #f4f6f9; 
            color: #333; 
        }
        .navbar { 
            background-color: #1a252f; 
            color: #fff; 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
        }
        .navbar h1 { font-size: 22px; font-weight: bold; }
        .navbar .user-info { display: flex; align-items: center; gap: 15px; }
        .logout-btn { 
            background-color: #dc3545; 
            color: white; 
            padding: 8px 16px; 
            text-decoration: none; 
            border-radius: 5px; 
            font-size: 14px; 
            font-weight: bold; 
        }
        .logout-btn:hover { background-color: #bd2130; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .header-title { margin-bottom: 25px; color: #1a252f; text-align: center; }
        .alert { 
            background-color: #f8d7da; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
            text-align: center; 
        }
        .catalog-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); 
            gap: 25px; 
        }
        .card { 
            background: #fff; 
            border-radius: 10px; 
            overflow: hidden; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.08); 
            transition: transform 0.2s ease, box-shadow 0.2s ease; 
        }
        .card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 6px 15px rgba(0,0,0,0.12); 
        }
        .card-img { 
            width: 100%; 
            height: 200px; 
            object-fit: cover; 
            background-color: #e9ecef; 
            display: block; 
        }
        .card-body { padding: 20px; }
        .card-title { 
            font-size: 18px; 
            font-weight: bold; 
            color: #1a252f; 
            margin-bottom: 8px; 
        }
        .card-details { 
            font-size: 14px; 
            color: #6c757d; 
            margin-bottom: 15px; 
            line-height: 1.5; 
        }
        .card-price { 
            font-size: 16px; 
            font-weight: bold; 
            color: #28a745; 
            margin-bottom