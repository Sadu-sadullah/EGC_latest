<?php
// Start session
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Include permissions and config
require 'permissions.php';
$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get project ID from POST data
$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : null;

if (!$projectId || $projectId === 0) {
    echo json_encode(['success' => false, 'message' => 'No valid project ID provided']);
    exit;
}

try {
    // Prepare query to fetch all necessary project details
    $stmt = $conn->prepare("
        SELECT project_name, project_code, project_type, department_id, start_date, end_date, 
               customer_name, customer_email, customer_mobile, cost, project_manager
        FROM projects 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $projectId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $project = $result->fetch_assoc();
        // Ensure all fields are included in the response, even if NULL
        echo json_encode([
            'success' => true,
            'project_name' => $project['project_name'] ?? '',
            'project_code' => $project['project_code'] ?? '',
            'project_type' => $project['project_type'] ?? 'Internal',
            'department_id' => $project['department_id'] !== null ? (int) $project['department_id'] : '',
            'start_date' => $project['start_date'] ?? '',
            'end_date' => $project['end_date'] ?? '',
            'customer_name' => $project['customer_name'] ?? '',
            'customer_email' => $project['customer_email'] ?? '',
            'customer_mobile' => $project['customer_mobile'] ?? '',
            'cost' => $project['cost'] !== null ? (float) $project['cost'] : '',
            'project_manager' => $project['project_manager'] ?? ''
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Project not found with ID: ' . $projectId]);
    }

    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching project details: ' . $e->getMessage()]);
}

$conn->close();
?>