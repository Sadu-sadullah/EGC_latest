<?php
// fetch_tasks.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'permissions.php';

session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_login_system;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $status = $_GET['status'] ?? '';
    $userId = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['role'] ?? '';

    // Validate status
    $validStatuses = [
        'Assigned',
        'In Progress',
        'Hold',
        'Cancelled',
        'Reinstated',
        'Reassigned',
        'Completed on Time',
        'Delayed Completion',
        'Closed'
    ];

    if (!in_array($status, $validStatuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status']);
        exit;
    }

    // Fetch tasks based on status and user role
    $query = "
        SELECT 
            t.task_id, 
            t.task_name, 
            u.username as assigned_to, 
            d.name as department, 
            DATE_FORMAT(t.planned_finish_date, '%Y-%m-%d') as completion_date,
            DATE_FORMAT(t.planned_start_date, '%Y-%m-%d') as start_date,
            DATE_FORMAT(t.actual_finish_date, '%Y-%m-%d') as actual_completion_date
        FROM tasks t
        JOIN users u ON t.user_id = u.id
        JOIN user_departments ud ON u.id = ud.user_id
        JOIN departments d ON ud.department_id = d.id
        WHERE t.status = :status
    ";

    // Add role-specific filters
    if (hasPermission('view_department_tasks')) {
        $query .= " AND ud.department_id IN (SELECT department_id FROM user_departments WHERE user_id = :user_id)";
    } elseif (hasPermission('view_own_tasks')) {
        $query .= " AND t.user_id = :user_id";
    }

    // Limit to the last 3 months
    $query .= " AND t.planned_finish_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH) 
           ORDER BY COALESCE(t.actual_finish_date, t.planned_finish_date) DESC";

    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':status', $status, PDO::PARAM_STR);

    if ($userRole === 'Manager' || $userRole === 'User') {
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    }

    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($tasks)) {
        echo json_encode([]); // Return an empty array if no tasks are found
    } else {
        echo json_encode($tasks);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>