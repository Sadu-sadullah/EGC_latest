<?php
session_start();

// Check if the user is logged in and has the necessary permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

// Include the database connection
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle OTP verification
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
        $otp = trim($_POST['otp']);
        $user_id = $_POST['user_id'];
        $pending_deletion = $_SESSION['pending_deletion'] ?? [];

        if (empty($pending_deletion)) {
            $_SESSION['errorMsg'] = "Session expired. Please start the deletion process again.";
        } elseif ($otp !== $pending_deletion['otp'] || time() > $pending_deletion['otp_expiry']) {
            $_SESSION['errorMsg'] = "Invalid or expired OTP.";
            $_SESSION['showDeleteOtpForm'] = true;
        } else {
            // Delete the user from the `users` table
            $deleteUserQuery = "DELETE FROM users WHERE id = ?";
            $stmt = $pdo->prepare($deleteUserQuery);
            $stmt->execute([$user_id]);

            // Clear session data
            unset($_SESSION['pending_deletion']);
            unset($_SESSION['showDeleteOtpForm']);
            $_SESSION['deletionMsg'] = "User deleted successfully after OTP confirmation.";
        }
    } else {
        $_SESSION['errorMsg'] = "Invalid request.";
    }

    // Redirect back to the view-users page
    header("Location: view-users.php");
    exit;

} catch (PDOException $e) {
    // Handle any errors
    die("Error: " . $e->getMessage());
}
?>