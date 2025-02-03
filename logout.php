<?php
session_start();

// Check if the user is logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    // Clear the session token in the database
    $config = include '../config.php';
    $dbHost = 'localhost';
    $dbUsername = $config['dbUsername'];
    $dbPassword = $config['dbPassword'];
    $dbName = 'euro_login_system';

    $conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $updateStmt = $conn->prepare("UPDATE users SET session_token = NULL WHERE id = ?");
    $updateStmt->bind_param("i", $_SESSION['user_id']);
    $updateStmt->execute();
    $updateStmt->close();
    $conn->close();
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to the login page
header("Location: portal-login.html");
exit;
?>