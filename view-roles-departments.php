<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require 'permissions.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'Admin') {
    header("Location: portal-login.html");
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

$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_login_system_2;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Initialize error and success messages
    $errorMsg = $_SESSION['errorMsg'] ?? "";
    $successMsg = $_SESSION['successMsg'] ?? "";

    // Clear the messages from the session after displaying them
    unset($_SESSION['errorMsg']);
    unset($_SESSION['successMsg']);

    // Handle form submission for creating a new role
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {
        $roleName = trim($_POST['role_name']);

        if (empty($roleName)) {
            $_SESSION['errorMsg'] = "Role name is required.";
        } else {
            // Check if the role already exists
            $checkStmt = $pdo->prepare("SELECT id FROM roles WHERE name = :name");
            $checkStmt->bindParam(':name', $roleName);
            $checkStmt->execute();

            if ($checkStmt->rowCount() > 0) {
                $_SESSION['errorMsg'] = "Role already exists.";
            } else {
                // Insert the new role into the database
                $insertStmt = $pdo->prepare("INSERT INTO roles (name) VALUES (:name)");
                $insertStmt->bindParam(':name', $roleName);

                if ($insertStmt->execute()) {
                    $_SESSION['successMsg'] = "Role created successfully.";
                } else {
                    $_SESSION['errorMsg'] = "Failed to add role. Please try again.";
                }
            }
        }

        // Redirect to the same page to avoid form resubmission
        header("Location: view-roles-departments.php");
        exit;
    }

    // Handle form submission for creating a new department
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_department'])) {
        $departmentName = trim($_POST['department_name']);

        if (empty($departmentName)) {
            $_SESSION['errorMsg'] = "Department name is required.";
        } else {
            // Check if the department already exists
            $checkStmt = $pdo->prepare("SELECT id FROM departments WHERE name = :name");
            $checkStmt->bindParam(':name', $departmentName);
            $checkStmt->execute();

            if ($checkStmt->rowCount() > 0) {
                $_SESSION['errorMsg'] = "Department already exists.";
            } else {
                // Insert the new department into the database
                $insertStmt = $pdo->prepare("INSERT INTO departments (name) VALUES (:name)");
                $insertStmt->bindParam(':name', $departmentName);

                if ($insertStmt->execute()) {
                    $_SESSION['successMsg'] = "Department created successfully.";
                } else {
                    $_SESSION['errorMsg'] = "Failed to add department. Please try again.";
                }
            }
        }

        // Redirect to the same page to avoid form resubmission
        header("Location: view-roles-departments.php");
        exit;
    }

    // Fetch the logged-in user's departments from the user_departments table
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("
        SELECT d.name 
        FROM user_departments ud
        JOIN departments d ON ud.department_id = d.id
        WHERE ud.user_id = :user_id
    ");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user_departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch all roles
    $rolesQuery = $pdo->query("SELECT id, name FROM roles");
    $roles = $rolesQuery->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all departments
    $departmentsQuery = $pdo->query("SELECT id, name FROM departments");
    $departments = $departmentsQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Roles & Departments</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        * {
            box-sizing: border-box;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
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

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .logout-btn {
            background-color: #ff4d4d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .logout-btn:hover {
            background-color: #ff1a1a;
        }

        .container {
            width: 100%;
            max-width: 1400px;
            margin: 20px auto;
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
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #002c5f;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            transition: background-color 0.3s ease;
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

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
            border-radius: 10px;
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
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <h3>Menu</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('read_users', 'Users')): ?>
                <a href="view-users.php">View Users</a>
            <?php endif; ?>
            <?php if (hasPermission('read_roles_&_departments', 'Roles & Departments')): ?>
                <a href="view-roles-departments.php">View Role or Department</a>
            <?php endif; ?>
            <?php if (hasPermission('read_&_write_privileges', 'Privileges')): ?>
                <a href="assign-privilege.php">Assign & View Privileges</a>
            <?php endif; ?>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <!-- Logo Container -->
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>

                <!-- User Info -->
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong></p>
                    <p class="mb-0">Departments:
                        <strong><?= !empty($user_departments) ? htmlspecialchars(implode(', ', $user_departments)) : 'None' ?></strong>
                    </p>
                </div>

                <!-- Back Button -->
                <button class="back-btn" onclick="window.location.href='welcome.php'">Back</button>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="container">
                    <h1>Roles & Departments</h1>

                    <!-- Display error or success messages -->
                    <?php if (!empty($errorMsg)): ?>
                        <div class="error"><?= htmlspecialchars($errorMsg) ?></div>
                    <?php elseif (!empty($successMsg)): ?>
                        <div class="success"><?= htmlspecialchars($successMsg) ?></div>
                    <?php endif; ?>

                    <!-- Roles Table -->
                    <h2>Roles</h2>
                    <?php if (!empty($roles)): ?>
                        <table>
                            <colgroup>
                                <col style="width: 10%">
                                <col style="width: 60%">
                                <col style="width: 30%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Role Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rcount = 1 ?>
                                <?php foreach ($roles as $role): ?>
                                    <tr>
                                        <td><?= $rcount++ ?></td>
                                        <td><?= htmlspecialchars($role['name']) ?></td>
                                        <td>
                                            <a href="edit-role.php?id=<?= urlencode($role['id']) ?>"
                                                class="edit-button">Edit</a>
                                            <form action="delete-role.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="role_id"
                                                    value="<?= htmlspecialchars($role['id']) ?>">
                                                <button type="submit" class="delete-button"
                                                    onclick="return confirm('Are you sure you want to delete this role?')">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No roles found.</p>
                    <?php endif; ?>

                    <!-- Departments Table -->
                    <h2>Departments</h2>
                    <?php $dcount = 1 ?>
                    <?php if (!empty($departments)): ?>
                        <table>
                            <colgroup>
                                <col style="width: 10%">
                                <col style="width: 60%">
                                <col style="width: 30%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Department Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $department): ?>
                                    <tr>
                                        <td><?= $dcount++ ?></td>
                                        <td><?= htmlspecialchars($department['name']) ?></td>
                                        <td>
                                            <a href="edit-department.php?id=<?= urlencode($department['id']) ?>"
                                                class="edit-button">Edit</a>
                                            <form action="delete-department.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="department_id"
                                                    value="<?= htmlspecialchars($department['id']) ?>">
                                                <button type="submit" class="delete-button"
                                                    onclick="return confirm('Are you sure you want to delete this department?')">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No departments found.</p>
                    <?php endif; ?>

                    <!-- Centered modal buttons -->
                    <div class="modal-buttons" style="text-align: right; margin-top: 20px;">
                        <a type="button" class="back-button" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                            Add Role
                        </a>
                        <a type="button" class="back-button" data-bs-toggle="modal"
                            data-bs-target="#createDepartmentModal">
                            Add Department
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for creating new role -->
    <div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createRoleModalLabel">Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="role_name" class="form-label">Role Name</label>
                            <input type="text" class="form-control" id="role_name" name="role_name" required>
                        </div>
                        <button type="submit" name="create_role" class="btn btn-primary">Add Role</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for creating new department -->
    <div class="modal fade" id="createDepartmentModal" tabindex="-1" aria-labelledby="createDepartmentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createDepartmentModalLabel">Add Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="department_name" class="form-label">Department Name</label>
                            <input type="text" class="form-control" id="department_name" name="department_name"
                                required>
                        </div>
                        <button type="submit" name="create_department" class="btn btn-primary">Create
                            Department</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>