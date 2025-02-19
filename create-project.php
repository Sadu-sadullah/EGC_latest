<?php
session_start();
require 'permissions.php';
require '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

if (!isset($_SESSION['loggedin'])) {
    header("Location: portal-login.html");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $project_name = trim($_POST['new_project_name']);
    $created_by_user_id = $_POST['created_by_user_id'];

    if (empty($project_name)) {
        echo '<script>alert("Project name cannot be empty."); window.history.back();</script>';
        exit;
    }

    $conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Check if the project name already exists
    $checkStmt = $conn->prepare("SELECT id FROM projects WHERE project_name = ?");
    $checkStmt->bind_param("s", $project_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        echo '<script>alert("Project name already exists."); window.history.back();</script>';
        $checkStmt->close();
        $conn->close();
        exit;
    }

    // If the project name does not exist, proceed with the insertion
    $stmt = $conn->prepare("INSERT INTO projects (project_name, created_by_user_id) VALUES (?, ?)");
    $stmt->bind_param("si", $project_name, $created_by_user_id);

    if ($stmt->execute()) {
        echo '<script>alert("Project created successfully."); window.location.href="tasks.php";</script>';
    } else {
        echo '<script>alert("Failed to create project."); window.history.back();</script>';
    }

    $stmt->close();
    $conn->close();
}
?>