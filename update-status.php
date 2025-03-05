<?php
// Enable error reporting for debugging (consider disabling in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure proper JSON response
header('Content-Type: application/json');
ob_start(); // Start output buffering

session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get the user's timezone from cookies
if (isset($_COOKIE['user_timezone'])) {
    date_default_timezone_set($_COOKIE['user_timezone']);
} else {
    date_default_timezone_set('UTC');
}

$currentTime = date('Y-m-d H:i:s');

// Include the configuration file
$configPath = realpath(__DIR__ . '/../config.php');
if (!file_exists($configPath)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Config file not found.']);
    exit;
}
$config = include $configPath;

// Database connection details
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'] ?? null;
$dbPassword = $config['dbPassword'] ?? null;
$dbName = 'new';

// DSN for PDO
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8";

try {
    // Establish database connection using PDO
    $pdo = new PDO($dsn, $dbUsername, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

include 'permissions.php';

try {
    // Validate user input
    $user_id = $_SESSION['user_id'] ?? null;
    $task_id = $_POST['task_id'] ?? null;
    $new_status = $_POST['status'] ?? null;
    $completion_description = $_POST['completion_description'] ?? null;
    $delayed_reason = $_POST['delayed_reason'] ?? null;
    $verified_status = $_POST['verified_status'] ?? null; // New input from close task modal

    if (!$task_id || !$new_status) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
        exit;
    }

    // Fetch the current task details
    $stmt = $pdo->prepare("SELECT status, assigned_by_id, user_id, task_name, predecessor_task_id, completion_description, actual_finish_date, actual_start_date FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Task not found.']);
        exit;
    }

    $current_status = $task['status'];
    $assigned_by_id = $task['assigned_by_id'];
    $assigned_user_id = $task['user_id'];
    $task_name = $task['task_name'];
    $predecessor_task_id = $task['predecessor_task_id'];
    $existing_completion_description = $task['completion_description'];
    $existing_actual_finish_date = $task['actual_finish_date'];
    $actual_start_date = $task['actual_start_date'];

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
        } elseif (in_array($current_status, ['Completed on Time', 'Delayed Completion'])) {
            $statuses = ['Closed'];
        }
    }

    // Logic for self-assigned users with status_change_normal
    if ($isSelfAssigned && hasPermission('status_change_normal')) {
        $statuses = $assignerStatuses;
        if (isset($normalUserStatuses[$current_status])) {
            $statuses = array_merge($statuses, $normalUserStatuses[$current_status]);
        } else {
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
    if ($new_status !== $current_status && !in_array($new_status, $statuses)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid status change.']);
        exit;
    }

    // Check if the task has a predecessor and ensure it is completed before starting the task
    if ($predecessor_task_id && $new_status === 'In Progress' && $new_status !== $current_status) {
        $predecessorStmt = $pdo->prepare("SELECT status, actual_finish_date FROM tasks WHERE task_id = ?");
        $predecessorStmt->execute([$predecessor_task_id]);
        $predecessorTask = $predecessorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$predecessorTask || !in_array($predecessorTask['status'], ['Completed on Time', 'Delayed Completion'])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Cannot start this task until the predecessor task is completed.']);
            exit;
        }

        $actualStartDate = date('Y-m-d H:i:s', strtotime($predecessorTask['actual_finish_date'] . ' +1 day'));
    } else {
        $actualStartDate = $currentTime;
    }

    // Perform task update based on new status
    if ($new_status === $current_status) {
        // No status change intended; skip status update but allow actual_start_date edit below
    } elseif ($new_status === 'In Progress') {
        $sql = "UPDATE tasks SET status = ?, actual_start_date = COALESCE(actual_start_date, ?) WHERE task_id = ?";
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
    } elseif ($new_status === 'Closed') {
        // Handle closure with verification
        if ($verified_status === 'Completed on Time' && $current_status === 'Delayed Completion') {
            // Update status to Closed and remove delayed reason
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);

            $deleteStmt = $pdo->prepare("DELETE FROM task_transactions WHERE task_id = ?");
            $deleteStmt->execute([$task_id]);
        } elseif ($verified_status === 'Delayed Completion' || $current_status === 'Delayed Completion') {
            // Keep delayed reason and update status to Closed
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);
        } else {
            // Just close the task (no delayed reason to remove)
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);
        }

        if ($current_status === 'Delayed Completion') {
            $delayStmt = $pdo->prepare("SELECT delayed_reason FROM task_transactions WHERE task_id = ? ORDER BY actual_finish_date DESC LIMIT 1");
            $delayStmt->execute([$task_id]);
            $delayed_reason = $delayStmt->fetchColumn();
        }
    } else {
        $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $task_id]);
    }

    // Allow users with status_change_main to update actual_start_date if provided
    if (isset($_POST['actual_start_date']) && hasPermission('status_change_main') && $task['actual_start_date'] !== null) {
        $sql = "UPDATE tasks SET actual_start_date = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['actual_start_date'], $task_id]);

        $sql = "INSERT INTO task_timeline (task_id, action, previous_status, new_status, changed_by_user_id) VALUES (?, 'actual_start_date_updated', ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$task_id, $current_status, $current_status, $user_id]);
    }

    // Log the status change in task_timeline (only if status actually changed)
    if ($new_status !== $current_status) {
        $sql = "INSERT INTO task_timeline (task_id, action, previous_status, new_status, changed_by_user_id) VALUES (?, 'status_changed', ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$task_id, $current_status, $new_status, $user_id]);
    }

    // Clear buffer and send successful response
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Status updated successfully.', 'task_name' => $task_name]);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>