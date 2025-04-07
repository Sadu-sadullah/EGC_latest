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
$dbName = 'new';

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

// Start a transaction
$pdo->beginTransaction();

try {
    // Fetch the current task details from the database
    $stmt = $pdo->prepare("SELECT assigned_by_id, status, task_name FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception('Task not found.');
    }

    $assigned_by_id = $task['assigned_by_id'];
    $current_status = $task['status'];
    $task_name = $task['task_name'];

    // Validate the reassignment based on the user's role and assigned_by_id
    if ($user_role !== 'Admin' && $assigned_by_id != $user_id) {
        throw new Exception('Unauthorized access. Only admins or the task assigner can reassign tasks.');
    }

    // Ensure the task is in a state that allows reassignment
    $allowed_statuses_for_reassignment = ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned'];
    if (!in_array($current_status, $allowed_statuses_for_reassignment)) {
        throw new Exception('Task cannot be reassigned in its current status.');
    }

    // Update the task's assigned user and set the status to "Reassigned"
    $stmt = $pdo->prepare("UPDATE tasks SET user_id = ?, status = 'Reassigned' WHERE task_id = ?");
    $stmt->execute([$reassign_user_id, $task_id]);

    // Fetch the new assignee's username
    $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $userStmt->execute([$reassign_user_id]);
    $newAssignee = $userStmt->fetch(PDO::FETCH_ASSOC);
    $newAssigneeUsername = $newAssignee ? $newAssignee['username'] : 'Unknown';

    // Log the reassignment in task_timeline
    $details = json_encode(['reassigned_to_user_id' => $reassign_user_id, 'reassigned_to_username' => $newAssigneeUsername]);
    $stmt = $pdo->prepare("INSERT INTO task_timeline (task_id, action, previous_status, new_status, changed_by_user_id, details) VALUES (?, 'task_reassigned', ?, 'Reassigned', ?, ?)");
    $stmt->execute([$task_id, $current_status, $user_id, $details]);

    // Commit the transaction
    $pdo->commit();

    // Return a success response
    echo json_encode([
        'success' => true,
        'message' => 'Task reassigned successfully.',
        'task_name' => $task_name
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    die(json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]));
} catch (PDOException $e) {
    $pdo->rollBack();
    die(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
?>