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
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

// Get the role ID from the query string
$roleId = $_GET['role_id'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query the database to get all privileges for the selected role
    $query = "
        SELECT p.id, p.name, m.id AS module_id, rp.role_id
        FROM permissions p
        JOIN modules m ON p.module_id = m.id
        LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = ?
    ";
    $statement = $pdo->prepare($query);
    $statement->execute([$roleId]);

    // Fetch all privileges for the selected role
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
    // Return error message as JSON
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error fetching role privileges: ' . $e->getMessage()]);
}
?>