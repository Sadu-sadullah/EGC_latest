<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

require 'permissions.php';

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: portal-login.html");
    exit;
}

// Database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

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

    // Prepare and execute the query to fetch the session token
    $checkStmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
    $checkStmt->execute([$_SESSION['user_id']]);
    $sessionToken = $checkStmt->fetchColumn();

    // If the session token doesn't match, log the user out
    if ($sessionToken !== $_SESSION['session_token']) {
        session_unset();
        session_destroy();
        echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
    }

    // Retrieve the username, role, and user ID from the session
    $username = $_SESSION['username'] ?? 'Unknown';
    $userRole = $_SESSION['role'] ?? 'Unknown';
    $userId = $_SESSION['user_id'] ?? null;

    // Fetch all departments assigned to the user
    $userDepartments = [];
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT d.name 
            FROM user_departments ud
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $userDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Fetch all departments assigned to the manager
    $managerDepartments = [];
    if ($userId && $userRole === 'Manager') {
        $stmt = $pdo->prepare("
            SELECT d.name 
            FROM user_departments ud
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $managerDepartments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (hasPermission('view_all_tasks')) {
        // Fetch total tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks FROM tasks");
        $stmt->execute();
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Fetch active task counts (Assigned, In Progress, Reassigned)
        $activeTaskStatuses = [
            'Assigned' => 'assigned_tasks',
            'In Progress' => 'in_progress_tasks',
            'Reassigned' => 'reassigned_tasks'
        ];
        $taskCounts = [];
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = :status");
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch inactive task counts (Completed on Time, Delayed Completion, Closed, Cancelled, Hold, Reinstated)
        $inactiveTaskStatuses = [
            'Completed on Time' => 'completed_tasks',
            'Delayed Completion' => 'delayed_tasks',
            'Closed' => 'closed_tasks',
            'Cancelled' => 'cancelled_tasks',
            'Hold' => 'hold_tasks',
            'Reinstated' => 'reinstated_tasks'
        ];
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tasks WHERE status = :status");
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch active project counts
        // Assigned Projects: All tasks in Assigned/Reassigned
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

        // In Progress Projects: At least one task in In Progress, none in completed/inactive statuses
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

        // Fetch inactive project counts
        // Completed Projects: All tasks in completed/inactive statuses
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

        // No Tasks Projects
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as no_tasks_projects
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            WHERE t.project_id IS NULL
        ");
        $stmt->execute();
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Fetch average task duration
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

        // Fetch tasks by department
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

        // Generate colors dynamically based on the number of departments
        $departmentColors = generateColors(count($tasksByDepartment));

        // Fetch task distribution by status
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as hold,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as reinstated,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as reassigned,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
            FROM tasks
        ");
        $stmt->execute();
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch task completion over time (grouped by month)
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

        // Fetch top performers
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
        // Fetch total tasks for manager's departments
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_tasks 
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Fetch active task counts for manager's departments
        $activeTaskStatuses = [
            'Assigned' => 'assigned_tasks',
            'In Progress' => 'in_progress_tasks',
            'Reassigned' => 'reassigned_tasks'
        ];
        $taskCounts = [];
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks t
                JOIN user_departments ud ON t.user_id = ud.user_id
                WHERE t.status = :status
                AND ud.department_id IN (
                    SELECT department_id 
                    FROM user_departments 
                    WHERE user_id = :user_id
                )
            ");
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch inactive task counts for manager's departments
        $inactiveTaskStatuses = [
            'Completed on Time' => 'completed_tasks',
            'Delayed Completion' => 'delayed_tasks',
            'Closed' => 'closed_tasks',
            'Cancelled' => 'cancelled_tasks',
            'Hold' => 'hold_tasks',
            'Reinstated' => 'reinstated_tasks'
        ];
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks t
                JOIN user_departments ud ON t.user_id = ud.user_id
                WHERE t.status = :status
                AND ud.department_id IN (
                    SELECT department_id 
                    FROM user_departments 
                    WHERE user_id = :user_id
                )
            ");
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch active project counts for manager's departments
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as assigned_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status NOT IN ('Assigned', 'Reassigned')
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $assignedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as in_progress_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
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
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $inProgressProjects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];

        // Fetch inactive project counts for manager's departments
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as completed_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status IN ('Assigned', 'In Progress', 'Reassigned')
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
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
                WHERE user_id = :user_id
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Fetch tasks by department for manager's departments
        $stmt = $pdo->prepare("
            SELECT d.name, COUNT(t.task_id) as task_count 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
            GROUP BY d.name
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $tasksByDepartment = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate colors dynamically based on the number of departments
        $departmentColors = generateColors(count($tasksByDepartment));

        // Fetch task distribution by status for manager's departments
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as hold,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as reinstated,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as reassigned,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch task completion over time for manager's departments
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(planned_finish_date, '%b') as month,
                COUNT(*) as tasks_completed
            FROM tasks t
            JOIN user_departments ud ON t.user_id = ud.user_id
            WHERE t.status = 'Completed on Time' AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
            GROUP BY DATE_FORMAT(planned_finish_date, '%Y-%m')
            ORDER BY planned_finish_date
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $taskCompletionOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch top performers for manager's departments
        $stmt = $pdo->prepare("
            SELECT 
                u.username, 
                d.name as department, 
                COUNT(t.task_id) as tasks_completed 
            FROM tasks t
            JOIN users u ON t.user_id = u.id
            JOIN user_departments ud ON u.id = ud.user_id
            JOIN departments d ON ud.department_id = d.id
            WHERE t.status = 'Completed on Time' AND ud.department_id IN (
                SELECT department_id 
                FROM user_departments 
                WHERE user_id = :user_id
            )
            GROUP BY u.username, d.name
            ORDER BY tasks_completed DESC
            LIMIT 3
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $topPerformers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch average task duration for manager's departments
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
                WHERE user_id = :user_id
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $avgDuration = $stmt->fetch(PDO::FETCH_ASSOC)['avg_duration'];
        $avgDuration = round($avgDuration ?? 0, 1);

    } elseif (hasPermission('view_own_tasks')) {
        // Fetch total tasks for the user
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks FROM tasks WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $totalTasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];

        // Fetch active task counts for the user
        $activeTaskStatuses = [
            'Assigned' => 'assigned_tasks',
            'In Progress' => 'in_progress_tasks',
            'Reassigned' => 'reassigned_tasks'
        ];
        $taskCounts = [];
        foreach ($activeTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks 
                WHERE user_id = :user_id 
                AND status = :status
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch inactive task counts for the user
        $inactiveTaskStatuses = [
            'Completed on Time' => 'completed_tasks',
            'Delayed Completion' => 'delayed_tasks',
            'Closed' => 'closed_tasks',
            'Cancelled' => 'cancelled_tasks',
            'Hold' => 'hold_tasks',
            'Reinstated' => 'reinstated_tasks'
        ];
        foreach ($inactiveTaskStatuses as $status => $key) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM tasks 
                WHERE user_id = :user_id 
                AND status = :status
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->execute();
            $taskCounts[$key] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        }

        // Fetch active project counts for the user
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as assigned_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = :user_id
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status NOT IN ('Assigned', 'Reassigned')
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $assignedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['assigned_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as in_progress_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = :user_id
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
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $inProgressProjects = $stmt->fetch(PDO::FETCH_ASSOC)['in_progress_projects'];

        // Fetch inactive project counts for the user
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as completed_projects
            FROM projects p
            JOIN tasks t ON p.id = t.project_id
            WHERE t.user_id = :user_id
            AND NOT EXISTS (
                SELECT 1
                FROM tasks t2
                WHERE t2.project_id = p.id
                AND t2.status IN ('Assigned', 'In Progress', 'Reassigned')
            )
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $completedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['completed_projects'];

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as no_tasks_projects
            FROM projects p
            LEFT JOIN tasks t ON p.id = t.project_id
            WHERE t.project_id IS NULL
            AND t.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $noTasksProjects = $stmt->fetch(PDO::FETCH_ASSOC)['no_tasks_projects'];

        // Fetch task distribution by status for the user
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Assigned' THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'Hold' THEN 1 ELSE 0 END) as hold,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'Reinstated' THEN 1 ELSE 0 END) as reinstated,
                SUM(CASE WHEN status = 'Reassigned' THEN 1 ELSE 0 END) as reassigned,
                SUM(CASE WHEN status = 'Completed on Time' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'Delayed Completion' THEN 1 ELSE 0 END) as `delayed`,
                SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
            FROM tasks
            WHERE user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $taskDistribution = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch task completion over time for the user
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(planned_finish_date, '%b') as month,
                COUNT(*) as tasks_completed
            FROM tasks
            WHERE status = 'Completed on Time' AND user_id = :user_id
            GROUP BY DATE_FORMAT(planned_finish_date, '%Y-%m')
            ORDER BY planned_finish_date
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $taskCompletionOverTime = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch average task duration for the user
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
            AND user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $avgDuration = $stmt->fetch(PDO::FETCH_ASSOC)['avg_duration'];
        $avgDuration = round($avgDuration ?? 0, 1);
    }

    // Session timeout settings
    $timeout_duration = 1200;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: portal-login.html");
        exit;
    }

    // Update last activity time
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

        .modal-content {
            border-radius: 10px;
        }

        .modal-header {
            background-color: #002c5f;
            color: white;
            border-radius: 10px 10px 0 0;
        }

        .modal-title {
            font-weight: bold;
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

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navbar -->
            <div class="navbar">
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($username) ?></strong></p>
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
                        <!-- Assigned Projects -->
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Assigned Projects</h5>
                                    <p class="card-text display-4"><?= $assignedProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- In Progress Projects -->
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">In Progress Projects</h5>
                                    <p class="card-text display-4"><?= $inProgressProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Reassigned Tasks -->
                        <div class="col-md-4 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Reassigned Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['reassigned_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Assigned Tasks -->
                        <div class="col-md-2 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Assigned Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['assigned_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- In Progress Tasks -->
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
                        <!-- Completed Projects -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Completed Projects</h5>
                                    <p class="card-text display-4"><?= $completedProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- No Tasks Projects -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Projects with no Tasks</h5>
                                    <p class="card-text display-4"><?= $noTasksProjects ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Completed on Time Tasks -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Tasks Completed on Time</h5>
                                    <p class="card-text display-4"><?= $taskCounts['completed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Delayed Completion Tasks -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Delayed Completion Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['delayed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Closed Tasks -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Closed Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['closed_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Cancelled Tasks -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Cancelled Tasks</h5>
                                    <p class="card-text display-4"><?= $taskCounts['cancelled_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Hold Tasks -->
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">Tasks on Hold</h5>
                                    <p class="card-text display-4"><?= $taskCounts['hold_tasks'] ?></p>
                                </div>
                            </div>
                        </div>
                        <!-- Reinstated Tasks -->
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

                <!-- Row: Charts and Graphs -->
                <div class="row mb-4">
                    <h2 class="section-header">Other Metrics</h2>
                    <!-- Task Distribution Chart -->
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
                    <!-- Task Completion Over Time -->
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

                <!-- Row: Additional Metrics -->
                <div class="row mb-4">
                    <!-- Average Task Duration -->
                    <div class="col-md-4">
                        <div class="card metric-card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Average Task Duration</h5>
                                <p class="card-text display-4"><?= $avgDuration ?></p>
                                <p class="text-muted">Days</p>
                            </div>
                        </div>
                    </div>
                    <!-- Tasks by Department (Only for Admin and Manager) -->
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
                    <?php endif; ?>
                    <!-- User Performance (Only for Admin and Manager) -->
                    <?php if (hasPermission('dashboard_tasks')): ?>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="text-center card-title">Top Performers</h5>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($topPerformers as $performer): ?>
                                            <li class="list-group-item"><?= htmlspecialchars($performer['username']) ?>
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

        <!-- Modals for Task Status -->
        <!-- Completed Tasks Modal -->
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
                                <tbody id="completedTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned Tasks Modal -->
        <div class="modal fade" id="pendingTasksModal" tabindex="-1" aria-labelledby="pendingTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pendingTasksModalLabel">Assigned Tasks (Last 3 Months)</h5>
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
                                        <th>Expected Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="pendingTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- In Progress Tasks Modal -->
        <div class="modal fade" id="inProgressTasksModal" tabindex="-1" aria-labelledby="inProgressTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="inProgressTasksModalLabel">In Progress Tasks (Last 3 Months)</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="inProgressTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delayed Tasks Modal -->
        <div class="modal fade" id="delayedTasksModal" tabindex="-1" aria-labelledby="delayedTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="delayedTasksModalLabel">Delayed Tasks (Last 3 Months)</h5>
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
                                        <th>Expected Finish Date</th>
                                    </tr>
                                </thead>
                                <tbody id="delayedTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hold Tasks Modal -->
        <div class="modal fade" id="holdTasksModal" tabindex="-1" aria-labelledby="holdTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="holdTasksModalLabel">Hold Tasks</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="holdTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cancelled Tasks Modal -->
        <div class="modal fade" id="cancelledTasksModal" tabindex="-1" aria-labelledby="cancelledTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cancelledTasksModalLabel">Cancelled Tasks</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="cancelledTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reinstated Tasks Modal -->
        <div class="modal fade" id="reinstatedTasksModal" tabindex="-1" aria-labelledby="reinstatedTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reinstatedTasksModalLabel">Reinstated Tasks</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="reinstatedTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reassigned Tasks Modal -->
        <div class="modal fade" id="reassignedTasksModal" tabindex="-1" aria-labelledby="reassignedTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reassignedTasksModalLabel">Reassigned Tasks</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="reassignedTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Closed Tasks Modal -->
        <div class="modal fade" id="closedTasksModal" tabindex="-1" aria-labelledby="closedTasksModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="closedTasksModalLabel">Closed Tasks</h5>
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
                                        <th>Start Date</th>
                                    </tr>
                                </thead>
                                <tbody id="closedTasksTableBody">
                                    <!-- Rows will be populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap JS (with Popper.js) -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            const statusMapping = {
                'Assigned': 'Assigned',
                'In Progress': 'In Progress',
                'Completed': 'Completed on Time',
                'Delayed': 'Delayed Completion'
            };

            // Task Distribution Chart (Pie Chart)
            const taskDistributionChart = new Chart(document.getElementById('taskDistributionChart'), {
                type: 'pie',
                data: {
                    labels: [
                        'Assigned',
                        'In Progress',
                        'Hold',
                        'Cancelled',
                        'Reinstated',
                        'Reassigned',
                        'Completed on Time',
                        'Delayed Completion',
                        'Closed'
                    ],
                    datasets: [{
                        label: 'Task Distribution',
                        data: [
                            <?= $taskDistribution['assigned'] ?>,
                            <?= $taskDistribution['in_progress'] ?>,
                            <?= $taskDistribution['hold'] ?>,
                            <?= $taskDistribution['cancelled'] ?>,
                            <?= $taskDistribution['reinstated'] ?>,
                            <?= $taskDistribution['reassigned'] ?>,
                            <?= $taskDistribution['completed'] ?>,
                            <?= $taskDistribution['delayed'] ?>,
                            <?= $taskDistribution['closed'] ?>
                        ],
                        backgroundColor: [
                            '#FF0000',
                            '#0000FF',
                            '#800080',
                            '#EE2C2C',
                            '#660000',
                            '#FF6600',
                            '#00CD00',
                            '#FFD54F',
                            '#64B5F6'
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: true,
                            text: 'Task Distribution by Status'
                        }
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const statusLabel = [
                                'Assigned',
                                'In Progress',
                                'Hold',
                                'Cancelled',
                                'Reinstated',
                                'Reassigned',
                                'Completed on Time',
                                'Delayed Completion',
                                'Closed'
                            ][index];
                            fetchTaskData(statusLabel);
                        }
                    }
                }
            });

            function fetchTaskData(status) {
                const encodedStatus = encodeURIComponent(status);
                fetch(`fetch-tasks.php?status=${encodedStatus}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (Array.isArray(data)) {
                            populateModal(status, data);
                        } else {
                            console.error('Unexpected response format:', data);
                            alert('Failed to fetch task data. Please try again.');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching task data:', error);
                        alert('Failed to fetch task data. Please try again.');
                    });
            }

            function populateModal(status, data) {
                let modalId, tableBodyId;
                switch (status) {
                    case 'Assigned':
                        modalId = 'pendingTasksModal';
                        tableBodyId = 'pendingTasksTableBody';
                        break;
                    case 'In Progress':
                        modalId = 'inProgressTasksModal';
                        tableBodyId = 'inProgressTasksTableBody';
                        break;
                    case 'Hold':
                        modalId = 'holdTasksModal';
                        tableBodyId = 'holdTasksTableBody';
                        break;
                    case 'Cancelled':
                        modalId = 'cancelledTasksModal';
                        tableBodyId = 'cancelledTasksTableBody';
                        break;
                    case 'Reinstated':
                        modalId = 'reinstatedTasksModal';
                        tableBodyId = 'reinstatedTasksTableBody';
                        break;
                    case 'Reassigned':
                        modalId = 'reassignedTasksModal';
                        tableBodyId = 'reassignedTasksTableBody';
                        break;
                    case 'Completed on Time':
                        modalId = 'completedTasksModal';
                        tableBodyId = 'completedTasksTableBody';
                        break;
                    case 'Delayed Completion':
                        modalId = 'delayedTasksModal';
                        tableBodyId = 'delayedTasksTableBody';
                        break;
                    case 'Closed':
                        modalId = 'closedTasksModal';
                        tableBodyId = 'closedTasksTableBody';
                        break;
                    default:
                        return;
                }

                const tableBody = document.getElementById(tableBodyId);
                tableBody.innerHTML = '';

                if (data.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="5" class="text-center">No tasks found.</td></tr>';
                } else {
                    data.forEach((task, index) => {
                        const row = document.createElement('tr');
                        let displayDate;

                        if ((status === 'Delayed Completion' || status === 'Completed on Time') && task.actual_completion_date) {
                            displayDate = task.actual_completion_date;
                        } else {
                            displayDate = task.completion_date || task.start_date || task.planned_finish_date;
                        }

                        row.innerHTML = `
                            <td>${index + 1}</td>
                            <td>${task.task_name}</td>
                            <td>${task.assigned_to}</td>
                            <td>${task.department}</td>
                            <td>${displayDate}</td>
                        `;
                        tableBody.appendChild(row);
                    });
                }

                const modal = new bootstrap.Modal(document.getElementById(modalId));
                modal.show();
            }

            // Task Completion Over Time (Line Chart)
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
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: true,
                            text: 'Task Completion Over Time'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            <?php if (hasPermission('dashboard_tasks')): ?>
                // Tasks by Department (Bar Chart)
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
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                            title: {
                                display: true,
                                text: 'Tasks by Department'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            <?php endif; ?>
        </script>
</body>

</html>