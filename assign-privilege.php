<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require 'permissions.php'; // Check if the user is logged in and has admin role 
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'Admin') {
    header("Location: portal-login.html");
    exit;
} // Session timeout for 20 mins
$timeout_duration = 1200;
if (
    isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) >
    $timeout_duration
) {
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

    $roles = $pdo->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
    $modules = $pdo->query("SELECT id, name FROM modules")->fetchAll(PDO::FETCH_ASSOC);
    // Fetch permissions with their module information
    $permissions = $pdo->query("
    SELECT p.id, p.name, m.name AS module_name, m.id AS module_id
    FROM permissions p
    JOIN role_permissions rp ON p.id = rp.permission_id
    JOIN modules m ON rp.module_id = m.id
    GROUP BY p.id, m.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Group permissions by module
    $groupedPermissions = [];
    foreach ($permissions as $permission) {
        $moduleId = $permission['module_id'];
        if (!isset($groupedPermissions[$moduleId])) {
            $groupedPermissions[$moduleId] = [
                'module_name' => $permission['module_name'],
                'permissions' => []
            ];
        }
        $groupedPermissions[$moduleId]['permissions'][] = $permission;
    }

    $errorMsg = $_SESSION['errorMsg'] ?? "";
    $successMsg = $_SESSION['successMsg'] ?? "";
    unset($_SESSION['errorMsg'], $_SESSION['successMsg']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_permissions'])) {
        $role_id = $_POST['role_id'];
        $module_id = $_POST['module_id'];
        $permission_ids = $_POST['permissions'] ?? [];

        // Add validation check
        if (empty($role_id)) {
            $_SESSION['errorMsg'] = "Please select a Role to assign or revoke privileges.";
            header("Location: assign-privilege.php");
            exit;
        }

        // Delete existing permissions for the role and module
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND module_id = ?")
            ->execute([$role_id, $module_id]);

        // Insert new permissions for the role and module
        foreach ($permission_ids as $permission_id) {
            $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, module_id) VALUES (?, ?, ?)")
                ->execute([$role_id, $permission_id, $module_id]);
        }

        // If no permissions were selected, delete all permissions for the role and module
        if (empty($permission_ids)) {
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND module_id = ?")
                ->execute([$role_id, $module_id]);
        }

        $_SESSION['successMsg'] = "Privileges updated successfully.";
        header("Location: assign-privilege.php");
        exit;
    }

    $role_permissions = $pdo->query("SELECT r.name as role_name, m.name as module_name, GROUP_CONCAT(p.name SEPARATOR ',
    ') as permissions
    FROM role_permissions rp
    JOIN roles r ON rp.role_id = r.id
    JOIN modules m ON rp.module_id = m.id
    JOIN permissions p ON rp.permission_id = p.id
    GROUP BY rp.role_id, rp.module_id")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
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


function formatPermissionName($permissionName)
{
    // Split permission name by underscore into array of words
    $words = explode('_', $permissionName);
    // Capitalize first letter of each word using ucwords()
    $formattedWords = array_map('ucwords', $words);
    // Join words back together with spaces between them
    return implode(' ', $formattedWords);
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

        .module-group {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .module-group h4 {
            margin-bottom: 10px;
            color: #1d3557;
            font-size: 1.2rem;
        }

        .permissions-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
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
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
                    </p>
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
                    <!-- Assign Permissions Section -->
                    <div class="styled-box">
                        <h1>Assign Privileges</h1>
                        <?php if ($errorMsg): ?>
                            <div class="alert alert-danger"> <?= htmlspecialchars($errorMsg) ?> </div>
                        <?php elseif ($successMsg): ?>
                            <div class="alert alert-success"> <?= htmlspecialchars($successMsg) ?> </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="role_id" class="form-label">Select Role</label>
                                <select name="role_id" id="role_id" class="form-select" required>
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['id'] ?>"> <?= htmlspecialchars($role['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="module_id" class="form-label">Select Module</label>
                                <select name="module_id" id="module_id" class="form-select" required>
                                    <option value="">-- Select Module --</option>
                                    <?php foreach ($modules as $module): ?>
                                        <option value="<?= $module['id'] ?>"> <?= htmlspecialchars($module['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Select Previleges</label>
                                <?php foreach ($groupedPermissions as $moduleId => $moduleData): ?>
                                    <div class="module-group mb-4">
                                        <h4><?= htmlspecialchars($moduleData['module_name']) ?></h4>
                                        <div class="permissions-list">
                                            <?php foreach ($moduleData['permissions'] as $permission): ?>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="permissions[]"
                                                        value="<?= $permission['id'] ?>" data-module-id="<?= $moduleId ?>">
                                                    <label
                                                        class="form-check-label"><?= formatPermissionName($permission['name']) ?></label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" name="assign_permissions" class="back-button">Assign
                                Permissions</button>
                        </form>
                    </div>

                    <!-- Current Role Permissions Section -->
                    <div class="styled-box">
                        <h1>Current Role Privileges</h1>
                        <table>
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Module</th>
                                    <th>Privileges</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($role_permissions as $rp): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($rp['role_name']) ?></td>
                                        <td><?= htmlspecialchars($rp['module_name']) ?></td>
                                        <td><?= htmlspecialchars(formatPermissionName($rp['permissions'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Get the role and module select elements
        const roleSelect = document.getElementById('role_id');
        const moduleSelect = document.getElementById('module_id');

        // Get all the checkboxes
        const checkboxes = document.querySelectorAll('.form-check-input');

        // Add event listeners to the role and module select elements
        roleSelect.addEventListener('change', function () {
            // Get the selected role ID
            const roleId = this.value;

            // Clear all checkboxes
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = false;
            });

            // Get the role privileges
            fetch('get-role-privileges.php?role_id=' + roleId)
                .then(response => response.json())
                .then(data => {
                    // Check the checkboxes based on the role privileges
                    data.forEach(function (privilege) {
                        const checkbox = document.querySelector(`input[name="permissions[]"][value="${privilege.permission_id}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                })
                .catch(error => {
                    console.error('Error fetching role privileges:', error);
                    // Handle the error here
                    alert('Error fetching role privileges: ' + error.message);
                });
        });

        moduleSelect.addEventListener('change', function () {
            // Get the selected module ID
            const moduleId = this.value;

            // Get the role ID
            const roleId = roleSelect.value;

            // Get the role privileges for the selected role and module
            fetch('get-role-privileges.php?role_id=' + roleId + '&module_id=' + moduleId)
                .then(response => response.json())
                .then(data => {
                    // Clear all checkboxes
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = false;
                    });

                    // Check the checkboxes based on the filtered privileges
                    data.forEach(function (privilege) {
                        const checkbox = document.querySelector(`input[name="permissions[]"][value="${privilege.permission_id}"][data-module-id="${moduleId}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                })
                .catch(error => {
                    console.error('Error fetching role privileges:', error);
                    // Handle the error here
                    alert('Error fetching role privileges: ' + error.message);
                });
        });
    </script>
</body>

</html>