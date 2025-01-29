<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die(json_encode(['success' => false, 'message' => 'Unauthorized access.']));
}

// Include the configuration file for database credentials
$config = include '../config.php';

// Database connection details
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_login_system';

// DSN for PDO
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8";

try {
    // Establish database connection using PDO
    $pdo = new PDO($dsn, $dbUsername, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
}

// Get the current user's role and ID from the session
$user_role = $_SESSION['role'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

// Get the task ID and reassign user ID from the POST request
$task_id = $_POST['task_id'] ?? null;
$reassign_user_id = $_POST['reassign_user_id'] ?? null;

// Validate the input
if ($task_id === null || $reassign_user_id === null) {
    die(json_encode(['success' => false, 'message' => 'Invalid request. Task ID or Reassign User ID is missing.']));
}

// Fetch the current task details from the database
try {
    $stmt = $pdo->prepare("SELECT assigned_by_id, status FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        die(json_encode(['success' => false, 'message' => 'Task not found.']));
    }

    $assigned_by_id = $task['assigned_by_id']; // The user who originally assigned the task
    $current_status = $task['status']; // Current status of the task

    // Validate the reassignment based on the user's role and assigned_by_id
    if ($user_role === 'Admin' || $assigned_by_id == $user_id) {
        // Only Admin or the user who assigned the task can reassign it
        // Ensure the task is in a state that allows reassignment
        $allowed_statuses_for_reassignment = ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned'];
        if (!in_array($current_status, $allowed_statuses_for_reassignment)) {
            die(json_encode(['success' => false, 'message' => 'Task cannot be reassigned in its current status.']));
        }
    } else {
        die(json_encode(['success' => false, 'message' => 'Unauthorized access. Only admins or the task assigner can reassign tasks.']));
    }

    // Update the task's assigned user and set the status to "Assigned"
    $stmt = $pdo->prepare("UPDATE tasks SET user_id = ?, status = 'Reassigned' WHERE task_id = ?");
    $stmt->execute([$reassign_user_id, $task_id]);

    // Fetch the task name for the response (optional)
    $stmt = $pdo->prepare("SELECT task_name FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task_name = $stmt->fetchColumn();

    // Return a success response
    echo json_encode([
        'success' => true,
        'message' => 'Task reassigned successfully.',
        'task_name' => $task_name ?? 'Task'
    ]);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
?>