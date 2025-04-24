<?php
session_start();
require 'permissions.php';

$user_id = $_SESSION['user_id'] ?? null;
$selected_role_id = $_SESSION['selected_role_id'] ?? null;

if ($user_id === null || $selected_role_id === null) {
    header("Location: portal-login.html");
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp']) && isset($_POST['user_id'])) {
        $otp = trim($_POST['otp']);
        $delete_user_id = $_POST['user_id'];
        $pending_deletion = $_SESSION['pending_deletion'] ?? [];

        if (empty($pending_deletion)) {
            $_SESSION['errorMsg'] = "Session expired. Please start the deletion process again.";
            error_log("Delete User Error: Session expired for user_id $delete_user_id");
        } elseif ($otp !== $pending_deletion['otp'] || time() > $pending_deletion['otp_expiry']) {
            $_SESSION['errorMsg'] = "Invalid or expired OTP.";
            $_SESSION['showDeleteOtpForm'] = true;
            error_log("Delete User Error: Invalid or expired OTP for user_id $delete_user_id");
        } elseif ($pending_deletion['user_id'] != $delete_user_id) {
            $_SESSION['errorMsg'] = "Invalid deletion request.";
            error_log("Delete User Error: Mismatched user_id in session ($pending_deletion[user_id]) and POST ($delete_user_id)");
        } else {
            // Fetch user details before deletion
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.email,
                       GROUP_CONCAT(DISTINCT r.id SEPARATOR ',') AS role_ids,
                       GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') AS departments
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN roles r ON ur.role_id = r.id
                LEFT JOIN user_departments ud ON u.id = ud.user_id
                LEFT JOIN departments d ON ud.department_id = d.id
                WHERE u.id = ?
                GROUP BY u.id
            ");
            $stmt->execute([$delete_user_id]);
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
                    $user['role_ids'] ?: '',
                    $user['departments'] ?: '',
                    $_SESSION['user_id']
                ]);

                // Delete related records from user_roles and user_departments
                $deleteRolesStmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $deleteRolesStmt->execute([$delete_user_id]);

                $deleteDeptsStmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = ?");
                $deleteDeptsStmt->execute([$delete_user_id]);

                // Delete from users table
                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$delete_user_id]);

                // Clear session data
                unset($_SESSION['pending_deletion']);
                unset($_SESSION['showDeleteOtpForm']);
                $_SESSION['deletionMsg'] = "User '{$user['username']}' deleted successfully and logged in audit trail.";
            } else {
                $_SESSION['errorMsg'] = "User not found.";
                error_log("Delete User Error: User not found for user_id $delete_user_id");
            }
        }
    } else {
        $_SESSION['errorMsg'] = "Invalid request.";
        error_log("Delete User Error: Invalid request, missing OTP or user_id");
    }

    // Redirect back to the view-users page
    header("Location: view-users.php");
    exit;

} catch (PDOException $e) {
    error_log("Database Error in delete-user.php: " . $e->getMessage());
    $_SESSION['errorMsg'] = "Database error occurred.";
    header("Location: view-users.php");
    exit;
}
?>