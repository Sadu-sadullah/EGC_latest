<?php
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

// Start session
session_start();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Prepare the SQL query to fetch user data
    $stmt = $conn->prepare("
        SELECT users.*, roles.name as role_name 
        FROM users 
        LEFT JOIN roles ON users.role_id = roles.id 
        WHERE username = ?
    ");
    $stmt->bind_param("s", $username);

    // Execute the query
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch the user data
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Verify the password
        if (password_verify($password, $user['password'])) {
            // Generate a unique session token
            $sessionToken = bin2hex(random_bytes(32));

            // Update the user's session token in the database
            $updateStmt = $conn->prepare("UPDATE users SET session_token = ? WHERE id = ?");
            $updateStmt->bind_param("si", $sessionToken, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            // Start a new session
            session_regenerate_id(true); // Regenerate session ID for security
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $user['role_name']; // Store role name
            $_SESSION['user_id'] = $user['id']; // Store user ID
            $_SESSION['session_token'] = $sessionToken; // Store session token

            // Fetch all departments assigned to the user
            $departmentStmt = $conn->prepare("
                SELECT departments.name 
                FROM user_departments 
                JOIN departments ON user_departments.department_id = departments.id 
                WHERE user_departments.user_id = ?
            ");
            $departmentStmt->bind_param("i", $user['id']);
            $departmentStmt->execute();
            $departmentResult = $departmentStmt->get_result();

            $departments = [];
            while ($row = $departmentResult->fetch_assoc()) {
                $departments[] = $row['name'];
            }

            // Store departments in the session
            $_SESSION['departments'] = $departments;

            // Redirect to the welcome page
            header("Location: welcome.php");
            exit;
        } else {
            // Password is incorrect
            echo "<script>alert('Incorrect password.'); window.location.href = 'portal-login.html';</script>";
        }
    } else {
        // Username not found
        echo "<script>alert('Username not found.'); window.location.href = 'portal-login.html';</script>";
    }

    // Close the statements
    $stmt->close();
    $departmentStmt->close();
}

// Close the connection
$conn->close();
?>