<?php
header('Content-Type: application/json');
require 'permissions.php';
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if ($user_id === null) {
    echo json_encode(['success' => false, 'message' => 'User ID not set']);
    exit;
}

$config = include '../config.php';
$conn = new mysqli('localhost', $config['dbUsername'], $config['dbPassword'], 'new');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$department_id = isset($_POST['department_id']) ? (int) $_POST['department_id'] : null;
$project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int) $_POST['project_id'] : null;

if (!$department_id) {
    echo json_encode(['success' => false, 'message' => 'Department ID required']);
    exit;
}

// Verify department access
$deptCheck = $conn->prepare("
    SELECT d.id 
    FROM departments d
    JOIN user_departments ud ON d.id = ud.department_id
    WHERE d.id = ? AND ud.user_id = ?
");
$deptCheck->bind_param("ii", $department_id, $user_id);
$deptCheck->execute();
if ($deptCheck->get_result()->num_rows === 0 && !hasPermission('create_projects')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized department access']);
    $deptCheck->close();
    $conn->close();
    exit;
}
$deptCheck->close();

// Fetch department name
$deptStmt = $conn->prepare("SELECT name FROM departments WHERE id = ?");
$deptStmt->bind_param("i", $department_id);
$deptStmt->execute();
$deptName = $deptStmt->get_result()->fetch_assoc()['name'];
$deptStmt->close();

if (!$deptName) {
    echo json_encode(['success' => false, 'message' => 'Invalid department']);
    $conn->close();
    exit;
}

// Generate project code
$deptCode = strtoupper(substr($deptName, 0, 2));
$countStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM projects 
    WHERE department_id = ?
");
$countStmt->bind_param("i", $department_id);
$countStmt->execute();
$projectCount = $countStmt->get_result()->fetch_assoc()['count'] + 1;
$countStmt->close();

$project_code = sprintf("%s%03d", $deptCode, $projectCount);

// Check if code is unique
$codeCheckStmt = $conn->prepare("
    SELECT id 
    FROM projects 
    WHERE project_code = ? AND id != ?
");
$codeCheckStmt->bind_param("si", $project_code, $project_id);
$codeCheckStmt->execute();
if ($codeCheckStmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Generated project code is already in use']);
    $codeCheckStmt->close();
    $conn->close();
    exit;
}
$codeCheckStmt->close();

echo json_encode(['success' => true, 'project_code' => $project_code]);
$conn->close();
?>