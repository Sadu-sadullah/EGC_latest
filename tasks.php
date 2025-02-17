<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

// Include the permissions file
require 'permissions.php';

session_start();

if (isset($_COOKIE['user_timezone'])) {
    $userTimeZone = $_COOKIE['user_timezone'];
    date_default_timezone_set($userTimeZone);
} else {
    // Default timezone if not provided
    date_default_timezone_set('UTC');
}

// Check if the user is not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: portal-login.html");
    exit;
}

// Get user information from the session
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

// Verify that user ID and role are set
if ($user_id === null || $user_role === null) {
    die("Error: User ID or role is not set. Please log in again.");
}

// Session timeout (Optional)
$timeout_duration = 1200;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: portal-login.html");
    exit;
}

$_SESSION['last_activity'] = time();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_login_system';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


// Prepare and execute the query to fetch the session token
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

// Fetch departments from the database
$departments = $conn->query("SELECT id, name FROM departments")->fetch_all(MYSQLI_ASSOC);

// Fetch roles from the database
$roles = $conn->query("SELECT id, name FROM roles")->fetch_all(MYSQLI_ASSOC);

// Fetch all unique project names from the projects table
$projectQuery = $conn->query("SELECT id, project_name FROM projects");
if ($projectQuery) {
    $projects = $projectQuery->fetch_all(MYSQLI_ASSOC);
} else {
    die("Error fetching projects: " . $conn->error);
}

// Fetch logged-in user's details
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

    // Check if the user has more than one department
    $departmentsArray = explode(', ', $loggedInDepartment);
    $hasMultipleDepartments = count($departmentsArray) > 1;
} else {
    $loggedInUsername = "Unknown";
    $loggedInDepartment = "Unknown";
    $loggedInRole = "Unknown";
    $hasMultipleDepartments = false;
}

// Fetch users for task assignment (assign_tasks privilege & Tasks module)
$users = [];
if (hasPermission('assign_tasks')) {
    if (hasPermission('assign_to_any_user_tasks')) {
        // assign_to_any_user_tasks privilege can assign tasks to users and managers
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
        // others can only assign tasks to users in their department
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

// Function to send email notifications
function sendTaskNotification($email, $username, $project_name, $project_type, $task_name, $task_description, $start_date, $end_date, $assigned_by_id, $conn)
{
    $mail = new PHPMailer(true);

    try {
        $config = include("../config.php");

        // Fetch the assigned by user's details
        $assignedByQuery = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $assignedByQuery->bind_param("i", $assigned_by_id);
        $assignedByQuery->execute();
        $assignedByResult = $assignedByQuery->get_result();

        if ($assignedByResult->num_rows > 0) {
            $assignedByUser = $assignedByResult->fetch_assoc();
            $assignedByName = $assignedByUser['username'];
        } else {
            $assignedByName = "Unknown"; // Fallback if the assigned by user is not found
        }

        // Format the start and end dates
        $formattedStartDate = (new DateTime($start_date))->format('d M Y, h:i A'); // e.g., 30 Jan 2025, 06:45 PM
        $formattedEndDate = (new DateTime($end_date))->format('d M Y, h:i A'); // e.g., 01 Feb 2025, 06:45 PM

        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtppro.zoho.com'; // Update with your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = $config["email_username"];
        $mail->Password = $config["email_password"];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System');
        $mail->addAddress($email, $username);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'New Task Assigned';
        $mail->Body = "<h3>Hello $username,</h3>" .
            "<p>You have been assigned a new task by <strong>$assignedByName</strong>:</p>" .
            "<ul>" .
            "<li><strong>Project Name:</strong> $project_name</li>" .
            "<li><strong>Task Name:</strong> $task_name</li>" .
            "<li><strong>Task Description:</strong> $task_description</li>" .
            "<li><strong>Project Type:</strong> $project_type</li>" .
            "<li><strong>Start Date:</strong> $formattedStartDate</li>" .
            "<li><strong>End Date:</strong> $formattedEndDate</li>" .
            "</ul>" .
            "<p>Please log in to your account for more details.</p>";

        $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

// Handle form submission for adding a task
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['task_name'])) {
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : null;
    $task_name = trim($_POST['task_name']);
    $task_description = trim($_POST['task_description']);
    $project_type = trim($_POST['project_type']);
    $planned_start_date = trim($_POST['planned_start_date']);
    $planned_finish_date = trim($_POST['planned_finish_date']);
    $status = 'assigned';
    $assigned_user_id = isset($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : null;
    $recorded_timestamp = date("Y-m-d H:i:s");
    $assigned_by_id = $_SESSION['user_id'];
    $predecessor_task_id = isset($_POST['predecessor_task_id']) && !empty($_POST['predecessor_task_id']) ? (int) $_POST['predecessor_task_id'] : null;

    $currentDate = new DateTime();

    // Get the planned start and end dates
    $datePlannedStartDate = new DateTime($planned_start_date);
    $datePlannedEndDate = new DateTime($planned_finish_date);

    // Calculate the 3-month boundary dates
    $threeMonthsAgo = clone $currentDate;
    $threeMonthsAgo->modify('-3 months');

    $threeMonthsAhead = clone $currentDate;
    $threeMonthsAhead->modify('+3 months');

    if (empty($task_name) || empty($task_description) || empty($project_type) || empty($planned_start_date) || empty($planned_finish_date) || !$assigned_user_id || !$project_id) {
        echo '<script>alert("Please fill in all required fields.");</script>';
    } elseif ($datePlannedStartDate < $threeMonthsAgo || $datePlannedEndDate > $threeMonthsAhead) {
        echo '<script>alert("Error: Planned start date is too far in the past or too far in the future.");</script>';
    } else {
        // Prepare the SQL query with the appropriate number of placeholders
        $placeholders = [
            'user_id',
            'project_id',
            'task_name',
            'task_description',
            'project_type',
            'planned_start_date',
            'planned_finish_date',
            'status',
            'recorded_timestamp',
            'assigned_by_id'
        ];

        if ($predecessor_task_id !== null) {
            $placeholders[] = 'predecessor_task_id';
        }

        $sql = "INSERT INTO tasks (" . implode(", ", $placeholders) . ") VALUES (" . str_repeat('?,', count($placeholders) - 1) . "?)";

        $stmt = $conn->prepare($sql);
        $params = [
            $assigned_user_id,
            $project_id,
            $task_name,
            $task_description,
            $project_type,
            $planned_start_date,
            $planned_finish_date,
            $status,
            $recorded_timestamp,
            $assigned_by_id
        ];

        if ($predecessor_task_id !== null) {
            $params[] = $predecessor_task_id;
        }

        $types = str_repeat('s', count($params) - 1) . 'i'; // Adjust types for the last parameter which is an integer

        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            echo '<script>alert("Task added successfully.");</script>';

            // Fetch the assigned user's email and username
            $userQuery = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
            $userQuery->bind_param("i", $assigned_user_id);
            $userQuery->execute();
            $userResult = $userQuery->get_result();

            if ($userResult->num_rows > 0) {
                $user = $userResult->fetch_assoc();
                $email = $user['email'];
                $username = $user['username'];

                // Fetch the project name from the projects table
                $projectQuery = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
                $projectQuery->bind_param("i", $project_id);
                $projectQuery->execute();
                $projectResult = $projectQuery->get_result();

                if ($projectResult->num_rows > 0) {
                    $project = $projectResult->fetch_assoc();
                    $project_name = $project['project_name'];

                    // Send email notification with assigned_by_id
                    sendTaskNotification(
                        $email,
                        $username,
                        $project_name,
                        $task_name,
                        $task_description,
                        $project_type,
                        $planned_start_date,
                        $planned_finish_date,
                        $assigned_by_id, // Pass the assigned_by_id
                        $conn // Pass the database connection
                    );
                }
            }
        } else {
            echo '<script>alert("Failed to add task.");</script>';
        }
        $stmt->close();
    }
}

if (hasPermission('view_all_tasks')) {
    // Admin-like query: Fetch all tasks
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
            tasks.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            tasks.predecessor_task_id, -- Add predecessor_task_id
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_to_user.username AS assigned_to, 
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department, 
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department,
            predecessor_task.task_name AS predecessor_task_name -- Add predecessor task name
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        LEFT JOIN tasks AS predecessor_task ON tasks.predecessor_task_id = predecessor_task.task_id -- Join predecessor task
        JOIN projects ON tasks.project_id = projects.id -- Join projects table
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
    //Fetch tasks for users in the same department
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
            tasks.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_to_user.username AS assigned_to, 
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department, 
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department 
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        JOIN projects ON tasks.project_id = projects.id -- Join projects table
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
    //Fetch only tasks assigned to the current user
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
            tasks.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date,
            tasks.completion_description,
            assigned_by_user.username AS assigned_by,
            GROUP_CONCAT(DISTINCT assigned_by_department.name SEPARATOR ', ') AS assigned_by_department 
        FROM tasks 
        LEFT JOIN task_transactions ON tasks.task_id = task_transactions.task_id
        JOIN users AS assigned_by_user ON tasks.assigned_by_id = assigned_by_user.id 
        JOIN user_departments AS assigned_by_ud ON assigned_by_user.id = assigned_by_ud.user_id
        JOIN departments AS assigned_by_department ON assigned_by_ud.department_id = assigned_by_department.id
        JOIN projects ON tasks.project_id = projects.id -- Join projects table
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
    // No permission: Fetch no tasks
    $taskQuery = "SELECT NULL";
}

// Fetch all tasks based on privilege
$stmt = $conn->prepare($taskQuery);

if (hasPermission('view_department_tasks') || hasPermission('view_own_tasks')) {
    // Bind the user ID for Manager and User queries
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
        $currentDate = time();
        $actualDurationHours = getWeekdayHours($actualStartDate, $currentDate);
        $task['actual_duration_hours'] = $actualDurationHours;

        // Clear available statuses before assigning
        $task['available_statuses'] = [];

        // Assign status based on the duration comparison
        if ($actualDurationHours <= $plannedDurationHours) {
            $task['available_statuses'][] = 'Completed on Time';
        } else {
            $task['available_statuses'][] = 'Delayed Completion';
        }
    } else {
        $task['available_statuses'] = [];
        $task['actual_duration_hours'] = null;
    }
}

// Split tasks into Pending/Started and Completed
$pendingStartedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned', 'Cancelled']);
});

$completedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Completed on Time', 'Delayed Completion', 'Closed']);
});
?>

<!-- Delay logic -->
<?php
function getWeekdayHours($start, $end)
{
    $weekdayHours = 0;
    $current = $start;
    while ($current <= $end) {
        $dayOfWeek = date('N', $current);
        if ($dayOfWeek <= 5) {
            $startOfDay = strtotime('today', $current);
            $endOfDay = strtotime('tomorrow', $current) - 1;
            $startTime = max($start, $startOfDay);
            $endTime = min($end, $endOfDay);
            $hours = ($endTime - $startTime) / 3600;
            $weekdayHours += $hours;
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
        }

        * {
            box-sizing: border-box;
        }

        .task-container {
            width: 100%;
            max-width: 1400px;
            margin: 25px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
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
            display: inline-block;
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
        }

        table,
        th,
        td {
            border: 1px solid #ccc;
        }

        th,
        td {
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #002c5f;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .delete-button {
            display: inline-block;
            padding: 5px 10px;
            background-color: #e63946;
            /* Red color for the delete button */
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            border: none;
            /* Removes default button border */
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .delete-button:hover {
            background-color: #d62828;
            /* Darker red for hover effect */
        }

        button.delete-button {
            font-family: 'Poppins', sans-serif;
            /* Ensures consistent font style */
        }

        .edit-button {
            display: inline-block;
            padding: 5px 10px;
            background-color: #457b9d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            transition: background-color 0.3s ease;
            text-align: center;
        }

        .edit-button:hover {
            background-color: #1d3557;
        }

        input,
        select {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            display: inline-block;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            /* Ensure consistent box sizing */
        }

        textarea {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            display: inline-block;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            /* Ensure consistent box sizing */
            resize: vertical;
            /* Allows resizing vertically */
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
            /* Allow wrapping of filter elements */
            gap: 10px;
            /* Adjust the gap between dropdowns and date range */
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .filter-dropdown {
            margin-bottom: 15px;
            flex: 1 1 300px;
            /* Allow flexible sizing with a minimum width of 300px */
            max-width: 100%;
            /* Ensure it doesn't exceed the parent container */
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
            /* Make the dropdowns and inputs take full width of their container */
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }

        .filter-date {
            display: flex;
            gap: 10px;
            align-items: center;
            flex: 1 1 300px;
            /* Allow flexible sizing with a minimum width of 300px */
            max-width: 100%;
            /* Ensure it doesn't exceed the parent container */
        }

        .filter-date .filter-dropdown {
            margin-bottom: 0;
            /* Remove bottom margin for date range dropdowns */
            flex: 1 1 150px;
            /* Allow flexible sizing for date inputs */
        }

        .custom-table tr.delayed-task {
            --bs-table-bg: transparent !important;
            --bs-table-hover-bg: transparent !important;
            --bs-table-striped-bg: transparent !important;
            --bs-table-border-color: var(--bs-border-color) !important;
            background-color: #f8d7da !important;
            /* Light red */
            color: #842029 !important;
            /* Dark red text */
        }

        .task-description {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            /* Limit to 2 lines */
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            /* Allow wrapping */
            max-width: 300px;
            /* Adjust as needed */
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

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
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
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
            /* Remove default margin */
        }

        #status-filter {
            width: 300px;
            /* Adjust width as needed */
        }

        .button-container {
            display: flex;
            /* Use flexbox for layout */
            flex-direction: column;
            /* Stack buttons vertically */
            gap: 8px;
            /* Add spacing between buttons */
            align-items: stretch;
            /* Make buttons stretch to the same width */
        }

        .button-container .btn {
            width: 100%;
            /* Make buttons take full width of the container */
            text-align: center;
            /* Center text inside buttons */
            padding: 0.375rem 0.75rem;
            /* Match Bootstrap's default button padding */
            display: flex;
            /* Use flexbox for centering */
            justify-content: center;
            /* Center text horizontally */
            align-items: center;
            /* Center text vertically */
        }

        /* Ensure the <a> and <button> elements look the same */
        .button-container a.btn,
        .button-container button.btn {
            text-decoration: none;
        }
    </style>
</head>

<body>
    <!-- Task Management Modal -->
    <div class="modal fade" id="taskManagementModal" tabindex="-1" aria-labelledby="taskManagementModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskManagementModalLabel">Create New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="">
                        <input type="hidden" id="user-role" value="<?= htmlspecialchars($user_role) ?>">

                        <!-- Project Name Field -->
                        <div class="form-group">
                            <label for="project_name">Project Name:</label>
                            <select id="project_name_dropdown" class="form-control mb-2" name="project_id" required>
                                <option value="">Select an existing project</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= htmlspecialchars($project['id']) ?>">
                                        <?= htmlspecialchars($project['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Predecessor Task Field -->
                        <div class="form-group">
                            <label for="predecessor_task_id">Predecessor Task (Optional):</label>
                            <select id="predecessor_task_id" name="predecessor_task_id" class="form-control">
                                <option value="">Select a predecessor task</option>
                                <!-- Predecessor tasks will be dynamically populated here -->
                            </select>
                        </div>

                        <!-- Rest of the form fields -->
                        <div class="form-group">
                            <label for="task_name">Task Name:</label>
                            <input type="text" id="task_name" name="task_name" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="task_description">Task Description:</label>
                            <textarea id="task_description" name="task_description" rows="4"
                                class="form-control"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="project_type">Project Type:</label>
                            <select id="project_type" name="project_type" class="form-control">
                                <option value="Internal">Internal</option>
                                <option value="External">External</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="planned_start_date">Expected Start Date & Time</label>
                            <input type="datetime-local" id="planned_start_date" name="planned_start_date"
                                class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="planned_finish_date">Expected End Date & Time</label>
                            <input type="datetime-local" id="planned_finish_date" name="planned_finish_date"
                                class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="assigned_user_id">Assign to:</label>
                            <select id="assigned_user_id" name="assigned_user_id" class="form-control" required>
                                <option value="">Select a user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                        (<?= htmlspecialchars($user['departments']) ?> -
                                        <?= htmlspecialchars($user['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">Add Task</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Creating New Projects -->
    <div class="modal fade" id="createProjectModal" tabindex="-1" aria-labelledby="createProjectModalLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createProjectModalLabel">Create New Project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createProjectForm" method="POST" action="create-project.php">
                        <div class="form-group">
                            <label for="new_project_name">Project Name:</label>
                            <input type="text" id="new_project_name" name="new_project_name" class="form-control"
                                required>
                        </div>
                        <input type="hidden" name="created_by_user_id" value="<?= $user_id ?>">
                        <button type="submit" class="btn btn-primary">Create Project</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    // Fetch all unique project names from the tasks table
    $projectQuery = $conn->query("SELECT id, project_name FROM projects");
    if ($projectQuery) {
        $projects = $projectQuery->fetch_all(MYSQLI_ASSOC);
    } else {
        die("Error fetching projects: " . $conn->error);
    }
    ?>


    <body>

        <!-- Sidebar and Navbar -->
        <div class="dashboard-container">
            <!-- Sidebar -->
            <div class="sidebar">
                <h3>Menu</h3>
                <a href="tasks.php">Tasks</a>
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

            <!-- Main Content -->
            <div class="main-content">
                <!-- Navbar -->
                <div class="navbar">
                    <!-- Logo Container -->
                    <div class="d-flex align-items-center me-3">
                        <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                    </div>

                    <!-- User Info -->
                    <div class="user-info me-3 ms-auto">
                        <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($loggedInUsername) ?></strong></p>
                        <p class="mb-0">Departments:
                            <strong><?= htmlspecialchars($loggedInDepartment ?? 'Unknown') ?></strong>
                        </p>
                    </div>

                    <!-- Back Button -->
                    <button class="back-btn" onclick="window.location.href='welcome.php'">Back</button>
                </div>

                <div class="task-container">
                    <h2>Tasks</h2>
                    <div class="container mt-4">
                        <!-- Filter Buttons -->
                        <!-- Filter Container -->
                        <div class="filter-container">
                            <div class="filter-buttons">
                                <?php if (hasPermission('create_tasks')): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                        data-bs-target="#createProjectModal">
                                        Create New Project
                                    </button>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                        data-bs-target="#taskManagementModal">
                                        Create New Task
                                    </button>
                                <?php endif; ?>
                                <button onclick="resetFilters()" class="btn btn-primary">Reset</button>
                                <?php if (hasPermission('export_tasks')): ?>
                                    <a href="export_tasks.php" class="btn btn-success">Export to CSV</a>
                                <?php endif; ?>
                            </div>

                            <!-- Filter Dropdowns and Date Range -->
                            <div class="filter-row">
                                <!-- Multi-select dropdown for filtering by project -->
                                <div class="filter-dropdown">
                                    <label for="project-filter">Filter by Project:</label>
                                    <select id="project-filter" multiple="multiple">
                                        <option value="All">All</option>
                                        <?php foreach ($projects as $project): ?>
                                            <option value="<?= htmlspecialchars($project['project_name']) ?>"
                                                <?= (isset($_GET['project']) && in_array($project['project_name'], explode(',', $_GET['project']))) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($project['project_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if (hasPermission('filter_tasks') || $hasMultipleDepartments): ?>
                                    <!-- Multi-select dropdown for filtering by department -->
                                    <div class="filter-dropdown">
                                        <label for="department-filter">Filter by Department of Assigned User:</label>
                                        <select id="department-filter" multiple="multiple">
                                            <option value="All">All</option>
                                            <?php foreach ($departments as $department): ?>
                                                <option value="<?= htmlspecialchars($department['name']) ?>"
                                                    <?= (isset($_GET['department']) && in_array($department['name'], explode(',', $_GET['department']))) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($department['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif ?>

                                <!-- Date Range Inputs -->
                                <div class="filter-date">
                                    <div class="filter-dropdown">
                                        <label for="start-date">Start Date:</label>
                                        <input type="date" id="start-date"
                                            value="<?= isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : '' ?>">
                                    </div>
                                    <div class="filter-dropdown">
                                        <label for="end-date">End Date:</label>
                                        <input type="date" id="end-date"
                                            value="<?= isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pending & Started Tasks Table -->
                        <h3>Tasks In Progress</h3>
                        <!-- Filtering ny status for this table only -->
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
                                    <th>Actual Start Date</th>
                                    <th>Actual End Date</th>
                                    <th>Status</th>
                                    <th>Project Type</th>
                                    <th>Assigned By</th>
                                    <?php if (hasPermission('assign_tasks')): ?>
                                        <th>Assigned To</th>
                                    <?php endif; ?>
                                    <th>Created On</th>
                                    <th>Predecessor Task</th>
                                    <?php if (hasPermission('assign_tasks')): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $taskCountStart = 1;
                                foreach ($pendingStartedTasks as $row): ?>
                                    <tr class="align-middle">
                                        <td><?= $taskCountStart++ ?></td> <!-- Display task count and increment -->
                                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                                        <td>
                                            <?php if ($row['status'] === 'Completed on Time'): ?>
                                                <!-- Link to Completed on Time Modal -->
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#viewDescriptionModal"
                                                    data-description="<?= htmlspecialchars($row['completion_description']); ?>">
                                                    <?= htmlspecialchars($row['task_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <!-- Plain Text for Other Statuses -->
                                                <?php echo htmlspecialchars($row['task_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="task-description-container">
                                                <div class="task-description">
                                                    <?= htmlspecialchars($row['task_description']) ?>
                                                </div>
                                                <a href="#" class="see-more-link" data-bs-toggle="modal"
                                                    data-bs-target="#taskDescriptionModal"
                                                    data-description="<?= htmlspecialchars($row['task_description']) ?>"
                                                    style="display: none;">
                                                    See more
                                                </a>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_start_date']))) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_finish_date']))) ?>
                                        </td>
                                        <td>
                                            <?= $row['actual_start_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['actual_start_date']))) : 'N/A' ?>
                                        </td>
                                        <td>
                                            <?= $row['task_actual_finish_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['actual_finish_date']))) : 'N/A' ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="update-status.php">
                                                <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                                                <?php
                                                // Fetch the current status of the task
                                                $currentStatus = $row['status'];

                                                // Fetch the assigned_by_id for the task
                                                $assigned_by_id = $row['assigned_by_id'];

                                                // Fetch the user_id of the assigned user
                                                $assigned_user_id = $row['user_id'];

                                                // Initialize statuses array
                                                $statuses = [];

                                                // Check if the task is self-assigned
                                                $isSelfAssigned = ($assigned_by_id == $user_id && $assigned_user_id == $user_id);

                                                // Define status sets
                                                $assignerStatuses = ['Assigned', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'];
                                                $normalUserStatuses = [
                                                    'Assigned' => ['In Progress'],
                                                    'In Progress' => isset($row['available_statuses'][0]) ? [$row['available_statuses'][0]] : []
                                                ];

                                                // Logic for status_change_main privilege or the user who assigned the task (excluding self-assigned)
                                                if (hasPermission('status_change_main') || ($assigned_by_id == $user_id && !$isSelfAssigned)) {
                                                    if (in_array($currentStatus, ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'])) {
                                                        $statuses = $assignerStatuses;
                                                    }
                                                }
                                                // Logic for self-assigned users with status_change_normal
                                                elseif ($isSelfAssigned && hasPermission('status_change_normal')) {
                                                    $statuses = $assignerStatuses; // Start with assigner statuses
                                            
                                                    if (isset($normalUserStatuses[$currentStatus])) {
                                                        // Merge in the statuses that a normal assigned user would get
                                                        $statuses = array_merge($statuses, $normalUserStatuses[$currentStatus]);
                                                    } else {
                                                        // Allow all default statuses from both assigner and normal user logic
                                                        $allowedStatuses = array_merge($assignerStatuses, ['Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion']);
                                                        if (in_array($currentStatus, $allowedStatuses)) {
                                                            $statuses = $allowedStatuses;
                                                        }
                                                    }
                                                }
                                                // Logic for Regular User (assigned user)
                                                elseif (hasPermission('status_change_normal') && $user_id == $assigned_user_id) {
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

                                                // Generate the status dropdown or display the status as text
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
                                            (<?= htmlspecialchars($row['assigned_by_department']) ?>)
                                        </td>
                                        <?php if (hasPermission('assign_tasks')): ?>
                                            <td><?= htmlspecialchars($row['assigned_to']) ?>
                                                (<?= htmlspecialchars($row['assigned_to_department']) ?>)
                                            </td>
                                        <?php endif; ?>
                                        <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['predecessor_task_name'] ?? 'N/A') ?></td>
                                        <?php if ((hasPermission('update_tasks') && $row['assigned_by_id'] == $_SESSION['user_id']) || hasPermission('update_tasks_all')): ?>
                                            <td>
                                                <div class="button-container">
                                                    <a href="edit-tasks.php?id=<?= $row['task_id'] ?>"
                                                        class="edit-button">Edit</a>
                                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal"
                                                        data-bs-target="#deleteModal<?= $row['task_id'] ?>">
                                                        Delete
                                                    </button>
                                                </div>
                                                <!-- Delete Modal -->
                                                <div class="modal fade" id="deleteModal<?= $row['task_id'] ?>" tabindex="-1"
                                                    aria-labelledby="deleteModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="deleteModalLabel">Delete Task</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                                    aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <form method="POST" action="delete-task.php">
                                                                    <input type="hidden" name="task_id"
                                                                        value="<?= $row['task_id'] ?>">
                                                                    <input type="hidden" name="user_id"
                                                                        value="<?= $_SESSION['user_id'] ?>">
                                                                    <div class="mb-3">
                                                                        <label for="reason" class="form-label">Reason for
                                                                            deleting the task:</label>
                                                                        <textarea class="form-control" id="reason" name="reason"
                                                                            rows="3" required></textarea>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary"
                                                                            data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-danger">Delete
                                                                            Task</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php else: ?>
                                            <td>N/A</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Alert for Pending & In Progress Tasks -->
                        <div id="no-data-alert-pending" class="alert alert-warning mt-3"
                            style="display: <?= $showPendingAlert ?>;">
                            No data to be displayed.
                        </div>

                        <!-- Completed Tasks Table -->
                        <h3>Completed Tasks</h3>
                        <table class="table table-striped table-hover align-middle text-center custom-table"
                            id="remaining-tasks">
                            <thead>
                                <tr class="align-middle">
                                    <th>#</th> <!-- New column for task count -->
                                    <th>Project Name</th>
                                    <th>Task Name</th>
                                    <th>Task Description</th>
                                    <th>Planned Start Date</th>
                                    <th>Planned End Date</th>
                                    <th>Actual Start Date</th>
                                    <th>Actual End Date</th>
                                    <th>Status</th>
                                    <th>Project Type</th>
                                    <th>Assigned By</th>
                                    <?php if (hasPermission('assign_tasks')): ?>
                                        <th>Assigned To</th>
                                    <?php endif; ?>
                                    <th>Created On</th>
                                    <th>Predecessor Task</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $taskCountStart = 1;
                                foreach ($completedTasks as $row): ?>
                                    <tr data-project="<?= htmlspecialchars($row['project_name']) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>" class="align-middle <?php if ($row['status'] === 'Delayed Completion')
                                              echo 'delayed-task'; ?>">
                                        <td><?= $taskCountStart++ ?></td> <!-- Display task count and increment -->
                                        <td><?= htmlspecialchars($row['project_name']) ?></td>
                                        <td>
                                            <?php if ($row['status'] === 'Completed on Time'): ?>
                                                <!-- Link to Completed on Time Modal -->
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#viewDescriptionModal"
                                                    data-description="<?= htmlspecialchars($row['completion_description']); ?>">
                                                    <?= htmlspecialchars($row['task_name']); ?>
                                                </a>
                                            <?php elseif ($row['status'] === 'Delayed Completion'): ?>
                                                <!-- Link to Delayed Completion Modal -->
                                                <a href="#" data-bs-toggle="modal" data-bs-target="#delayedCompletionModal"
                                                    onclick="showDelayedDetails('<?php echo htmlspecialchars($row['task_name']); ?>', '<?php echo htmlspecialchars($row['task_actual_finish_date']); ?>', '<?php echo htmlspecialchars($row['delayed_reason']); ?>', '<?php echo htmlspecialchars($row['completion_description']); ?>')">
                                                    <?php echo htmlspecialchars($row['task_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <!-- Plain Text for Other Statuses -->
                                                <?php echo htmlspecialchars($row['task_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="task-description-container">
                                                <div class="task-description">
                                                    <?= htmlspecialchars($row['task_description']) ?>
                                                </div>
                                                <a href="#" class="see-more-link" data-bs-toggle="modal"
                                                    data-bs-target="#taskDescriptionModal"
                                                    data-description="<?= htmlspecialchars($row['task_description']) ?>"
                                                    style="display: none;">
                                                    See more
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_start_date']))) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['planned_finish_date']))) ?>
                                        </td>
                                        <td>
                                            <?= $row['actual_start_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($row['actual_start_date']))) : 'N/A' ?>
                                        </td>
                                        <td>
                                            <?php if ($row['task_actual_finish_date']): ?>
                                                <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['task_actual_finish_date']))) ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="update-status.php">
                                                <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                                                <?php
                                                // Fetch the current status of the task
                                                $currentStatus = $row['status'];

                                                // Fetch the assigned_by_id for the task
                                                $assigned_by_id = $row['assigned_by_id'];

                                                // Define the available statuses based on the user role and current status
                                                $statuses = [];
                                                if (hasPermission('status_change_main') || $assigned_by_id == $user_id) {
                                                    // Admin or the user who assigned the task can change status to "Closed"
                                                    if (in_array($currentStatus, ['Completed on Time', 'Delayed Completion'])) {
                                                        $statuses = ['Closed'];
                                                    }
                                                }

                                                // Generate the status dropdown or display the status as text
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
                                                    // Display the status as plain text if the user is not allowed to change it
                                                    echo $currentStatus;
                                                }

                                                // Show delay information for delayed completion
                                                if ($currentStatus === 'Delayed Completion') {
                                                    $plannedStartDate = strtotime($row['planned_start_date']);
                                                    $plannedFinishDate = strtotime($row['planned_finish_date']);

                                                    // Ensure planned finish date is after planned start date
                                                    if ($plannedFinishDate < $plannedStartDate) {
                                                        $plannedFinishDate += 86400; // Add 24 hours to correct AM/PM crossing
                                                    }

                                                    // Calculate planned duration
                                                    $plannedDuration = $plannedFinishDate - $plannedStartDate;

                                                    if (!empty($row['actual_start_date']) && !empty($row['task_actual_finish_date'])) {
                                                        $actualStartDate = strtotime($row['actual_start_date']);
                                                        $actualFinishDate = strtotime($row['task_actual_finish_date']);

                                                        // Ensure actual finish date is after actual start date
                                                        if ($actualFinishDate < $actualStartDate) {
                                                            $actualFinishDate += 86400; // Add 24 hours if needed
                                                        }

                                                        // Calculate actual duration
                                                        $actualDuration = $actualFinishDate - $actualStartDate;
                                                        $delaySeconds = max(0, $actualDuration - $plannedDuration); // Prevent negative delays
                                            
                                                        if ($delaySeconds > 0) {
                                                            $delayDays = floor($delaySeconds / (60 * 60 * 24));
                                                            $delayHours = floor(($delaySeconds % (60 * 60 * 24)) / (60 * 60));

                                                            $delayText = [];
                                                            if ($delayDays > 0) {
                                                                $delayText[] = "$delayDays days";
                                                            }
                                                            if ($delayHours > 0 || empty($delayText)) {
                                                                $delayText[] = "$delayHours hours";
                                                            }

                                                            echo "<br><small class='text-danger'>" . implode(", ", $delayText) . " delayed</small>";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </form>
                                        </td>
                                        <td><?= htmlspecialchars($row['project_type']) ?></td>
                                        <td><?= htmlspecialchars($row['assigned_by']) ?>
                                            (<?= htmlspecialchars($row['assigned_by_department']) ?>)
                                        </td>
                                        <?php if (hasPermission('assign_tasks')): ?>
                                            <td><?= htmlspecialchars($row['assigned_to']) ?>
                                                (<?= htmlspecialchars($row['assigned_to_department']) ?>)
                                            </td>
                                        <?php endif; ?>
                                        <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['predecessor_task_name'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Alert for Completed Tasks -->
                        <div id="no-data-alert-completed" class="alert alert-warning mt-3"
                            style="display: <?= $showCompletedAlert ?>;">
                            No data to be displayed.
                        </div>

                        <!-- Pagination for the entire page -->
                        <div class="pagination"></div>
                    </div>
                </div>
            </div>

            <!-- Modal for Task Completion -->
            <div class="modal fade" id="completionModal" tabindex="-1" aria-labelledby="completionModalLabel"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form id="completionForm" method="POST" onsubmit="handleCompletionForm(event)">
                            <div class="modal-header">
                                <h5 class="modal-title" id="completionModalLabel">Task Completion</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Hidden input for Task ID -->
                                <input type="hidden" id="task-id" name="task_id">
                                <!-- Hidden input for Status -->
                                <input type="hidden" id="modal-status" name="status">
                                <!-- Hidden input for Actual Completion Date (automatically populated) -->
                                <input type="hidden" id="actual-completion-date" name="actual_finish_date">

                                <!-- Display predecessor task name -->
                                <p><strong>Predecessor Task:</strong> <span id="predecessor-task-name"></span></p>

                                <!-- Completion Description -->
                                <div class="mb-3">
                                    <label for="completion-description" class="form-label">What was completed?</label>
                                    <textarea class="form-control" id="completion-description"
                                        name="completion_description" rows="3" required></textarea>
                                </div>

                                <!-- Delayed Reason (Shown only for Delayed Completion) -->
                                <div class="mb-3" id="delayed-reason-container" style="display: none;">
                                    <label for="delayed-reason" class="form-label">Why was it completed late?</label>
                                    <textarea class="form-control" id="delayed-reason" name="delayed_reason"
                                        rows="3"></textarea>
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

            <!-- Modal for Viewing Completion Description -->
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
            <div class="modal fade" id="delayedCompletionModal" tabindex="-1"
                aria-labelledby="delayedCompletionModalLabel" aria-hidden="true">
                <!-- Modal for delayed completion details viewing -->
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

            <!-- Success modal for task updation -->
            <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel"
                aria-hidden="true">
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

            <!-- Modal for task description -->
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

            <!-- Modal for Reassignment -->
            <div class="modal fade" id="reassignmentModal" tabindex="-1" aria-labelledby="reassignmentModalLabel"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form id="reassignmentForm" method="POST" onsubmit="handleReassignmentForm(event)">
                            <div class="modal-header">
                                <h5 class="modal-title" id="reassignmentModalLabel">Reassign Task</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Hidden input for Task ID -->
                                <input type="hidden" id="reassign-task-id" name="task_id">
                                <!-- Hidden input for Status (will be set to "Assigned") -->
                                <input type="hidden" id="reassign-status" name="status" value="Reassigned">

                                <!-- Dropdown for selecting the user to reassign to -->
                                <div class="mb-3">
                                    <label for="reassign-user-id" class="form-label">Reassign To:</label>
                                    <select id="reassign-user-id" name="reassign_user_id" class="form-control" required>
                                        <option value="">Select a user</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['id'] ?>">
                                                <?= htmlspecialchars($user['username']) ?>
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

            <!-- Jquery -->
            <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

            <!-- Script for opening the modal to view details of completion -->
            <script>
                // Attach event listener for task name links
                const viewDescriptionModal = document.getElementById('viewDescriptionModal');
                viewDescriptionModal.addEventListener('show.bs.modal', function (event) {
                    // Button/link that triggered the modal
                    const button = event.relatedTarget;

                    // Extract completion description from data attribute
                    const description = button.getAttribute('data-description');

                    // Update the modal content
                    const descriptionText = document.getElementById('completion-description-text');
                    descriptionText.textContent = description || "No description provided.";
                });
            </script>

            <script>
                const viewDescriptionModal = document.getElementById('viewDescriptionModal');
                viewDescriptionModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const description = button.getAttribute('data-description');
                    const descriptionText = document.getElementById('completion-description-text');
                    descriptionText.textContent = description || "No description provided.";
                });
            </script>

            <!-- JS for the dropdown handling -->
            <script>
                // function to calculate the duration of days and hours between the actual dates
                function calculateWeekdayDuration(startDate, endDate) {
                    let days = 0;
                    let hours = 0;
                    let currentDate = new Date(startDate);

                    // Loop through each day between start and end dates
                    while (currentDate <= endDate) {
                        const dayOfWeek = currentDate.getDay(); // 0 (Sunday) to 6 (Saturday)

                        // Exclude weekends
                        if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                            days++;
                        }

                        // Move to the next day
                        currentDate.setDate(currentDate.getDate() + 1);
                    }

                    // Calculate the remaining hours
                    const startTime = startDate.getTime();
                    const endTime = endDate.getTime();
                    const totalHours = Math.floor((endTime - startTime) / (1000 * 60 * 60));
                    hours = totalHours % 24;

                    return { days, hours };
                }

                function handleStatusChange(event, taskId) {
                    event.preventDefault();

                    const status = event.target.value;
                    const form = event.target.form;

                    // Fetch the predecessor task name
                    const predecessorTaskName = $(`#pending-tasks tr[data-task-id="${taskId}"]`).find('td:eq(12)').text();

                    if (status === 'Reassigned') {
                        // Show the reassignment modal
                        document.getElementById('reassign-task-id').value = taskId;
                        const reassignmentModal = new bootstrap.Modal(document.getElementById('reassignmentModal'));
                        reassignmentModal.show();
                    } else if (status === 'Delayed Completion' || status === 'Completed on Time') {
                        // Set the task ID and status in the completion modal
                        document.getElementById('task-id').value = taskId;
                        document.getElementById('modal-status').value = status;
                        document.getElementById('predecessor-task-name').innerText = predecessorTaskName; // Set predecessor task name

                        // Show or hide the delayed reason container based on the status
                        const delayedReasonContainer = document.getElementById('delayed-reason-container');
                        if (delayedReasonContainer) { // Check if the element exists
                            if (status === 'Delayed Completion') {
                                delayedReasonContainer.style.display = 'block';
                            } else {
                                delayedReasonContainer.style.display = 'none';
                            }
                        }

                        // Show the completion modal
                        const completionModal = new bootstrap.Modal(document.getElementById('completionModal'));
                        completionModal.show();
                    } else if (status === 'Closed') {
                        // Existing logic for 'Closed' status
                        fetch('update-status.php', {
                            method: 'POST',
                            body: new FormData(form)
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    document.getElementById('success-task-name').innerText = data.task_name;
                                    document.getElementById('success-message').innerText = data.message;
                                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                                    successModal.show();

                                    // Refresh the page after 2 seconds
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    alert(data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while updating the status.');
                            });
                    } else {
                        // Existing logic for other statuses
                        fetch('update-status.php', {
                            method: 'POST',
                            body: new FormData(form)
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    document.getElementById('success-task-name').innerText = data.task_name;
                                    document.getElementById('success-message').innerText = data.message;
                                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                                    successModal.show();

                                    // Refresh the page after 2 seconds
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    alert(data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('An error occurred while updating the status.');
                            });
                    }
                }

                // Handle Reassignment Form Submission
                function handleReassignmentForm(event) {
                    event.preventDefault(); // Prevent the default form submission

                    const form = event.target;
                    const formData = new FormData(form);

                    // Explicitly set the status to "Reassigned"
                    formData.set('status', 'Reassigned');

                    fetch('reassign-task.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Close the reassignment modal
                                const reassignmentModal = bootstrap.Modal.getInstance(document.getElementById('reassignmentModal'));
                                reassignmentModal.hide();

                                // Show the success modal
                                document.getElementById('success-task-name').innerText = data.task_name;
                                document.getElementById('success-message').innerText = data.message;
                                const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                                successModal.show();

                                // Refresh the page after 2 seconds
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                alert(data.message); // Show an error message if the update failed
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while reassigning the task.');
                        });
                }
            </script>

            <!-- Script for viewing the delayed completion details -->
            <script>
                function showDelayedDetails(taskName, completionDate, delayReason, completionDescription) {
                    // Set the modal elements with the provided values
                    document.getElementById('delayed-task-name').innerText = taskName || "N/A";
                    document.getElementById('delayed-completion-date').innerText = completionDate || "N/A";
                    document.getElementById('delay-reason').innerText = delayReason || "N/A";

                    // Correctly set the completion description
                    const completionDescriptionElement = document.getElementById('completion-description-delayed');
                    completionDescriptionElement.innerText = completionDescription && completionDescription.trim() ? completionDescription : "No description provided.";
                }
            </script>
            <!-- Script for handling completion form -->
            <script>
                function handleCompletionForm(event) {
                    event.preventDefault(); // Prevent the default form submission

                    // Get the current date and time in the correct format (YYYY-MM-DD HH:MM:SS)
                    const now = new Date();
                    const formattedDate = now.toISOString().slice(0, 19).replace('T', ' ');

                    // Set the value of the hidden input field for the actual finish date
                    document.getElementById('actual-completion-date').value = formattedDate;

                    const form = event.target;
                    const formData = new FormData(form);

                    fetch('update-status.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json(); // Parse the response as JSON
                        })
                        .then(data => {
                            if (data.success) {
                                // Close the completion modal
                                const completionModal = bootstrap.Modal.getInstance(document.getElementById('completionModal'));
                                completionModal.hide();

                                // Show the success modal
                                document.getElementById('success-task-name').innerText = data.task_name;
                                document.getElementById('success-message').innerText = data.message;
                                const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                                successModal.show();

                                // Refresh the page after 2 seconds
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                alert(data.message); // Show an error message if the update failed
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while updating the status.');
                        });
                }
            </script>

            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
                integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
                crossorigin="anonymous">
                </script>
            <!-- Fix for Select2 and Filtering -->
            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

            <script>
                $(document).ready(function () {
                    // Get the user's timezone
                    const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

                    // Send the timezone to the server (via AJAX or hidden form field)
                    document.cookie = "user_timezone=" + userTimeZone; // Store it as a cookie

                    const tasksPerPage = 10; // Number of tasks per table per page
                    let currentPage = 1; // Current page for both tables

                    // Initialize Select2 for project and department filters
                    $('#project-filter').select2({
                        placeholder: "Select projects to filter",
                        allowClear: true,
                        width: '300px'
                    });

                    $('#department-filter').select2({
                        placeholder: "Select departments to filter",
                        allowClear: true,
                        width: '300px'
                    });

                    $('#status-filter').select2({
                        placeholder: "Select statuses to filter",
                        allowClear: true,
                        width: '300px'
                    });

                    // Function to apply filters and update pagination
                    function applyFilters() {
                        const selectedProjects = $('#project-filter').val() || [];
                        const selectedDepartments = $('#department-filter').val() || [];
                        const startDate = $('#start-date').val();
                        const endDate = $('#end-date').val();

                        // Filter and paginate Pending Tasks
                        const pendingVisibleRows = filterAndPaginateTable('#pending-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);

                        // Filter and paginate Completed Tasks
                        const completedVisibleRows = filterAndPaginateTable('#remaining-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);

                        // Update pagination controls
                        updatePagination(pendingVisibleRows, completedVisibleRows);
                    }

                    function filterAndPaginateTable(tableId, selectedProjects, selectedDepartments, startDate, endDate, currentPage) {
                        const rows = $(`${tableId} tbody tr`);
                        let visibleRows = [];

                        // Get selected statuses only for the Tasks in Progress table
                        const selectedStatuses = tableId === '#pending-tasks' ? $('#status-filter').val() || [] : [];

                        rows.each(function () {
                            const projectName = $(this).find('td:nth-child(2)').text().trim();
                            const departmentName = $(this).find('td:nth-child(12)').text().trim().match(/\(([^)]+)\)/)?.[1] || '';
                            const plannedStartDate = new Date($(this).find('td:nth-child(5)').text().trim());
                            const plannedFinishDate = new Date($(this).find('td:nth-child(6)').text().trim());
                            const actualStartDate = new Date($(this).find('td:nth-child(7)').text().trim());
                            const actualFinishDate = new Date($(this).find('td:nth-child(8)').text().trim());
                            const taskStatus = $(this).find('td:nth-child(9) select').val() || $(this).find('td:nth-child(9)').text().trim();

                            // Enhanced date range filtering
                            let dateInRange = true;
                            if (startDate && endDate) {
                                const filterStartDate = new Date(startDate);
                                const filterEndDate = new Date(endDate);

                                // Check planned dates
                                const plannedInRange = plannedStartDate >= filterStartDate && plannedFinishDate <= filterEndDate;

                                // Check actual dates if they exist
                                const actualInRange = !isNaN(actualStartDate) && !isNaN(actualFinishDate) &&
                                    actualStartDate >= filterStartDate && actualFinishDate <= filterEndDate;

                                dateInRange = plannedInRange || actualInRange;
                            }

                            // Check if the row matches the selected filters
                            const projectMatch = selectedProjects.length === 0 || selectedProjects.includes('All') || selectedProjects.includes(projectName);
                            const departmentMatch = selectedDepartments.length === 0 || selectedDepartments.includes('All') || departmentName.split(', ').some(dept => selectedDepartments.includes(dept));
                            const statusMatch = tableId !== '#pending-tasks' || selectedStatuses.length === 0 || selectedStatuses.includes('All') || selectedStatuses.includes(taskStatus);

                            if (projectMatch && departmentMatch && statusMatch && dateInRange) {
                                visibleRows.push(this);
                            }
                        });

                        // Hide all rows
                        rows.hide();

                        // Show rows for the current page
                        const startIndex = (currentPage - 1) * tasksPerPage;
                        const endIndex = startIndex + tasksPerPage;
                        const rowsToShow = visibleRows.slice(startIndex, endIndex);

                        if (rowsToShow.length > 0) {
                            rowsToShow.forEach((row, index) => {
                                $(row).find('td:first-child').text(startIndex + index + 1); // Update task numbering
                                $(row).show();
                            });
                        }

                        // Show/hide "No data" alert
                        const noDataAlert = $(`${tableId} + .alert`);
                        if (visibleRows.length === 0) {
                            noDataAlert.show(); // Show the alert if no rows match the filters
                        } else if (rowsToShow.length === 0) {
                            noDataAlert.show(); // Show the alert if no rows are on the current page
                        } else {
                            noDataAlert.hide(); // Hide the alert if there are rows to show
                        }

                        // Return the number of visible rows for pagination
                        return visibleRows.length;
                    }

                    function resetTaskNumbering(tableId) {
                        const rows = $(`${tableId} tbody tr`);
                        rows.each(function (index) {
                            $(this).find('td:first-child').text(index + 1); // Reset task numbering
                        });
                    }

                    $('#status-filter').on('change', function () {
                        currentPage = 1; // Reset to the first page when filters change
                        applyFilters();
                    });

                    function updatePagination(pendingVisibleRows, completedVisibleRows) {
                        // Calculate total pages based on the larger of the two tables
                        const totalPages = Math.max(
                            Math.ceil(pendingVisibleRows / tasksPerPage),
                            Math.ceil(completedVisibleRows / tasksPerPage)
                        );

                        const pagination = $('.pagination');
                        pagination.empty();

                        if (currentPage > 1) {
                            pagination.append(`<a href="#" class="page-link" data-page="${currentPage - 1}">Previous</a>`);
                        }

                        for (let i = 1; i <= totalPages; i++) {
                            pagination.append(`<a href="#" class="page-link ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</a>`);
                        }

                        if (currentPage < totalPages) {
                            pagination.append(`<a href="#" class="page-link" data-page="${currentPage + 1}">Next</a>`);
                        }
                    }

                    // Attach click event to pagination links
                    $(document).on('click', '.page-link', function (e) {
                        e.preventDefault();
                        currentPage = parseInt($(this).data('page'));
                        applyFilters();
                    });

                    // Attach filter change events
                    $('#project-filter, #department-filter, #start-date, #end-date').on('change', function () {
                        currentPage = 1; // Reset to the first page when filters change
                        applyFilters();
                    });

                    // Reset filters
                    function resetFilters() {
                        $('#project-filter').val(null).trigger('change');
                        $('#department-filter').val(null).trigger('change');
                        $('#start-date').val('');
                        $('#end-date').val('');
                        $('#status-filter').val(['Assigned']).trigger('change');
                        currentPage = 1;

                        // Reset task numbering for both tables
                        resetTaskNumbering('#pending-tasks');
                        resetTaskNumbering('#remaining-tasks');

                        // Hide the "No data" alerts
                        $('#no-data-alert-pending').hide();
                        $('#no-data-alert-completed').hide();

                        applyFilters();
                    }

                    // Attach reset button event
                    $('.btn-primary[onclick="resetFilters()"]').on('click', resetFilters);

                    // Initialize pagination
                    applyFilters();
                });
            </script>

            <!-- JS for task description modal -->
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const taskDescriptionModal = document.getElementById('taskDescriptionModal');
                    taskDescriptionModal.addEventListener('show.bs.modal', function (event) {
                        const button = event.relatedTarget; // Button/link that triggered the modal
                        const description = button.getAttribute('data-description'); // Extract description from data attribute
                        const modalBody = taskDescriptionModal.querySelector('.modal-body p');
                        modalBody.textContent = description; // Set the modal content
                    });
                });
            </script>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    function convertUTCTimeToLocal() {
                        const timestampCells = document.querySelectorAll('td[data-utc]');

                        timestampCells.forEach(cell => {
                            const utcTimestamp = cell.getAttribute('data-utc'); // Get the UTC timestamp

                            const options = {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: true,
                                timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone // Use the user's timezone
                            };

                            const localTime = new Date(utcTimestamp).toLocaleString('en-US', options);

                            // Update the cell content with the local time
                            cell.textContent = localTime;
                        });
                    }

                    convertUTCTimeToLocal();
                });
            </script>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // Select all delete buttons
                    const deleteButtons = document.querySelectorAll('.btn-danger[data-bs-toggle="modal"]');
                    deleteButtons.forEach(button => {
                        button.addEventListener('click', function () {
                            const targetModalId = button.getAttribute('data-bs-target');
                            const targetModal = document.querySelector(targetModalId);

                            if (targetModal) {
                                console.log(`Opening modal: ${targetModalId}`);
                                // Modal opens automatically due to Bootstrap behavior
                            } else {
                                console.error(`Modal not found: ${targetModalId}`);
                            }
                        });
                    });
                }); 
            </script>
            <!-- To check if task desc is more than 2 lines -->
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // Function to check if the description exceeds 2 lines
                    function checkDescriptionHeight() {
                        const descriptionContainers = document.querySelectorAll('.task-description-container');

                        descriptionContainers.forEach(container => {
                            const descriptionElement = container.querySelector('.task-description');
                            const seeMoreLink = container.querySelector('.see-more-link');

                            // Calculate the height of the description element
                            const lineHeight = parseInt(window.getComputedStyle(descriptionElement).lineHeight);
                            const maxHeight = lineHeight * 2; // Max height for 2 lines

                            if (descriptionElement.scrollHeight > maxHeight) {
                                // If the description exceeds 2 lines, show the "See more" link
                                seeMoreLink.style.display = 'block';
                            }
                        });
                    }

                    // Run the check when the page loads
                    checkDescriptionHeight();

                    // Optional: Re-check if the window is resized (in case of dynamic content or layout changes)
                    window.addEventListener('resize', checkDescriptionHeight);
                });
            </script>
            <!-- JavaScript to handle the dropdown and text input interaction -->
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const projectNameDropdown = document.getElementById('project_name_dropdown');
                    const projectNameInput = document.getElementById('project_name');

                    // When an option is selected from the dropdown, populate the text input
                    projectNameDropdown.addEventListener('change', function () {
                        if (this.value) {
                            projectNameInput.value = this.value;
                        }
                    });
                });
            </script>
            <!-- JavaScript to handle the fecthing of predecessor tasks -->
            <script>
                function fetchPredecessorTasks(projectId) {
                    if (!projectId) {
                        document.getElementById('predecessor_task_id').innerHTML = '<option value="">Select a predecessor task</option>';
                        return;
                    }

                    console.log("Fetching predecessor tasks for project ID:", projectId);

                    // Fetch predecessor tasks for the selected project
                    fetch('fetch-predecessor-tasks.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `project_id=${encodeURIComponent(projectId)}&user_id=<?= $user_id ?>`
                    })
                        .then(response => response.json())
                        .then(data => {
                            console.log("Received response:", data); // Debugging

                            const predecessorDropdown = document.getElementById('predecessor_task_id');
                            predecessorDropdown.innerHTML = '<option value="">Select a predecessor task</option>';

                            if (Array.isArray(data)) {
                                if (data.length > 0) {
                                    data.forEach(task => {
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
                            } else {
                                console.error("Invalid response format:", data);
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching predecessor tasks:', error);
                        });
                }
                document.getElementById('project_name_dropdown').addEventListener('change', function () {
                    const projectId = this.value;
                    fetchPredecessorTasks(projectId);
                });
            </script>
    </body>

</html>
<?php $conn->close(); ?>