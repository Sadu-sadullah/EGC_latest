<?php

require 'permissions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_login_system;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

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
$user_departments = $_SESSION['departments'] ?? []; // Fetch departments from session
$user_username = $_SESSION['username'];

// Function to validate password complexity
function validatePassword($password)
{
    // Password must contain at least one uppercase letter, one number, and one special character
    $uppercase = preg_match('@[A-Z]@', $password);
    $number = preg_match('@\d@', $password);
    $specialChar = preg_match('@[^\w]@', $password); // Matches any non-word character

    if (!$uppercase || !$number || !$specialChar || strlen($password) < 8) {
        return false;
    }
    return true;
}

// Fetch users based on role
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Prepare and execute the query to fetch the session token
    $checkStmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
    $checkStmt->execute([$_SESSION['user_id']]);
    $sessionToken = $checkStmt->fetchColumn();

    // If the session token doesn't match, log the user out
    if ($sessionToken !== $_SESSION['session_token']) {
        session_unset();
        session_destroy();
        echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
    }

    if (hasPermission('read_all_users')) {
        // Admin: View all users except Admins
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
        // Manager: View only users in the same department(s), excluding Admins and their own account
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
        // Bind department names and user ID to the query
        $params = array_merge($user_departments, [$user_id]);
        $stmt->execute($params);
    } else {
        // Unauthorized access
        echo "You do not have the required permissions to view this page.";
        exit;
    }

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch roles and departments for the modal
    $roles = [];
    $roleQuery = $pdo->query("SELECT id, name FROM roles WHERE name != 'Admin'");
    if ($roleQuery) {
        $roles = $roleQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    $departments = [];
    $departmentQuery = $pdo->query("SELECT id, name FROM departments");
    if ($departmentQuery) {
        $departments = $departmentQuery->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Handle form submission for creating a user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role_id = intval($_POST['role']);
    $department_ids = $_POST['departments'];

    // Validate inputs
    if (empty($username) || empty($email) || empty($password) || empty($role_id) || empty($department_ids)) {
        $errorMsg = "Please fill in all fields.";
    } else {
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            // Insert the user into the `users` table
            $insertUserQuery = "INSERT INTO users (username, email, password, role_id) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($insertUserQuery);
            $stmt->execute([$username, $email, $hashedPassword, $role_id]);

            // Get the last inserted user ID
            $newUserId = $pdo->lastInsertId();

            // Insert the user-department relationships into the `user_departments` table
            $insertUserDepartmentQuery = "INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)";
            $stmt = $pdo->prepare($insertUserDepartmentQuery);

            foreach ($department_ids as $department_id) {
                $stmt->execute([$newUserId, intval($department_id)]);
            }

            // Set success message and refresh the page
            $_SESSION['successMsg'] = "User created successfully.";
            header("Location: view-users.php");
            exit;
        } catch (PDOException $e) {
            $errorMsg = "Failed to create user: " . $e->getMessage();
        }
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
            max-width: 1400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
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
            padding: 10px 20px;
            background-color: #002c5f;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 1rem;
            transition: background-color 0.3s ease;
            margin-top: 20px;
            /* Add margin to separate it from the table */
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

        /* Sidebar and Navbar Styles */
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
            width: 100%;
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
    </style>
</head>

<body>
    <!-- Sidebar and Navbar -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <h3>Menu</h3>
            <a href="tasks.php">Tasks</a>
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
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($user_username) ?></strong></p>
                    <p class="mb-0">Departments:
                        <strong><?= !empty($user_departments) ? htmlspecialchars(implode(', ', $user_departments)) : 'None' ?></strong>
                    </p>
                </div>

                <!-- Back Button -->
                <button class="back-btn" onclick="window.location.href='welcome.php'">Back</button>
            </div>
            <div class="main-container">
                <div class="container">
                    <h1>Users</h1>

                    <?php if (isset($_SESSION['successMsg'])): ?>
                        <div class="success-message">
                            <?= htmlspecialchars($_SESSION['successMsg']) ?>
                        </div>
                        <?php unset($_SESSION['successMsg']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['deletionMsg'])): ?>
                        <div class="deletion-message">
                            <?= htmlspecialchars($_SESSION['deletionMsg']) ?>
                        </div>
                        <?php unset($_SESSION['deletionMsg']); ?>
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
                                    <?php $count = 1 ?>
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
                                                <form action='delete-user.php' method='POST' style='display:inline;'>
                                                    <input type='hidden' name='user_id'
                                                        value='<?= htmlspecialchars($user['id']) ?>'>
                                                    <button type='submit' class='delete-button'
                                                        onclick='return confirm("Are you sure you want to delete this user?")'>Delete</button>
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
                        <a type="button" class="back-button" data-bs-toggle="modal" data-bs-target="#createUserModal">
                            Create User
                        </a>
                    </div>
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
                            <!-- Error message for password validation -->
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
                            <button type="submit" class="btn btn-primary">Create User</button>
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

    <script>
        // Initialize Select2 for the departments dropdown
        $(document).ready(function () {
            $('#departments').select2({
                placeholder: "Select departments", // Placeholder text
                allowClear: true, // Allow clearing selections
                closeOnSelect: false // Keep the dropdown open after selecting an item
            });
        });

        // Password validation function
        function validatePassword(password) {
            const uppercase = /[A-Z]/.test(password);
            const number = /\d/.test(password);
            const specialChar = /[^\w]/.test(password);

            if (!uppercase || !number || !specialChar || password.length < 8) {
                return false;
            }
            return true;
        }

        // Add form submission validation
        document.getElementById('createUserForm').addEventListener('submit', function (event) {
            const password = document.getElementById('password').value;
            const passwordError = document.getElementById('passwordError');
            const departments = document.getElementById('departments');

            // Validate password
            if (!validatePassword(password)) {
                event.preventDefault(); // Prevent form submission
                passwordError.style.display = 'block'; // Show the error message
            } else {
                passwordError.style.display = 'none'; // Hide the error message if valid
            }

            // Validate departments
            if (departments.selectedOptions.length === 0) {
                event.preventDefault(); // Prevent form submission
                alert('Please select at least one department.'); // Show an alert
            }
        });
    </script>

    <script>
        // Open the modal using Bootstrap's modal method
        function openModal() {
            const modal = new bootstrap.Modal(document.getElementById('createUserModal'));
            modal.show();
        }

        // Close the modal using Bootstrap's modal method
        function closeModal() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('createUserModal'));
            modal.hide();
        }

        // Password validation function
        function validatePassword(password) {
            const uppercase = /[A-Z]/.test(password);
            const number = /\d/.test(password);
            const specialChar = /[^\w]/.test(password);

            if (!uppercase || !number || !specialChar || password.length < 8) {
                return false;
            }
            return true;
        }

        // Add form submission validation
        document.getElementById('createUserForm').addEventListener('submit', function (event) {
            const password = document.getElementById('password').value;
            const passwordError = document.getElementById('passwordError');

            if (!validatePassword(password)) {
                event.preventDefault(); // Prevent form submission
                passwordError.style.display = 'block'; // Show the error message
            } else {
                passwordError.style.display = 'none'; // Hide the error message if valid
            }

            // Validate departments
            if (departments.selectedOptions.length === 0) {
                event.preventDefault(); // Prevent form submission
                alert('Please select at least one department.'); // Show an alert
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>