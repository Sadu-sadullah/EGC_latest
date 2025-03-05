<?php
session_start(); // Ensure session is started

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo '<script>alert("Unauthorized access."); window.location.href = "portal-login.html";</script>';
    exit;
}

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

    // Start transaction to ensure data consistency
    $conn->begin_transaction();

    try {
        // Fetch the task name before deletion
        $stmt = $conn->prepare("SELECT task_name FROM tasks WHERE task_id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("Task not found.");
        }

        $task = $result->fetch_assoc();
        $task_name = $task['task_name'];
        $stmt->close();

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
            $conn->commit();
            echo '<script>alert("Task deleted successfully."); window.location.href = "tasks.php";</script>';
        } else {
            throw new Exception("Failed to delete task.");
        }

        $stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        echo '<script>alert("' . $e->getMessage() . '"); window.location.href = "tasks.php";</script>';
    }
}

$conn->close();
?>