<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in and has admin role
if (!isset($_SESSION['loggedin']) || $_SESSION['role'] !== 'Admin') {
    header("Location: portal-login.html");
    exit;
}

$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_login_system_2;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $departmentId = $_GET['id'];

    // Fetch department details
    $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE id = :id");
    $stmt->bindParam(':id', $departmentId, PDO::PARAM_INT);
    $stmt->execute();
    $department = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$department) {
        echo "Department not found.";
        exit;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $departmentName = trim($_POST['department_name']);

        if (empty($departmentName)) {
            $error = "Department name is required.";
        } else {
            // Update the department name
            $updateStmt = $pdo->prepare("UPDATE departments SET name = :name WHERE id = :id");
            $updateStmt->bindParam(':name', $departmentName);
            $updateStmt->bindParam(':id', $departmentId);

            if ($updateStmt->execute()) {
                $success = "Department updated successfully.";
                $department['name'] = $departmentName; // Update the displayed department name
            } else {
                $error = "Failed to update department. Please try again.";
            }
        }
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Department</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .form-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            text-align: center;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input[type="text"] {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background-color: #004080;
        }

        .error {
            color: red;
            text-align: center;
            margin-bottom: 20px;
        }

        .success {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }

        .back-btn {
            display: block;
            margin-top: 20px;
            text-align: center;
            font-size: 16px;
            text-decoration: none;
            color: #004080;
        }
    </style>
</head>
<body>

<div class="form-container">
    <h1>Edit Department: <?= htmlspecialchars($department['name']) ?></h1>
    <?php if (isset($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php elseif (isset($success)): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label for="department_name">Department Name</label>
            <input type="text" id="department_name" name="department_name" value="<?= htmlspecialchars($department['name']) ?>" required>
        </div>
        <button type="submit">Save Changes</button>
    </form>
    <a href="view-roles-departments.php" class="back-btn">Back</a>
</div>

</body>
</html>