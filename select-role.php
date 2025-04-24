<?php
session_start();

// Check if user is logged in and has roles
if (!isset($_SESSION['loggedin']) || !isset($_SESSION['user_roles']) || empty($_SESSION['user_roles'])) {
    header("Location: portal-login.html");
    exit;
}

// Handle role selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_role'])) {
    $selectedRoleId = (int) $_POST['selected_role'];
    $validRole = false;
    foreach ($_SESSION['user_roles'] as $role) {
        if ($role['id'] === $selectedRoleId) {
            $_SESSION['selected_role'] = $role['name'];
            $_SESSION['selected_role_id'] = $role['id'];
            $validRole = true;
            break;
        }
    }
    if ($validRole) {
        header("Location: welcome.php");
        exit;
    } else {
        echo "<script>alert('Invalid role selected.');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Role</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .role-selection-container {
            width: 400px;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .role-selection-container h2 {
            color: #002c5f;
            margin-bottom: 20px;
        }
        .form-check {
            margin-bottom: 15px;
            text-align: left;
        }
        .btn-primary {
            width: 100%;
            background-color: #002c5f;
            border: none;
        }
        .btn-primary:hover {
            background-color: #02438e;
        }
    </style>
</head>
<body>
    <div class="role-selection-container">
        <img src="images/logo/logo.webp" alt="Company Logo" style="width: auto; height: 80px; margin-bottom: 20px;">
        <h2>Select Your Role</h2>
        <form method="POST">
            <p>Please choose the role you want to use for this session:</p>
            <?php foreach ($_SESSION['user_roles'] as $role): ?>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="selected_role" id="role_<?= $role['id'] ?>" value="<?= $role['id'] ?>" required>
                    <label class="form-check-label" for="role_<?= $role['id'] ?>">
                        <?= htmlspecialchars($role['name']) ?>
                    </label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary mt-3">Select Role</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>