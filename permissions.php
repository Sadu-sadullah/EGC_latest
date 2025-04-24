<?php
// Include the config file
$config = require '../config.php';

// Database connection details
$dbHost = 'localhost'; // Database host
$dbName = 'new'; // Database name
$dbUsername = $config['dbUsername']; // Database username from config
$dbPassword = $config['dbPassword']; // Database password from config

// Create a database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to check if a user has a specific permission
function hasPermission($permission)
{
    global $conn;

    // Check if session and selected_role_id are set
    if (!isset($_SESSION['selected_role_id'])) {
        return false; // No role selected, deny permission
    }

    $roleId = $_SESSION['selected_role_id'];

    // Query to check if the selected role has the permission
    $sql = "SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.name = ?";

    // Prepare and execute the query
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt->bind_param("is", $roleId, $permission);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    // Return true if the role has the permission
    return $count > 0;
}
?>