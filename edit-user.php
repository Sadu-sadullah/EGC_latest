<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

// Check if the user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'user') {
    header("Location: portal-login.html");
    exit;
}

$user_role = $_SESSION['role'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $userId = $_GET['id'];

    // Fetch user details with role and department names
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.role_id, r.name AS role_name 
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.id = :id
    ");
    $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "User not found.";
        exit;
    }

    $username = $user['username'];

    // Fetch all roles except 'admin'
    $roles = [];
    $roleQuery = $pdo->query("SELECT id, name FROM roles WHERE name != 'Admin'");
    if ($roleQuery) {
        while ($row = $roleQuery->fetch(PDO::FETCH_ASSOC)) {
            $roles[] = $row;
        }
    }

    // Fetch all departments
    $departments = [];
    $departmentQuery = $pdo->query("SELECT id, name FROM departments");
    if ($departmentQuery) {
        while ($row = $departmentQuery->fetch(PDO::FETCH_ASSOC)) {
            $departments[] = $row;
        }
    }

    // Fetch the departments assigned to the user
    $userDepartments = [];
    $userDepartmentQuery = $pdo->prepare("SELECT department_id FROM user_departments WHERE user_id = :user_id");
    $userDepartmentQuery->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $userDepartmentQuery->execute();
    while ($row = $userDepartmentQuery->fetch(PDO::FETCH_ASSOC)) {
        $userDepartments[] = $row['department_id'];
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email']);
        $role_id = isset($_POST['role']) ? intval($_POST['role']) : null; // Ensure role_id is an integer
        $selectedDepartments = isset($_POST['departments']) ? $_POST['departments'] : []; // Selected departments

        if (empty($email)) {
            $error = "Email is required.";
        } elseif ($user_role === 'Admin') { // Admin can change all fields
            // Validate role_id
            $validRoleIds = array_column($roles, 'id');
            if (!in_array($role_id, $validRoleIds)) {
                $error = "Invalid role selected.";
            } else {
                // Update user email and role
                $updateStmt = $pdo->prepare("UPDATE users SET email = :email, role_id = :role_id WHERE id = :id");
                $updateStmt->bindParam(':email', $email);
                $updateStmt->bindParam(':role_id', $role_id);
                $updateStmt->bindParam(':id', $userId);

                if ($updateStmt->execute()) {
                    // Update user departments
                    // First, delete existing department assignments
                    $deleteStmt = $pdo->prepare("DELETE FROM user_departments WHERE user_id = :user_id");
                    $deleteStmt->bindParam(':user_id', $userId);
                    $deleteStmt->execute();

                    // Insert new department assignments
                    foreach ($selectedDepartments as $department_id) {
                        $insertStmt = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (:user_id, :department_id)");
                        $insertStmt->bindParam(':user_id', $userId);
                        $insertStmt->bindParam(':department_id', $department_id);
                        $insertStmt->execute();
                    }

                    $success = "User updated successfully.";
                } else {
                    $error = "Failed to update user. Please try again.";
                }
            }
        } elseif ($user_role === 'Manager') { // Manager can only change email
            $updateStmt = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
            $updateStmt->bindParam(':email', $email);
            $updateStmt->bindParam(':id', $userId);

            if ($updateStmt->execute()) {
                $success = "Email updated successfully.";
            } else {
                $error = "Failed to update email. Please try again.";
            }
        } else {
            $error = "You do not have permission to update this user.";
        }
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <!-- Add Select2 CSS and JS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .form-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            text-align: center;
            margin-bottom: 20px;
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

        .back-btn {
            display: block;
            margin-top: 20px;
            text-align: center;
            font-size: 16px;
            text-decoration: none;
            color: #004080;
        }

        /* Select2 Styling */
        input[type="text"],
        input[type="email"],
        select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #1d3557 !important;
            border: 1px solid #1d3557 !important;
            color: white !important;
            padding: 2px 8px !important;
            margin: 2px !important;
            border-radius: 4px !important;
            display: inline-flex !important;
            align-items: center !important;
            white-space: nowrap !important;
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
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 4px !important;
        }

        .select2-container--open {
            z-index: 9999 !important;
        }
    </style>
</head>

<body>

    <div class="form-container">
        <h1>Edit User: <?= htmlspecialchars($username) ?></h1>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <?php if ($user_role == 'Admin'): ?>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>" <?= $role['id'] == $user['role_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="departments">Departments</label>
                    <select id="departments" name="departments[]" multiple required>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= $department['id'] ?>" <?= in_array($department['id'], $userDepartments) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($department['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <button type="submit">Save Changes</button>
        </form>
        <a href="view-users.php" class="back-btn">Back</a>
    </div>

    <script>
        $(document).ready(function () {
            // Initialize Select2 for the departments dropdown
            $('#departments').select2({
                placeholder: "Select departments", // Placeholder text
                allowClear: true, // Allow clearing selections
                closeOnSelect: false // Keep the dropdown open after selecting an item
            });
        });
    </script>

</body>

</html>