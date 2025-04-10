<?php
session_start();
// Include PHPMailer files
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

// Get user's timezone from cookie or default to UTC
$userTimezone = isset($_COOKIE['user_timezone']) ? $_COOKIE['user_timezone'] : 'UTC';
date_default_timezone_set($userTimezone);

// Function to validate password complexity
function validatePassword($password)
{
    return preg_match('@[A-Z]@', $password) &&
        preg_match('@\d@', $password) &&
        preg_match('@[^\w]@', $password) &&
        strlen($password) >= 8;
}

// Handle username submission for OTP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username']) && !isset($_POST['otp'])) {
    $username = trim($_POST['username']);

    $stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $email = $user['email'];

        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(0, 999999));

        // Calculate expiry time in user's timezone, then convert to UTC for storage
        $dateTime = new DateTime('now', new DateTimeZone($userTimezone));
        $dateTime->modify('+15 minutes');
        $expiryDisplay = $dateTime->format('Y-m-d H:i:s'); // For email display
        $dateTime->setTimezone(new DateTimeZone('UTC')); // Convert to UTC for DB
        $expiryUtc = $dateTime->format('Y-m-d H:i:s');

        // Store OTP and UTC expiry in database
        $updateStmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE username = ?");
        $updateStmt->bind_param("sss", $otp, $expiryUtc, $username);
        $updateStmt->execute();
        $updateStmt->close();

        // Send OTP via email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.zoho.com';
            $mail->SMTPAuth = true;
            $mail->Username = $config['email_username'];
            $mail->Password = $config['email_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('enquiry@euroglobalconsultancy.com', 'Task Management System');
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'Your Password Reset OTP';
            $mail->Body = "Hello,<br><br>Your One-Time Password (OTP) for password reset is: <strong>$otp</strong><br><br>This OTP will expire in 15 minutes (" . htmlspecialchars($expiryDisplay) . " " . htmlspecialchars($userTimezone) . ").<br><br>If you didn’t request this, please ignore this email.";
            $mail->AltBody = "Hello,\n\nYour One-Time Password (OTP) for password reset is: $otp\n\nThis OTP will expire in 15 minutes (" . htmlspecialchars($expiryDisplay) . " " . htmlspecialchars($userTimezone) . ").\n\nIf you didn’t request this, please ignore this email.";

            $mail->send();
            $_SESSION['reset_username'] = $username; // Store username for next step
            $successMsg = "An OTP has been sent to your registered email. Please check your inbox (and spam/junk folder).";
            $showOtpForm = true;
        } catch (Exception $e) {
            $errorMsg = "Failed to send OTP. Please try again later.";
        }
    } else {
        $errorMsg = "No account found with that username.";
    }
    $stmt->close();
}

// Handle OTP verification and password reset
elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['otp'])) {
    $otp = trim($_POST['otp']);
    $username = $_SESSION['reset_username'] ?? '';

    if (empty($username)) {
        $errorMsg = "Session expired. Please start the reset process again.";
    } else {
        // Check OTP against UTC time in DB
        $stmt = $conn->prepare("SELECT email, password FROM users WHERE username = ? AND reset_token = ? AND reset_token_expiry > UTC_TIMESTAMP()");
        $stmt->bind_param("ss", $username, $otp);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (isset($_POST['new_password'])) {
                // Password reset stage
                $newPassword = trim($_POST['new_password']);
                $confirmPassword = trim($_POST['confirm_password']);

                if ($newPassword !== $confirmPassword) {
                    $errorMsg = "Passwords do not match.";
                    $showOtpForm = true;
                    $otpVerified = true;
                } elseif (!validatePassword($newPassword)) {
                    $errorMsg = "Password must be at least 8 characters long and include one uppercase letter, one number, and one special character.";
                    $showOtpForm = true;
                    $otpVerified = true;
                } elseif (password_verify($newPassword, $user['password'])) {
                    $errorMsg = "New password cannot be the same as your current password.";
                    $showOtpForm = true;
                    $otpVerified = true;
                } else {
                    $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE username = ?");
                    $updateStmt->bind_param("ss", $newHashedPassword, $username);
                    if ($updateStmt->execute()) {
                        unset($_SESSION['reset_username']);
                        header("Location: portal-login.html?reset=success");
                        exit;
                    } else {
                        $errorMsg = "Error updating password. Please try again.";
                        $showOtpForm = true;
                        $otpVerified = true;
                    }
                    $updateStmt->close();
                }
            } else {
                // OTP verified, show password reset form
                $successMsg = "OTP verified successfully. Please enter your new password.";
                $showOtpForm = true;
                $otpVerified = true;
            }
        } else {
            $errorMsg = "Invalid or expired OTP.";
            $showOtpForm = true;
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
            margin-bottom: 20px;
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
            border: 1px solid #ccc;
            border-radius: 5px;
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
            margin-bottom: 15px;
        }

        .error {
            color: #d9534f;
        }

        .success {
            color: #5cb85c;
        }

        label {
            margin-bottom: 5px;
            font-weight: bold;
        }

        .back-link {
            text-align: center;
            margin-top: 15px;
        }

        .back-link a {
            color: #002c5f;
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="form-container">
        <h2>Reset Password</h2>

        <?php if (isset($errorMsg)): ?>
            <p class="message error"><?= htmlspecialchars($errorMsg) ?></p>
        <?php endif; ?>
        <?php if (isset($successMsg)): ?>
            <p class="message success"><?= htmlspecialchars($successMsg) ?></p>
        <?php endif; ?>

        <?php if (isset($showOtpForm) && $showOtpForm): ?>
            <?php if (isset($otpVerified) && $otpVerified): ?>
                <form method="post">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password"
                        required>
                    <input type="hidden" name="otp" value="<?= htmlspecialchars($otp ?? '') ?>">
                    <button type="submit">Reset Password</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <label for="otp">Enter OTP</label>
                    <input type="text" id="otp" name="otp" placeholder="Enter 6-digit OTP" required maxlength="6"
                        pattern="\d{6}">
                    <button type="submit">Verify OTP</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="post">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required>
                <button type="submit">Request OTP</button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="portal-login.html">Back to Login</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function setUserTimezone() {
                const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                if (!document.cookie.match(/user_timezone=([^;]+)/)) {
                    document.cookie = `user_timezone=${encodeURIComponent(userTimeZone)}; path=/; max-age=2592000`;
                    window.location.reload();
                }
            }
            setUserTimezone();
        });
    </script>
</body>

</html>