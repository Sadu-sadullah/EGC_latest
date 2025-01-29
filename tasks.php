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
$dbName = 'euro_login_system_2';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch departments from the database
$departments = $conn->query("SELECT id, name FROM departments")->fetch_all(MYSQLI_ASSOC);

// Fetch roles from the database
$roles = $conn->query("SELECT id, name FROM roles")->fetch_all(MYSQLI_ASSOC);

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
if (hasPermission('assign_tasks', 'Tasks')) {
    if (hasPermission('assign_to_any_user_tasks', 'Tasks')) {
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
              AND r.name = 'User'
            GROUP BY u.id
        ";
    }

    $stmt = $conn->prepare($userQuery);
    if (!hasPermission('assign_to_any_user_tasks', 'Tasks')) {
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $userResult = $stmt->get_result();
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
}

// Function to send email notifications
function sendTaskNotification($email, $username, $project_name, $project_type, $task_name, $task_description, $start_date, $end_date)
{
    $mail = new PHPMailer(true);

    try {
        $config = include("../config.php");
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
            "<p>You have been assigned a new task:</p>" .
            "<ul>" .
            "<li><strong>Project Name:</strong> $project_name</li>" .
            "<li><strong>Task Name:</strong> $task_name</li>" .
            "<li><strong>Task Description:</strong> $task_description</li>" .
            "<li><strong>Project Type:</strong> $project_type</li>" .
            "<li><strong>Start Date:</strong> $start_date</li>" .
            "<li><strong>End Date:</strong> $end_date</li>" .
            "</ul>" .
            "<p>Please log in to your account for more details.</p>";

        $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

// Handle form submission for adding a task
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['task_name'])) {
    $project_name = trim($_POST['project_name']);
    $task_name = trim($_POST['task_name']);
    $task_description = trim($_POST['task_description']);
    $project_type = trim($_POST['project_type']);
    $planned_start_date = trim($_POST['planned_start_date']);
    $planned_finish_date = trim($_POST['planned_finish_date']);
    $status = 'assigned';
    $assigned_user_id = isset($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : null;
    $recorded_timestamp = date("Y-m-d H:i:s");
    $assigned_by_id = $_SESSION['user_id'];

    $currentDate = new DateTime();

    // Get the planned start and end dates
    $datePlannedStartDate = new DateTime($planned_start_date);
    $datePlannedEndDate = new DateTime($planned_finish_date);

    // Calculate the 3-month boundary dates
    $threeMonthsAgo = clone $currentDate;
    $threeMonthsAgo->modify('-3 months');

    $threeMonthsAhead = clone $currentDate;
    $threeMonthsAhead->modify('+3 months');



    if (empty($project_name) || empty($task_name) || empty($task_description) || empty($project_type) || empty($planned_start_date) || empty($planned_finish_date) || !$assigned_user_id) {
        echo '<script>alert("Please fill in all required fields.");</script>';
    } elseif ($datePlannedStartDate < $threeMonthsAgo || $datePlannedEndDate > $threeMonthsAhead) {
        echo '<script>alert("Error: Planned start date is too far in the past or too far in the future.");</script>';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO tasks (user_id, project_name, task_name, task_description, project_type, planned_start_date, planned_finish_date, status, recorded_timestamp, assigned_by_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "issssssssi",
            $assigned_user_id,
            $project_name,
            $task_name,
            $task_description,
            $project_type,
            $planned_start_date,
            $planned_finish_date,
            $status,
            $recorded_timestamp,
            $assigned_by_id
        );

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

                // Send email notification
                sendTaskNotification($email, $username, $project_name, $task_name, $task_description, $project_type, $planned_start_date, $planned_finish_date);
            }
        } else {
            echo '<script>alert("Failed to add task.");</script>';
        }
        $stmt->close();
    }
}

if (hasPermission('view_all_tasks', 'Tasks')) {
    // Admin-like query: Fetch all tasks
    $taskQuery = "
        SELECT 
            tasks.task_id,
            tasks.project_name,
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
        GROUP BY tasks.task_id
        ORDER BY 
            CASE 
                WHEN tasks.status = 'Completed on Time' THEN tasks.planned_finish_date 
                WHEN tasks.status = 'Delayed Completion' THEN task_transactions.actual_finish_date 
                WHEN tasks.status = 'Closed' THEN tasks.planned_finish_date 
            END DESC, 
            tasks.recorded_timestamp DESC
    ";
} elseif (hasPermission('view_department_tasks', 'Tasks')) {
    //Fetch tasks for users in the same department
    $taskQuery = "
        SELECT 
            tasks.task_id,
            tasks.project_name,
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
} elseif (hasPermission('view_own_tasks', 'Tasks')) {
    //Fetch only tasks assigned to the current user
    $taskQuery = "
        SELECT 
            tasks.task_id,
            tasks.project_name,
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

if (hasPermission('view_department_tasks', 'Tasks') || hasPermission('view_own_tasks', 'Tasks')) {
    // Bind the user ID for Manager and User queries
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$allTasks = $result->fetch_all(MYSQLI_ASSOC);

foreach ($allTasks as &$task) {
    // Calculate planned duration (excluding weekends)
    $plannedStartDate = strtotime($task['planned_start_date']);
    $plannedEndDate = strtotime($task['planned_finish_date']);
    $plannedDurationHours = getWeekdayHours($plannedStartDate, $plannedEndDate);

    // Store planned duration in the task array
    $task['planned_duration_hours'] = $plannedDurationHours;

    // Calculate actual duration (from actual start date to current date)
    if (!empty($task['actual_start_date'])) {
        $actualStartDate = strtotime($task['actual_start_date']);
        $currentDate = time(); // Current date and time
        $actualDurationHours = getWeekdayHours($actualStartDate, $currentDate);

        // Store actual duration in the task array
        $task['actual_duration_hours'] = $actualDurationHours;

        // Determine available statuses based on the comparison
        if ($actualDurationHours >= $plannedDurationHours) {
            $task['available_statuses'] = ['Delayed Completion'];
        } else {
            $task['available_statuses'] = ['Completed on Time'];
        }
    } else {
        // If actual start date is not set, no status change is allowed
        $task['available_statuses'] = [];
        $task['actual_duration_hours'] = null; // Set actual duration to null
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

    // Loop through each day between start and end
    while ($current <= $end) {
        $dayOfWeek = date('N', $current); // 1 (Monday) to 7 (Sunday)

        // Exclude weekends
        if ($dayOfWeek <= 5) {
            // Calculate the start and end of the current day
            $startOfDay = strtotime('today', $current); // Start of the day (00:00:00)
            $endOfDay = strtotime('tomorrow', $current) - 1; // End of the day (23:59:59)

            // Adjust the start and end times to fit within the current day
            $startTime = max($start, $startOfDay);
            $endTime = min($end, $endOfDay);

            // Calculate the hours for the current day
            $hours = ($endTime - $startTime) / 3600; // Convert seconds to hours

            // Add the hours to the total
            $weekdayHours += $hours;
        }

        // Move to the next day
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
                        <div class="form-group">
                            <label for="project_name">Project Name:</label>
                            <input type="text" id="project_name" name="project_name" required>
                        </div>

                        <div class="form-group">
                            <label for="task_name">Task Name:</label>
                            <input type="text" id="task_name" name="task_name" required>
                        </div>

                        <div>
                            <label for="task_description">Task Description:</label>
                            <textarea id="task_description" name="task_description" rows="4"></textarea>
                        </div>

                        <div>
                            <label for="project_type">Project Type:</label>
                            <select id="project_type" name="project_type">
                                <option value="Internal">Internal</option>
                                <option value="External">External</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="planned_start_date">Expected Start Date & Time</label>
                            <input type="datetime-local" id="planned_start_date" name="planned_start_date" required>
                        </div>

                        <div class="form-group">
                            <label for="planned_finish_date">Expected End Date & Time</label>
                            <input type="datetime-local" id="planned_finish_date" name="planned_finish_date" required>
                        </div>

                        <div class="form-group">
                            <label for="assigned_user_id">Assign to:</label>
                            <select id="assigned_user_id" name="assigned_user_id" required>
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

                        <button type="submit" class="submit-btn">Add Task</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    // Fetch all unique project names from the tasks table
    $projectQuery = $conn->query("SELECT DISTINCT project_name FROM tasks");
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
                <?php if (hasPermission('read_users', 'Users')): ?>
                    <a href="view-users.php">View Users</a>
                <?php endif; ?>
                <?php if (hasPermission('read_roles_&_departments', 'Roles & Departments')): ?>
                    <a href="view-roles-departments.php">View Role or Department</a>
                <?php endif; ?>
                <?php if (hasPermission('read_&_write_privileges', 'Privileges')): ?>
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
                                <?php if (hasPermission('create_tasks', 'Tasks')): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                                        data-bs-target="#taskManagementModal">
                                        Create New Task
                                    </button>
                                <?php endif; ?>
                                <button onclick="resetFilters()" class="btn btn-primary">Reset</button>
                                <?php if (hasPermission('export_tasks', 'Tasks')): ?>
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

                                <?php if (hasPermission('filter_tasks', 'Tasks') || $hasMultipleDepartments): ?>
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
                                    <?php if (hasPermission('assign_tasks', 'Tasks')): ?>
                                        <th>Assigned To</th>
                                    <?php endif; ?>
                                    <th>Created On</th>
                                    <?php if (hasPermission('assign_tasks', 'Tasks')): ?>
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

                                                // Define the available statuses based on the user role and current status
                                                $statuses = [];

                                                // Logic for status_change_main privilege or the user who assigned the task
                                                if (hasPermission('status_change_main', 'Tasks') || $assigned_by_id == $user_id) {
                                                    // status_change_main privilege or the user who assigned the task can change status to anything except "In Progress", "Completed on Time", and "Delayed Completion"
                                                    if (in_array($currentStatus, ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'])) {
                                                        $statuses = ['Assigned', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned'];
                                                    }
                                                }
                                                // Logic for Regular User (assigned user)
                                                elseif (hasPermission('status_change_normal', 'Tasks') && $user_id == $assigned_user_id) {
                                                    // Regular user can only change status if they are the assigned user
                                                    if ($currentStatus === 'Assigned') {
                                                        // If the task is "Assigned", the next viable options are "In Progress"
                                                        $statuses = ['In Progress'];
                                                    } elseif ($currentStatus === 'In Progress') {
                                                        // If the task is "In Progress", use the available statuses calculated earlier
                                                        $statuses = $row['available_statuses'];
                                                    } else {
                                                        // For other statuses, allow the default transitions
                                                        $allowedStatuses = ['Assigned', 'Reassigned', 'In Progress', 'Completed on Time', 'Delayed Completion'];
                                                        if (in_array($currentStatus, $allowedStatuses)) {
                                                            $statuses = $allowedStatuses;
                                                        }
                                                    }
                                                }

                                                // Generate the status dropdown or display the status as text
                                                if (!empty($statuses)) {
                                                    echo '<select id="status" name="status" onchange="handleStatusChange(event, ' . $row['task_id'] . ')">';
                                                    // Always include the current status in the dropdown, even if it's not in the $statuses array
                                                    if (!in_array($currentStatus, $statuses)) {
                                                        echo "<option value='$currentStatus' selected>$currentStatus</option>";
                                                    }
                                                    // Add the other status options
                                                    foreach ($statuses as $statusValue) {
                                                        $selected = ($currentStatus === $statusValue) ? 'selected' : '';
                                                        echo "<option value='$statusValue' $selected>$statusValue</option>";
                                                    }
                                                    echo '</select>';
                                                } else {
                                                    // Display the status as plain text if the user is not allowed to change it
                                                    echo $currentStatus;
                                                }
                                                ?>
                                            </form>
                                        </td>
                                        <td><?= htmlspecialchars($row['project_type']) ?></td>
                                        <td><?= htmlspecialchars($row['assigned_by']) ?>
                                            (<?= htmlspecialchars($row['assigned_by_department']) ?>)
                                        </td>
                                        <?php if (hasPermission('assign_tasks', 'Tasks')): ?>
                                            <td><?= htmlspecialchars($row['assigned_to']) ?>
                                                (<?= htmlspecialchars($row['assigned_to_department']) ?>)
                                            </td>
                                        <?php endif; ?>
                                        <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                        </td>
                                        <?php if ((hasPermission('update_tasks', 'Tasks') && $row['assigned_by_id'] == $_SESSION['user_id']) || hasPermission('upate_tasks_all', 'Tasks')): ?>
                                            <td>
                                                <a href="edit-tasks.php?id=<?= $row['task_id'] ?>" class="edit-button">Edit</a>
                                                <button type="button" class="btn btn-danger" data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal<?= $row['task_id'] ?>">
                                                    Delete
                                                </button>
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
                                    <?php if (hasPermission('assign_tasks', 'Tasks')): ?>
                                        <th>Assigned To</th>
                                    <?php endif; ?>
                                    <th>Created On</th>
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
                                                if (hasPermission('status_change_main', 'Tasks') || $assigned_by_id == $user_id) {
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
                                        <?php if (hasPermission('assign_tasks', 'Tasks')): ?>
                                            <td><?= htmlspecialchars($row['assigned_to']) ?>
                                                (<?= htmlspecialchars($row['assigned_to_department']) ?>)
                                            </td>
                                        <?php endif; ?>
                                        <td data-utc="<?= htmlspecialchars($row['recorded_timestamp']) ?>">
                                            <?= htmlspecialchars(date("d M Y, h:i A", strtotime($row['recorded_timestamp']))) ?>
                                        </td>
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

                    if (status === 'Reassigned') {
                        // Show the reassignment modal
                        document.getElementById('reassign-task-id').value = taskId;
                        const reassignmentModal = new bootstrap.Modal(document.getElementById('reassignmentModal'));
                        reassignmentModal.show();
                    } else if (status === 'Delayed Completion' || status === 'Completed on Time') {
                        // Set the task ID and status in the completion modal
                        document.getElementById('task-id').value = taskId;
                        document.getElementById('modal-status').value = status;

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
    </body>

</html>
<?php $conn->close(); ?>