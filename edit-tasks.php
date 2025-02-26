<?php
// Display all errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$config = include '../config.php';
$dsn = "mysql:host=localhost;dbname=euro_new;charset=utf8mb4";
$username = $config['dbUsername'];
$password = $config['dbPassword'];

// Check if the user is logged in and has appropriate privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'user') {
    header("Location: portal-login.html");
    exit;
}

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the task ID from the URL
    $taskId = $_GET['id'];

    // Fetch task details
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE task_id = :task_id");
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
    $stmt->execute();
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo "Task not found.";
        exit;
    }

    // Fetch predecessor tasks for the selected project, excluding the current task
    $predecessorTasksStmt = $pdo->prepare("
        SELECT task_id, task_name 
        FROM tasks 
        WHERE predecessor_task_id IS NULL 
          AND status = 'Assigned' 
          AND assigned_by_id = :assigned_by_id 
          AND project_id = :project_id 
          AND task_id != :task_id
        ORDER BY recorded_timestamp DESC
    ");
    $predecessorTasksStmt->bindParam(':assigned_by_id', $user_id, PDO::PARAM_INT);
    $predecessorTasksStmt->bindParam(':project_id', $task['project_id'], PDO::PARAM_INT);
    $predecessorTasksStmt->bindParam(':task_id', $taskId, PDO::PARAM_INT);
    $predecessorTasksStmt->execute();
    $predecessorTasks = $predecessorTasksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ensure all form values are captured properly
        $project_id = trim($_POST['project_id']);
        $task_name = trim($_POST['task_name']);
        $task_description = trim($_POST['task_description']);
        $project_type = trim($_POST['project_type']);
        $planned_start_date = trim($_POST['planned_start_date']);
        $planned_finish_date = trim($_POST['planned_finish_date']);
        $user_id = trim($_POST['user_id']);
        $predecessor_task_id = isset($_POST['predecessor_task_id']) && !empty($_POST['predecessor_task_id']) ? trim($_POST['predecessor_task_id']) : null;

        // Validate inputs
        if (empty($task_name) || empty($project_id) || empty($task_description) || empty($project_type) || empty($planned_start_date) || empty($planned_finish_date) || empty($user_id)) {
            $error = "All fields are required.";
        } else {
            // Update the task in the database
            $updateStmt = $pdo->prepare("
                UPDATE tasks 
                SET 
                    project_id = :project_id,
                    task_name = :task_name, 
                    task_description = :task_description, 
                    project_type = :project_type, 
                    planned_start_date = :planned_start_date, 
                    planned_finish_date = :planned_finish_date,
                    user_id = :user_id,
                    predecessor_task_id = :predecessor_task_id
                WHERE task_id = :task_id
            ");
            $updateStmt->bindParam(':project_id', $project_id);
            $updateStmt->bindParam(':task_name', $task_name);
            $updateStmt->bindParam(':task_description', $task_description);
            $updateStmt->bindParam(':project_type', $project_type);
            $updateStmt->bindParam(':planned_start_date', $planned_start_date);
            $updateStmt->bindParam(':planned_finish_date', $planned_finish_date);
            $updateStmt->bindParam(':user_id', $user_id);
            $updateStmt->bindParam(':predecessor_task_id', $predecessor_task_id);
            $updateStmt->bindParam(':task_id', $taskId);

            if ($updateStmt->execute()) {
                $success = "Task updated successfully.";
                // Refresh task details after update
                $task['project_id'] = $project_id;
                $task['task_name'] = $task_name;
                $task['task_description'] = $task_description;
                $task['project_type'] = $project_type;
                $task['planned_start_date'] = $planned_start_date;
                $task['planned_finish_date'] = $planned_finish_date;
                $task['user_id'] = $user_id;
                $task['predecessor_task_id'] = $predecessor_task_id;
            } else {
                $error = "Failed to update task. Please try again.";
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
    <title>Edit Task</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        .form-container {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            margin: 20px;
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

        input[type="text"],
        input[type="datetime-local"],
        select {
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

        textarea {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            display: inline-block;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            resize: vertical;
            font-family: Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>

<body>

    <div class="form-container">
        <h1>Edit Task: <?= htmlspecialchars($task['task_name']) ?></h1>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="project_id">Project Name:</label>
                <select id="project_id" name="project_id" required>
                    <option value="">Select an existing project</option>
                    <?php
                    $projectsStmt = $pdo->query("SELECT id, project_name FROM projects");
                    $projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($projects as $project): ?>
                        <option value="<?= htmlspecialchars($project['id']) ?>" 
                            <?php if ($project['id'] == $task['project_id']) echo 'selected'; ?>>
                            <?= htmlspecialchars($project['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="task_name">Task Name</label>
                <input type="text" id="task_name" name="task_name" value="<?= htmlspecialchars($task['task_name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="task_description">Task Description:</label>
                <textarea id="task_description" name="task_description" rows="4"><?= htmlspecialchars($task['task_description']) ?></textarea>
            </div>
            <div class="form-group">
                <label for="project_type">Project Type:</label>
                <select id="project_type" name="project_type">
                    <option value="Internal" <?php if ($task['project_type'] == 'Internal') echo 'selected'; ?>>Internal</option>
                    <option value="External" <?php if ($task['project_type'] == 'External') echo 'selected'; ?>>External</option>
                </select>
            </div>
            <div class="form-group">
                <label for="planned_start_date">Planned Start Date</label>
                <input type="datetime-local" id="planned_start_date" name="planned_start_date" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($task['planned_start_date']))) ?>" required>
            </div>
            <div class="form-group">
                <label for="planned_finish_date">Planned Finish Date</label>
                <input type="datetime-local" id="planned_finish_date" name="planned_finish_date" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($task['planned_finish_date']))) ?>" required>
            </div>
            <div class="form-group">
                <label for="user_id">Assign to:</label>
                <select id="user_id" name="user_id" required>
                    <option value="">Select a user</option>
                    <?php
                    $usersStmt = $pdo->query("
                        SELECT u.id, u.username, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role 
                        FROM users u
                        JOIN user_departments ud ON u.id = ud.user_id
                        JOIN departments d ON ud.department_id = d.id
                        JOIN roles r ON u.role_id = r.id
                        WHERE r.name != 'Admin'
                        GROUP BY u.id
                    ");
                    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" 
                            <?php if ($user['id'] == $task['user_id']) echo 'selected'; ?>>
                            <?= htmlspecialchars($user['username']) ?>
                            (<?= htmlspecialchars($user['departments']) ?> - <?= htmlspecialchars($user['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="predecessor_task_id">Predecessor Task (Optional):</label>
                <select id="predecessor_task_id" name="predecessor_task_id" class="form-control">
                    <option value="">Select a predecessor task</option>
                    <?php if (!empty($predecessorTasks)): ?>
                        <?php foreach ($predecessorTasks as $predecessorTask): ?>
                            <option value="<?= $predecessorTask['task_id'] ?>" 
                                <?php if ($predecessorTask['task_id'] == $task['predecessor_task_id']) echo 'selected'; ?>>
                                <?= htmlspecialchars($predecessorTask['task_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No available predecessor tasks</option>
                    <?php endif; ?>
                </select>
            </div>
            <button type="submit">Save Changes</button>
        </form>
        <a href="tasks.php" class="back-btn">Back</a>
    </div>
</body>

</html>