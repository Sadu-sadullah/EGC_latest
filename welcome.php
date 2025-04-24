<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require 'permissions.php';

// Check if the user is logged in and has a selected role
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['selected_role_id'])) {
    header("Location: portal-login.html");
    exit;
}

// Database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

// Define task status arrays
$activeTaskStatuses = [
    'Assigned' => 'assigned_tasks',
    'In Progress' => 'in_progress_tasks',
    'Reassigned' => 'reassigned_tasks'
];
$inactiveTaskStatuses = [
    'Completed on Time' => 'completed_tasks',
    'Delayed Completion' => 'delayed_tasks',
    'Closed' => 'closed_tasks',
    'Cancelled' => 'cancelled_tasks',
    'Hold' => 'hold_tasks',
    'Reinstated' => 'reinstated_tasks'
];

// Function to generate colors
function generateColors($count)
{
    $colors = ['#FF6384', '#36A2EB', '#4BC0C0', '#FFCE56', '#9966FF', '#FF8A80', '#7CB342', '#FFD54F', '#64B5F6', '#BA68C8'];
    if ($count > count($colors)) {
        for ($i = count($colors); $i < $count; $i++) {
            $colors[] = '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
        }
    }
    return array_slice($colors, 0, $count);
}

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

    // Verify session token
    $checkStmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
    $checkStmt->execute([$_SESSION['user_id']]);
    $sessionToken = $checkStmt->fetchColumn();

    if ($sessionToken !== $_SESSION['session_token']) {
        session_unset();
        session_destroy();
        echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
        exit;
    }

    // Retrieve user ID
    $userId = $_SESSION['user_id'] ?? null;

    // Fetch all roles assigned to the user
    $roleStmt = $pdo->prepare("
        SELECT r.id, r.name
        FROM roles r
        JOIN user_roles ur ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $roleStmt->execute([$userId]);
    $userRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

    // Store roles in session
    $_SESSION['user_roles'] = $userRoles;

    // Retrieve username and selected role
    $username = $_SESSION['username'] ?? 'Unknown';
    $userRole = $_SESSION['selected_role'] ?? 'Unknown';

    // Fetch user departments
    $userDepartments = [];
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT d.name 
            FROM user_departments ud
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.user_id = ?
        ");
        $stmt->execute([$userId]);
        $userDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Initialize task counts
    $taskCounts = array_fill_keys(array_merge(array_values($activeTaskStatuses), array_values($inactiveTaskStatuses)), 0);
    $totalTasks = 0;
    $assignedProjects = 0;
    $inProgressProjects = 0;
    $completedProjects = 0;
    $noTasksProjects = 0;
    $avgDuration = 0;
    $tasksByDepartment = [];
    $taskDistribution = [];
    $taskCompletionOverTime = [];
    $topPerformers = [];

    // Fetch dashboard data based on permissions
    if (hasPermission('view_all_tasks')) {
        // Total tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks FROM tasks");
        $stmt->execute();
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Active task counts
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = ?");
            $stmt->execute([$status]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Inactive task counts
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = ?");
            $stmt->execute([$status]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Active project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as assigned_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status NOT IN ('Assigned', 'Reassigned')
            )
        ");
        $stmt->execute();
        $assignedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as in_progress_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status = 'In Progress'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t3
                WHERE t3.project_id = p.id
                AND t3.status IN ('Completed on Time', 'Delayed Completion', 'Closed', 'Cancelled', 'Hold', 'Reinstated')
            )
        ");
        $stmt->execute();
        $inProgressProjects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];

        // Inactive project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as completed_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status IN ('Assigned', 'In Progress', 'Reassigned')
            )
        ");
        $stmt->execute();
        $completedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['completed_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as no_tasks_projects
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            WHERE t.project_id IS NULL
        ");
        $stmt->execute();
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Average task duration
        $stmt = $pdo->prepare(
            "SELECT AVG(
                CASE 
                    WHEN actual_start_date IS NOT NULL AND actual_finish_date IS NOT NULL 
                    THEN TIMESTAMPDIFF(DAY, actual_start_date, actual_finish_date)
                    ELSE TIMESTAMPDIFF(DAY, planned_start_date, planned_finish_date)
                END
            ) as avg_duration 
            FROM tasks 
            WHERE status = 'Completed on Time'"
        );
        $stmt->execute();
        $avgDuration = $stmt->fetch(PDO::FETCH_ASSOC)['avg_duration'];
        $avgDuration = round($avgDuration ?? 0, 1);

        // Tasks by department
        $stmt = $pdo->prepare("
            SELECT d.name, COUNT(t.task_id) as task_count 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            GROUP BY d.name
        ");
        $stmt->execute();
        $tasksByDepartment = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Task distribution
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as `assigned`,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as `in_progress`,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as `hold`,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as `cancelled`,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as `reinstated`,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as `reassigned`,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as `completed`,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as `closed`
            FROM tasks
        ");
        $stmt->execute();
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Task completion over time
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(planned_finish_date, '%b') as month,
                COUNT(*) as tasks_completed
            FROM tasks
            WHERE status = 'Completed on Time'
            GROUP BY DATE_FORMAT(planned_finish_date, '%Y-%m')
            ORDER BY MIN(planned_finish_date)
        ");
        $stmt->execute();
        $taskCompletionOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top performers
        $stmt = $pdo->prepare("
            SELECT 
                u.username, 
                d.name as department, 
                COUNT(t.task_id) as tasks_completed 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE t.status = 'Completed on Time'
            GROUP BY u.username, d.name
            ORDER BY tasks_completed DESC
            LIMIT 3
        ");
        $stmt->execute();
        $topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (hasPermission('view_department_tasks')) {
        // Total tasks
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_tasks 
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
        ");
        $stmt->execute([$userId]);
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Active task counts
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks t
                JOIN user_departments ud ON t.user_id = ud.user_id
                WHERE t.status = ?
                AND ud.department_id IN (
                    SELECT department_id 
                    FROM user_departments 
                    WHERE user_id = ?
                )
            ");
            $stmt->execute([$status, $userId]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Inactive task counts
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks t
                JOIN user_departments ud ON t.user_id = ud.user_id
                WHERE t.status = ?
                AND ud.department_id IN (
                    SELECT department_id 
                    FROM user_departments 
                    WHERE user_id = ?
                )
            ");
            $stmt->execute([$status, $userId]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Active project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as assigned_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status NOT IN ('Assigned', 'Reassigned')
            )
        ");
        $stmt->execute([$userId]);
        $assignedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as in_progress_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            AND EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status = 'In Progress'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t3
                WHERE t3.project_id = p.id
                AND t3.status IN ('Completed on Time', 'Delayed Completion', 'Closed', 'Cancelled', 'Hold', 'Reinstated')
            )
        ");
        $stmt->execute([$userId]);
        $inProgressProjects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];

        // Inactive project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as completed_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status IN ('Assigned', 'In Progress', 'Reassigned')
            )
        ");
        $stmt->execute([$userId]);
        $completedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['completed_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as no_tasks_projects
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE t.project_id IS NULL
            AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
        ");
        $stmt->execute([$userId]);
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Average task duration
        $stmt = $pdo->prepare("
            SELECT AVG(
                CASE 
                    WHEN actual_start_date IS NOT NULL AND actual_finish_date IS NOT NULL 
                    THEN TIMESTAMPDIFF(DAY, actual_start_date, actual_finish_date)
                    ELSE TIMESTAMPDIFF(DAY, planned_start_date, planned_finish_date)
                END
            ) as avg_duration 
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE t.status = 'Completed on Time'
            AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
        ");
        $stmt->execute([$userId]);
        $avgDuration = $stmt->fetch(PDO::FETCH_ASSOC)['avg_duration'];
        $avgDuration = round($avgDuration ?? 0, 1);

        // Tasks by department
        $stmt = $pdo->prepare("
            SELECT d.name, COUNT(t.task_id) as task_count 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            GROUP BY d.name
        ");
        $stmt->execute([$userId]);
        $tasksByDepartment = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Task distribution
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as `assigned`,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as `in_progress`,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as `hold`,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as `cancelled`,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as `reinstated`,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as `reassigned`,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as `completed`,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as `closed`
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
        ");
        $stmt->execute([$userId]);
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Task completion over time
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(planned_finish_date, '%b') as month,
                COUNT(*) as tasks_completed
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE t.status = 'Completed on Time'
            AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            GROUP BY DATE_FORMAT(planned_finish_date, '%Y-%m')
            ORDER BY MIN(planned_finish_date)
        ");
        $stmt->execute([$userId]);
        $taskCompletionOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top performers
        $stmt = $pdo->prepare("
            SELECT 
                u.username, 
                d.name as department, 
                COUNT(t.task_id) as tasks_completed 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE t.status = 'Completed on Time'
            AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = ?
            )
            GROUP BY u.username, d.name
            ORDER BY tasks_completed DESC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (hasPermission('view_own_tasks')) {
        // Total tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks FROM tasks WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Active task counts
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks 
                WHERE user_id = ? 
                AND status = ?
            ");
            $stmt->execute([$userId, $status]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Inactive task counts
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks 
                WHERE user_id = ? 
                AND status = ?
            ");
            $stmt->execute([$userId, $status]);
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Active project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as assigned_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = ?
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status NOT IN ('Assigned', 'Reassigned')
            )
        ");
        $stmt->execute([$userId]);
        $assignedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as in_progress_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = ?
            AND EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status = 'In Progress'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t3
                WHERE t3.project_id = p.id
                AND t3.status IN ('Completed on Time', 'Delayed Completion', 'Closed', 'Cancelled', 'Hold', 'Reinstated')
            )
        ");
        $stmt->execute([$userId]);
        $inProgressProjects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];

        // Inactive project counts
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as completed_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = ?
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status IN ('Assigned', 'In Progress', 'Reassigned')
            )
        ");
        $stmt->execute([$userId]);
        $completedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['completed_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as no_tasks_projects
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = ?
            AND t.project_id IS NULL
        ");
        $stmt->execute([$userId]);
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Average task duration
        $stmt = $pdo->prepare("
            SELECT AVG(
                CASE 
                    WHEN actual_start_date IS NOT NULL AND actual_finish_date IS NOT NULL 
                    THEN TIMESTAMPDIFF(DAY, actual_start_date, actual_finish_date)
                    ELSE TIMESTAMPDIFF(DAY, planned_start_date, planned_finish_date)
                END
            ) as avg_duration 
            FROM tasks 
            WHERE status = 'Completed on Time' 
            AND user_id = ?
        ");
        $stmt->execute([$userId]);
        $avgDuration = $stmt->fetch(PDO::FETCH_ASSOC)['avg_duration'];
        $avgDuration = round($avgDuration ?? 0, 1);

        // Task distribution
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as `assigned`,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as `in_progress`,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as `hold`,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as `cancelled`,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as `reinstated`,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as `reassigned`,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as `completed`,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as `closed`
            FROM tasks
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Task completion over time
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(planned_finish_date, '%b') as month,
                COUNT(*) as tasks_completed
            FROM tasks
            WHERE status = 'Completed on Time' 
            AND user_id = ?
            GROUP BY DATE_FORMAT(planned_finish_date, '%Y-%m')
            ORDER BY MIN(planned_finish_date)
        ");
        $stmt->execute([$userId]);
        $taskCompletionOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $departmentColors = generateColors(count($tasksByDepartment));

    // Session timeout
    $timeout_duration = 1200;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: portal-login.html");
        exit;
    }
    $_SESSION['last_activity'] = time();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        * {
            box-sizing: border-box;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
            flex-shrink: 0;
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

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .logout-btn {
            background-color: #ff4d4d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-left: 10px;
        }

        .logout-btn:hover {
            background-color: #ff1a1a;
        }

        .dashboard-content {
            padding: 20px;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #002c5f;
        }

        .card-text {
            font-size: 2.5rem;
            font-weight: bold;
            color: #333;
        }

        .text-muted {
            font-size: 0.9rem;
            color: #666;
        }

        .list-group-item {
            border: none;
            padding: 10px 15px;
        }

        .list-group-item:hover {
            background-color: #f8f9fa;
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        .chart-canvas {
            width: 100% !important;
            height: 300px !important;
        }

        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 44, 95, 0.05);
        }

        .card-body {
            padding: 20px;
        }

        .card.metric-card .card-body {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            height: 100%;
        }

        .section-header {
            font-size: 1.5rem;
            font-weight: bold;
            color: #002c5f;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('view_projects')): ?>
                <a href="projects.php">Projects</a>
            <?php endif; ?>
            <?php if (hasPermission('task_actions')): ?>
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

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($username) ?></strong></p>
                    <p class="mb-0">Role: <strong><?= htmlspecialchars($userRole) ?></strong></p>
                    <p class="mb-0">Departments:
                        <strong><?= !empty($userDepartments) ? htmlspecialchars(implode(', ', $userDepartments)) : 'None' ?></strong>
                    </p>
                </div>
                <button class="logout-btn" onclick="window.location.href='logout.php'">Log Out</button>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Active Metrics Section -->
                <div class="mb-4">
                    <h2 class="section-header">Active Metrics</h2>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Assigned Projects</h5>
                                    <p class="card-text display-4"><?= $assignedProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">In Progress Projects</h5>
                                    <p class="card-text display-4"><?= $inProgressProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Reassigned Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['reassigned_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Assigned Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['assigned_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">In Progress Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['in_progress_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inactive Metrics Section -->
                <div class="mb-4">
                    <h2 class="section-header">Inactive Metrics</h2>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Completed Projects</h5>
                                    <p class="card-text display-4"><?= $completedProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Projects with no Tasks</h5>
                                    <p class="card-text display-4"><?= $noTasksProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Tasks Completed on Time</h5>
                                    <p class="card-text display-4"><?= $taskCounts['completed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Delayed Completion Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['delayed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Closed Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['closed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Cancelled Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['cancelled_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Tasks on Hold</h5>
                                    <p class="card-text display-4"><?= $taskCounts['hold_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Reinstated Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['reinstated_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts and Graphs -->
                <div class="row mb-4">
                    <h2 class="section-header">Other Metrics</h2>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="text-center card-title">Task Distribution</h5>
                                <div class="text-center">
                                    <canvas id="taskDistributionChart" class="chart-canvas"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="text-center card-title">Task Completion Over Time</h5>
                                <div class="text-center">
                                    <canvas id="taskCompletionChart" class="chart-canvas"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Metrics -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card metric-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Average Task Duration</h5>
                                <p class="card-text display-4"><?= $avgDuration ?></p>
                                <p class="text-muted">Days</p>
                            </div>
                        </div>
                    </div>
                    <?php if (hasPermission('dashboard_tasks')): ?>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="text-center card-title">Tasks by Department</h5>
                                    <div class="text-center">
                                        <canvas id="tasksByDepartmentChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="text-center card-title">Top Performers</h5>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($topPerformers as $performer): ?>
                                            <li class="list-group-item">
                                                <?= htmlspecialchars($performer['username']) ?>
                                                (<?= htmlspecialchars($performer['department']) ?>) -
                                                <?= $performer['tasks_completed'] ?> tasks completed
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Task Status Modals -->
    <div class="modal fade" id="completedTasksModal" tabindex="-1" aria-labelledby="completedTasksModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="completedTasksModalLabel">Completed Tasks (Last 3 Months)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Task ID</th>
                                    <th>Task Name</th>
                                    <th>Assigned To</th>
                                    <th>Department</th>
                                    <th>Completion Date</th>
                                </tr>
                            </thead>
                            <tbody id="completedTasksTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Add other modals (Assigned, In Progress, etc.) as needed -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Task Distribution Chart
        const taskDistributionChart = new Chart(document.getElementById('taskDistributionChart'), {
            type: 'pie',
            data: {
                labels: ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned', 'Completed on Time', 'Delayed Completion', 'Closed'],
                datasets: [{
                    label: 'Task Distribution',
                    data: [
                        <?= $taskDistribution['assigned'] ?? 0 ?>,
                        <?= $taskDistribution['in_progress'] ?? 0 ?>,
                        <?= $taskDistribution['hold'] ?? 0 ?>,
                        <?= $taskDistribution['cancelled'] ?? 0 ?>,
                        <?= $taskDistribution['reinstated'] ?? 0 ?>,
                        <?= $taskDistribution['reassigned'] ?? 0 ?>,
                        <?= $taskDistribution['completed'] ?? 0 ?>,
                        <?= $taskDistribution['delayed'] ?? 0 ?>,
                        <?= $taskDistribution['closed'] ?? 0 ?>
                    ],
                    backgroundColor: ['#FF0000', '#0000FF', '#800080', '#EE2C2C', '#660000', '#FF6600', '#00CD00', '#FFD54F', '#64B5F6'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    title: { display: true, text: 'Task Distribution by Status' }
                },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const statusLabel = ['Assigned', 'In Progress', 'Hold', 'Cancelled', 'Reinstated', 'Reassigned', 'Completed on Time', 'Delayed Completion', 'Closed'][index];
                        fetchTaskData(statusLabel);
                    }
                }
            }
        });

        function fetchTaskData(status) {
            fetch(`fetch-tasks.php?status=${encodeURIComponent(status)}`)
                .then(response => response.json())
                .then(data => populateModal(status, data))
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to fetch task data.');
                });
        }

        function populateModal(status, data) {
            const modalId = {
                'Assigned': 'pendingTasksModal',
                'In Progress': 'inProgressTasksModal',
                'Hold': 'holdTasksModal',
                'Cancelled': 'cancelledTasksModal',
                'Reinstated': 'reinstatedTasksModal',
                'Reassigned': 'reassignedTasksModal',
                'Completed on Time': 'completedTasksModal',
                'Delayed Completion': 'delayedTasksModal',
                'Closed': 'closedTasksModal'
            }[status];
            const tableBodyId = modalId.replace('Modal', 'TableBody');
            const tableBody = document.getElementById(tableBodyId);
            tableBody.innerHTML = data.length === 0 ? '<tr><td colspan="5" class="text-center">No tasks found.</td></tr>' : data.map((task, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${task.task_name}</td>
                    <td>${task.assigned_to}</td>
                    <td>${task.department}</td>
                    <td>${task.actual_completion_date || task.start_date || task.planned_finish_date}</td>
                </tr>
            `).join('');
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();
        }

        // Task Completion Over Time
        const taskCompletionChart = new Chart(document.getElementById('taskCompletionChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($taskCompletionOverTime, 'month')) ?>,
                datasets: [{
                    label: 'Tasks Completed',
                    data: <?= json_encode(array_column($taskCompletionOverTime, 'tasks_completed')) ?>,
                    fill: false,
                    borderColor: '#36A2EB',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' }, title: { display: true, text: 'Task Completion Over Time' } },
                scales: { y: { beginAtZero: true } }
            }
        });

        <?php if (hasPermission('dashboard_tasks')): ?>
            // Tasks by Department
            const tasksByDepartmentChart = new Chart(document.getElementById('tasksByDepartmentChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($tasksByDepartment, 'name')) ?>,
                    datasets: [{
                        label: 'Tasks by Department',
                        data: <?= json_encode(array_column($tasksByDepartment, 'task_count')) ?>,
                        backgroundColor: <?= json_encode($departmentColors) ?>,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' }, title: { display: true, text: 'Tasks by Department' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        <?php endif; ?>
    </script>
</body>

</html>