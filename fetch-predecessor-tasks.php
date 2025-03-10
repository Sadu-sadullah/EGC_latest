<?php
session_start();
$config = include '../config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

// Create connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit;
}

// Get input values
$projectId = $_POST['project_id'] ?? 0;
$userId = $_POST['user_id'] ?? 0;
$projectType = $_POST['project_type'] ?? ''; // Add project_type

// Validate inputs
if (empty($projectId) || empty($userId) || empty($projectType)) {
    echo json_encode(["error" => "Invalid input: Project ID, User ID, or Project Type is missing."]);
    exit;
}

// Prepare the SQL query
$predecessorTasksQuery = $conn->prepare("
    SELECT task_id, task_name 
    FROM tasks 
    WHERE predecessor_task_id IS NULL 
      AND status = 'Assigned' 
      AND assigned_by_id = ? 
      AND project_id = ? 
      AND project_type = ? 
    ORDER BY recorded_timestamp DESC
");

// Bind parameters and execute query
$predecessorTasksQuery->bind_param("iis", $userId, $projectId, $projectType); // 'iis' for integer, integer, string
$predecessorTasksQuery->execute();
$result = $predecessorTasksQuery->get_result();

$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}

// Ensure JSON encoding is successful
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["error" => "JSON encoding error: " . json_last_error_msg()]);
    exit;
}

// Return tasks as JSON
header('Content-Type: application/json');
echo json_encode($tasks);
exit;
?>