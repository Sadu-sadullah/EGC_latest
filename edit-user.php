<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'permissions.php';
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

// Check if the user is logged in and has permissions
$user_id = $_SESSION['user_id'] ?? null;
$selected_role_id = $_SESSION['selected_role_id'] ?? null;
$user_departments = $_SESSION['departments'] ?? [];

if ($user_id === null || $selected_role_id === null || (!hasPermission('read_all_users') && !hasPermission('read_department_users'))) {
    header("Location: portal-login.html");
    exit;
}

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($userId <= 0) {
        $_SESSION['errorMsg'] = "Invalid user ID.";
        header("Location: view-users.php");
        exit;
    }

    // Verify user exists and is accessible
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email,
               GROUP_CONCAT(DISTINCT r.id SEPARATOR ',') AS role_ids,
               GROUP_CONCAT(DISTINCT d.id SEPARATOR ',') AS department_ids
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        LEFT JOIN user_departments ud ON u.id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.id
        WHERE u.id = :id
        GROUP BY u.id
    ");
    $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['errorMsg'] = "User not found.";
        header("Location: view-users.php");
        exit;
    }

    // Restrict Managers to their department
    if (hasPermission('read_department_users') && !hasPermission('read_all_users')) {
        $deptStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_departments ud
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.user_id = ? AND d.name IN (" . implode(',', array_fill(0, count($user_departments), '?')) . ")
        ");
        $deptStmt->execute(array_merge([$userId], $user_departments));
        if ($deptStmt->fetchColumn() == 0) {
            $_SESSION['errorMsg'] = "You do not have permission to edit this user.";
            header("Location: view-users.php");
            exit;
        }
    }

    $username = $user['username'];
    $userRoleIds = $user['role_ids'] ? explode(',', $user['role_ids']) : [];
    $userDepartmentIds = $user['department_ids'] ? explode(',', $user['department_ids']) : [];

    // Fetch all roles except 'Admin'
    $roles = $pdo->query("SELECT id, name FROM roles WHERE name != 'Admin'")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all departments
    $departments = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['otp'])) {
            // Step 2: Verify OTP and update user
            $otp = trim($_POST['otp']);
            $pending_update = $_SESSION['pending_user_update'] ?? [];

            if (empty($pending_update)) {
                $error = "Session expired. Please start the update process again.";
                error_log("Edit User Error: Session expired for user_id $userId");
            } elseif ($otp !== $pending_update['otp'] || time() > $pending_update['otp_expiry']) {
                $error = "Invalid or expired OTP.";
                $_SESSION['showOtpForm'] = true;
                error_log("Edit User Error: Invalid or expired OTP for user_id $userId");
            } else {
                $email = $pending_update['email'];
                $role_ids = $pending_update['role_ids'];
                $selectedDepartments = $pending_update['departments'];

                if (hasPermission('read_all_users')) {
                    // Validate role IDs
                    $validRoleIds = array_column($roles, 'id');
                    if (array_diff($role_ids, $validRoleIds)) {
                        $error = "Invalid role(s) selected.";
                    } else {
                        // Update email
                        $updateStmt = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
                        $updateStmt->bindParam(':email', $email);
                        $updateStmt->bindParam(':id', $userId);
                        $updateStmt->execute();

                        // Update roles
                        $deleteRolesStmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id");
                        $deleteRolesStmt->bindParam(':user_id', $userId);
                        $deleteRolesStmt->execute();

                        if (!empty($role_ids)) {
                            $insertRoleStmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
                            foreach ($role_ids as $role_id) {
                                $insertRoleStmt->bindParam(':user_id', $userId);
                                $insertRoleStmt->bindParam(':role_id', $role_id);
                                $insertRoleStmt->execute();
                            }
                        }

                        // Update departments
                        $deleteDeptStmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = :user_id");
                        $deleteDeptStmt->bindParam(':user_id', $userId);
                        $deleteDeptStmt->execute();

                        if (!empty($selectedDepartments)) {
                            $insertDeptStmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (:user_id, :department_id)");
                            foreach ($selectedDepartments as $department_id) {
                                $insertDeptStmt->bindParam(':user_id', $userId);
                                $insertDeptStmt->bindParam(':department_id', $department_id);
                                $insertDeptStmt->execute();
                            }
                        }

                        $success = "User updated successfully after email verification.";
                    }
                } elseif (hasPermission('read_department_users')) {
                    // Managers can only update email
                    $updateStmt = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
                    $updateStmt->bindParam(':email', $email);
                    $updateStmt->bindParam(':id', $userId);
                    $updateStmt->execute();
                    $success = "Email updated successfully after verification.";
                }

                // Clear session data
                unset($_SESSION['pending_user_update']);
                unset($_SESSION['showOtpForm']);
            }
        } else {
            // Step 1: Process form and send OTP if email changed
            $email = trim($_POST['email']);
            $role_ids = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : $userRoleIds;
            $selectedDepartments = isset($_POST['departments']) && is_array($_POST['departments']) ? array_map('intval', $_POST['departments']) : $userDepartmentIds;

            if (empty($email)) {
                $error = "Email is required.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email address.";
            } else {
                // Check if email is already used by another user
                $emailCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
                $emailCheckStmt->bindParam(':email', $email);
                $emailCheckStmt->bindParam(':id', $userId);
                $emailCheckStmt->execute();
                if ($emailCheckStmt->fetchColumn() > 0) {
                    $error = "Email is already in use by another user.";
                } elseif ($email !== $user['email']) {
                    // Email has changed, send OTP
                    $otp = sprintf("%06d", mt_rand(0, 999999));
                    $_SESSION['pending_user_update'] = [
                        'email' => $email,
                        'role_ids' => $role_ids,
                        'departments' => $selectedDepartments,
                        'otp' => $otp,
                        'otp_expiry' => time() + 900 // 15 minutes
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
                        $mail->Subject = 'Verify Your New Email - OTP';
                        $mail->Body = "Hello,<br><br>Your One-Time Password (OTP) to verify your new email for user update is: <strong>$otp</strong><br><br>This OTP will expire in 15 minutes.<br><br>Please provide this OTP to complete the update.";
                        $mail->AltBody = "Hello,\n\nYour OTP to verify your new email for user update is: $otp\n\nThis OTP will expire in 15 minutes.\n\nPlease provide this OTP to complete the update.";

                        $mail->send();
                        $success = "An OTP has been sent to $email. Please verify it to update the user.";
                        $_SESSION['showOtpForm'] = true;
                    } catch (Exception $e) {
                        $error = "Failed to send OTP: " . $e->getMessage();
                        error_log("PHPMailer Error in edit-user.php: " . $e->getMessage() . " | Email: $email");
                    }
                } else {
                    // Email unchanged, proceed with update
                    if (hasPermission('read_all_users')) {
                        // Validate role IDs
                        $validRoleIds = array_column($roles, 'id');
                        if (array_diff($role_ids, $validRoleIds)) {
                            $error = "Invalid role(s) selected.";
                        } else {
                            // Update email
                            $updateStmt = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
                            $updateStmt->bindParam(':email', $email);
                            $updateStmt->bindParam(':id', $userId);
                            $updateStmt->execute();

                            // Update roles
                            $deleteRolesStmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id");
                            $deleteRolesStmt->bindParam(':user_id', $userId);
                            $deleteRolesStmt->execute();

                            if (!empty($role_ids)) {
                                $insertRoleStmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
                                foreach ($role_ids as $role_id) {
                                    $insertRoleStmt->bindParam(':user_id', $userId);
                                    $insertRoleStmt->bindParam(':role_id', $role_id);
                                    $insertRoleStmt->execute();
                                }
                            }

                            // Update departments
                            $deleteDeptStmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = :user_id");
                            $deleteDeptStmt->bindParam(':user_id', $userId);
                            $deleteDeptStmt->execute();

                            if (!empty($selectedDepartments)) {
                                $insertDeptStmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (:user_id, :department_id)");
                                foreach ($selectedDepartments as $department_id) {
                                    $insertDeptStmt->bindParam(':user_id', $userId);
                                    $insertDeptStmt->bindParam(':department_id', $department_id);
                                    $insertDeptStmt->execute();
                                }
                            }

                            $success = "User updated successfully.";
                        }
                    } elseif (hasPermission('read_department_users')) {
                        // Managers can only update email
                        $updateStmt = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
                        $updateStmt->bindParam(':email', $email);
                        $updateStmt->bindParam(':id', $userId);
                        $updateStmt->execute();
                        $success = "Email updated successfully.";
                    }
                }
            }
        }
        if (isset($success)) {
            $_SESSION['successMsg'] = $success;
            header("Location: view-users.php");
            exit;
        }
    }

} catch (PDOException $e) {
    error_log("Database Error in edit-user.php: " . $e->getMessage());
    $_SESSION['errorMsg'] = "Database error occurred.";
    header("Location: view-users.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
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
            background-color: #f4f4f4;
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .user-info p {
            color: black;
            margin-bottom: 0;
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

        .form-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            margin-bottom: 20px;
            color: #1d3557;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #457b9d;
        }

        input[type="text"],
        input[type="email"],
        select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        button:hover {
            background-color: #004080;
        }

        .error {
            color: red;
            text-align: center;
            margin-bottom: 20px;
        }

        .success {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }

        .back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
            font-size: 16px;
            text-decoration: none;
            color: #004080;
        }

        .select2-container {
            width: 100% !important;
        }

        .select2-selection--multiple {
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
            color: white !important;
            margin-right: 5px !important;
            border: none !important;
            background-color: transparent !important;
            font-weight: bold !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #e63946 !important;
        }

        .select2-container--open {
            z-index: 9999 !important;
        }

        .otp-form-container {
            margin-top: 20px;
        }

        .otp-form-container input[type="text"] {
            max-width: 200px;
            margin: 0 auto;
            display: block;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('view_projects')): ?>
                <a href="projects.php">Projects</a>
            <?php endif; ?>
            <?php if (hasPermission('task_actions')): ?>
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
                    <p>Logged in as: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></p>
                    <p>Departments:
                        <strong><?= !empty($user_departments) ? htmlspecialchars(implode(', ', $user_departments)) : 'None' ?></strong>
                    </p>
                </div>
                <button class="back-btn" onclick="window.location.href='welcome.php'">Dashboard</button>
            </div>

            <div class="form-container">
                <h1>Edit User: <?= htmlspecialchars($username) ?></h1>
                <?php if (isset($error)): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php elseif (isset($success)): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['showOtpForm']) && $_SESSION['showOtpForm']): ?>
                    <div class="otp-form-container">
                        <h2>Verify OTP</h2>
                        <form method="POST">
                            <div class="form-group">
                                <label for="otp">Enter OTP sent to
                                    <?= htmlspecialchars($_SESSION['pending_user_update']['email']) ?></label>
                                <input type="text" id="otp" name="otp" placeholder="Enter 6-digit OTP" required
                                    maxlength="6" pattern="\d{6}">
                            </div>
                            <button type="submit">Verify OTP</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>"
                                required>
                        </div>
                        <?php if (hasPermission('read_all_users')): ?>
                            <div class="form-group">
                                <label for="roles">Roles</label>
                                <select id="roles" name="roles[]" multiple required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['id'] ?>" <?= in_array($role['id'], $userRoleIds) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="departments">Departments</label>
                                <select id="departments" name="departments[]" multiple required>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= $department['id'] ?>" <?= in_array($department['id'], $userDepartmentIds) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($department['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button type="submit">Save Changes</button>
                    </form>
                <?php endif; ?>
                <a href="view-users.php" class="back-link">Back</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function () {
            $('#roles').select2({
                placeholder: "Select roles",
                allowClear: true,
                closeOnSelect: false
            });
            $('#departments').select2({
                placeholder: "Select departments",
                allowClear: true,
                closeOnSelect: false
            });

            $('form').on('submit', function (e) {
                if ($('#roles').val().length === 0) {
                    e.preventDefault();
                    alert('Please select at least one role.');
                }
                if ($('#departments').val().length === 0) {
                    e.preventDefault();
                    alert('Please select at least one department.');
                }
            });
        });
    </script>
</body>

</html>