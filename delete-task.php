<?php
session_start(); // Ensure session is started

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

// Establish database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Delete task
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : null; // Task ID to delete
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : null;   // Reason for deletion

    // Validate inputs
    if ($task_id === null || empty($reason)) {
        echo '<script>alert("Missing task ID or reason."); window.location.href = "tasks.php";</script>';
        exit;
    }

    // Fetch the task name before deletion using the task_id
    $stmt = $conn->prepare("SELECT task_name FROM tasks WHERE task_id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo '<script>alert("Task not found."); window.location.href = "tasks.php";</script>';
        exit;
    }

    $task = $result->fetch_assoc();
    $task_name = $task['task_name'];

    // Update tasks that reference this task_id as predecessor_task_id
    $updatePredecessorStmt = $conn->prepare("UPDATE tasks SET predecessor_task_id = NULL WHERE predecessor_task_id = ?");
    $updatePredecessorStmt->bind_param("i", $task_id);
    $updatePredecessorStmt->execute();
    $updatePredecessorStmt->close();

    // Record the reason for deletion along with the task name and user_id
    $stmt = $conn->prepare("INSERT INTO task_deletion_reasons (task_name, user_id, reason) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $task_name, $_SESSION['user_id'], $reason);
    $stmt->execute();
    $stmt->close();

    // Delete the task
    $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo '<script>alert("Task deleted successfully."); window.location.href = "tasks.php";</script>';
    } else {
        echo '<script>alert("Failed to delete task."); window.location.href = "tasks.php";</script>';
    }

    $stmt->close();
}

$conn->close();
?>