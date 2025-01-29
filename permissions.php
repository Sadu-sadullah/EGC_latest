<?php
// Include the config file
$config = require '../config.php';

// Database connection details
$dbHost = 'localhost'; // Database host
$dbName = 'euro_login_system_2'; // Database name
$dbUsername = $config['dbUsername']; // Database username from config
$dbPassword = $config['dbPassword']; // Database password from config

// Create a database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to check if a user has a specific permission for a specific module
function hasPermission($permission, $module)
{
    global $conn;

    // Get the user ID from the session
    $userId = $_SESSION['user_id'];

    // Fetch the user's role ID from the database
    $roleSql = "SELECT role_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($roleSql);
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($roleId);
    $stmt->fetch();
    $stmt->close();

    if (!$roleId) {
        return false; // User has no role assigned
    }

    // Query to check if the user's role has the permission
    $sql = "SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            JOIN modules m ON rp.module_id = m.id
            WHERE rp.role_id = ? AND p.name = ? AND m.name = ?";

    // Prepare and execute the query
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt->bind_param("iss", $roleId, $permission, $module);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    // Return true if the role has the permission
    return $count > 0;
}
?>