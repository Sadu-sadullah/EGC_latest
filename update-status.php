<?php
// Enable error reporting
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

$user_role = $_SESSION['role'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;
$task_id = $_POST['task_id'] ?? null;
$new_status = $_POST['status'] ?? null;
$completion_description = $_POST['completion_description'] ?? null;
$delayed_reason = $_POST['delayed_reason'] ?? null;

if ($task_id === null || $new_status === null) {
    die(json_encode(['success' => false, 'message' => 'Invalid request.']));
}

// Fetch the current task status, assigned_by_id, and user_id (assigned user) from the database
try {
    $stmt = $pdo->prepare("SELECT status, assigned_by_id, user_id, task_name FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        die(json_encode(['success' => false, 'message' => 'Task not found.']));
    }

    $current_status = $task['status'];
    $assigned_by_id = $task['assigned_by_id'];
    $assigned_user_id = $task['user_id']; // User assigned to the task
    $task_name = $task['task_name'];

    // Define valid statuses for the top table (Pending & Started Tasks)
    $top_table_statuses = ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned', 'Completed on Time', 'Delayed Completion'];

    // Define valid statuses for the bottom table (Completed Tasks)
    $bottom_table_statuses = ['Closed'];

    // Validate the status change based on the user's role and assigned_by_id
    if ($user_role === 'Admin' || $assigned_by_id == $user_id) {
        // Admin or the user who assigned the task can change status to any status except "In Progress", "Completed on Time", and "Delayed Completion"
        if (in_array($current_status, $top_table_statuses) && !in_array($new_status, ['In Progress', 'Completed on Time', 'Delayed Completion'])) {
            // Allow the status change
        } elseif (in_array($current_status, ['Completed on Time', 'Delayed Completion']) && $new_status === 'Closed') {
            // Allow changing to "Closed" in the bottom table
        } elseif ($new_status === 'Reassigned') {
            // Allow changing to "Reassigned" when the task is reassigned
        } else {
            die(json_encode(['success' => false, 'message' => 'Invalid status change.']));
        }
    } elseif ($user_role === 'User' && $user_id == $assigned_user_id || $user_id === $assigned_user_id) {
        // Regular user or the assigned user can only change status if they are the assigned user
        if ($current_status === 'Assigned' || $current_status === 'Reassigned') {
            if (in_array($new_status, ['In Progress', 'Completed on Time', 'Delayed Completion'])) {
                // Allow the status change
            } else {
                die(json_encode(['success' => false, 'message' => 'Unauthorized status change.']));
            }
        }
    } else {
        die(json_encode(['success' => false, 'message' => 'Unauthorized access.']));
    }

    // Prepare the SQL query to update the task
    if ($new_status === 'In Progress') {
        // Update status and set actual_start_date to the current timestamp
        $sql = "UPDATE tasks 
                SET status = ?, 
                    actual_start_date = NOW() 
                WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $new_status,
            $task_id
        ]);
    } elseif ($new_status === 'Completed on Time') {
        // Update status, completion_description, and actual_finish_date for "Completed on Time"
        $sql = "UPDATE tasks 
                SET status = ?, 
                    completion_description = ?, 
                    actual_finish_date = NOW() 
                WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $new_status,
            $completion_description,
            $task_id
        ]);
    } elseif ($new_status === 'Delayed Completion') {
        // Update status, completion_description, and actual_finish_date in the tasks table
        $sql = "UPDATE tasks 
                SET status = ?, 
                    completion_description = ?, 
                    actual_finish_date = NOW() 
                WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $new_status,
            $completion_description,
            $task_id
        ]);

        // Insert delayed_reason and actual_finish_date into the task_transactions table
        $sql = "INSERT INTO task_transactions (task_id, delayed_reason, actual_finish_date) 
                VALUES (?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $task_id,
            $delayed_reason
        ]);
    } else {
        // For other statuses, only update the status
        $sql = "UPDATE tasks 
                SET status = ? 
                WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $new_status,
            $task_id
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully.', 'task_name' => $task_name]);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
}
?>