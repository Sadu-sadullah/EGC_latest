<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'permissions.php';

session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: portal-login.html");
    exit;
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

// Fetch tasks based on user role
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

$taskQuery = hasPermission('view_all_tasks')
    ? "
        SELECT 
            tasks.task_id,
            tasks.project_name,
            tasks.task_name,
            tasks.task_description,
            tasks.planned_start_date,
            tasks.planned_finish_date,
            tasks.actual_start_date,
            tasks.actual_finish_date AS task_actual_finish_date, -- Alias for tasks.actual_finish_date
            tasks.status,
            tasks.project_type,
            tasks.recorded_timestamp,
            tasks.assigned_by_id,
            tasks.user_id,
            task_transactions.delayed_reason,
            task_transactions.actual_finish_date AS transaction_actual_finish_date, -- Alias for task_transactions.actual_finish_date
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
    "
    : (hasPermission('view_department_tasks')
        ? "
            SELECT 
                tasks.task_id,
                tasks.project_name,
                tasks.task_name,
                tasks.task_description,
                tasks.planned_start_date,
                tasks.planned_finish_date,
                tasks.actual_start_date,
                tasks.actual_finish_date AS task_actual_finish_date, -- Alias for tasks.actual_finish_date
                tasks.status,
                tasks.project_type,
                tasks.recorded_timestamp,
                tasks.assigned_by_id,
                tasks.user_id,
                task_transactions.delayed_reason,
                task_transactions.actual_finish_date AS transaction_actual_finish_date, -- Alias for task_transactions.actual_finish_date
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
        "
        : "
            SELECT 
                tasks.task_id,
                tasks.project_name,
                tasks.task_name,
                tasks.task_description,
                tasks.planned_start_date,
                tasks.planned_finish_date,
                tasks.actual_start_date,
                tasks.actual_finish_date AS task_actual_finish_date, -- Alias for tasks.actual_finish_date
                tasks.status,
                tasks.project_type,
                tasks.recorded_timestamp,
                tasks.assigned_by_id,
                tasks.user_id,
                task_transactions.delayed_reason,
                task_transactions.actual_finish_date AS transaction_actual_finish_date, -- Alias for task_transactions.actual_finish_date
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
        ");

$stmt = $conn->prepare($taskQuery);
if (hasPermission('view_department_tasks')||hasPermission('view_department_tasks')) {
    $stmt->bind_param("i", $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="tasks_export.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV headers
$headers = [
    'Project Name',
    'Task Name',
    'Task Description',
    'Planned Start Date',
    'Planned Finish Date',
    'Actual Start Date',
    'Actual Finish Date',
    'Status',
    'Project Type',
    'Assigned By',
    'Assigned By Department',
    'Created On',
    'Completion Description',
    'Delayed Reason',
    'Transaction Actual Finish Date'
];

// Add "Assigned To" and "Assigned To Department" only for Admin and Manager roles
if (hasPermission('view_all_tasks')||hasPermission('view_department_tasks')) {
    array_splice($headers, 11, 0, ['Assigned To', 'Assigned To Department']);
}

// Write Pending Tasks Section
fputcsv($output, ['Pending & In Progress Tasks']);
fputcsv($output, $headers); // Write headers for Pending Tasks

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'Assigned' || $row['status'] === 'In Progress') {
        $rowData = [
            $row['project_name'],
            $row['task_name'],
            $row['task_description'],
            date("d M Y, h:i A", strtotime($row['planned_start_date'])),
            date("d M Y, h:i A", strtotime($row['planned_finish_date'])),
            $row['actual_start_date'] ? date("d M Y, h:i A", strtotime($row['actual_start_date'])) : '',
            $row['task_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['task_actual_finish_date'])) : '',
            $row['status'],
            $row['project_type'],
            $row['assigned_by'],
            $row['assigned_by_department'],
            date("d M Y, h:i A", strtotime($row['recorded_timestamp'])),
            $row['completion_description'] ?? '', // Handle cases where completion_description is not available
            $row['delayed_reason'] ?? '', // Handle cases where delayed_reason is not available
            $row['transaction_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['transaction_actual_finish_date'])) : '' // Handle cases where transaction_actual_finish_date is not available
        ];

        // Add "Assigned To" and "Assigned To Department" only for Admin and Manager roles
        if (hasPermission('view_all_tasks')||hasPermission('view_department_tasks')) {
            array_splice($rowData, 11, 0, [
                $row['assigned_to'] ?? '', // Handle cases where assigned_to is not available
                $row['assigned_to_department'] ?? ''  // Handle cases where assigned_to_department is not available
            ]);
        }

        fputcsv($output, $rowData);
    }
}

// Add a blank row to separate sections
fputcsv($output, []);

// Write Completed Tasks Section
fputcsv($output, ['Completed Tasks']);
fputcsv($output, $headers); // Write headers for Completed Tasks

// Reset the result pointer to iterate again
$result->data_seek(0);

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'Completed on Time' || $row['status'] === 'Delayed Completion' || $row['status'] === 'Closed') {
        $rowData = [
            $row['project_name'],
            $row['task_name'],
            $row['task_description'],
            date("d M Y, h:i A", strtotime($row['planned_start_date'])),
            date("d M Y, h:i A", strtotime($row['planned_finish_date'])),
            $row['actual_start_date'] ? date("d M Y, h:i A", strtotime($row['actual_start_date'])) : '',
            $row['task_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['task_actual_finish_date'])) : '',
            $row['status'],
            $row['project_type'],
            $row['assigned_by'],
            $row['assigned_by_department'],
            date("d M Y, h:i A", strtotime($row['recorded_timestamp'])),
            $row['completion_description'] ?? '', // Handle cases where completion_description is not available
            $row['delayed_reason'] ?? '', // Handle cases where delayed_reason is not available
            $row['transaction_actual_finish_date'] ? date("d M Y, h:i A", strtotime($row['transaction_actual_finish_date'])) : '' // Handle cases where transaction_actual_finish_date is not available
        ];

        // Add "Assigned To" and "Assigned To Department" only for Admin and Manager roles
        if (hasPermission('view_all_tasks')||hasPermission('view_department_tasks')) {
            array_splice($rowData, 11, 0, [
                $row['assigned_to'] ?? '', // Handle cases where assigned_to is not available
                $row['assigned_to_department'] ?? ''  // Handle cases where assigned_to_department is not available
            ]);
        }

        fputcsv($output, $rowData);
    }
}

// Close output stream
fclose($output);
exit;
?>