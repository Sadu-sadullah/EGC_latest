<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'permissions.php';
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: portal-login.html");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if ($user_id === null || $user_role === null) {
    die("Error: User ID or role is not set. Please log in again.");
}

$timeout_duration = 1200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: portal-login.html");
    exit;
}

$_SESSION['last_activity'] = time();

if (isset($_COOKIE['user_timezone'])) {
    $userTimeZone = $_COOKIE['user_timezone'];
    date_default_timezone_set($userTimeZone);
} else {
    date_default_timezone_set('UTC');
}

$config = include '../config.php';
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Verify session token
$checkStmt = $conn->prepare("SELECT session_token FROM users WHERE id = ?");
$checkStmt->bind_param("i", $user_id);
$checkStmt->execute();
$sessionToken = $checkStmt->get_result()->fetch_assoc()['session_token'];

if ($sessionToken !== $_SESSION['session_token']) {
    session_unset();
    session_destroy();
    echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
    exit;
}

// Fetch user details
$userQuery = $conn->prepare("
    SELECT u.id, u.username, u.email, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role 
    FROM users u
    JOIN user_departments ud ON u.id = ud.user_id
    JOIN departments d ON ud.department_id = d.id
    JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
    GROUP BY u.id
");
$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$userResult = $userQuery->get_result();

if ($userResult->num_rows > 0) {
    $userDetails = $userResult->fetch_assoc();
    $loggedInUsername = $userDetails['username'];
    $loggedInDepartment = $userDetails['departments'];
} else {
    $loggedInUsername = "Unknown";
    $loggedInDepartment = "Unknown";
}

// Pagination setup
$projects_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $projects_per_page;

// Count total projects for pagination
$total_projects_query = $conn->query("SELECT COUNT(*) as total FROM projects");
$total_projects = $total_projects_query->fetch_assoc()['total'];
$total_pages = ceil($total_projects / $projects_per_page);

// Fetch projects for the current page
$projects = [];
$projectQuery = $conn->prepare("
    SELECT p.id, p.project_name, p.project_type, p.start_date, p.end_date, 
           p.customer_name, p.customer_email, p.customer_mobile, p.cost, p.project_manager
    FROM projects p
    ORDER BY p.id DESC
    LIMIT ? OFFSET ?
");
$projectQuery->bind_param("ii", $projects_per_page, $offset);
$projectQuery->execute();
$result = $projectQuery->get_result();

while ($row = $result->fetch_assoc()) {
    // Fetch tasks for this project to determine status
    $taskStmt = $conn->prepare("
        SELECT status 
        FROM tasks 
        WHERE project_id = ?
    ");
    $taskStmt->bind_param("i", $row['id']);
    $taskStmt->execute();
    $tasks = $taskStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($tasks)) {
        $row['status'] = 'No Tasks';
    } else {
        $statuses = array_column($tasks, 'status');
        if (array_unique($statuses) === ['Assigned']) {
            $row['status'] = 'Assigned';
        } elseif (in_array('Completed on Time', $statuses) || in_array('Delayed Completion', $statuses) || in_array('Closed', $statuses)) {
            $allCompleted = true;
            foreach ($statuses as $status) {
                if (!in_array($status, ['Completed on Time', 'Delayed Completion', 'Closed'])) {
                    $allCompleted = false;
                    break;
                }
            }
            $row['status'] = $allCompleted ? 'Completed' : 'In Progress';
        } else {
            $row['status'] = 'In Progress';
        }
    }
    $projects[] = $row;
    $taskStmt->close();
}

// Handle project form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : null;
    $project_name = trim($_POST['new_project_name']);
    $project_type = $_POST['project_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $customer_name = $_POST['customer_name'] ?? null;
    $customer_email = $_POST['customer_email'] ?? null;
    $customer_mobile = $_POST['customer_mobile'] ?? null;
    $cost = isset($_POST['cost']) ? floatval($_POST['cost']) : null;
    $project_manager = $_POST['project_manager'] ?? null;
    $created_by_user_id = $user_id;

    $currentDateTime = new DateTime();
    $dateStartDate = new DateTime($start_date);
    $dateEndDate = new DateTime($end_date);
    $threeMonthsAhead = (clone $currentDateTime)->modify('+3 months');

    // Validation
    if (empty($project_name) || empty($project_type) || empty($start_date) || empty($end_date)) {
        echo "<script>alert('Please fill in all required fields.');</script>";
    } elseif ($dateStartDate < $currentDateTime) {
        echo "<script>alert('Start date cannot be before the current date and time.');</script>";
    } elseif ($dateEndDate < $currentDateTime) {
        echo "<script>alert('End date cannot be before the current date and time.');</script>";
    } elseif ($dateEndDate < $dateStartDate) {
        echo "<script>alert('End date cannot be before the start date.');</script>";
    } elseif ($dateEndDate > $threeMonthsAhead) {
        echo "<script>alert('End date is too far in the future (more than 3 months ahead).');</script>";
    } else {
        if ($action === 'create') {
            $stmt = $conn->prepare("
                INSERT INTO projects (project_name, project_type, start_date, end_date, customer_name, customer_email, customer_mobile, cost, project_manager, created_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "sssssssssi",
                $project_name,
                $project_type,
                $start_date,
                $end_date,
                $customer_name,
                $customer_email,
                $customer_mobile,
                $cost,
                $project_manager,
                $created_by_user_id
            );
        } elseif ($action === 'edit' && $project_id) {
            $stmt = $conn->prepare("
                UPDATE projects 
                SET project_name = ?, project_type = ?, start_date = ?, end_date = ?, 
                    customer_name = ?, customer_email = ?, customer_mobile = ?, cost = ?, project_manager = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                "sssssssssi",
                $project_name,
                $project_type,
                $start_date,
                $end_date,
                $customer_name,
                $customer_email,
                $customer_mobile,
                $cost,
                $project_manager,
                $project_id
            );
        }

        if (isset($stmt) && $stmt->execute()) {
            echo "<script>alert('Project " . ($action === 'create' ? 'created' : 'updated') . " successfully.'); window.location.href='projects.php';</script>";
        } else {
            echo "<script>alert('Failed to " . $action . " project: " . ($stmt->error ?? $conn->error) . "');</script>";
        }
        if (isset($stmt))
            $stmt->close();
    }
}

// Handle project deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $project_id = (int) $_POST['project_id'];
    $taskCheck = $conn->prepare("SELECT COUNT(*) as task_count FROM tasks WHERE project_id = ?");
    $taskCheck->bind_param("i", $project_id);
    $taskCheck->execute();
    $taskCount = $taskCheck->get_result()->fetch_assoc()['task_count'];
    $taskCheck->close();

    if ($taskCount > 0) {
        echo "<script>alert('Cannot delete project. There are $taskCount task(s) associated with it.');</script>";
    } else {
        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        if ($stmt->execute()) {
            echo "<script>alert('Project deleted successfully.'); window.location.href='projects.php';</script>";
        } else {
            echo "<script>alert('Failed to delete project: " . $stmt->error . "');</script>";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        * {
            box-sizing: border-box;
        }

        .project-container {
            width: 100%;
            max-width: 100vw;
            margin: 25px 0;
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            overflow-x: auto;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input,
        select {
            width: 100%;
            padding: 8px;
            margin: 5px 0 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .submit-btn {
            background-color: #002c5f;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .submit-btn:hover {
            background-color: #004080;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            min-width: 1000px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background-color: #002c5f;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
            flex-shrink: 0;
        }

        .sidebar a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .sidebar a:hover {
            background-color: #004080;
        }

        .main-content {
            flex-grow: 1;
            padding: 20px;
            background-color: #ffffff;
            overflow-x: hidden;
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 100vw;
        }

        .user-info {
            margin-right: 20px;
            font-size: 14px;
        }

        .back-btn {
            background-color: #002c5f;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .back-btn:hover {
            background-color: #004080;
        }

        .action-btn {
            padding: 5px 10px;
            margin: 0 5px;
            border-radius: 5px;
            text-decoration: none;
            color: white;
            cursor: pointer;
        }

        .edit-btn {
            background-color: #28a745;
        }

        .edit-btn:hover {
            background-color: #218838;
        }

        .delete-btn {
            background-color: #dc3545;
        }

        .delete-btn:hover {
            background-color: #c82333;
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 5px;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            text-decoration: none;
            color: #002c5f;
            transition: background-color 0.3s;
        }

        .pagination a:hover {
            background-color: #f0f0f0;
        }

        .pagination a.active {
            background-color: #002c5f;
            color: white;
            border-color: #002c5f;
        }

        .pagination a.disabled {
            color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
            <?php if (hasPermission('view_projects')): ?>
                <a href="projects.php">Projects</a>
            <?php endif; ?>
            <?php if (hasPermission('update_tasks') || hasPermission('update_tasks_all')): ?>
                <a href="task-actions.php">Task Actions</a>
            <?php endif; ?>
            <?php if (hasPermission('tasks_archive')): ?>
                <a href="archived-tasks.php">Tasks Archive</a>
            <?php endif; ?>
            <?php if (hasPermission('read_users')): ?>
                <a href="view-users.php">View Users</a>
            <?php endif; ?>
            <?php if (hasPermission('read_roles_&_departments')): ?>
                <a href="view-roles-departments.php">View Role or Department</a>
            <?php endif; ?>
            <?php if (hasPermission('read_&_write_privileges')): ?>
                <a href="assign-privilege.php">Assign & View Privileges</a>
            <?php endif; ?>
        </div>

        <div class="main-content">
            <div class="navbar">
                <div class="d-flex align-items-center me-3">
                    <img src="images/logo/logo.webp" alt="Logo" class="logo" style="width: auto; height: 80px;">
                </div>
                <div class="user-info me-3 ms-auto">
                    <p class="mb-0">Logged in as: <strong><?= htmlspecialchars($loggedInUsername) ?></strong></p>
                    <p class="mb-0">Departments: <strong><?= htmlspecialchars($loggedInDepartment) ?></strong></p>
                </div>
                <button class="back-btn" onclick="window.location.href='welcome.php'">Dashboard</button>
            </div>

            <div class="project-container">
                <h2>Projects</h2>
                <?php if (hasPermission('create_projects')): ?>
                    <form method="POST" id="projectForm">
                        <div class="form-group">
                            <label for="new_project_name">Project Name:</label>
                            <input type="text" id="new_project_name" name="new_project_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="project_type">Project Type:</label>
                            <select id="project_type" name="project_type" class="form-control" required>
                                <option value="Internal">Internal</option>
                                <option value="External">External</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="start_date">Start Date:</label>
                            <input type="datetime-local" id="start_date" name="start_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date:</label>
                            <input type="datetime-local" id="end_date" name="end_date" class="form-control" required>
                        </div>
                        <div id="external-project-fields" style="display: none;">
                            <div class="form-group">
                                <label for="customer_name">Customer Name:</label>
                                <input type="text" id="customer_name" name="customer_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="customer_email">Customer Email:</label>
                                <input type="email" id="customer_email" name="customer_email" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="customer_mobile">Customer Mobile:</label>
                                <input type="text" id="customer_mobile" name="customer_mobile" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="cost">Cost:</label>
                                <input type="number" id="cost" name="cost" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label for="project_manager">Project Manager:</label>
                                <input type="text" id="project_manager" name="project_manager" class="form-control">
                            </div>
                        </div>
                        <input type="hidden" name="project_id" id="project_id" value="">
                        <input type="hidden" name="action" id="project_action" value="create">
                        <input type="hidden" name="created_by_user_id" value="<?= $user_id ?>">
                        <button type="submit" class="submit-btn" id="submitProjectBtn">Create Project</button>
                    </form>
                <?php endif; ?>

                <table class="table table-striped table-hover align-middle text-center">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Project Name</th>
                            <th>Project Type</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Customer Name</th>
                            <th>Customer Email</th>
                            <th>Customer Mobile</th>
                            <th>Cost</th>
                            <th>Project Manager</th>
                            <?php if (hasPermission('edit_projects') || hasPermission('delete_projects')): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $index = ($page - 1) * $projects_per_page + 1;
                        foreach ($projects as $project): ?>
                            <tr>
                                <td><?= $index++ ?></td>
                                <td><?= htmlspecialchars($project['project_name']) ?></td>
                                <td><?= htmlspecialchars($project['project_type']) ?></td>
                                <td><?= $project['start_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($project['start_date']))) : 'N/A' ?>
                                </td>
                                <td><?= $project['end_date'] ? htmlspecialchars(date("d M Y, h:i A", strtotime($project['end_date']))) : 'N/A' ?>
                                </td>
                                <td><?= htmlspecialchars($project['status']) ?></td>
                                <td><?= $project['project_type'] === 'External' ? htmlspecialchars($project['customer_name'] ?? 'N/A') : 'N/A' ?>
                                </td>
                                <td><?= $project['project_type'] === 'External' ? htmlspecialchars($project['customer_email'] ?? 'N/A') : 'N/A' ?>
                                </td>
                                <td><?= $project['project_type'] === 'External' ? htmlspecialchars($project['customer_mobile'] ?? 'N/A') : 'N/A' ?>
                                </td>
                                <td><?= $project['project_type'] === 'External' && $project['cost'] !== null ? htmlspecialchars(number_format($project['cost'], 2)) : 'N/A' ?>
                                </td>
                                <td><?= $project['project_type'] === 'External' ? htmlspecialchars($project['project_manager'] ?? 'N/A') : 'N/A' ?>
                                </td>
                                <?php if (hasPermission('edit_projects') || hasPermission('delete_projects')): ?>
                                    <td>
                                        <?php if (hasPermission('edit_projects')): ?>
                                            <a href="#" class="action-btn edit-btn"
                                                onclick="editProject(<?= $project['id'] ?>)">Edit</a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('delete_projects')): ?>
                                            <a href="#" class="action-btn delete-btn"
                                                onclick="deleteProject(<?= $project['id'] ?>)">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($projects)): ?>
                    <div class="no-projects">No projects available.</div>
                <?php endif; ?>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?page=<?= max(1, $page - 1) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($total_pages, $page + 1) ?>"
                            class="<?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script>
        function toggleExternalFields() {
            const projectType = document.getElementById('project_type').value;
            const externalFields = document.getElementById('external-project-fields');
            externalFields.style.display = projectType === 'External' ? 'block' : 'none';
        }

        function resetProjectForm() {
            document.getElementById('new_project_name').value = '';
            document.getElementById('project_type').value = 'Internal';
            document.getElementById('start_date').value = '';
            document.getElementById('end_date').value = '';
            document.getElementById('project_id').value = '';
            document.getElementById('project_action').value = 'create';
            document.getElementById('submitProjectBtn').textContent = 'Create Project';
            document.getElementById('customer_name').value = '';
            document.getElementById('customer_email').value = '';
            document.getElementById('customer_mobile').value = '';
            document.getElementById('cost').value = '';
            document.getElementById('project_manager').value = '';
            toggleExternalFields();
        }

        function editProject(projectId) {
            fetch('get-project-details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `project_id=${encodeURIComponent(projectId)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('new_project_name').value = data.project_name || '';
                        document.getElementById('project_type').value = data.project_type || 'Internal';
                        document.getElementById('start_date').value = data.start_date ? new Date(data.start_date).toISOString().slice(0, 16) : '';
                        document.getElementById('end_date').value = data.end_date ? new Date(data.end_date).toISOString().slice(0, 16) : '';
                        document.getElementById('project_id').value = projectId;
                        document.getElementById('project_action').value = 'edit';
                        document.getElementById('submitProjectBtn').textContent = 'Update Project';
                        if (data.project_type === 'External') {
                            document.getElementById('customer_name').value = data.customer_name || '';
                            document.getElementById('customer_email').value = data.customer_email || '';
                            document.getElementById('customer_mobile').value = data.customer_mobile || '';
                            document.getElementById('cost').value = data.cost !== '' ? data.cost : '';
                            document.getElementById('project_manager').value = data.project_manager || '';
                        } else {
                            document.getElementById('customer_name').value = '';
                            document.getElementById('customer_email').value = '';
                            document.getElementById('customer_mobile').value = '';
                            document.getElementById('cost').value = '';
                            document.getElementById('project_manager').value = '';
                        }
                        toggleExternalFields();
                    } else {
                        alert(data.message || 'Failed to fetch project details.');
                    }
                })
                .catch(error => {
                    console.error('Error fetching project details:', error);
                    alert('Failed to load project details.');
                });
        }

        function deleteProject(projectId) {
            if (confirm('Are you sure you want to delete this project?')) {
                fetch('projects.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete&project_id=${encodeURIComponent(projectId)}`
                })
                    .then(response => response.text())
                    .then(text => {
                        // Response will be a script tag with alert and redirect
                        document.body.insertAdjacentHTML('beforeend', text);
                    })
                    .catch(error => {
                        console.error('Error deleting project:', error);
                        alert('Error deleting project.');
                    });
            }
        }

        document.getElementById('project_type').addEventListener('change', toggleExternalFields);

        document.getElementById('projectForm').addEventListener('submit', function (event) {
            const submitBtn = document.getElementById('submitProjectBtn');
            submitBtn.disabled = true;
            setTimeout(() => submitBtn.disabled = false, 2000); // Re-enable after 2 seconds
        });

        document.addEventListener('DOMContentLoaded', function () {
            resetProjectForm();
            const now = new Date();
            const minDateTime = now.toISOString().slice(0, 16);
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            startDateInput.min = minDateTime;
            endDateInput.min = minDateTime;

            startDateInput.addEventListener('change', function () {
                endDateInput.min = this.value;
                if (endDateInput.value && new Date(endDateInput.value) < new Date(this.value)) {
                    endDateInput.value = this.value;
                }
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>