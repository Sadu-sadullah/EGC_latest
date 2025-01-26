<?php
session_start();

// Check if the user is logged in and has the necessary permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

// Include the database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_login_system;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the user ID from the POST request
    $user_id = $_POST['user_id'];

    // Delete the user from the `users` table
    $deleteUserQuery = "DELETE FROM users WHERE id = ?";
    $stmt = $pdo->prepare($deleteUserQuery);
    $stmt->execute([$user_id]);

    // Set deletion message
    $_SESSION['deletionMsg'] = "User deleted successfully.";

    // Redirect back to the view-users page
    header("Location: view-users.php");
    exit;

} catch (PDOException $e) {
    // Handle any errors
    die("Error: " . $e->getMessage());
}
?>