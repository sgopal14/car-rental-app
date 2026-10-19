<?php
ob_start();
session_start();
require_once 'db.php';

// Safe CSRF verification check for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verify_csrf_token')) {
    verify_csrf_token();
}

// 1. Authenticate Session & Role Access Control for Branch Agents
$allowed_agent_roles = ['agent', 'branch_agent', 'staff', 'admin'];$current_role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');

if (!isset($_SESSION['user_id']) || !in_array($current_role,$allowed_agent_roles, true)) {
    header("Location: index.php");
    exit();
}

$message = '';$error = '';

// Helper function to check if a table exists in MySQL
function table_exists($pdo,$table_name) {
    try {
        $result =$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table_name));
        return $result && $result->rowCount() > 0; } catch (Exception$e) {
        return false;
    }
}

// Detect whether system uses 'rentals' or 'bookings' table
$rental_table = table_exists($pdo, 'rentals') ? 'rentals' : 'bookings';

// 2. Handle POST Actions (Processing Check-outs/Returns & Updating Vehicle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action =$_POST['action'];

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
                $b_stmt->execute([$target_status,$booking_id]);

                // Automatically synchronize vehicle availability in database
                if ($car_id > 0) {
                    $car_new_status = ($target_status === 'confirmed') ? 'rented' : 'available';
                    $c_stmt =$pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                    $c_stmt->execute([$car_new_status,$car_id]);
                }

                $pdo->commit();
                $message = "Transaction #" . $booking_id . " updated to '" . strtoupper($target_status) . "' successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }$error = "Failed to update rental status: " . $e->getMessage();
            }
        }
    }
    // FLEET CONTROL: Update Vehicle Operational Status directly
    elseif ($action === 'update_car_status') {
        $car_id = intval($_POST['car_id'] ?? 0);
        $status = strtolower(trim($_POST['status'] ?? 'available'));

        if ($car_id > 0 && in_array($status, ['available', 'rented', 'maintenance'], true)) {
            try {
                $stmt =$pdo->prepare("UPDATE cars SET status = ? WHERE id = ?");
                $stmt->execute([$status,$car_id]);
                $message = "Vehicle #" . $car_id . " status set to '" . strtoupper($status) . "'.";
            } catch (PDOException $e) {$error = "Failed to update vehicle status: " . $e->getMessage();
            }
        }
    }
}

// 3. Fetch Data for Branch Agent Desk
$transactions = [];$cars = [];

try {
    // Transaction Query (Uses c.brand directly to eliminate 1054 Unknown Column errors)
    $transactions =$pdo->query("
        SELECT r.*, 
               COALESCE(u.name, u.email, 'Customer') AS customer_name, 
               u.email AS customer_email,
               c.id AS car_id, 
               COALESCE(c.brand, 'Vehicle') AS vehicle_brand, 
               c.model AS vehicle_model
        FROM {$rental_table} r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN cars c ON r.car_id = c.id
        ORDER BY r.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fleet Query
    $cars =$pdo->query("SELECT * FROM cars ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {$error = "Database Error: " . $e->getMessage();
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
        .card-title { font-size: 20px; font-weight: bold; color: #1a252f; margin-bottom: 15px; border-bottom: 2px solid #f4f6f9