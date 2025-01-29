<?php
$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_login_system';

// Establish database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to validate password complexity
function validatePassword($password) {
    // Password must contain at least one uppercase letter, one number, and one special character
    $uppercase = preg_match('@[A-Z]@', $password);
    $number = preg_match('@\d@', $password);
    $specialChar = preg_match('@[^\w]@', $password); // Matches any non-word character

    if (!$uppercase || !$number || !$specialChar || strlen($password) < 8) {
        return false;
    }
    return true;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // Check if new password and confirm password match
    if ($newPassword !== $confirmPassword) {
        echo "<script>alert('New password and confirm password do not match.'); window.location.href = 'reset-password.php';</script>";
        exit;
    }

    // Validate password complexity
    if (!validatePassword($newPassword)) {
        echo "<script>alert('Password must contain at least one uppercase letter, one number, one special character, and be at least 8 characters long.'); window.location.href = 'reset-password.php';</script>";
        exit;
    }

    // Fetch the current hashed password from the database for the given username
    $stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $currentHashedPassword = $user['password'];

        // Verify that the new password is different from the current one
        if (password_verify($newPassword, $currentHashedPassword)) {
            // If the new password matches the old one, show an error message
            echo "<script>alert('The new password cannot be the same as the current password.'); window.location.href = 'reset-password.php';</script>";
            exit;
        } else {
            // Hash the new password
            $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update the password in the database
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
            $updateStmt->bind_param("ss", $newHashedPassword, $username);

            if ($updateStmt->execute()) {
                // Redirect to the login page with success parameter
                header("Location: portal-login.html?reset=success");
                exit;
            } else {
                echo "Error updating password: " . $conn->error;
            }

            // Close the update statement
            $updateStmt->close();
        }
    } else {
        echo "<script>alert('Username not found.'); window.location.href = 'reset-password.php';</script>";
    }

    // Close the statement and connection
    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }

        .form-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            color: #333;
        }

        form {
            display: flex;
            flex-direction: column;
        }

        input[type="text"], input[type="password"] {
            margin-bottom: 15px;
            padding: 10px;
            font-size: 16px;
        }

        button {
            padding: 10px;
            font-size: 16px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background-color: #005bb5;
        }

        .message {
            text-align: center;
            color: #d9534f;
        }

        .success {
            color: #5cb85c;
        }
    </style>
</head>
<body>

<div class="form-container">
    <h2>Reset Password</h2>

    <!-- Display message -->
    <?php if (!empty($message)) : ?>
        <p class="message <?php echo (strpos($message, 'successfully') !== false) ? 'success' : ''; ?>">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <form action="reset-password.php" method="post">
        <input type="text" name="username" placeholder="Enter your username" required>
        <input type="password" name="new_password" placeholder="Enter new password" required>
        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
        <button type="submit">Reset Password</button>
    </form>
</div>

</body>
</html>