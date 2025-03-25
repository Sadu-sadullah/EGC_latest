<?php
// Disable error display to prevent HTML output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

header('Content-Type: application/json');
ob_start();

session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (isset($_COOKIE['user_timezone'])) {
    date_default_timezone_set($_COOKIE['user_timezone']);
} else {
    date_default_timezone_set('UTC');
}

$currentTime = date('Y-m-d H:i:s');

$configPath = realpath(__DIR__ . '/../config.php');
if (!file_exists($configPath)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Config file not found.']);
    exit;
}
$config = include $configPath;

$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8";

try {
    $pdo = new PDO($dsn, $dbUsername, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

include 'permissions.php';

function calculateActualDuration($start, $end)
{
    if (!$start || !$end || $start >= $end)
        return 0;
    return (strtotime($end) - strtotime($start)) / 3600;
}

try {
    $user_id = $_SESSION['user_id'] ?? null;
    $task_id = $_POST['task_id'] ?? null;
    $new_status = $_POST['status'] ?? null;
    $completion_description = $_POST['completion_description'] ?? null;
    $delayed_reason = $_POST['delayed_reason'] ?? null;
    $verified_status = $_POST['verified_status'] ?? null;
    $actual_finish_date = $_POST['actual_finish_date'] ?? $currentTime;

    if (!$task_id || !$new_status) {
        throw new Exception('Invalid request parameters.');
    }

    $stmt = $pdo->prepare("SELECT status, assigned_by_id, user_id, task_name, predecessor_task_id, completion_description, actual_finish_date, actual_start_date FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception('Task not found.');
    }

    $current_status = $task['status'];
    $assigned_by_id = $task['assigned_by_id'];
    $assigned_user_id = $task['user_id'];
    $task_name = $task['task_name'];
    $predecessor_task_id = $task['predecessor_task_id'];
    $actual_start_date = $task['actual_start_date'];

    $assignerStatuses = ['Assigned', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'];
    $normalUserStatuses = ['Assigned' => ['In Progress'], 'In Progress' => ['Completed on Time', 'Delayed Completion']];
    $allowedStatuses = array_merge($assignerStatuses, ['Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion', 'Closed']);
    $isSelfAssigned = ($assigned_by_id == $user_id && $assigned_user_id == $user_id);

    $statuses = [];
    if (hasPermission('status_change_main') || ($assigned_by_id == $user_id && !$isSelfAssigned)) {
        if (in_array($current_status, $assignerStatuses)) {
            $statuses = $assignerStatuses;
        } elseif (in_array($current_status, ['Completed on Time', 'Delayed Completion'])) {
            $statuses = ['Closed'];
        }
    }
    if ($isSelfAssigned && hasPermission('status_change_normal')) {
        $statuses = $assignerStatuses;
        if (isset($normalUserStatuses[$current_status])) {
            $statuses = array_merge($statuses, $normalUserStatuses[$current_status]);
        } else {
            if (in_array($current_status, $allowedStatuses))
                $statuses = $allowedStatuses;
        }
    } elseif (hasPermission('status_change_normal') && $user_id === $assigned_user_id) {
        if (isset($normalUserStatuses[$current_status])) {
            $statuses = $normalUserStatuses[$current_status];
        } elseif ($current_status === 'In Progress') {
            $statuses = ['Completed on Time', 'Delayed Completion'];
        }
    }

    if ($new_status !== $current_status && !in_array($new_status, $statuses)) {
        throw new Exception('Invalid status change.');
    }

    if ($predecessor_task_id && $new_status === 'In Progress' && $new_status !== $current_status) {
        $predecessorStmt = $pdo->prepare("SELECT status, actual_finish_date FROM tasks WHERE task_id = ?");
        $predecessorStmt->execute([$predecessor_task_id]);
        $predecessorTask = $predecessorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$predecessorTask || !in_array($predecessorTask['status'], ['Completed on Time', 'Delayed Completion'])) {
            throw new Exception('Cannot start this task until the predecessor task is completed.');
        }
        $actualStartDate = date('Y-m-d H:i:s', strtotime($predecessorTask['actual_finish_date'] . ' +1 day'));
    } else {
        $actualStartDate = $actual_start_date ?? $currentTime;
    }

    if (in_array($new_status, ['Completed on Time', 'Delayed Completion']) && $new_status !== $current_status) {
        if (!$actual_start_date) {
            throw new Exception('Task must have an actual start date before completion.');
        }
        if (!$completion_description) {
            throw new Exception('Completion description is required for completed statuses.');
        }
        $actualDurationHours = calculateActualDuration($actual_start_date, $actual_finish_date);
        if ($actualDurationHours < 1) {
            throw new Exception('Error: Actual duration must be at least 1 hour for completion.');
        }
    }

    // Handle file upload
    $attachmentPath = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $uploadDir = __DIR__ . '/uploads/';

        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            throw new Exception('Upload directory is not writable.');
        }
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type. Only PDF, JPG, and PNG are allowed.');
        }
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds 5MB limit.');
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'task_' . $task_id . '_' . $new_status . '_' . time() . '.' . $extension;
        $attachmentPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $attachmentPath)) {
            throw new Exception('Failed to upload file.');
        }

        $stmt = $pdo->prepare("INSERT INTO task_attachments (task_id, filename, filepath, uploaded_at, status_at_upload, uploaded_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$task_id, $filename, $attachmentPath, $currentTime, $new_status, $user_id]);
    }

    // Perform task update
    $pdo->beginTransaction();
    if ($new_status === $current_status) {
        // No status change, but allow attachment
    } elseif ($new_status === 'In Progress') {
        $sql = "UPDATE tasks SET status = ?, actual_start_date = COALESCE(actual_start_date, ?) WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $actualStartDate, $task_id]);
    } elseif ($new_status === 'Completed on Time' || $new_status === 'Delayed Completion') {
        $sql = "UPDATE tasks SET status = ?, completion_description = ?, actual_finish_date = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $completion_description, $actual_finish_date, $task_id]);

        if ($new_status === 'Delayed Completion') {
            if (!$delayed_reason) {
                throw new Exception('Delayed reason is required for Delayed Completion.');
            }
            $sql = "INSERT INTO task_transactions (task_id, delayed_reason, actual_finish_date) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$task_id, $delayed_reason, $actual_finish_date]);
        }
    } elseif ($new_status === 'Closed') {
        if ($verified_status === 'Completed on Time' && $current_status === 'Delayed Completion') {
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);

            $deleteStmt = $pdo->prepare("DELETE FROM task_transactions WHERE task_id = ?");
            $deleteStmt->execute([$task_id]);
        } elseif ($verified_status === 'Delayed Completion' || $current_status === 'Delayed Completion') {
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);
        } else {
            $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$new_status, $task_id]);
        }
    } else {
        $sql = "UPDATE tasks SET status = ? WHERE task_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $task_id]);
    }

    if ($new_status !== $current_status) {
        $sql = "INSERT INTO task_timeline (task_id, action, previous_status, new_status, changed_by_user_id) VALUES (?, 'status_changed', ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$task_id, $current_status, $new_status, $user_id]);
    }

    $pdo->commit();
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Status updated successfully.', 'task_name' => $task_name]);
} catch (Exception $e) {
    $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
} catch (Throwable $e) {
    $pdo->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unexpected error: ' . $e->getMessage()]);
}
?>