<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require 'permissions.php';

// Check if the user is logged in, has a selected role, and has the required permission
if (!isset($_SESSION['loggedin']) || !isset($_SESSION['selected_role_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Session timeout for 20 minutes
$timeout_duration = 1200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Session expired']);
    exit;
}
$_SESSION['last_activity'] = time();

// Database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify session token
    $checkStmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
    $checkStmt->execute([$_SESSION['user_id']]);
    $sessionToken = $checkStmt->fetchColumn();
    if ($sessionToken !== $_SESSION['session_token']) {
        session_unset();
        session_destroy();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session invalid. Please log in again.']);
        exit;
    }

    // Get and validate the role ID from the query string
    $roleId = isset($_GET['role_id']) ? filter_var($_GET['role_id'], FILTER_VALIDATE_INT) : false;
    if ($roleId === false || $roleId <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid role ID']);
        exit;
    }

    // Verify the role exists
    $roleCheckStmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $roleCheckStmt->execute([$roleId]);
    if (!$roleCheckStmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Role not found']);
        exit;
    }

    // Query to get all privileges for the selected role
    $query = "
        SELECT p.id, p.name, m.id AS module_id, rp.role_id
        FROM permissions p
        JOIN modules m ON p.module_id = m.id
        LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = ?
    ";
    $statement = $pdo->prepare($query);
    $statement->execute([$roleId]);

    // Fetch all privileges
    $allPrivileges = $statement->fetchAll(PDO::FETCH_ASSOC);

    // Group privileges by module
    $assignedPrivileges = [];
    foreach ($allPrivileges as $privilege) {
        $moduleId = $privilege['module_id'];
        if (!isset($assignedPrivileges[$moduleId])) {
            $assignedPrivileges[$moduleId] = [];
        }
        if ($privilege['role_id']) { // Check if the privilege is assigned to the role
            $assignedPrivileges[$moduleId][] = $privilege['id'];
        }
    }

    // Return the assigned privileges as JSON
    header('Content-Type: application/json');
    echo json_encode($assignedPrivileges);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>