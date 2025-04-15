<?php
require 'permissions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

// Include PHPMailer files
require './PHPMailer-master/src/Exception.php';
require './PHPMailer-master/src/PHPMailer.php';
require './PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Session timeout for 20 mins
$timeout_duration = 1200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: portal-login.html");
    exit;
}
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_departments = $_SESSION['departments'] ?? [];
$user_username = $_SESSION['username'];

// Function to validate password complexity
function validatePassword($password)
{
    $uppercase = preg_match('@[A-Z]@', $password);
    $number = preg_match('@\d@', $password);
    $specialChar = preg_match('@[^\w]@', $password);
    return $uppercase && $number && $specialChar && strlen($password) >= 8;
}

// Database connection
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check session token
    $checkStmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
    $checkStmt->execute([$_SESSION['user_id']]);
    $sessionToken = $checkStmt->fetchColumn();
    if ($sessionToken !== $_SESSION['session_token']) {
        session_unset();
        session_destroy();
        echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
    }

    // Fetch users based on role
    if (hasPermission('read_all_users')) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, r.name AS role_name, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN user_departments ud ON u.id = ud.user_id
            LEFT JOIN departments d ON ud.department_id = d.id
            WHERE r.name != 'Admin'
            GROUP BY u.id
            ORDER BY u.username
        ");
        $stmt->execute();
    } elseif (hasPermission('read_department_users')) {
        $departmentPlaceholders = implode(',', array_fill(0, count($user_departments), '?'));
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, r.name AS role_name, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN user_departments ud ON u.id = ud.user_id
            LEFT JOIN departments d ON ud.department_id = d.id
            WHERE d.name IN ($departmentPlaceholders) 
              AND r.name NOT IN ('Admin') 
              AND u.id != ?
            GROUP BY u.id
            ORDER BY u.username
        ");
        $params = array_merge($user_departments, [$user_id]);
        $stmt->execute($params);
    } else {
        echo "You do not have the required permissions to view this page.";
        exit;
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch roles and departments for the modal
    $roles = $pdo->query("SELECT id, name FROM roles WHERE name != 'Admin'")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);

    // Handle user restoration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_user_id']) && hasPermission('read_all_users')) {
        $restore_user_id = $_POST['restore_user_id'];

        // Fetch audit data
        $auditStmt = $pdo->prepare("SELECT * FROM user_deletion_audit WHERE user_id = ?");
        $auditStmt->execute([$restore_user_id]);
        $audit = $auditStmt->fetch(PDO::FETCH_ASSOC);

        if ($audit) {
            // Use a temporary password placeholder
            $tempPassword = 'RESET_REQUIRED'; // Unhashed, acts as a flag

            // Re-insert into users table with temporary password
            $restoreStmt = $pdo->prepare("INSERT INTO users (id, username, email, role_id, password) VALUES (?, ?, ?, ?, ?)");
            $restoreStmt->execute([$audit['user_id'], $audit['username'], $audit['email'], $audit['role_id'], $tempPassword]);

            // Restore departments
            if (!empty($audit['departments'])) {
                $departments = explode(', ', $audit['departments']);
                $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
                $insertDeptStmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                foreach ($departments as $deptName) {
                    $deptStmt->execute([$deptName]);
                    $deptId = $deptStmt->fetchColumn();
                    if ($deptId) {
                        $insertDeptStmt->execute([$audit['user_id'], $deptId]);
                    }
                }
            }

            // Remove from audit table
            $deleteAuditStmt = $pdo->prepare("DELETE FROM user_deletion_audit WHERE user_id = ?");
            $deleteAuditStmt->execute([$restore_user_id]);

            $_SESSION['successMsg'] = "User '{$audit['username']}' restored successfully. They must reset their password on next login.";
        } else {
            $_SESSION['errorMsg'] = "User not found in audit trail.";
        }
        header("Location: view-users.php");
        exit;
    }

    // Fetch audit trail data (unchanged)
    if (hasPermission('read_all_users')) {
        $auditStmt = $pdo->prepare("
        SELECT uda.*, u.username AS deleted_by_username
        FROM user_deletion_audit uda
        LEFT JOIN users u ON uda.deleted_by = u.id
        ORDER BY uda.deleted_at DESC
    ");
        $auditStmt->execute();
        $auditRecords = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Handle delete request initiation
    if (isset($_POST['initiate_delete']) && hasPermission('read_all_users')) { // Only Admins can delete
        $delete_user_id = $_POST['user_id'];
        $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
        $stmt->execute([$delete_user_id]);
        $user_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_to_delete) {
            $email = $user_to_delete['email'];
            $username = $user_to_delete['username'];
            $otp = sprintf("%06d", mt_rand(0, 999999));

            // Get user's timezone from cookie or default to UTC
            $userTimezone = isset($_COOKIE['user_timezone']) ? $_COOKIE['user_timezone'] : 'UTC';

            // Calculate expiry time in user's timezone
            $dateTime = new DateTime('now', new DateTimeZone($userTimezone));
            $dateTime->modify('+15 minutes');
            $expiryDisplay = $dateTime->format('Y-m-d H:i:s'); // For email display
            $expiryTimestamp = $dateTime->getTimestamp(); // For session storage

            $_SESSION['pending_deletion'] = [
                'user_id' => $delete_user_id,
                'email' => $email,
                'username' => $username,
                'otp' => $otp,
                'otp_expiry' => $expiryTimestamp // Store as timestamp
            ];

            // Send OTP via email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.zoho.com';
                $mail->SMTPAuth = true;
                $mail->Username = $config['email_username'];
                $mail->Password = $config['email_password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Confirm User Deletion - OTP';
                $mail->Body = "Hello $username,<br><br>An administrator has requested to delete your account. Your One-Time Password (OTP) to confirm this action is: <strong>$otp</strong><br><br>This OTP will expire at " . htmlspecialchars($expiryDisplay) . " (" . htmlspecialchars($userTimezone) . ").<br><br>Please provide this OTP to the administrator if you agree to the deletion.";
                $mail->AltBody = "Hello $username,\n\nAn administrator has requested to delete your account. Your OTP to confirm this action is: $otp\n\nThis OTP will expire at " . htmlspecialchars($expiryDisplay) . " (" . htmlspecialchars($userTimezone) . ").\n\nPlease provide this OTP to the administrator if you agree.";

                $mail->send();
                $_SESSION['successMsg'] = "An OTP has been sent to $email to confirm the deletion of $username.";
                $_SESSION['showDeleteOtpForm'] = true;
            } catch (Exception $e) {
                $_SESSION['errorMsg'] = "Failed to send OTP for deletion. Please try again.";
            }
        } else {
            $_SESSION['errorMsg'] = "User not found.";
        }
        header("Location: view-users.php");
        exit;
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Handle form submissions (user creation remains unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['initiate_delete'])) {
    if (isset($_POST['username']) && !isset($_POST['otp'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $role_id = intval($_POST['role']);
        $department_ids = $_POST['departments'];

        if (empty($username) || empty($email) || empty($password) || empty($role_id) || empty($department_ids)) {
            $_SESSION['errorMsg'] = "Please fill in all fields.";
        } elseif (!validatePassword($password)) {
            $_SESSION['errorMsg'] = "Password must contain at least one uppercase letter, one number, one special character, and be at least 8 characters long.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['errorMsg'] = "Username or email already exists.";
            } else {
                $otp = sprintf("%06d", mt_rand(0, 999999));
                $_SESSION['pending_user'] = [
                    'username' => $username,
                    'email' => $email,
                    'password' => $password,
                    'role_id' => $role_id,
                    'department_ids' => $department_ids,
                    'otp' => $otp,
                    'otp_expiry' => time() + 900
                ];

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.zoho.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = $config['email_username'];
                    $mail->Password = $config['email_password'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Verify Your Email - User Creation OTP';
                    $mail->Body = "Hello,<br><br>Your One-Time Password (OTP) to verify your email for user creation is: <strong>$otp</strong><br><br>This OTP will expire in 15 minutes.<br><br>Please provide this OTP to the administrator to complete your account creation.";
                    $mail->AltBody = "Hello,\n\nYour OTP to verify your email for user creation is: $otp\n\nThis OTP will expire in 15 minutes.\n\nPlease provide this OTP to the administrator.";

                    $mail->send();
                    $_SESSION['successMsg'] = "An OTP has been sent to $email. Please verify it to complete user creation.";
                    $_SESSION['showOtpForm'] = true;
                } catch (Exception $e) {
                    $_SESSION['errorMsg'] = "Failed to send OTP. Please try again.";
                }
            }
        }
        header("Location: view-users.php");
        exit;
    } elseif (isset($_POST['otp'])) {
        $otp = trim($_POST['otp']);
        $pending_user = $_SESSION['pending_user'] ?? [];

        if (empty($pending_user)) {
            $_SESSION['errorMsg'] = "Session expired. Please start the user creation process again.";
        } elseif ($otp !== $pending_user['otp'] || time() > $pending_user['otp_expiry']) {
            $_SESSION['errorMsg'] = "Invalid or expired OTP.";
            $_SESSION['showOtpForm'] = true;
        } else {
            $username = $pending_user['username'];
            $email = $pending_user['email'];
            $password = password_hash($pending_user['password'], PASSWORD_DEFAULT);
            $role_id = $pending_user['role_id'];
            $department_ids = $pending_user['department_ids'];

            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $role_id]);
            $newUserId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
            foreach ($department_ids as $department_id) {
                $stmt->execute([$newUserId, intval($department_id)]);
            }

            unset($_SESSION['pending_user']);
            unset($_SESSION['showOtpForm']);
            $_SESSION['successMsg'] = "User $username created successfully after email verification.";
        }
        header("Location: view-users.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <title>View Users</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        * {
            box-sizing: border-box;
        }

        .main-container {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .container {
            width: 100%;
            max-width: 1400px;
            margin: 20px 0;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        h1 {
            font-size: 2.2rem;
            text-align: center;
            color: #1d3557;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 1.5rem;
            color: #457b9d;
            margin-top: 30px;
        }

        p {
            text-align: center;
            font-size: 1rem;
            color: #457b9d;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table th,
        table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }

        table th {
            background-color: #1d3557;
            color: #fff;
            font-weight: bold;
        }

        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #002c5f;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            transition: background-color 0.3s ease;
            margin-top: 20px;
        }

        .back-button:hover {
            background-color: #004080;
        }

        .edit-button {
            display: inline-block;
            padding: 5px 10px;
            background-color: #457b9d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            transition: background-color 0.3s ease;
        }

        .edit-button:hover {
            background-color: #1d3557;
        }

        .delete-button {
            display: inline-block;
            padding: 5px 10px;
            background-color: #e63946;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .delete-button:hover {
            background-color: #d62828;
        }

        button.delete-button {
            font-family: 'Poppins', sans-serif;
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 1.8rem;
            }

            table th,
            table td {
                font-size: 0.9rem;
                padding: 8px;
            }

            .back-button {
                font-size: 0.9rem;
            }
        }

        .success-message {
            text-align: center;
            background-color: #5cb85c;
            color: white;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .deletion-message {
            text-align: center;
            background-color: #e63946;
            color: white;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        #passwordError {
            color: red;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input[type="text"],
        input[type="password"],
        input[type="email"],
        select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        .select2-container {
            width: 100% !important;
        }

        .select2-dropdown {
            width: 100% !important;
        }

        .select2-selection--multiple {
            width: 100% !important;
            border: 1px solid #ced4da !important;
            border-radius: 4px !important;
            padding: 6px !important;
            min-height: 38px !important;
        }

        .select2-selection--multiple .select2-selection__choice {
            background-color: #1d3557 !important;
            border: 1px solid #1d3557 !important;
            color: white !important;
            padding: 2px 8px !important;
            margin: 2px !important;
            border-radius: 4px !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            position: relative !important;
            left: auto !important;
            top: auto !important;
            margin-right: 5px !important;
            color: white !important;
            border: none !important;
            background-color: transparent !important;
            font-size: 1em !important;
            font-weight: bold !important;
            cursor: pointer !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #e63946 !important;
        }

        .select2-container--open {
            z-index: 9999 !important;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
            flex-shrink: 0;
        }

        .sidebar a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .sidebar a:hover {
            background-color: #004080;
        }

        .main-content {
            flex-grow: 1;
            padding: 20px;
            background-color: #ffffff;
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
            border-radius: 10px;
        }

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .back-btn {
            background-color: #002c5f;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .back-btn:hover {
            background-color: #004080;
        }

        .user-info p {
            color: black;
        }

        .otp-form-container {
            margin-top: 20px;
        }

        .otp-form-container input[type="text"] {
            max-width: 200px;
            margin: 0 auto;
        }

        .modal-body table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .modal-body table th,
        .modal-body table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .modal-body table th {
            background-color: #1d3557;
            color: #fff;
            font-weight: bold;
        }

        .modal-body table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('update_tasks') || hasPermission('update_tasks_all')): ?>
                <a href="task-actions.php">Task Actions</a>
            <?php endif; ?>
            <?php if (hasPermission('tasks_archive')): ?>
                <a href="archived-tasks.php">Tasks Archive</a>
            <?php endif; ?>
            <?php if (hasPermission('read_users')): ?>
                <a href="view-users.php">View Users</a>
            <?php endif; ?>
            <?php if (hasPermission('read_roles_&_departments')): ?>
                <a href="view-roles-departments.php">View Role or Department</a>
            <?php endif; ?>
            <?php if (hasPermission('read_&_write_privileges')): ?>
                <a href="assign-privilege.php">Assign & View Privileges</a>
            <?php endif; ?>
        </div>

        <div class="main-content">
            <div class="navbar">
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($user_username) ?></strong></p>
                    <p class="mb-0">Departments:
                        <strong><?= !empty($user_departments) ? htmlspecialchars(implode(', ', $user_departments)) : 'None' ?></strong>
                    </p>
                </div>
                <button class="back-btn" onclick="window.location.href='welcome.php'">Dashboard</button>
            </div>
            <div class="main-container">
                <div class="container">
                    <h1>Users</h1>

                    <?php if (isset($_SESSION['successMsg'])): ?>
                        <div class="success-message"><?= htmlspecialchars($_SESSION['successMsg']) ?></div>
                        <?php unset($_SESSION['successMsg']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['deletionMsg'])): ?>
                        <div class="success-message"><?= htmlspecialchars($_SESSION['deletionMsg']) ?></div>
                        <?php unset($_SESSION['deletionMsg']); ?>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['errorMsg'])): ?>
                        <div class="deletion-message"><?= htmlspecialchars($_SESSION['errorMsg']) ?></div>
                        <?php unset($_SESSION['errorMsg']); ?>
                    <?php endif; ?>

                    <?php if (hasPermission('read_all_users')): ?>
                        <p>Viewing all users</p>
                        <?php if (!empty($users)): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Departments</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $count = 1; ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= $count++ ?></td>
                                            <td><?= htmlspecialchars($user['username']) ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><?= htmlspecialchars($user['role_name']) ?></td>
                                            <td><?= !empty($user['departments']) ? htmlspecialchars($user['departments']) : 'None' ?>
                                            </td>
                                            <td>
                                                <a href='edit-user.php?id=<?= urlencode($user['id']) ?>'
                                                    class='edit-button'>Edit</a>
                                                <form action='view-users.php' method='POST' style='display:inline;'>
                                                    <input type='hidden' name='user_id'
                                                        value='<?= htmlspecialchars($user['id']) ?>'>
                                                    <input type='hidden' name='initiate_delete' value='1'>
                                                    <button type='submit' class='delete-button'
                                                        onclick='return confirm("Are you sure you want to initiate deletion of this user? An OTP will be sent for confirmation.")'>Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No users found.</p>
                        <?php endif; ?>
                    <?php elseif (hasPermission('read_department_users')): ?>
                        <p>Viewing users in your department(s):
                            <strong><?= htmlspecialchars(implode(', ', $user_departments)) ?></strong>
                        </p>
                        <?php if (!empty($users)): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Departments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($user['username']) ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td><?= htmlspecialchars($user['role_name']) ?></td>
                                            <td><?= !empty($user['departments']) ? htmlspecialchars($user['departments']) : 'None' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No users found.</p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div style="text-align: right; margin-top: 20px;">
                        <a type="button" class="back-button" data-bs-toggle="modal"
                            data-bs-target="#createUserModal">Create User</a>
                    </div>

                    <?php if (hasPermission('read_all_users')): ?>
                        <div style="text-align: right; margin-top: 20px;">
                            <button type="button" class="back-button" data-bs-toggle="modal"
                                data-bs-target="#auditTrailModal">View Deletion Audit Trail</button>
                        </div>
                    <?php endif; ?>

                    <!-- Modal for Audit Trail -->
                    <?php if (hasPermission('read_all_users')): ?>
                        <div class="modal fade" id="auditTrailModal" tabindex="-1" aria-labelledby="auditTrailModalLabel"
                            aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title text-center w-100" id="auditTrailModalLabel">User Deletion
                                            Audit Trail</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <?php if (!empty($auditRecords)): ?>
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Username</th>
                                                        <th>Email</th>
                                                        <th>Role</th>
                                                        <th>Departments</th>
                                                        <th>Deleted By</th>
                                                        <th>Deleted At</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $auditCount = 1; ?>
                                                    <?php foreach ($auditRecords as $record): ?>
                                                        <tr>
                                                            <td><?= $auditCount++ ?></td>
                                                            <td><?= htmlspecialchars($record['username']) ?></td>
                                                            <td><?= htmlspecialchars($record['email']) ?></td>
                                                            <td>
                                                                <?php
                                                                $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                                                                $roleStmt->execute([$record['role_id']]);
                                                                echo htmlspecialchars($roleStmt->fetchColumn());
                                                                ?>
                                                            </td>
                                                            <td><?= htmlspecialchars($record['departments'] ?: 'None') ?></td>
                                                            <td><?= htmlspecialchars($record['deleted_by_username']) ?></td>
                                                            <td><?= htmlspecialchars($record['deleted_at']) ?></td>
                                                            <td>
                                                                <form action="view-users.php" method="POST" style="display:inline;">
                                                                    <input type="hidden" name="restore_user_id"
                                                                        value="<?= $record['user_id'] ?>">
                                                                    <button type="submit" class="edit-button"
                                                                        onclick="return confirm('Are you sure you want to restore this user?')">Restore</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else: ?>
                                            <p>No deletion records found.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- OTP Verification Form for Creation -->
                    <?php if (isset($_SESSION['showOtpForm']) && $_SESSION['showOtpForm']): ?>
                        <div class="otp-form-container">
                            <h2>Verify OTP for Creation</h2>
                            <form method="POST" action="view-users.php">
                                <div class="form-group">
                                    <label for="otp">Enter OTP sent to
                                        <?= htmlspecialchars($_SESSION['pending_user']['email']) ?></label>
                                    <input type="text" id="otp" name="otp" placeholder="Enter 6-digit OTP" required
                                        maxlength="6" pattern="\d{6}">
                                </div>
                                <button type="submit" class="back-button">Verify OTP</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- OTP Verification Form for Deletion -->
                    <?php if (isset($_SESSION['showDeleteOtpForm']) && $_SESSION['showDeleteOtpForm']): ?>
                        <div class="otp-form-container">
                            <h2>Verify OTP for Deletion</h2>
                            <form method="POST" action="delete-user.php">
                                <div class="form-group">
                                    <label for="delete_otp">Enter OTP sent to
                                        <?= htmlspecialchars($_SESSION['pending_deletion']['email']) ?></label>
                                    <input type="text" id="delete_otp" name="otp" placeholder="Enter 6-digit OTP" required
                                        maxlength="6" pattern="\d{6}">
                                    <input type="hidden" name="user_id"
                                        value="<?= htmlspecialchars($_SESSION['pending_deletion']['user_id']) ?>">
                                </div>
                                <button type="submit" class="back-button">Confirm Deletion</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Create User -->
    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-center w-100" id="createUserModalLabel">Create User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createUserForm" method="POST" action="view-users.php">
                        <div class="form-group mb-3">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                            <div id="passwordError" class="text-danger" style="display: none;">
                                Password must contain at least one uppercase letter, one number, one special character,
                                and be at least 8 characters long.
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="role">Role</label>
                            <select id="role" name="role" class="form-control" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label for="departments">Departments</label>
                            <select id="departments" name="departments[]" class="form-control" multiple required>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>"><?= htmlspecialchars($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Request OTP</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Jquery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Select 2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#departments').select2({
                placeholder: "Select departments",
                allowClear: true,
                closeOnSelect: false
            });
        });

        document.getElementById('createUserForm').addEventListener('submit', function (event) {
            const password = document.getElementById('password').value;
            const passwordError = document.getElementById('passwordError');
            const departments = document.getElementById('departments');

            if (!validatePassword(password)) {
                event.preventDefault();
                passwordError.style.display = 'block';
            } else {
                passwordError.style.display = 'none';
            }

            if (departments.selectedOptions.length === 0) {
                event.preventDefault();
                alert('Please select at least one department.');
            }
        });

        function validatePassword(password) {
            const uppercase = /[A-Z]/.test(password);
            const number = /\d/.test(password);
            const specialChar = /[^\w]/.test(password);
            return uppercase && number && specialChar && password.length >= 8;
        }
    </script>
</body>

</html>