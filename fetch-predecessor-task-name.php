<?php
// Start session if needed (not strictly required here unless you use session data)
session_start();

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure the response is always JSON
header('Content-Type: application/json');

try {
    // Load database configuration
    $config = include '../config.php';

    // Database connection
    $dbHost = 'localhost';
    $dbUsername = $config['dbUsername'];
    $dbPassword = $config['dbPassword'];
    $dbName = 'new';

    $conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }

    // Get task_id from GET request
    $task_id = $_GET['task_id'] ?? null;
    if (!$task_id) {
        echo json_encode(['error' => 'No task ID provided']);
        exit;
    }

    // Prepare and execute query
    $stmt = $conn->prepare("SELECT task_name FROM tasks WHERE task_id = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    // Return JSON response
    echo json_encode([
        'task_name' => $row['task_name'] ?? 'N/A'
    ]);

    // Clean up
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    // Return error as JSON
    echo json_encode(['error' => $e->getMessage()]);
}
?>