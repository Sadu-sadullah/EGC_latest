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

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    echo json_encode(["error" => "Database connection failed: " . $conn->connect_error]);
    exit;
}

// Get input values
$projectId = $_POST['project_id'] ?? 0;
$userId = $_POST['user_id'] ?? 0;
$projectType = $_POST['project_type'] ?? '';

// Validate inputs
if (empty($projectId) || empty($userId)) { // Removed $projectType from required validation
    echo json_encode(["error" => "Invalid input: Project ID or User ID is missing."]);
    exit;
}

// Prepare the SQL query with join to projects table
$predecessorTasksQuery = $conn->prepare("
    SELECT t.task_id, t.task_name 
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.predecessor_task_id IS NULL 
      AND t.status = 'Assigned' 
      AND t.assigned_by_id = ? 
      AND t.project_id = ? 
      AND p.project_type = ? 
    ORDER BY t.recorded_timestamp DESC
");

// Bind parameters and execute query
$predecessorTasksQuery->bind_param("iis", $userId, $projectId, $projectType); // 'iis' for integer, integer, string
$predecessorTasksQuery->execute();
$result = $predecessorTasksQuery->get_result();

$tasks = [];
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}

// Return tasks as JSON
header('Content-Type: application/json');
echo json_encode(["success" => true, "tasks" => $tasks]);
exit;
?>