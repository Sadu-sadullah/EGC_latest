<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';
require 'permissions.php';

session_start();

if (isset($_COOKIE['user_timezone'])) {
    $userTimeZone = $_COOKIE['user_timezone'];
    date_default_timezone_set($userTimeZone);
} else {
    date_default_timezone_set('UTC');
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: portal-login.html");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if ($user_id === null || $user_role === null) {
    die("Error: User ID or role is not set. Please log in again.");
}

$timeout_duration = 1200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: portal-login.html");
    exit;
}

$_SESSION['last_activity'] = time();

$config = include '../config.php';

$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_SESSION['task_added_success'])) {
    $message = $_SESSION['task_added_success'];
    echo "<script>alert('$message');</script>";
    unset($_SESSION['task_added_success']);
}

$checkStmt = $conn->prepare("SELECT session_token FROM users WHERE id = ?");
$checkStmt->bind_param("i", $_SESSION['user_id']);
$checkStmt->execute();
$sessionToken = $checkStmt->get_result()->fetch_assoc()['session_token'];

if ($sessionToken !== $_SESSION['session_token']) {
    session_unset();
    session_destroy();
    echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
}

$conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

$departments = $conn->query("SELECT id, name FROM departments")->fetch_all(MYSQLI_ASSOC);
$roles = $conn->query("SELECT id, name FROM roles")->fetch_all(MYSQLI_ASSOC);
$projectQuery = $conn->query("SELECT id, project_name FROM projects");
if ($projectQuery) {
    $projects = $projectQuery->fetch_all(MYSQLI_ASSOC);
} else {
    die("Error fetching projects: " . $conn->error);
}

$userQuery = $conn->prepare("
    SELECT u.id, u.username, u.email, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role 
    FROM users u
    JOIN user_departments ud ON u.id = ud.user_id
    JOIN departments d ON ud.department_id = d.id
    JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
    GROUP BY u.id
");
$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$userResult = $userQuery->get_result();

if ($userResult->num_rows > 0) {
    $userDetails = $userResult->fetch_assoc();
    $loggedInUsername = $userDetails['username'];
    $loggedInDepartment = $userDetails['departments'];
    $loggedInRole = $userDetails['role'];
    $hasMultipleDepartments = count(explode(', ', $loggedInDepartment)) > 1;
} else {
    $loggedInUsername = "Unknown";
    $loggedInDepartment = "Unknown";
    $loggedInRole = "Unknown";
    $hasMultipleDepartments = false;
}

$users = [];
if (hasPermission('assign_tasks')) {
    if (hasPermission('assign_to_any_user_tasks')) {
        $userQuery = "
            SELECT u.id, u.username, u.email, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role 
            FROM users u
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            JOIN roles r ON u.role_id = r.id
            WHERE r.name != 'Admin'
            GROUP BY u.id
        ";
    } else {
        $userQuery = "
            SELECT u.id, u.username, u.email, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role 
            FROM users u
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            JOIN roles r ON u.role_id = r.id
            WHERE ud.department_id IN (SELECT department_id FROM user_departments WHERE user_id = ?)
            GROUP BY u.id
        ";
    }
    $stmt = $conn->prepare($userQuery);
    if (!hasPermission('assign_to_any_user_tasks')) {
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $userResult = $stmt->get_result();
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
}

function sendTaskNotification($email, $username, $project_name, $project_type, $task_name, $task_description, $start_date, $end_date, $assigned_by_id, $conn)
{
    $mail = new PHPMailer(true);
    try {
        $config = include("../config.php");
        $assignedByQuery = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $assignedByQuery->bind_param("i", $assigned_by_id);
        $assignedByQuery->execute();
        $assignedByResult = $assignedByQuery->get_result();
        $assignedByName = $assignedByResult->num_rows > 0 ? $assignedByResult->fetch_assoc()['username'] : "Unknown";
        $formattedStartDate = (new DateTime($start_date))->format('d M Y, h:i A');
        $formattedEndDate = (new DateTime($end_date))->format('d M Y, h:i A');
        $mail->isSMTP();
        $mail->Host = 'smtppro.zoho.com';
        $mail->SMTPAuth = true;
        $mail->Username = $config["email_username"];
        $mail->Password = $config["email_password"];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System');
        $mail->addAddress($email, $username);
        $mail->isHTML(true);
        $mail->Subject = 'New Task Assigned';
        $mail->Body = "<h3>Hello $username,</h3><p>You have been assigned a new task by <strong>$assignedByName</strong>:</p><ul><li><strong>Project Name:</strong> $project_name</li><li><strong>Task Name:</strong> $task_name</li><li><strong>Task Description:</strong> $task_description</li><li><strong>Project Type:</strong> $project_type</li><li><strong>Start Date:</strong> $formattedStartDate</li><li><strong>End Date:</strong> $formattedEndDate</li></ul><p>Please log in to your account for more details.</p>";
        $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['task_name'])) {
    $project_id = (int) $_POST['project_id'];
    $task_name = trim($_POST['task_name']);
    $task_description = trim($_POST['task_description']);
    $planned_start_date = trim($_POST['planned_start_date']);
    $planned_finish_date = trim($_POST['planned_finish_date']);
    $status = 'assigned';
    $assigned_user_id = (int) $_POST['assigned_user_id'];
    $recorded_timestamp = date("Y-m-d H:i:s");
    $assigned_by_id = $_SESSION['user_id'];
    $predecessor_task_id = !empty($_POST['predecessor_task_id']) ? (int) $_POST['predecessor_task_id'] : null;

    $currentDate = new DateTime();
    $datePlannedStartDate = new DateTime($planned_start_date);
    $datePlannedEndDate = new DateTime($planned_finish_date);
    $threeMonthsAgo = (clone $currentDate)->modify('-3 months');
    $threeMonthsAhead = (clone $currentDate)->modify('+3 months');

    if (empty($task_name) || empty($task_description) || empty($planned_start_date) || empty($planned_finish_date) || !$assigned_user_id || !$project_id) {
        echo '<script>alert("Please fill in all required fields.");</script>';
    } elseif ($datePlannedStartDate < $threeMonthsAgo || $datePlannedEndDate > $threeMonthsAhead) {
        echo '<script>alert("Error: Planned start date is too far in the past or too far in the future.");</script>';
    } else {
        $placeholders = [
            'user_id',
            'project_id',
            'task_name',
            'task_description',
            'planned_start_date',
            'planned_finish_date',
            'status',
            'recorded_timestamp',
            'assigned_by_id'
        ];
        $params = [$assigned_user_id, $project_id, $task_name, $task_description, $planned_start_date, $planned_finish_date, $status, $recorded_timestamp, $assigned_by_id];
        $types = str_repeat('s', count($params));

        if ($predecessor_task_id !== null) {
            $placeholders[] = 'predecessor_task_id';
            $params[] = $predecessor_task_id;
            $types .= 'i';
        }

        $sql = "INSERT INTO tasks (" . implode(", ", $placeholders) . ") VALUES (" . str_repeat('?,', count($placeholders) - 1) . "?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $task_id = $stmt->insert_id;
            $userQuery = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
            $userQuery->bind_param("i", $assigned_user_id);
            $userQuery->execute();
            $userResult = $userQuery->get_result();
            if ($userResult->num_rows > 0) {
                $user = $userResult->fetch_assoc();
                $email = $user['email'];
                $username = $user['username'];
                $projectQuery = $conn->prepare("SELECT project_name, project_type FROM projects WHERE id = ?");
                $projectQuery->bind_param("i", $project_id);
                $projectQuery->execute();
                $projectResult = $projectQuery->get_result();
                if ($projectResult->num_rows > 0) {
                    $project = $projectResult->fetch_assoc();
                    $project_name = $project['project_name'];
                    $project_type = $project['project_type'];
                    sendTaskNotification($email, $username, $project_name, $project_type, $task_name, $task_description, $planned_start_date, $planned_finish_date, $assigned_by_id, $conn);
                }
            }
            $timelineStmt = $conn->prepare("INSERT INTO task_timeline (task_id, action, previous_status, new_status, changed_by_user_id) VALUES (?, 'task_created', NULL, 'assigned', ?)");
            $timelineStmt->bind_param("ii", $task_id, $assigned_by_id);
            $timelineStmt->execute();
            $timelineStmt->close();
            $stmt->close();
            $_SESSION['task_added_success'] = "Task added successfully.";
            header("Location: tasks.php");
            exit;
        } else {
            echo '<script>alert("Failed to add task: ' . $conn->error . '");</script>';
        }
        $stmt->close();
    }
}

if (hasPermission('view_all_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.task_description,
            tasks.planned_start_date,
            tasks.planned_finish_date,
            tasks.actual_start_date,
            tasks.actual_finish_date AS task_actual_finish_date,
            tasks.status,
            projects.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            tasks.predecessor_task_id,
            projects.customer_name,
            projects.customer_email,
            projects.customer_mobile,
            projects.cost,
            projects.project_manager,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_to_user.username AS assigned_to, 
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department, 
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department,
            predecessor_task.task_name AS predecessor_task_name
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        LEFT JOIN tasks AS predecessor_task ON tasks.predecessor_task_id = predecessor_task.task_id
        JOIN projects ON tasks.project_id = projects.id
        GROUP BY tasks.task_id
        ORDER BY 
            CASE 
                WHEN tasks.status = 'Completed on Time' THEN tasks.planned_finish_date 
                WHEN tasks.status = 'Delayed Completion' THEN task_transactions.actual_finish_date 
                WHEN tasks.status = 'Closed' THEN tasks.planned_finish_date 
            END DESC, 
            tasks.recorded_timestamp DESC
    ";
} elseif (hasPermission('view_department_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.task_description,
            tasks.planned_start_date,
            tasks.planned_finish_date,
            tasks.actual_start_date,
            tasks.actual_finish_date AS task_actual_finish_date,
            tasks.status,
            projects.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            tasks.predecessor_task_id,
            projects.customer_name,
            projects.customer_email,
            projects.customer_mobile,
            projects.cost,
            projects.project_manager,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_to_user.username AS assigned_to, 
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department, 
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department,
            predecessor_task.task_name AS predecessor_task_name
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        LEFT JOIN tasks AS predecessor_task ON tasks.predecessor_task_id = predecessor_task.task_id
        JOIN projects ON tasks.project_id = projects.id
        WHERE assigned_to_ud.department_id IN (SELECT department_id FROM user_departments WHERE user_id = ?)
        GROUP BY tasks.task_id
        ORDER BY 
            CASE 
                WHEN tasks.status = 'Completed on Time' THEN tasks.planned_finish_date 
                WHEN tasks.status = 'Delayed Completion' THEN task_transactions.actual_finish_date 
                WHEN tasks.status = 'Closed' THEN tasks.planned_finish_date 
            END DESC, 
            tasks.recorded_timestamp DESC
    ";
} elseif (hasPermission('view_own_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.task_description,
            tasks.planned_start_date,
            tasks.planned_finish_date,
            tasks.actual_start_date,
            tasks.actual_finish_date AS task_actual_finish_date,
            tasks.status,
            projects.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            tasks.predecessor_task_id,
            projects.customer_name,
            projects.customer_email,
            projects.customer_mobile,
            projects.cost,
            projects.project_manager,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department,
            predecessor_task.task_name AS predecessor_task_name
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        LEFT JOIN tasks AS predecessor_task ON tasks.predecessor_task_id = predecessor_task.task_id
        JOIN projects ON tasks.project_id = projects.id
        WHERE tasks.user_id = ?
        GROUP BY tasks.task_id
        ORDER BY 
            CASE 
                WHEN tasks.status = 'Completed on Time' THEN tasks.planned_finish_date 
                WHEN tasks.status = 'Delayed Completion' THEN task_transactions.actual_finish_date 
                WHEN tasks.status = 'Closed' THEN tasks.planned_finish_date 
            END DESC, 
            tasks.recorded_timestamp DESC
    ";
} else {
    $taskQuery = "SELECT NULL";
}

$stmt = $conn->prepare($taskQuery);
if (hasPermission('view_department_tasks') || hasPermission('view_own_tasks')) {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$allTasks = $result->fetch_all(MYSQLI_ASSOC);

foreach ($allTasks as &$task) {
    $plannedStartDate = strtotime($task['planned_start_date']);
    $plannedEndDate = strtotime($task['planned_finish_date']);
    $plannedDurationHours = getWeekdayHours($plannedStartDate, $plannedEndDate);
    $task['planned_duration_hours'] = $plannedDurationHours;

    if (!empty($task['actual_start_date'])) {
        $actualStartDate = strtotime($task['actual_start_date']);
        $completedStatuses = ['Completed on Time', 'Delayed Completion', 'Closed'];

        // Use actual_finish_date for completed tasks, current time for in-progress tasks
        if (in_array($task['status'], $completedStatuses) && !empty($task['task_actual_finish_date'])) {
            $actualEndDate = strtotime($task['task_actual_finish_date']);
        } else {
            $actualEndDate = time(); // For in-progress tasks
        }

        $actualDurationHours = getWeekdayHours($actualStartDate, $actualEndDate);
        $task['actual_duration_hours'] = $actualDurationHours;

        // Determine available statuses for in-progress tasks only
        if (!in_array($task['status'], $completedStatuses)) {
            $task['available_statuses'] = $actualDurationHours <= $plannedDurationHours ? ['Completed on Time'] : ['Delayed Completion'];
        } else {
            $task['available_statuses'] = []; // No status changes for completed tasks
        }
    } else {
        $task['actual_duration_hours'] = null;
        $task['available_statuses'] = [];
    }
}

$pendingStartedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Assigned', 'In Progress', 'Hold', 'Reassigned', 'Reinstated']);
});

$completedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Completed on Time', 'Delayed Completion', 'Closed', 'Cancelled']);
});

// Check if any task is external
$hasExternalTasks = false;
foreach ($allTasks as $task) {
    if ($task['project_type'] === 'External') {
        $hasExternalTasks = true;
        break;
    }
}

// Reset array keys to ensure sequential indices
$pendingStartedTasks = array_values($pendingStartedTasks);

// Track rendered task IDs to prevent duplicates
$renderedTaskIds = [];

// Calculate task counts by status
$statusCounts = array_count_values(array_column($allTasks, 'status'));
$pendingStatuses = ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned', 'Cancelled'];
$completedStatuses = ['Completed on Time', 'Delayed Completion', 'Closed'];

$pendingCounts = array_intersect_key($statusCounts, array_flip($pendingStatuses));
$completedCounts = array_intersect_key($statusCounts, array_flip($completedStatuses));

// Fetch the earliest recorded date from the tasks table
$earliestDateQuery = $conn->query("SELECT MIN(recorded_timestamp) AS earliest_date FROM tasks");
if ($earliestDateQuery && $earliestDateQuery->num_rows > 0) {
    $earliestDateRow = $earliestDateQuery->fetch_assoc();
    $earliestDate = $earliestDateRow['earliest_date'];
} else {
    // Fallback to a default date if no tasks exist (e.g., current date)
    $earliestDate = date("Y-m-d");
}

// Convert the earliest date to a DateTime object
$earliestDateTime = new DateTime($earliestDate);

// Calculate the maximum date (1 year from the earliest date)
$maxDateTime = clone $earliestDateTime;
$maxDateTime->modify('+3 months');

// Format the dates for the HTML input (type="date" expects YYYY-MM-DD)
$minDate = $earliestDateTime->format('Y-m-d');
$maxDate = $maxDateTime->format('Y-m-d');

function getWeekdayHours($start, $end)
{
    if ($start >= $end) {
        return 0; // Invalid range
    }

    $weekdayHours = 0;
    $current = $start;

    while ($current < $end) {
        $dayOfWeek = date('N', $current);
        if ($dayOfWeek <= 5) { // Monday to Friday
            $startOfDay = strtotime('today', $current);
            $endOfDay = strtotime('tomorrow', $current) - 1;

            // Determine the start and end times for this day
            $dayStart = max($start, $startOfDay);
            $dayEnd = min($end, $endOfDay);

            // Calculate hours for this day
            $hours = ($dayEnd - $dayStart) / 3600;
            if ($hours > 0) {
                $weekdayHours += $hours;
            }
        }
        $current = strtotime('+1 day', $current);
    }

    return $weekdayHours;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            /* Prevent horizontal scrolling on the body */
        }

        * {
            box-sizing: border-box;
        }

        .task-container {
            width: 100%;
            max-width: 100vw;
            /* Ensure it doesn't exceed the viewport width */
            margin: 25px 0;
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
            /* Enable horizontal scrolling */
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input,
        select {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .submit-btn {
            background-color: #002c5f;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .submit-btn:hover {
            background-color: #004080;
        }

        .no-tasks {
            text-align: center;
            color: #888;
            padding: 20px;
        }

        .logout-button {
            text-align: right;
            margin-bottom: 20px;
        }

        .logout-button a {
            background-color: #002c5f;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }

        .logout-button a:hover {
            background-color: #004080;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            min-width: 1200px;
            /* Ensure the table has a minimum width */
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
            white-space: nowrap;
            /* Prevent text wrapping */
        }

        th {
            background-color: #002c5f;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        textarea {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            resize: vertical;
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        .filter-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-buttons {
            margin-bottom: 15px;
            text-align: center;
        }

        .filter-buttons .btn {
            margin: 5px;
            padding: 10px 20px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .filter-buttons .btn:hover {
            background-color: #004080;
        }

        .filter-buttons .btn-secondary {
            background-color: #457b9d;
        }

        .filter-buttons .btn-secondary:hover {
            background-color: #1d3557;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .filter-dropdown {
            margin-bottom: 15px;
            flex: 1 1 300px;
            max-width: 100%;
        }

        .filter-dropdown label {
            font-weight: bold;
            color: #333;
            display: block;
            margin-bottom: 5px;
        }

        .filter-dropdown select,
        .filter-dropdown input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-date {
            display: flex;
            gap: 10px;
            align-items: center;
            flex: 1 1 300px;
            max-width: 100%;
        }

        .filter-date .filter-dropdown {
            margin-bottom: 0;
            flex: 1 1 150px;
        }

        .custom-table tr.delayed-task {
            background-color: #f8d7da !important;
            color: #842029 !important;
        }

        .task-description {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            max-width: 300px;
        }

        .see-more-link {
            color: #002c5f;
            cursor: pointer;
            text-decoration: underline;
            font-size: 14px;
            margin-top: 5px;
            display: block;
        }

        .see-more-link:hover {
            color: #004080;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination a {
            margin: 0 5px;
            padding: 5px 10px;
            text-decoration: none;
            color: #002c5f;
            border: 1px solid #002c5f;
            border-radius: 5px;
        }

        .pagination a.active {
            background-color: #002c5f;
            color: white;
        }

        .pagination a:hover {
            background-color: #004080;
            color: white;
        }

        /* Sidebar and Navbar Styles */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
            /* Prevent horizontal scrolling */
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
            flex-shrink: 0;
            /* Prevent sidebar from shrinking */
        }

        .sidebar a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .sidebar a:hover {
            background-color: #004080;
        }

        .main-content {
            flex-grow: 1;
            padding: 20px;
            background-color: #ffffff;
            overflow-x: hidden;
            /* Prevent horizontal scrolling */
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 100vw;
            /* Ensure it doesn't exceed the viewport width */
        }

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .back-btn {
            background-color: #002c5f;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .back-btn:hover {
            background-color: #004080;
        }

        .status-filter-container {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-filter-container label {
            font-weight: bold;
            color: #333;
            margin-bottom: 0;
        }

        #status-filter {
            width: 300px;
        }

        .status-counts {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .status-box {
            background-color: #f9f9f9;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px 15px;
            text-align: center;
            min-width: 150px;
        }

        .status-box h4 {
            margin: 0;
            font-size: 14px;
            color: #333;
        }

        .status-box span {
            font-size: 18px;
            font-weight: bold;
            color: #002c5f;
        }

        .external-only {
            display: none;
            /* Hidden by default */
        }

        tr[data-project-type="External"] .external-only {
            display: table-cell;
            /* Shown only for external projects */
        }
    </style>
</head>

<body>
    <div class="modal fade" id="taskManagementModal" tabindex="-1" aria-labelledby="taskManagementModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskManagementModalLabel">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createTaskForm" method="POST" action="tasks.php">
                        <div class="form-group">
                            <label for="project_id">Select Project:</label>
                            <select id="project_id" name="project_id" class="form-control" required>
                                <option value="">Select a project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= htmlspecialchars($project['id']) ?>">
                                        <?= htmlspecialchars($project['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="task_name">Task Name:</label>
                            <input type="text" id="task_name" name="task_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="task_description">Task Description:</label>
                            <textarea id="task_description" name="task_description" class="form-control" rows="3"
                                required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="planned_start_date">Planned Start Date:</label>
                            <input type="datetime-local" id="planned_start_date" name="planned_start_date"
                                class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="planned_finish_date">Planned Finish Date:</label>
                            <input type="datetime-local" id="planned_finish_date" name="planned_finish_date"
                                class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="assigned_user_id">Assign To:</label>
                            <select id="assigned_user_id" name="assigned_user_id" class="form-control" required>
                                <option value="">Select a user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= htmlspecialchars($user['id']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                        (<?= htmlspecialchars($user['departments']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="predecessor_task_id">Predecessor Task:</label>
                            <select id="predecessor_task_id" name="predecessor_task_id" class="form-control">
                                <option value="">Select a predecessor task</option>
                                <!-- Populated dynamically via JavaScript -->
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Create Task</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="createProjectModal" tabindex="-1" aria-labelledby="createProjectModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createProjectModalLabel">Manage Projects</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="manageProjectForm" method="POST">
                        <div class="form-group">
                            <label for="existing_projects">Existing Projects:</label>
                            <select id="existing_projects" name="existing_projects" class="form-control">
                                <option value="">Select an existing project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= htmlspecialchars($project['id']) ?>">
                                        <?= htmlspecialchars($project['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="new_project_name">Project Name:</label>
                            <input type="text" id="new_project_name" name="new_project_name" class="form-control"
                                required>
                        </div>
                        <div class="form-group">
                            <label for="project_type">Project Type:</label>
                            <select id="project_type" name="project_type" class="form-control" required>
                                <option value="Internal">Internal</option>
                                <option value="External">External</option>
                            </select>
                        </div>
                        <!-- External Project Fields -->
                        <div id="external-project-fields" style="display: none;">
                            <div class="form-group">
                                <label for="customer_name">Customer Name:</label>
                                <input type="text" id="customer_name" name="customer_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="customer_email">Customer Email:</label>
                                <input type="email" id="customer_email" name="customer_email" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="customer_mobile">Customer Mobile:</label>
                                <input type="text" id="customer_mobile" name="customer_mobile" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="cost">Cost:</label>
                                <input type="number" id="cost" name="cost" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label for="project_manager">Project Manager:</label>
                                <input type="text" id="project_manager" name="project_manager" class="form-control">
                            </div>
                        </div>
                        <input type="hidden" name="project_id" id="project_id" value="">
                        <input type="hidden" name="action" id="project_action" value="create">
                        <input type="hidden" name="created_by_user_id" value="<?= $user_id ?>">
                        <button type="submit" class="btn btn-primary" id="submitProjectBtn">Create Project</button>
                        <button type="button" class="btn btn-warning" id="editProjectBtn" onclick="editProject()">Edit
                            Project</button>
                        <button type="button" class="btn btn-danger" onclick="deleteProject()">Delete Project</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('update_tasks') || hasPermission('update_tasks_all')): ?>
                <a href="task-actions.php">Task Actions</a>
            <?php endif; ?>
            <?php if (hasPermission('tasks_archive')): ?>
                <a href="archived-tasks.php">Tasks Archive</a>
            <?php endif; ?>
            <?php if (hasPermission('read_users')): ?>
                <a href="view-users.php">View Users</a>
            <?php endif; ?>
            <?php if (hasPermission('read_roles_&_departments')): ?>
                <a href="view-roles-departments.php">View Role or Department</a>
            <?php endif; ?>
            <?php if (hasPermission('read_&_write_privileges')): ?>
                <a href="assign-privilege.php">Assign & View Privileges</a>
            <?php endif; ?>
        </div>

        <div class="main-content">
            <div class="navbar">
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($loggedInUsername) ?></strong></p>
                    <p class="mb-0">Departments:
                        <strong><?= htmlspecialchars($loggedInDepartment ?? 'Unknown') ?></strong>
                    </p>
                </div>
                <button class="back-btn" onclick="window.location.href='welcome.php'">Dashboard</button>
            </div>

            <div class="task-container">
                <h2>Tasks</h2>
                <div class="container mt-4">
                    <div class="filter-container">
                        <div class="filter-buttons">
                            <?php if (hasPermission('create_tasks')): ?>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#createProjectModal">Create New Project</button>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#taskManagementModal">Create New Task</button>
                            <?php endif; ?>
                            <button onclick="resetFilters()" class="btn btn-primary">Reset</button>
                            <?php if (hasPermission('export_tasks')): ?>
                                <a href="export_tasks.php" class="btn btn-success">Export to CSV</a>
                            <?php endif; ?>
                        </div>
                        <div class="filter-row">
                            <div class="filter-dropdown">
                                <label for="project-filter">Filter by Project:</label>
                                <select id="project-filter" multiple="multiple">
                                    <option value="All">All</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= htmlspecialchars($project['project_name']) ?>">
                                            <?= htmlspecialchars($project['project_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (hasPermission('filter_tasks') || $hasMultipleDepartments): ?>
                                <div class="filter-dropdown">
                                    <label for="department-filter">Filter by Department of Assigned User:</label>
                                    <select id="department-filter" multiple="multiple">
                                        <option value="All">All</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?= htmlspecialchars($department['name']) ?>">
                                                <?= htmlspecialchars($department['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif ?>
                            <div class="filter-date">
                                <div class="filter-dropdown">
                                    <label for="start-date">Start Date:</label>
                                    <input type="date" id="start-date" min="<?= htmlspecialchars($minDate) ?>"
                                        max="<?= htmlspecialchars($maxDate) ?>">
                                </div>
                                <div class="filter-dropdown">
                                    <label for="end-date">End Date:</label>
                                    <input type="date" id="end-date" min="<?= htmlspecialchars($minDate) ?>"
                                        max="<?= htmlspecialchars($maxDate) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="status-counts">
                        <h3>Task Status Overview</h3>
                        <div style="width: 100%; text-align: center;">
                            <h4>In Progress</h4>
                        </div>
                        <?php foreach ($pendingStatuses as $status): ?>
                            <div class="status-box">
                                <h4><?= htmlspecialchars($status) ?></h4>
                                <span><?= $pendingCounts[$status] ?? 0 ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div style="width: 100%; text-align: center;">
                            <h4>Completed</h4>
                        </div>
                        <?php foreach ($completedStatuses as $status): ?>
                            <div class="status-box">
                                <h4><?= htmlspecialchars($status) ?></h4>
                                <span><?= $completedCounts[$status] ?? 0 ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3>Tasks In Progress</h3>
                    <div class="status-filter-container">
                        <label for="status-filter">Filter by Status:</label>
                        <select id="status-filter" multiple="multiple">
                            <option value="All">All</option>
                            <option value="Assigned" selected>Assigned</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Hold">Hold</option>
                            <option value="Reinstated">Reinstated</option>
                            <option value="Reassigned">Reassigned</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <table class="table table-striped table-hover align-middle text-center" id="pending-tasks">
                        <thead>
                            <tr class="align-middle">
                                <th>#</th>
                                <th>Project Name</th>
                                <th>Task Name</th>
                                <th>Task Description</th>
                                <th>Planned Start Date</th>
                                <th>Planned End Date</th>
                                <th>Planned Duration (Hours)</th>
                                <th>Actual Start Date</th>
                                <th>Actual End Date</th>
                                <th>Actual Duration (Hours)</th>
                                <th>Status</th>
                                <th>Project Type</th>
                                <th>Assigned By</th>
                                <?php if (hasPermission('assign_tasks')): ?>
                                    <th>Assigned To</th>
                                <?php endif; ?>
                                <th>Created On</th>
                                <th>Predecessor Task</th>
                                <?php if ($hasExternalTasks): ?>
                                    <th>Customer Name</th>
                                    <th>Customer Email</th>
                                    <th>Customer Mobile</th>
                                    <th>Cost</th>
                                    <th>Project Manager</th>
                                <?php endif; ?>
                                <th>Attachments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $taskCountStart = 1;
                            foreach ($pendingStartedTasks as $index => $row):
                                // Skip if task ID has already been rendered
                                if (in_array($row['task_id'], $renderedTaskIds)) {
                                    continue;
                                }
                                $renderedTaskIds[] = $row['task_id'];
                                ?>
                                <tr class="align-middle" data-task-id="<?= htmlspecialchars($row['task_id']) ?>"
                                    data-predecessor-task-id="<?= htmlspecialchars($row['predecessor_task_id'] ?? '') ?>">
                                    <td><?= $taskCountStart++ ?></td>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'Completed on Time'): ?>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#viewDescriptionModal"
                                                data-description="<?= htmlspecialchars($row['completion_description']) ?>"><?= htmlspecialchars($row['task_name']) ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($row['task_name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="task-description-container">
                                            <div class="task-description"><?= htmlspecialchars($row['task_description']) ?>
                                            </div>
                                            <a href="#" class="see-more-link" data-bs-toggle="modal"
                                                data-bs-target="#taskDescriptionModal"
                                                data-description="<?= htmlspecialchars($row['task_description']) ?>"
                                                style="display: none;">See more</a>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_start_date']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_finish_date']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars(number_format($row['planned_duration_hours'], 2)) ?></td>
                                    <td><?= $row['actual_start_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['actual_start_date']))) : 'N/A' ?>
                                    </td>
                                    <td><?= $row['task_actual_finish_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['task_actual_finish_date']))) : 'N/A' ?>
                                    </td>
                                    <td><?= $row['actual_duration_hours'] !== null ? htmlspecialchars(number_format($row['actual_duration_hours'], 2)) : 'N/A' ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="update-status.php">
                                            <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                                            <?php
                                            $currentStatus = $row['status'];
                                            $assigned_by_id = $row['assigned_by_id'];
                                            $assigned_user_id = $row['user_id'];
                                            $isSelfAssigned = ($assigned_by_id == $user_id && $assigned_user_id == $user_id);
                                            $statuses = [];
                                            $assignerStatuses = ['Assigned', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'];
                                            $normalUserStatuses = [
                                                'Assigned' => ['In Progress'],
                                                'In Progress' => isset($row['available_statuses'][0]) ? [$row['available_statuses'][0]] : []
                                            ];

                                            if (hasPermission('status_change_main') || ($assigned_by_id == $user_id && !$isSelfAssigned)) {
                                                if (in_array($currentStatus, ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'])) {
                                                    $statuses = $assignerStatuses;
                                                }
                                            } elseif ($isSelfAssigned && hasPermission('status_change_normal')) {
                                                $statuses = $assignerStatuses;
                                                if (isset($normalUserStatuses[$currentStatus])) {
                                                    $statuses = array_merge($statuses, $normalUserStatuses[$currentStatus]);
                                                } else {
                                                    $allowedStatuses = array_merge($assignerStatuses, ['Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion']);
                                                    if (in_array($currentStatus, $allowedStatuses)) {
                                                        $statuses = $allowedStatuses;
                                                    }
                                                }
                                            } elseif (hasPermission('status_change_normal') && $user_id == $assigned_user_id) {
                                                if (isset($normalUserStatuses[$currentStatus])) {
                                                    $statuses = $normalUserStatuses[$currentStatus];
                                                } elseif ($currentStatus === 'In Progress') {
                                                    $statuses = $row['available_statuses'];
                                                } else {
                                                    $allowedStatuses = ['Assigned', 'Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion'];
                                                    if (in_array($currentStatus, $allowedStatuses)) {
                                                        $statuses = $allowedStatuses;
                                                    }
                                                }
                                            }

                                            if (!empty($statuses)) {
                                                echo '<select id="status" name="status" onchange="handleStatusChange(event, ' . $row['task_id'] . ')">';
                                                if (!in_array($currentStatus, $statuses)) {
                                                    echo "<option value='$currentStatus' selected>$currentStatus</option>";
                                                }
                                                foreach ($statuses as $statusValue) {
                                                    $selected = ($currentStatus === $statusValue) ? 'selected' : '';
                                                    echo "<option value='$statusValue' $selected>$statusValue</option>";
                                                }
                                                echo '</select>';
                                            } else {
                                                echo $currentStatus;
                                            }
                                            ?>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($row['project_type']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_by']) ?>
                                        (<?= htmlspecialchars($row['assigned_by_department']) ?>)</td>
                                    <?php if (hasPermission('assign_tasks')): ?>
                                        <td><?= htmlspecialchars($row['assigned_to']) ?>
                                            (<?= htmlspecialchars($row['assigned_to_department']) ?>)</td>
                                    <?php endif; ?>
                                    <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                        <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['predecessor_task_name'] ?? 'N/A') ?></td>
                                    <?php if ($row['project_type'] === 'External'): ?>
                                        <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['customer_email'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['customer_mobile'] ?? 'N/A') ?></td>
                                        <td><?= $row['cost'] !== null ? htmlspecialchars(number_format($row['cost'], 2)) : 'N/A' ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['project_manager'] ?? 'N/A') ?></td>
                                    <?php else: ?>
                                        <?php if ($hasExternalTasks): ?>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <td>
                                        <?php
                                        $attachmentStmt = $conn->prepare("SELECT filename, filepath, status_at_upload FROM task_attachments WHERE task_id = ? ORDER BY uploaded_at ASC");
                                        $attachmentStmt->bind_param("i", $row['task_id']);
                                        $attachmentStmt->execute();
                                        $attachments = $attachmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                        if ($attachments) {
                                            foreach ($attachments as $attachment) {
                                                $link = 'serve-attachment.php?file=' . urlencode($attachment['filename']);
                                                $status_at_upload = htmlspecialchars($attachment['status_at_upload']);
                                                echo '<a href="' . htmlspecialchars($link) . '" target="_blank">' . htmlspecialchars($attachment['filename']) . '</a> (' . $status_at_upload . ')<br>';
                                            }
                                        } else {
                                            echo 'No attachments';
                                        }
                                        $attachmentStmt->close();
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-data-alert-pending" class="alert alert-warning mt-3" style="display: none;">No data to
                        be displayed.</div>

                    <h3>Completed Tasks</h3>
                    <table class="table table-striped table-hover align-middle text-center custom-table"
                        id="remaining-tasks">
                        <thead>
                            <tr class="align-middle">
                                <th>#</th>
                                <th>Project Name</th>
                                <th>Task Name</th>
                                <th>Task Description</th>
                                <th>Planned Start Date</th>
                                <th>Planned End Date</th>
                                <th>Planned Duration (Hours)</th>
                                <th>Actual Start Date</th>
                                <th>Actual End Date</th>
                                <th>Actual Duration (Hours)</th>
                                <th>Status</th>
                                <th>Project Type</th>
                                <th>Assigned By</th>
                                <?php if (hasPermission('assign_tasks')): ?>
                                    <th>Assigned To</th>
                                <?php endif; ?>
                                <th>Created On</th>
                                <th>Predecessor Task</th>
                                <?php if ($hasExternalTasks): ?>
                                    <th>Customer Name</th>
                                    <th>Customer Email</th>
                                    <th>Customer Mobile</th>
                                    <th>Cost</th>
                                    <th>Project Manager</th>
                                <?php endif; ?>
                                <th>Attachments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $taskCountStart = 1;
                            foreach ($completedTasks as $row):
                                $isClosedFromCompletedOnTime = $row['status'] === 'Closed' && $row['completion_description'] && !$row['delayed_reason'];
                                $isClosedFromDelayedCompletion = $row['status'] === 'Closed' && $row['delayed_reason'];
                                ?>
                                <tr data-project="<?= htmlspecialchars($row['project_name']) ?>"
                                    data-status="<?= htmlspecialchars($row['status']) ?>" class="align-middle <?php if ($row['status'] === 'Delayed Completion' || $isClosedFromDelayedCompletion)
                                          echo 'delayed-task'; ?>">
                                    <td><?= $taskCountStart++ ?></td>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'Completed on Time' || $isClosedFromCompletedOnTime): ?>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#viewDescriptionModal"
                                                data-description="<?= htmlspecialchars($row['completion_description']) ?>"><?= htmlspecialchars($row['task_name']) ?></a>
                                        <?php elseif ($row['status'] === 'Delayed Completion' || $isClosedFromDelayedCompletion): ?>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#delayedCompletionModal"
                                                onclick="showDelayedDetails('<?= htmlspecialchars($row['task_name']) ?>', '<?= htmlspecialchars($row['task_actual_finish_date']) ?>', '<?= htmlspecialchars($row['delayed_reason']) ?>', '<?= htmlspecialchars($row['completion_description']) ?>')"><?= htmlspecialchars($row['task_name']) ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($row['task_name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="task-description-container">
                                            <div class="task-description"><?= htmlspecialchars($row['task_description']) ?>
                                            </div>
                                            <a href="#" class="see-more-link" data-bs-toggle="modal"
                                                data-bs-target="#taskDescriptionModal"
                                                data-description="<?= htmlspecialchars($row['task_description']) ?>"
                                                style="display: none;">See more</a>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_start_date']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_finish_date']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars(number_format($row['planned_duration_hours'], 2)) ?></td>
                                    <td><?= $row['actual_start_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['actual_start_date']))) : 'N/A' ?>
                                    </td>
                                    <td><?= $row['task_actual_finish_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['task_actual_finish_date']))) : 'N/A' ?>
                                    </td>
                                    <td><?= $row['actual_duration_hours'] !== null ? htmlspecialchars(number_format($row['actual_duration_hours'], 2)) : 'N/A' ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="update-status.php">
                                            <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                                            <?php
                                            $currentStatus = $row['status'];
                                            $assigned_by_id = $row['assigned_by_id'];
                                            $statuses = [];
                                            if (hasPermission('status_change_main') || $assigned_by_id == $user_id) {
                                                if (in_array($currentStatus, ['Completed on Time', 'Delayed Completion'])) {
                                                    $statuses = ['Closed'];
                                                }
                                            }
                                            if (!empty($statuses)) {
                                                echo '<select id="status" name="status" onchange="handleStatusChange(event, ' . $row['task_id'] . ')">';
                                                if (!in_array($currentStatus, $statuses)) {
                                                    echo "<option value='$currentStatus' selected>$currentStatus</option>";
                                                }
                                                foreach ($statuses as $statusValue) {
                                                    $selected = ($currentStatus === $statusValue) ? 'selected' : '';
                                                    echo "<option value='$statusValue' $selected>$statusValue</option>";
                                                }
                                                echo '</select>';
                                            } else {
                                                echo $currentStatus;
                                            }
                                            if ($row['status'] === 'Delayed Completion' || $isClosedFromDelayedCompletion) {
                                                $plannedStartDate = strtotime($row['planned_start_date']);
                                                $plannedFinishDate = strtotime($row['planned_finish_date']);
                                                if ($plannedFinishDate < $plannedStartDate) {
                                                    $plannedFinishDate += 86400;
                                                }
                                                $plannedDuration = $plannedFinishDate - $plannedStartDate;
                                                if (!empty($row['actual_start_date']) && !empty($row['task_actual_finish_date'])) {
                                                    $actualStartDate = strtotime($row['actual_start_date']);
                                                    $actualFinishDate = strtotime($row['task_actual_finish_date']);
                                                    if ($actualFinishDate < $actualStartDate) {
                                                        $actualFinishDate += 86400;
                                                    }
                                                    $actualDuration = $actualFinishDate - $actualStartDate;
                                                    $delaySeconds = max(0, $actualDuration - $plannedDuration);
                                                    if ($delaySeconds > 0) {
                                                        $delayDays = floor($delaySeconds / (60 * 60 * 24));
                                                        $delayHours = floor(($delaySeconds % (60 * 60 * 24)) / (60 * 60));
                                                        $delayText = [];
                                                        if ($delayDays > 0)
                                                            $delayText[] = "$delayDays days";
                                                        if ($delayHours > 0 || empty($delayText))
                                                            $delayText[] = "$delayHours hours";
                                                        echo "<br><small class='text-danger'>" . implode(", ", $delayText) . " delayed</small>";
                                                    }
                                                }
                                            }
                                            ?>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($row['project_type']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_by']) ?>
                                        (<?= htmlspecialchars($row['assigned_by_department']) ?>)</td>
                                    <?php if (hasPermission('assign_tasks')): ?>
                                        <td><?= htmlspecialchars($row['assigned_to']) ?>
                                            (<?= htmlspecialchars($row['assigned_to_department']) ?>)</td>
                                    <?php endif; ?>
                                    <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                        <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['predecessor_task_name'] ?? 'N/A') ?></td>
                                    <?php if ($row['project_type'] === 'External'): ?>
                                        <td><?= htmlspecialchars($row['customer_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['customer_email'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($row['customer_mobile'] ?? 'N/A') ?></td>
                                        <td><?= $row['cost'] !== null ? htmlspecialchars(number_format($row['cost'], 2)) : 'N/A' ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['project_manager'] ?? 'N/A') ?></td>
                                    <?php else: ?>
                                        <?php if ($hasExternalTasks): ?>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                            <td>N/A</td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <td>
                                        <?php
                                        $attachmentStmt = $conn->prepare("SELECT filename, filepath, status_at_upload FROM task_attachments WHERE task_id = ? ORDER BY uploaded_at ASC");
                                        $attachmentStmt->bind_param("i", $row['task_id']);
                                        $attachmentStmt->execute();
                                        $attachments = $attachmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                        if ($attachments) {
                                            foreach ($attachments as $attachment) {
                                                $link = 'serve-attachment.php?file=' . urlencode($attachment['filename']);
                                                $status_at_upload = htmlspecialchars($attachment['status_at_upload']);
                                                echo '<a href="' . htmlspecialchars($link) . '" target="_blank">' . htmlspecialchars($attachment['filename']) . '</a> (' . $status_at_upload . ')<br>';
                                            }
                                        } else {
                                            echo 'No attachments';
                                        }
                                        $attachmentStmt->close();
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-data-alert-completed" class="alert alert-warning mt-3" style="display: none;">No data to
                        be displayed.</div>
                    <div class="pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="completionModal" tabindex="-1" aria-labelledby="completionModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="completionForm" method="POST" enctype="multipart/form-data"
                    onsubmit="handleCompletionForm(event)">
                    <div class="modal-header">
                        <h5 class="modal-title" id="completionModalLabel">Task Status Update</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="task-id" name="task_id">
                        <input type="hidden" id="modal-status" name="status">
                        <input type="hidden" id="actual-completion-date" name="actual_finish_date">
                        <input type="hidden" id="predecessor-task-id" name="predecessor_task_id">
                        <p id="predecessor-task-section" style="display: none;"><strong>Predecessor Task:</strong> <span
                                id="predecessor-task-name"></span></p>
                        <div class="mb-3" id="description-container">
                            <label for="completion-description" class="form-label">What was completed or
                                started?</label>
                            <textarea class="form-control" id="completion-description" name="completion_description"
                                rows="3" required></textarea>
                        </div>
                        <div class="mb-3" id="delayed-reason-container" style="display: none;">
                            <label for="delayed-reason" class="form-label">Why was it completed late?</label>
                            <textarea class="form-control" id="delayed-reason" name="delayed_reason"
                                rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="attachment" class="form-label">Upload Proof (PDF, Image, etc.):</label>
                            <input type="file" class="form-control" id="attachment" name="attachment"
                                accept=".pdf,.jpg,.jpeg,.png">
                            <small class="form-text text-muted">Max file size: 5MB. Allowed types: PDF, JPG,
                                PNG.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewDescriptionModal" tabindex="-1" aria-labelledby="viewDescriptionModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewDescriptionModalLabel">Task Completion Description</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="completion-description-text">No description provided.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="delayedCompletionModal" tabindex="-1" aria-labelledby="delayedCompletionModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="delayedCompletionModalLabel">Delayed Completion Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Task Name:</strong> <span id="delayed-task-name"></span></p>
                    <p><strong>Completed On:</strong> <span id="delayed-completion-date"></span></p>
                    <p><strong>Reason for Delay:</strong></p>
                    <p id="delay-reason"></p>
                    <p><strong>Completion Description:</strong></p>
                    <p id="completion-description-delayed"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="successModalLabel">Status Updated</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Task Name:</strong> <span id="success-task-name"></span></p>
                    <p><strong>Message:</strong> <span id="success-message"></span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="taskDescriptionModal" tabindex="-1" aria-labelledby="taskDescriptionModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskDescriptionModalLabel">Task Description</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="full-task-description"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reassignmentModal" tabindex="-1" aria-labelledby="reassignmentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="reassignmentForm" method="POST" onsubmit="handleReassignmentForm(event)">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reassignmentModalLabel">Reassign Task</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="reassign-task-id" name="task_id">
                        <input type="hidden" id="reassign-status" name="status" value="Reassigned">
                        <div class="mb-3">
                            <label for="reassign-user-id" class="form-label">Reassign To:</label>
                            <select id="reassign-user-id" name="reassign_user_id" class="form-control" required>
                                <option value="">Select a user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?>
                                        (<?= htmlspecialchars($user['departments']) ?> -
                                        <?= htmlspecialchars($user['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Reassign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="closeTaskModal" tabindex="-1" aria-labelledby="closeTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="closeTaskForm" method="POST" onsubmit="handleCloseTaskForm(event)">
                    <div class="modal-header">
                        <h5 class="modal-title" id="closeTaskModalLabel">Verify Task Closure</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="close-task-id" name="task_id">
                        <input type="hidden" id="close-status" name="status" value="Closed">
                        <p><strong>Task Name:</strong> <span id="close-task-name"></span></p>
                        <p><strong>Planned Start Date:</strong> <span id="close-planned-start"></span></p>
                        <p><strong>Planned End Date:</strong> <span id="close-planned-end"></span></p>
                        <p><strong>Actual Start Date:</strong> <span id="close-actual-start"></span></p>
                        <p><strong>Actual End Date:</strong> <span id="close-actual-end"></span></p>
                        <p><strong>Planned Duration:</strong> <span id="close-planned-duration"></span></p>
                        <p><strong>Actual Duration:</strong> <span id="close-actual-duration"></span></p>
                        <p id="close-delayed-reason-container" style="display: none;"><strong>Delayed Reason:</strong>
                            <span id="close-delayed-reason"></span>
                        </p>
                        <div class="mb-3">
                            <label for="close-verification" class="form-label">Verify Completion Status:</label>
                            <select id="close-verification" name="verified_status" class="form-control" required>
                                <option value="">Select an option</option>
                                <option value="Completed on Time">Completed on Time</option>
                                <option value="Delayed Completion">Delayed Completion</option>
                            </select>
                            <small class="form-text text-muted">If "Completed on Time" is selected and a delayed reason
                                exists, it will be removed.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Confirm Closure</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Task Status Management
        const viewDescriptionModal = document.getElementById('viewDescriptionModal');
        viewDescriptionModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const description = button.getAttribute('data-description');
            document.getElementById('completion-description-text').textContent = description || "No description provided.";
        });

        function handleStatusChange(event, taskId) {
            event.preventDefault();
            const status = event.target.value;
            const form = event.target.form;
            const row = $(`#pending-tasks tr[data-task-id="${taskId}"], #remaining-tasks tr[data-task-id="${taskId}"]`);
            const predecessorTaskId = row.data('predecessor-task-id') || null;
            let predecessorTaskName = row.find('td:eq(13)').text().trim();

            if (status === 'Reassigned') {
                document.getElementById('reassign-task-id').value = taskId;
                new bootstrap.Modal(document.getElementById('reassignmentModal')).show();
            } else if (status === 'In Progress' || status === 'Delayed Completion' || status === 'Completed on Time') {
                document.getElementById('task-id').value = taskId;
                document.getElementById('modal-status').value = status;
                document.getElementById('predecessor-task-id').value = predecessorTaskId;
                if (predecessorTaskId && (!predecessorTaskName || predecessorTaskName === 'N/A')) {
                    fetch(`fetch-predecessor-task-name.php?task_id=${predecessorTaskId}`)
                        .then(response => response.json())
                        .then(data => {
                            predecessorTaskName = data.task_name || 'N/A';
                            document.getElementById('predecessor-task-name').innerText = predecessorTaskName;
                            showPredecessorSection(predecessorTaskId, predecessorTaskName);
                        });
                } else {
                    document.getElementById('predecessor-task-name').innerText = predecessorTaskName;
                    showPredecessorSection(predecessorTaskId, predecessorTaskName);
                }
                const delayedReasonContainer = document.getElementById('delayed-reason-container');
                const descriptionLabel = document.querySelector('#description-container .form-label');
                delayedReasonContainer.style.display = (status === 'Delayed Completion') ? 'block' : 'none';
                descriptionLabel.textContent = (status === 'In Progress') ? 'What was started?' : 'What was completed?';
                new bootstrap.Modal(document.getElementById('completionModal')).show();
            } else if (status === 'Closed') {
                fetchTaskDetailsForClosure(taskId);
            } else {
                fetch('update-status.php', { method: 'POST', body: new FormData(form) })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('success-task-name').innerText = data.task_name;
                            document.getElementById('success-message').innerText = data.message;
                            new bootstrap.Modal(document.getElementById('successModal')).show();
                            setTimeout(() => window.location.reload(), 2000);
                        } else {
                            alert(data.message);
                        }
                    });
            }
        }

        function fetchTaskDetailsForClosure(taskId) {
            fetch(`fetch-task-details.php?task_id=${taskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('close-task-id').value = taskId;
                        document.getElementById('close-task-name').innerText = data.task_name;
                        document.getElementById('close-planned-start').innerText = data.planned_start_date;
                        document.getElementById('close-planned-end').innerText = data.planned_finish_date;
                        document.getElementById('close-actual-start').innerText = data.actual_start_date || 'N/A';
                        document.getElementById('close-actual-end').innerText = data.actual_finish_date || 'N/A';
                        document.getElementById('close-planned-duration').innerText = data.planned_duration;
                        document.getElementById('close-actual-duration').innerText = data.actual_duration || 'N/A';
                        const delayedReasonContainer = document.getElementById('close-delayed-reason-container');
                        const delayedReason = document.getElementById('close-delayed-reason');
                        if (data.delayed_reason) {
                            delayedReason.innerText = data.delayed_reason;
                            delayedReasonContainer.style.display = 'block';
                        } else {
                            delayedReasonContainer.style.display = 'none';
                        }
                        document.getElementById('close-verification').value = data.current_status;
                        new bootstrap.Modal(document.getElementById('closeTaskModal')).show();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error fetching task details:', error);
                    alert('An error occurred while fetching task details.');
                });
        }

        function handleCloseTaskForm(event) {
            event.preventDefault();
            const form = event.target;
            fetch('update-status.php', { method: 'POST', body: new FormData(form) })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('closeTaskModal')).hide();
                        document.getElementById('success-task-name').innerText = data.task_name;
                        document.getElementById('success-message').innerText = data.message;
                        new bootstrap.Modal(document.getElementById('successModal')).show();
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while closing the task.');
                });
        }

        function showPredecessorSection(predecessorTaskId, predecessorTaskName) {
            const predecessorSection = document.getElementById('predecessor-task-section');
            predecessorSection.style.display = (predecessorTaskId && predecessorTaskName !== 'N/A') ? 'block' : 'none';
        }

        function handleReassignmentForm(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            formData.set('status', 'Reassigned');
            fetch('reassign-task.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('reassignmentModal')).hide();
                        document.getElementById('success-task-name').innerText = data.task_name;
                        document.getElementById('success-message').innerText = data.message;
                        new bootstrap.Modal(document.getElementById('successModal')).show();
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while reassigning the task.');
                });
        }

        function showDelayedDetails(taskName, completionDate, delayReason, completionDescription) {
            document.getElementById('delayed-task-name').innerText = taskName || "N/A";
            document.getElementById('delayed-completion-date').innerText = completionDate || "N/A";
            document.getElementById('delay-reason').innerText = delayReason || "N/A";
            document.getElementById('completion-description-delayed').innerText = completionDescription && completionDescription.trim() ? completionDescription : "No description provided.";
        }

        function handleCompletionForm(event) {
            event.preventDefault();
            document.getElementById('actual-completion-date').value = new Date().toISOString().slice(0, 19).replace('T', ' ');
            const form = event.target;
            const formData = new FormData(form); // Use FormData to include file uploads

            fetch('update-status.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            throw new Error('Server error: ' + text);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('completionModal')).hide();
                        document.getElementById('success-task-name').innerText = data.task_name;
                        document.getElementById('success-message').innerText = data.message;
                        new bootstrap.Modal(document.getElementById('successModal')).show();
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Failed to update status: ' + error.message);
                });
        }

        // Table Filtering and Pagination
        $(document).ready(function () {
            const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            document.cookie = "user_timezone=" + userTimeZone;
            const tasksPerPage = 10;
            let currentPage = 1;

            $('#project-filter').select2({ placeholder: "Select projects to filter", allowClear: true, width: '300px' });
            $('#department-filter').select2({ placeholder: "Select departments to filter", allowClear: true, width: '300px' });
            $('#status-filter').select2({ placeholder: "Select statuses to filter", allowClear: true, width: '300px' });

            function applyFilters() {
                const selectedProjects = $('#project-filter').val() || [];
                const selectedDepartments = $('#department-filter').val() || [];
                let startDate = $('#start-date').val();
                let endDate = $('#end-date').val();
                const minDate = $('#start-date').attr('min');
                const maxDate = $('#start-date').attr('max');

                if (startDate) {
                    const startDateObj = new Date(startDate);
                    const minDateObj = new Date(minDate);
                    const maxDateObj = new Date(maxDate);
                    if (startDateObj < minDateObj) startDate = minDate, $('#start-date').val(minDate);
                    else if (startDateObj > maxDateObj) startDate = maxDate, $('#start-date').val(maxDate);
                }
                if (endDate) {
                    const endDateObj = new Date(endDate);
                    const minDateObj = new Date(minDate);
                    const maxDateObj = new Date(maxDate);
                    if (endDateObj < minDateObj) endDate = minDate, $('#end-date').val(minDate);
                    else if (endDateObj > maxDateObj) endDate = maxDate, $('#end-date').val(maxDate);
                }

                const pendingVisibleRows = filterAndPaginateTable('#pending-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);
                const completedVisibleRows = filterAndPaginateTable('#remaining-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);
                updatePagination(pendingVisibleRows, completedVisibleRows);
            }

            function filterAndPaginateTable(tableId, selectedProjects, selectedDepartments, startDate, endDate, currentPage) {
                const rows = $(`${tableId} tbody tr`);
                let visibleRows = [];
                const selectedStatuses = tableId === '#pending-tasks' ? $('#status-filter').val() || [] : [];

                rows.each(function () {
                    const projectName = $(this).find('td:nth-child(2)').text().trim();
                    const departmentName = $(this).find('td:nth-child(14)').text().trim().match(/\(([^)]+)\)/)?.[1] || '';
                    const plannedStartDate = new Date($(this).find('td:nth-child(5)').text().trim());
                    const plannedFinishDate = new Date($(this).find('td:nth-child(6)').text().trim());
                    const actualStartDate = new Date($(this).find('td:nth-child(8)').text().trim());
                    const actualFinishDate = new Date($(this).find('td:nth-child(9)').text().trim());
                    const taskStatus = $(this).find('td:nth-child(11) select').val() || $(this).find('td:nth-child(11)').text().trim();

                    let dateInRange = true;
                    if (startDate && endDate) {
                        const filterStartDate = new Date(startDate);
                        const filterEndDate = new Date(endDate);
                        const plannedInRange = plannedStartDate >= filterStartDate && plannedFinishDate <= filterEndDate;
                        const actualInRange = !isNaN(actualStartDate) && !isNaN(actualFinishDate) && actualStartDate >= filterStartDate && actualFinishDate <= filterEndDate;
                        dateInRange = plannedInRange || actualInRange;
                    }

                    const projectMatch = selectedProjects.length === 0 || selectedProjects.includes('All') || selectedProjects.includes(projectName);
                    const departmentMatch = selectedDepartments.length === 0 || selectedDepartments.includes('All') || departmentName.split(', ').some(dept => selectedDepartments.includes(dept));
                    const statusMatch = tableId !== '#pending-tasks' || selectedStatuses.length === 0 || selectedStatuses.includes('All') || selectedStatuses.includes(taskStatus);

                    if (projectMatch && departmentMatch && statusMatch && dateInRange) visibleRows.push(this);
                });

                rows.hide();
                const startIndex = (currentPage - 1) * tasksPerPage;
                const endIndex = startIndex + tasksPerPage;
                const rowsToShow = visibleRows.slice(startIndex, endIndex);

                if (rowsToShow.length > 0) {
                    rowsToShow.forEach((row, index) => {
                        $(row).find('td:first-child').text(startIndex + index + 1);
                        $(row).show();
                    });
                }

                const noDataAlert = $(`${tableId} + .alert`);
                noDataAlert.toggle(visibleRows.length === 0 || rowsToShow.length === 0);

                return visibleRows.length;
            }

            function updatePagination(pendingVisibleRows, completedVisibleRows) {
                const totalPages = Math.max(Math.ceil(pendingVisibleRows / tasksPerPage), Math.ceil(completedVisibleRows / tasksPerPage));
                const pagination = $('.pagination').empty();

                if (currentPage > 1) pagination.append(`<a href="#" class="page-link" data-page="${currentPage - 1}">Previous</a>`);
                for (let i = 1; i <= totalPages; i++) pagination.append(`<a href="#" class="page-link ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</a>`);
                if (currentPage < totalPages) pagination.append(`<a href="#" class="page-link" data-page="${currentPage + 1}">Next</a>`);
            }

            $(document).on('click', '.page-link', function (e) {
                e.preventDefault();
                currentPage = parseInt($(this).data('page'));
                applyFilters();
            });

            $('#project-filter, #department-filter, #start-date, #end-date, #status-filter').on('change', function () {
                currentPage = 1;
                applyFilters();
            });

            function resetFilters() {
                $('#project-filter').val(null).trigger('change');
                $('#department-filter').val(null).trigger('change');
                $('#start-date').val('');
                $('#end-date').val('');
                $('#status-filter').val(['Assigned']).trigger('change');
                currentPage = 1;
                applyFilters();
            }

            $('.btn-primary[onclick="resetFilters()"]').on('click', resetFilters);
            applyFilters();
        });

        // Timezone Conversion and Description Handling
        document.addEventListener('DOMContentLoaded', function () {
            function convertUTCTimeToLocal() {
                document.querySelectorAll('td[data-utc]').forEach(cell => {
                    const utcTimestamp = cell.getAttribute('data-utc');
                    const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true, timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone };
                    cell.textContent = new Date(utcTimestamp).toLocaleString('en-US', options);
                });
            }
            convertUTCTimeToLocal();

            const taskDescriptionModal = document.getElementById('taskDescriptionModal');
            taskDescriptionModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.querySelector('#full-task-description').textContent = button.getAttribute('data-description');
            });

            function checkDescriptionHeight() {
                document.querySelectorAll('.task-description-container').forEach(container => {
                    const descriptionElement = container.querySelector('.task-description');
                    const seeMoreLink = container.querySelector('.see-more-link');
                    const lineHeight = parseInt(window.getComputedStyle(descriptionElement).lineHeight);
                    seeMoreLink.style.display = (descriptionElement.scrollHeight > lineHeight * 2) ? 'block' : 'none';
                });
            }
            checkDescriptionHeight();
            window.addEventListener('resize', checkDescriptionHeight);
        });

        // Project and Task Modal Interactions
        function fetchPredecessorTasks(projectId) {
            if (!projectId) {
                document.getElementById('predecessor_task_id').innerHTML = '<option value="">Select a predecessor task</option>';
                return;
            }
            fetch('fetch-predecessor-tasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `project_id=${encodeURIComponent(projectId)}&user_id=<?= $user_id ?>`
            })
                .then(response => response.json())
                .then(data => {
                    const predecessorDropdown = document.getElementById('predecessor_task_id');
                    predecessorDropdown.innerHTML = '<option value="">Select a predecessor task</option>';
                    if (data.success && Array.isArray(data.tasks) && data.tasks.length > 0) {
                        data.tasks.forEach(task => {
                            const option = document.createElement('option');
                            option.value = task.task_id;
                            option.textContent = task.task_name;
                            predecessorDropdown.appendChild(option);
                        });
                    } else {
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = 'No available predecessor tasks';
                        option.disabled = true;
                        predecessorDropdown.appendChild(option);
                    }
                })
                .catch(error => console.error('Error fetching predecessor tasks:', error));
        }

        function toggleExternalFields() {
            const projectType = document.getElementById('project_type').value;
            const externalFields = document.getElementById('external-project-fields');
            externalFields.style.display = projectType === 'External' ? 'block' : 'none';
        }

        function resetProjectForm() {
            document.getElementById('new_project_name').value = '';
            document.getElementById('project_type').value = 'Internal';
            document.getElementById('project_id').value = '';
            document.getElementById('project_action').value = 'create';
            document.getElementById('submitProjectBtn').textContent = 'Create Project';
            document.getElementById('existing_projects').value = ''; // Reset dropdown
            document.getElementById('customer_name').value = '';
            document.getElementById('customer_email').value = '';
            document.getElementById('customer_mobile').value = '';
            document.getElementById('cost').value = '';
            document.getElementById('project_manager').value = '';
            toggleExternalFields();
        }

        function fetchProjectDetails(projectId) {
            if (!projectId || projectId === '0' || projectId === '') {
                alert('Please select a valid project from the dropdown.');
                return;
            }

            console.log('Fetching details for project ID:', projectId); // Debug log

            fetch('get-project-details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `project_id=${encodeURIComponent(projectId)}`
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Response from get-project-details.php:', data); // Debug log
                    if (data.success) {
                        document.getElementById('new_project_name').value = data.project_name || '';
                        document.getElementById('project_type').value = data.project_type || 'Internal';
                        document.getElementById('project_id').value = projectId; // Explicitly set project_id
                        document.getElementById('project_action').value = 'edit';
                        document.getElementById('submitProjectBtn').textContent = 'Update Project';

                        if (data.project_type === 'External') {
                            document.getElementById('customer_name').value = data.customer_name || '';
                            document.getElementById('customer_email').value = data.customer_email || '';
                            document.getElementById('customer_mobile').value = data.customer_mobile || '';
                            document.getElementById('cost').value = data.cost !== '' ? data.cost : '';
                            document.getElementById('project_manager').value = data.project_manager || '';
                        } else {
                            document.getElementById('customer_name').value = '';
                            document.getElementById('customer_email').value = '';
                            document.getElementById('customer_mobile').value = '';
                            document.getElementById('cost').value = '';
                            document.getElementById('project_manager').value = '';
                        }
                        toggleExternalFields();
                        console.log('Form project_id set to:', document.getElementById('project_id').value); // Debug log
                    } else {
                        alert(data.message || 'Failed to fetch project details. Please ensure the project exists.');
                    }
                })
                .catch(error => {
                    console.error('Error fetching project details:', error);
                    alert('Failed to load project details. Please check your connection and try again.');
                });
        }

        document.getElementById('project_id').addEventListener('change', function () {
            fetchPredecessorTasks(this.value);
        });

        document.getElementById('project_type').addEventListener('change', toggleExternalFields);

        document.getElementById('createProjectModal').addEventListener('show.bs.modal', resetProjectForm);

        // Remove the autofill on dropdown change
        // document.getElementById('existing_projects').addEventListener('change', function () {
        //     const projectId = this.value;
        //     if (projectId) fetchProjectDetails(projectId);
        //     else resetProjectForm();
        // });

        document.getElementById('manageProjectForm').addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(this);
            const projectId = document.getElementById('project_id').value;
            const action = document.getElementById('project_action').value;
            const submitBtn = document.getElementById('submitProjectBtn');
            submitBtn.disabled = true;

            console.log('Form submission - Action:', action, 'Project ID:', projectId); // Debug log
            console.log('Form data:', Object.fromEntries(formData)); // Debug log

            if (action === 'edit' && (!projectId || projectId === '' || projectId === '0')) {
                alert('Please select a project and click "Edit Project" to load its details before submitting.');
                submitBtn.disabled = false;
                return;
            }

            fetch('manage-project.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    console.log('Response from manage-project.php:', data); // Debug log
                    alert(data.message);
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error submitting form:', error);
                    alert('Error submitting form. Please try again.');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                });
        });

        function editProject() {
            const projectId = document.getElementById('existing_projects').value;
            if (!projectId || projectId === '0' || projectId === '') {
                alert('Please select a project to edit from the dropdown.');
                return;
            }
            fetchProjectDetails(projectId);
        }

        function deleteProject() {
            const projectId = document.getElementById('existing_projects').value;
            if (!projectId) return alert('Please select a project to delete.');

            fetch('manage-project.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=check_tasks&project_id=${encodeURIComponent(projectId)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) return alert(data.message);
                    if (data.task_count > 0) return alert(`Cannot delete project. There are ${data.task_count} task(s) associated with it. Please delete or reassign these tasks first.`);
                    if (confirm('Are you sure you want to delete this project?')) {
                        fetch('manage-project.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=delete&project_id=${encodeURIComponent(projectId)}`
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert(data.message);
                                    location.reload();
                                } else {
                                    alert(data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error deleting project:', error);
                                alert('Error deleting project.');
                            });
                    }
                })
                .catch(error => {
                    console.error('Error checking tasks for project:', error);
                    alert('Error checking tasks for project.');
                });
        }
    </script>
</body>

</html>
<?php $conn->close(); ?>