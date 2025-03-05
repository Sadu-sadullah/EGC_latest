<?php
header('Content-Type: application/json');
ob_start();

session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$config = include realpath(__DIR__ . '/../config.php');
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

$task_id = $_GET['task_id'] ?? null;
if (!$task_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Task ID not provided.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        task_name, 
        planned_start_date, 
        planned_finish_date, 
        actual_start_date, 
        actual_finish_date, 
        status AS current_status
    FROM tasks 
    WHERE task_id = ?
");
$stmt->execute([$task_id]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Task not found.']);
    exit;
}

// Fetch delayed reason if it exists
$delayStmt = $pdo->prepare("SELECT delayed_reason FROM task_transactions WHERE task_id = ? ORDER BY actual_finish_date DESC LIMIT 1");
$delayStmt->execute([$task_id]);
$delayed_reason = $delayStmt->fetchColumn();

// Calculate durations
function calculateWeekdayDuration($start, $end) {
    if (!$start || !$end) return 'N/A';
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $interval = $startDate->diff($endDate);
    $days = 0;
    $current = clone $startDate;
    while ($current <= $endDate) {
        if ($current->format('N') <= 5) $days++; // Count weekdays only
        $current->modify('+1 day');
    }
    $hours = $interval->h + ($interval->i / 60); // Convert minutes to fractional hours
    return sprintf("%d days, %.2f hours", $days - 1, $hours); // Subtract 1 day to account for inclusive range
}

$planned_duration = calculateWeekdayDuration($task['planned_start_date'], $task['planned_finish_date']);
$actual_duration = calculateWeekdayDuration($task['actual_start_date'], $task['actual_finish_date']);

$response = [
    'success' => true,
    'task_name' => $task['task_name'],
    'planned_start_date' => date('d M Y, h:i A', strtotime($task['planned_start_date'])),
    'planned_finish_date' => date('d M Y, h:i A', strtotime($task['planned_finish_date'])),
    'actual_start_date' => $task['actual_start_date'] ? date('d M Y, h:i A', strtotime($task['actual_start_date'])) : null,
    'actual_finish_date' => $task['actual_finish_date'] ? date('d M Y, h:i A', strtotime($task['actual_finish_date'])) : null,
    'planned_duration' => $planned_duration,
    'actual_duration' => $actual_duration,
    'current_status' => $task['current_status'],
    'delayed_reason' => $delayed_reason ?: null
];

ob_end_clean();
echo json_encode($response);
?>