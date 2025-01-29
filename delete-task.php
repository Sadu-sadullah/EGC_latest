<?php
session_start(); // Ensure session is started

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_login_system_2';

// Establish database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Delete task
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $task_id = $_POST['task_id']; // Task ID to delete
    $reason = $_POST['reason'];   // Reason for deletion

    // Fetch the task name before deletion using the task_id
    $stmt = $conn->prepare("SELECT task_name FROM tasks WHERE task_id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $stmt->bind_result($task_name);
    $stmt->fetch();
    $stmt->close();

    // Check if task name was fetched successfully
    if (!$task_name) {
        echo '<script>alert("Task not found."); window.location.href = "tasks.php";</script>';
        exit;
    }

    // Ensure user_id is available in session
    if (!isset($_SESSION['user_id'])) {
        echo '<script>alert("User not logged in."); window.location.href = "tasks.php";</script>';
        exit;
    }

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