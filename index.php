<?php
session_start();
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        die("<h2 style='color:red;'>[DEBUG ERROR 1] User email not found in database: " . htmlspecialchars($email) . "</h2>");
    }

    if (!password_verify($password, $user['password'])) {
        die("<h2 style='color:red;'>[DEBUG ERROR 2] Password mismatch for " . htmlspecialchars($email) . ". Submitted pass: " . htmlspecialchars($password) . "</h2>");
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'];

    echo "<h2 style='color:green;'>[DEBUG SUCCESS] Login verified! User Role: " . htmlspecialchars($user['role']) . ". Redirecting...</h2>";
    echo "<script>setTimeout(function(){ window.location.href='user_dashboard.php'; }, 2000);</script>";
    exit();

}
?>

                // Role Routing
                switch ($user['role']) {
                    case 'admin':
                        header("Location: admin_dashboard.php");
                        break;
                    case 'fleet_manager':
                        header("Location: fleet_dashboard.php");
                        break;
                    case 'branch_agent':
                        header("Location: agent_dashboard.php");
                        break;
                    case 'customer':
                    default:
                        header("Location: user_dashboard.php");
                        break;
                }
                exit(); // Ensure no further execution
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveEase - Login</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-card h2 { margin-top: 0; color: #333; text-align: center; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .btn { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; text-align: center; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>DriveEase Login</h2>

    <?php if (!empty($error)): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="index.php" method="POST">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required placeholder="admin@driveease.com">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="Password123!">
        </div>

        <button type="submit" class="btn">Sign In</button>
    </form>
</div>

</body>
</html>