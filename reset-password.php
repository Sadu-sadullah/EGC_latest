<?php
session_start();
// Manually include PHPMailer files
require './PHPMailer-master/src/Exception.php';
require './PHPMailer-master/src/PHPMailer.php';
require './PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Determine user's timezone
$userTimeZone = null;
if (isset($_COOKIE['user_timezone'])) {
    $userTimeZone = $_COOKIE['user_timezone'];
    date_default_timezone_set($userTimeZone); // Set PHP to user's timezone
} else {
    // If no cookie, default to a common timezone or detect via JavaScript (handled later in HTML)
    date_default_timezone_set('UTC'); // Default to UTC as fallback
}

// Function to validate password complexity
function validatePassword($password)
{
    $uppercase = preg_match('@[A-Z]@', $password);
    $number = preg_match('@\d@', $password);
    $specialChar = preg_match('@[^\w]@', $password);
    return $uppercase && $number && $specialChar && strlen($password) >= 8;
}

// Handle reset request (no token in URL)
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_GET['token'])) {
    $username = trim($_POST['username']);

    // Check if username exists
    $stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $email = $user['email'];

        // Generate a unique reset token
        $resetToken = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour')); // Use user's timezone for expiry

        // Store token and expiry in database using user's local timezone
        $updateStmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE username = ?");
        $updateStmt->bind_param("sss", $resetToken, $expiry, $username);
        $updateStmt->execute();
        $updateStmt->close();

        // Send reset email with PHPMailer
        $mail = new PHPMailer(true);
        try {
            // Server settings (using Zoho SMTP)
            $mail->isSMTP();
            $mail->Host = 'smtp.zoho.com';
            $mail->SMTPAuth = true;
            $mail->Username = $config['email_username'];
            $mail->Password = $config['email_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            // Recipients
            $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System Password Reset');
            $mail->addAddress($email);

            // Content
            $resetLink = "http://euroglobalconsultancy.com/reset-password.php?token=" . $resetToken;
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request';
            $mail->Body = "Hello,<br><br>Click the following link to reset your password:<br><a href='$resetLink'>$resetLink</a><br><br>This link will expire in 1 hour in your local time (" . date('T') . ").<br><br>If you didn’t request this, ignore this email.";
            $mail->AltBody = "Hello,\n\nClick the following link to reset your password:\n$resetLink\n\nThis link will expire in 1 hour in your local time (" . date('T') . ").\n\nIf you didn’t request this, ignore this email.";

            $mail->send();
            $successMsg = "A password reset link has been sent to your email.";
        } catch (Exception $e) {
            $errorMsg = "Failed to send the reset email. Error: " . $mail->ErrorInfo;
        }
    } else {
        $errorMsg = "Username not found.";
    }
    $stmt->close();
}

// Handle reset form submission (token in URL)
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_GET['token'])) {
    $token = $_GET['token'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($newPassword !== $confirmPassword) {
        $errorMsg = "New password and confirm password do not match.";
    } elseif (!validatePassword($newPassword)) {
        $errorMsg = "Password must contain at least one uppercase letter, one number, one special character, and be at least 8 characters long.";
    } else {
        $stmt = $conn->prepare("SELECT username, password FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $username = $user['username'];
            $currentHashedPassword = $user['password'];

            if (password_verify($newPassword, $currentHashedPassword)) {
                $errorMsg = "The new password cannot be the same as the current password.";
            } else {
                $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE username = ?");
                $updateStmt->bind_param("ss", $newHashedPassword, $username);
                if ($updateStmt->execute()) {
                    header("Location: portal-login.html?reset=success");
                    exit;
                } else {
                    $errorMsg = "Error updating password: " . $conn->error;
                }
                $updateStmt->close();
            }
        } else {
            $errorMsg = "Invalid or expired reset link.";
        }
        $stmt->close();
    }
}

$conn->close();
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

        input[type="text"],
        input[type="password"] {
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
        }

        .error {
            color: #d9534f;
        }

        .success {
            color: #5cb85c;
        }
    </style>
</head>

<body>
    <div class="form-container">
        <h2><?php echo isset($_GET['token']) ? 'Set New Password' : 'Reset Password'; ?></h2>

        <?php if (isset($errorMsg)): ?>
            <p class="message error"><?= htmlspecialchars($errorMsg) ?></p>
        <?php endif; ?>
        <?php if (isset($successMsg)): ?>
            <p class="message success"><?= htmlspecialchars($successMsg) ?></p>
        <?php endif; ?>

        <?php if (isset($_GET['token'])): ?>
            <form action="reset-password.php?token=<?= htmlspecialchars($_GET['token']) ?>" method="post">
                <input type="password" name="new_password" placeholder="Enter new password" required>
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                <button type="submit">Reset Password</button>
            </form>
        <?php else: ?>
            <form action="reset-password.php" method="post">
                <input type="text" name="username" placeholder="Enter your username" required>
                <button type="submit">Request Password Reset</button>
            </form>
        <?php endif; ?>

        <!-- JavaScript to set timezone cookie if not already set -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                function setUserTimezone() {
                    const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if (!document.cookie.match(/user_timezone=([^;]+)/)) {
                        // Set cookie if not already set, expires in 30 days
                        document.cookie = `user_timezone=${encodeURIComponent(userTimeZone)}; path=/; max-age=2592000`;
                    }
                    console.log('User Timezone:', userTimeZone);
                    console.log('Cookie Timezone:', document.cookie.match(/user_timezone=([^;]+)/)?.[1] || 'Not set');
                }

                setUserTimezone();
            });
        </script>
    </div>
</body>

</html>