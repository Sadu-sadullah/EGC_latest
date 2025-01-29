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

// Get the role ID and module ID from the query string
$roleId = $_GET['role_id'];
$moduleId = $_GET['module_id'] ?? null;

$pdo = new PDO($dsn, $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Query the database to get the role privileges
$query = "SELECT * FROM role_permissions WHERE role_id = ?";
$params = [$roleId];

if ($moduleId) {
    $query .= " AND module_id = ?";
    $params[] = $moduleId;
}

$statement = $pdo->prepare($query);
$statement->execute($params);

// Fetch the role privileges as an associative array
$rolePrivileges = $statement->fetchAll(PDO::FETCH_ASSOC);

// Return the role privileges as JSON
echo json_encode($rolePrivileges);
?>