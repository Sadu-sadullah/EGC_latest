<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure proper JSON response
header('Content-Type: application/json');
ob_start(); // Prevent unwanted output before JSON response

session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get the user's timezone from cookies
if (isset($_COOKIE['user_timezone'])) {
    date_default_timezone_set($_COOKIE['user_timezone']);
} else {
    date_default_timezone_set('UTC'); // Default to UTC if missing
}

$currentTime = date('Y-m-d H:i:s');

// Include the configuration file
$configPath = realpath(__DIR__ . '/../config.php');
if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Config file not found.']);
    exit;
}
$config = include $configPath;

// Database connection details
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'] ?? null;
$dbPassword = $config['dbPassword'] ?? null;
$dbName = 'euro_login_system';

// Check if database credentials exist
// if (!$dbUsername || !$dbPassword) {
//     echo json_encode(['success' => false, 'message' => 'Database credentials missing.']);
//     exit;
// }

include 'permissions.php';

// DSN for PDO
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8";

try {
    // Establish database connection using PDO
    $pdo = new PDO($dsn, $dbUsername, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    // Validate user input
    $user_id = $_SESSION['user_id'] ?? null;
    $task_id = $_POST['task_id'] ?? null;
    $new_status = $_POST['status'] ?? null;
    $completion_description = $_POST['completion_description'] ?? null;
    $delayed_reason = $_POST['delayed_reason'] ?? null;

    if (!$task_id || !$new_status) {
        echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
        exit;
    }

    // Fetch the current task details
    $stmt = $pdo->prepare("SELECT status, assigned_by_id, user_id, task_name, predecessor_task_id FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found.']);
        exit;
    }

    $current_status = $task['status'];
    $assigned_by_id = $task['assigned_by_id'];
    $assigned_user_id = $task['user_id'];
    $task_name = $task['task_name'];
    $predecessor_task_id = $task['predecessor_task_id'];

    // Define status categories
    $assignerStatuses = ['Assigned', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'];
    $normalUserStatuses = ['Assigned' => ['In Progress'], 'In Progress' => ['Completed on Time', 'Delayed Completion']];
    $allowedStatuses = array_merge($assignerStatuses, ['Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion', 'Closed']);

    // Check if the task is self-assigned
    $isSelfAssigned = ($assigned_by_id == $user_id && $assigned_user_id == $user_id);

    // Permission validation
    if (!function_exists('hasPermission')) {
        function hasPermission($perm)
        {
            return false; // Default to no permission if the function is missing
        }
    }

    // Initialize allowed status array
    $statuses = [];

    // Logic for users with status_change_main or assigner (excluding self-assigned users)
    if (hasPermission('status_change_main') || ($assigned_by_id == $user_id && !$isSelfAssigned)) {
        if (in_array($current_status, $assignerStatuses)) {
            $statuses = $assignerStatuses;
        }
    }

    // Logic for self-assigned users with status_change_normal
    if ($isSelfAssigned && hasPermission('status_change_normal')) {
        $statuses = $assignerStatuses; // Start with assigner statuses

        if (isset($normalUserStatuses[$current_status])) {
            // Merge in the statuses that a normal assigned user would get
            $statuses = array_merge($statuses, $normalUserStatuses[$current_status]);
        } else {
            // Allow all default statuses from both assigner and normal user logic
            if (in_array($current_status, $allowedStatuses)) {
                $statuses = $allowedStatuses;
            }
        }
    }
    // Logic for Regular User (assigned user)
    elseif (hasPermission('status_change_normal') && $user_id === $assigned_user_id) {
        if (isset($normalUserStatuses[$current_status])) {
            $statuses = $normalUserStatuses[$current_status];
        } elseif ($current_status === 'In Progress') {
            $statuses = ['Completed on Time', 'Delayed Completion'];
        }
    }

    // Validate the new status
    if (!in_array($new_status, $statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status change.']);
        exit;
    }

    // Check if the task has a predecessor and ensure it is completed before starting the task
    if ($predecessor_task_id && $new_status === 'In Progress') {
        $predecessorStmt = $pdo->prepare("SELECT status, actual_finish_date FROM tasks WHERE task_id = ?");
        $predecessorStmt->execute([$predecessor_task_id]);
        $predecessorTask = $predecessorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$predecessorTask || !in_array($predecessorTask['status'], ['Completed on Time', 'Delayed Completion'])) {
            echo json_encode(['success' => false, 'message' => 'Cannot start this task until the predecessor task is completed.']);
            exit;
        }

        // Set the actual start date of the sequential task to the day after the predecessor's actual finish date
        $actualStartDate = date('Y-m-d H:i:s', strtotime($predecessorTask['actual_finish_date'] . ' +1 day'));
    } else {
        $actualStartDate = $currentTime;
    }

    // Perform task update based on new status
    if ($new_status === 'In Progress') {
        $sql = "UPDATE tasks SET status = ?, actual_start_date = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $actualStartDate, $task_id]);
    } elseif ($new_status === 'Completed on Time' || $new_status === 'Delayed Completion') {
        $sql = "UPDATE tasks SET status = ?, completion_description = ?, actual_finish_date = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $completion_description, $currentTime, $task_id]);

        if ($new_status === 'Delayed Completion') {
            $sql = "INSERT INTO task_transactions (task_id, delayed_reason, actual_finish_date) VALUES (?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$task_id, $delayed_reason]);
        }
    } else {
        $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $task_id]);
    }

    // Successful response
    echo json_encode(['success' => true, 'message' => 'Status updated successfully.', 'task_name' => $task_name]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>