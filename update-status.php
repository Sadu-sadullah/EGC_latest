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
if (!$dbUsername || !$dbPassword) {
    echo json_encode(['success' => false, 'message' => 'Database credentials missing.']);
    exit;
}

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
    $stmt = $pdo->prepare("SELECT status, assigned_by_id, user_id, task_name FROM tasks WHERE task_id = ?");
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

    // Define valid status categories
    $top_table_statuses = ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned', 'Completed on Time', 'Delayed Completion'];
    $bottom_table_statuses = ['Closed'];

    // Permission validation (Ensure `hasPermission()` exists)
    if (!function_exists('hasPermission')) {
        function hasPermission($perm)
        {
            return false; // Default to no permission if the function is missing
        }
    }

    if (hasPermission('update_status_main') || $assigned_by_id == $user_id) {
        if (in_array($current_status, $top_table_statuses) && !in_array($new_status, ['In Progress', 'Completed on Time', 'Delayed Completion'])) {
            // Allow status change
        } elseif (in_array($current_status, ['Completed on Time', 'Delayed Completion']) && $new_status === 'Closed') {
            // Allow "Closed"
        } elseif ($new_status === 'Reassigned') {
            // Allow "Reassigned"
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid status change.']);
            exit;
        }
    } elseif (hasPermission('update_status_low') && $user_id === $assigned_user_id) {
        if (in_array($current_status, ['Assigned', 'Reassigned', 'In Progress']) && in_array($new_status, ['In Progress', 'Completed on Time', 'Delayed Completion'])) {
            // Allow status change
        } else {
            echo json_encode(['success' => false, 'message' => 'Unauthorized status change.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }

    // Perform task update based on new status
    if ($new_status === 'In Progress') {
        $sql = "UPDATE tasks SET status = ?, actual_start_date = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $currentTime, $task_id]);
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