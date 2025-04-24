<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$config = include '../config.php';
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
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);

    // Execute the query
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch the user data
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Check if password is the reset placeholder
        if ($user['password'] === 'RESET_REQUIRED') {
            $_SESSION['reset_username'] = $username;
            echo "<script>alert('Your account has been restored. Please reset your password to continue.'); window.location.href = 'reset-password.php?restored=true';</script>";
            exit;
        }

        // Verify the password
        if (password_verify($password, $user['password'])) {
            // Generate a unique session token
            $sessionToken = bin2hex(random_bytes(32));

            // Update the user's session token in the database
            $updateStmt = $conn->prepare("UPDATE users SET session_token = ? WHERE id = ?");
            $updateStmt->bind_param("si", $sessionToken, $user['id']);
            $updateStmt->execute();
            $updateStmt->close();

            // Fetch all roles assigned to the user
            $roleStmt = $conn->prepare("
                SELECT r.id, r.name 
                FROM roles r
                JOIN user_roles ur ON r.id = ur.role_id 
                WHERE ur.user_id = ?
            ");
            $roleStmt->bind_param("i", $user['id']);
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();

            $roles = [];
            while ($row = $roleResult->fetch_assoc()) {
                $roles[] = ['id' => $row['id'], 'name' => $row['name']];
            }
            $roleStmt->close();

            // Store roles in session
            $_SESSION['user_roles'] = $roles;

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

            // Start a new session
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['session_token'] = $sessionToken;

            // Redirect based on number of roles
            if (count($roles) === 1) {
                // Single role: Set it and go to welcome.php
                $_SESSION['selected_role'] = $roles[0]['name'];
                $_SESSION['selected_role_id'] = $roles[0]['id'];
                header("Location: welcome.php");
            } else {
                // Multiple roles: Go to role selection page
                header("Location: select-role.php");
            }
            exit;
        } else {
            echo "<script>alert('Incorrect password.'); window.location.href = 'portal-login.html';</script>";
        }
    } else {
        echo "<script>alert('Username not found.'); window.location.href = 'portal-login.html';</script>";
    }

    // Close the statements
    $stmt->close();
    $departmentStmt->close();
}

// Close the connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            height: 100vh;
            margin: 0;
        }

        .login-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 300px;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .login-form {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .input-group {
            margin-bottom: 15px;
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        .input-field-group {
            margin-right: 20px;
        }

        .input-group label {
            margin-bottom: 5px;
            text-align: left;
            width: 100%;
        }

        .input-group input {
            width: 100%;
            height: 40px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .submit-btn {
            width: 100%;
            height: 40px;
            background-color: #002c5f;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 auto;
        }

        .submit-btn:hover {
            background-color: #02438e;
        }

        .forgot-pwd {
            margin-top: 10px;
            text-align: center;
        }

        .forgot-pwd a {
            text-decoration: none;
            color: #002c5f;
        }

        .forgot-pwd a:hover {
            color: #02438e;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <form action="login.php" method="post" class="login-form">
            <img src="images/logo/logo.webp" alt="Company Logo" style="margin-bottom: 20px;">
            <div class="input-group input-field-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="input-group input-field-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="input-group">
                <button type="submit" class="submit-btn">Login</button>
            </div>
            <p class="forgot-pwd">Forgot <a href="reset-password.php">Password?</a></p>
        </form>
    </div>
    <script>
        function getQueryParam(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }
        if (getQueryParam('reset') === 'success') {
            alert("Password has been reset successfully. Please log in with your new password.");
        }
    </script>
</body>

</html>