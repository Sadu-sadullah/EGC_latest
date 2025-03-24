<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'permissions.php';

session_start();

// Check if the user is logged in and has export permission
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !hasPermission('export_tasks')) {
    header("Location: portal-login.html");
    exit;
}

// Set timezone based on user preference or fallback to UTC
if (isset($_COOKIE['user_timezone'])) {
    date_default_timezone_set($_COOKIE['user_timezone']);
} else {
    date_default_timezone_set('UTC');
}

// Database connection
$config = include '../config.php';
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch tasks based on user permissions
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

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
} else { // Default to 'view_own_tasks'
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
}

$stmt = $conn->prepare($taskQuery);
if (hasPermission('view_department_tasks') || hasPermission('view_own_tasks')) {
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$allTasks = $result->fetch_all(MYSQLI_ASSOC);

// Function to calculate weekday hours (from tasks.php)
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

            $dayStart = max($start, $startOfDay);
            $dayEnd = min($end, $endOfDay);

            $hours = ($dayEnd - $dayStart) / 3600;
            if ($hours > 0) {
                $weekdayHours += $hours;
            }
        }
        $current = strtotime('+1 day', $current);
    }

    return $weekdayHours;
}

// Calculate durations for each task
foreach ($allTasks as &$task) {
    $plannedStartDate = strtotime($task['planned_start_date']);
    $plannedEndDate = strtotime($task['planned_finish_date']);
    $plannedDurationHours = getWeekdayHours($plannedStartDate, $plannedEndDate);
    $task['planned_duration_hours'] = $plannedDurationHours;

    if (!empty($task['actual_start_date'])) {
        $actualStartDate = strtotime($task['actual_start_date']);
        $completedStatuses = ['Completed on Time', 'Delayed Completion', 'Closed'];

        if (in_array($task['status'], $completedStatuses) && !empty($task['task_actual_finish_date'])) {
            $actualEndDate = strtotime($task['task_actual_finish_date']);
        } else {
            $actualEndDate = time(); // For in-progress tasks
        }

        $actualDurationHours = getWeekdayHours($actualStartDate, $actualEndDate);
        $task['actual_duration_hours'] = $actualDurationHours;
    } else {
        $task['actual_duration_hours'] = null;
    }
}

// Check if there are any external tasks to determine if customer fields should be included
$hasExternalTasks = false;
foreach ($allTasks as $task) {
    if ($task['project_type'] === 'External') {
        $hasExternalTasks = true;
        break;
    }
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="tasks_export_' . date('Y-m-d_H-i-s') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Define CSV headers
$headers = [
    'Project Name',
    'Task Name',
    'Task Description',
    'Planned Start Date',
    'Planned Finish Date',
    'Planned Duration (Hours)',
    'Actual Start Date',
    'Actual Finish Date',
    'Actual Duration (Hours)',
    'Status',
    'Project Type',
    'Assigned By',
    'Assigned By Department',
    'Created On',
    'Predecessor Task'
];

// Conditionally add external project fields
if ($hasExternalTasks) {
    $headers = array_merge($headers, [
        'Customer Name',
        'Customer Email',
        'Customer Mobile',
        'Cost',
        'Project Manager'
    ]);
}

// Add remaining fields
$headers = array_merge($headers, [
    'Completion Description',
    'Delayed Reason',
    'Transaction Actual Finish Date'
]);

// Add "Assigned To" and "Assigned To Department" only if permission exists
if (hasPermission('assign_tasks')) {
    array_splice($headers, 11, 0, ['Assigned To', 'Assigned To Department']);
}

// Write Pending Tasks Section
fputcsv($output, ['Pending & In Progress Tasks']);
fputcsv($output, $headers);

$pendingStatuses = ['Assigned', 'In Progress', 'Hold', 'Reassigned', 'Reinstated', 'Cancelled'];
foreach ($allTasks as $row) {
    if (in_array($row['status'], $pendingStatuses)) {
        $rowData = [
            $row['project_name'],
            $row['task_name'],
            $row['task_description'],
            date("d M Y, h:i A", strtotime($row['planned_start_date'])),
            date("d M Y, h:i A", strtotime($row['planned_finish_date'])),
            number_format($row['planned_duration_hours'], 2),
            $row['actual_start_date'] ? date("d M Y, h:i A", strtotime($row['actual_start_date'])) : '',
            $row['task_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['task_actual_finish_date'])) : '',
            $row['actual_duration_hours'] !== null ? number_format($row['actual_duration_hours'], 2) : '',
            $row['status'],
            $row['project_type'],
            $row['assigned_by'],
            $row['assigned_by_department'],
            date("d M Y, h:i A", strtotime($row['recorded_timestamp'])),
            $row['predecessor_task_name'] ?? ''
        ];

        if ($hasExternalTasks) {
            $rowData = array_merge($rowData, [
                $row['customer_name'] ?? '',
                $row['customer_email'] ?? '',
                $row['customer_mobile'] ?? '',
                $row['cost'] !== null ? number_format($row['cost'], 2) : '',
                $row['project_manager'] ?? ''
            ]);
        }

        $rowData = array_merge($rowData, [
            $row['completion_description'] ?? '',
            $row['delayed_reason'] ?? '',
            $row['transaction_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['transaction_actual_finish_date'])) : ''
        ]);

        if (hasPermission('assign_tasks')) {
            array_splice($rowData, 11, 0, [
                $row['assigned_to'] ?? '',
                $row['assigned_to_department'] ?? ''
            ]);
        }

        fputcsv($output, $rowData);
    }
}

// Add a blank row to separate sections
fputcsv($output, []);

// Write Completed Tasks Section
fputcsv($output, ['Completed Tasks']);
fputcsv($output, $headers);

$completedStatuses = ['Completed on Time', 'Delayed Completion', 'Closed'];
foreach ($allTasks as $row) {
    if (in_array($row['status'], $completedStatuses)) {
        $rowData = [
            $row['project_name'],
            $row['task_name'],
            $row['task_description'],
            date("d M Y, h:i A", strtotime($row['planned_start_date'])),
            date("d M Y, h:i A", strtotime($row['planned_finish_date'])),
            number_format($row['planned_duration_hours'], 2),
            $row['actual_start_date'] ? date("d M Y, h:i A", strtotime($row['actual_start_date'])) : '',
            $row['task_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['task_actual_finish_date'])) : '',
            $row['actual_duration_hours'] !== null ? number_format($row['actual_duration_hours'], 2) : '',
            $row['status'],
            $row['project_type'],
            $row['assigned_by'],
            $row['assigned_by_department'],
            date("d M Y, h:i A", strtotime($row['recorded_timestamp'])),
            $row['predecessor_task_name'] ?? ''
        ];

        if ($hasExternalTasks) {
            $rowData = array_merge($rowData, [
                $row['customer_name'] ?? '',
                $row['customer_email'] ?? '',
                $row['customer_mobile'] ?? '',
                $row['cost'] !== null ? number_format($row['cost'], 2) : '',
                $row['project_manager'] ?? ''
            ]);
        }

        $rowData = array_merge($rowData, [
            $row['completion_description'] ?? '',
            $row['delayed_reason'] ?? '',
            $row['transaction_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['transaction_actual_finish_date'])) : ''
        ]);

        if (hasPermission('assign_tasks')) {
            array_splice($rowData, 11, 0, [
                $row['assigned_to'] ?? '',
                $row['assigned_to_department'] ?? ''
            ]);
        }

        fputcsv($output, $rowData);
    }
}

// Close output stream and database connection
fclose($output);
$conn->close();
exit;
?>