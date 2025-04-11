<?php
session_start();
require 'permissions.php';

// Check if the user is logged in and has the necessary permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !hasPermission('read_all_users')) { // Updated permission check
    header("Location: login.php");
    exit;
}

// Include the database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle OTP verification
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
        $otp = trim($_POST['otp']);
        $user_id = $_POST['user_id'];
        $pending_deletion = $_SESSION['pending_deletion'] ?? [];

        if (empty($pending_deletion)) {
            $_SESSION['errorMsg'] = "Session expired. Please start the deletion process again.";
        } elseif ($otp !== $pending_deletion['otp'] || time() > $pending_deletion['otp_expiry']) {
            $_SESSION['errorMsg'] = "Invalid or expired OTP.";
            $_SESSION['showDeleteOtpForm'] = true;
        } else {
            // Fetch user details before deletion
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.email, u.role_id, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments
                FROM users u
                LEFT JOIN user_departments ud ON u.id = ud.user_id
                LEFT JOIN departments d ON ud.department_id = d.id
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Insert into audit table
                $auditStmt = $pdo->prepare("
                    INSERT INTO user_deletion_audit (user_id, username, email, role_id, departments, deleted_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $auditStmt->execute([
                    $user['id'],
                    $user['username'],
                    $user['email'],
                    $user['role_id'],
                    $user['departments'],
                    $_SESSION['user_id']
                ]);

                // Delete from users table
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$user_id]);

                // Clear session data
                unset($_SESSION['pending_deletion']);
                unset($_SESSION['showDeleteOtpForm']);
                $_SESSION['deletionMsg'] = "User '{$user['username']}' deleted successfully and logged in audit trail.";
            } else {
                $_SESSION['errorMsg'] = "User not found.";
            }
        }
    } else {
        $_SESSION['errorMsg'] = "Invalid request.";
    }

    // Redirect back to the view-users page
    header("Location: view-users.php");
    exit;

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>