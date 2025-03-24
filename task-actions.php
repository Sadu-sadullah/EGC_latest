<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'permissions.php';

session_start();

if (isset($_COOKIE['user_timezone'])) {
    $userTimeZone = $_COOKIE['user_timezone'];
    date_default_timezone_set($userTimeZone);
} else {
    date_default_timezone_set('UTC');
}

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

$config = include '../config.php';

$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$checkStmt = $conn->prepare("SELECT session_token FROM users WHERE id = ?");
$checkStmt->bind_param("i", $_SESSION['user_id']);
$checkStmt->execute();
$sessionToken = $checkStmt->get_result()->fetch_assoc()['session_token'];

if ($sessionToken !== $_SESSION['session_token']) {
    session_unset();
    session_destroy();
    echo "<script>alert('Another person has logged in using the same account. Please try logging in again.'); window.location.href='portal-login.html';</script>";
}

$conn->query("SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

$departments = $conn->query("SELECT id, name FROM departments")->fetch_all(MYSQLI_ASSOC);
$projectQuery = $conn->query("SELECT id, project_name FROM projects");
if ($projectQuery) {
    $projects = $projectQuery->fetch_all(MYSQLI_ASSOC);
} else {
    die("Error fetching projects: " . $conn->error);
}

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
    $loggedInRole = $userDetails['role'];
    $hasMultipleDepartments = count(explode(', ', $loggedInDepartment)) > 1;
} else {
    $loggedInUsername = "Unknown";
    $loggedInDepartment = "Unknown";
    $loggedInRole = "Unknown";
    $hasMultipleDepartments = false;
}

if (hasPermission('view_all_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.status,
            tasks.assigned_by_id,
            assigned_to_user.username AS assigned_to,
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department
        FROM tasks 
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN projects ON tasks.project_id = projects.id
        GROUP BY tasks.task_id
        ORDER BY tasks.recorded_timestamp DESC
    ";
} elseif (hasPermission('view_department_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.status,
            tasks.assigned_by_id,
            assigned_to_user.username AS assigned_to,
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department
        FROM tasks 
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN projects ON tasks.project_id = projects.id
        WHERE assigned_to_ud.department_id IN (SELECT department_id FROM user_departments WHERE user_id = ?)
        GROUP BY tasks.task_id
        ORDER BY tasks.recorded_timestamp DESC
    ";
} elseif (hasPermission('view_own_tasks')) {
    $taskQuery = "
        SELECT 
            tasks.task_id,
            projects.project_name,
            tasks.task_name,
            tasks.status,
            tasks.assigned_by_id,
            assigned_to_user.username AS assigned_to,
            GROUP_CONCAT(DISTINCT assigned_to_department.name SEPARATOR ', ') AS assigned_to_department
        FROM tasks 
        JOIN users AS assigned_to_user ON tasks.user_id = assigned_to_user.id 
        JOIN user_departments AS assigned_to_ud ON assigned_to_user.id = assigned_to_ud.user_id
        JOIN departments AS assigned_to_department ON assigned_to_ud.department_id = assigned_to_department.id
        JOIN projects ON tasks.project_id = projects.id
        WHERE tasks.user_id = ?
        GROUP BY tasks.task_id
        ORDER BY tasks.recorded_timestamp DESC
    ";
} else {
    $taskQuery = "SELECT NULL";
}

$stmt = $conn->prepare($taskQuery);
if (hasPermission('view_department_tasks') || hasPermission('view_own_tasks')) {
    $stmt->bind_param("i", $user_id);
}
$stmt->execute();
$result = $stmt->get_result();
$allTasks = $result->fetch_all(MYSQLI_ASSOC);

$pendingStartedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned', 'Cancelled']);
});

$completedTasks = array_filter($allTasks, function ($task) {
    return in_array($task['status'], ['Completed on Time', 'Delayed Completion', 'Closed']);
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Actions</title>
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
        }

        * {
            box-sizing: border-box;
        }

        .task-container {
            width: 100%;
            max-width: 1400px;
            margin: 25px auto;
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #002c5f;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .filter-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-buttons {
            margin-bottom: 15px;
            text-align: center;
        }

        .filter-buttons .btn {
            margin: 5px;
            padding: 10px 20px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .filter-buttons .btn:hover {
            background-color: #004080;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .filter-dropdown {
            margin-bottom: 15px;
            flex: 1 1 300px;
            max-width: 100%;
        }

        .filter-dropdown label {
            font-weight: bold;
            color: #333;
            display: block;
            margin-bottom: 5px;
        }

        .filter-dropdown select,
        .filter-dropdown input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-date {
            display: flex;
            gap: 10px;
            align-items: center;
            flex: 1 1 300px;
            max-width: 100%;
        }

        .filter-date .filter-dropdown {
            margin-bottom: 0;
            flex: 1 1 150px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination a {
            margin: 0 5px;
            padding: 5px 10px;
            text-decoration: none;
            color: #002c5f;
            border: 1px solid #002c5f;
            border-radius: 5px;
        }

        .pagination a.active {
            background-color: #002c5f;
            color: white;
        }

        .pagination a:hover {
            background-color: #004080;
            color: white;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: #002c5f;
            color: white;
            padding: 20px;
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
        }

        .navbar {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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

        .status-filter-container {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-filter-container label {
            font-weight: bold;
            color: #333;
            margin-bottom: 0;
        }

        #status-filter {
            width: 300px;
        }

        .button-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: stretch;
        }

        .button-container .btn {
            width: 100%;
            text-align: center;
            padding: 0.375rem 0.75rem;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .edit-button {
            background-color: #457b9d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            transition: background-color 0.3s ease;
        }

        .edit-button:hover {
            background-color: #1d3557;
        }

        .delete-button {
            background-color: #e63946;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .delete-button:hover {
            background-color: #d62828;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h3>TMS</h3>
            <a href="tasks.php">Tasks</a>
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
                    <p class="mb-0">Departments:
                        <strong><?= htmlspecialchars($loggedInDepartment ?? 'Unknown') ?></strong>
                    </p>
                </div>
                <button class="back-btn" onclick="window.location.href='welcome.php'">Dashboard</button>
            </div>

            <div class="task-container">
                <h2>Task Actions</h2>
                <div class="container mt-4">
                    <div class="filter-container">
                        <div class="filter-buttons">
                            <button onclick="resetFilters()" class="btn btn-primary">Reset</button>
                        </div>
                        <div class="filter-row">
                            <div class="filter-dropdown">
                                <label for="project-filter">Filter by Project:</label>
                                <select id="project-filter" multiple="multiple">
                                    <option value="All">All</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?= htmlspecialchars($project['project_name']) ?>">
                                            <?= htmlspecialchars($project['project_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (hasPermission('filter_tasks') || $hasMultipleDepartments): ?>
                                <div class="filter-dropdown">
                                    <label for="department-filter">Filter by Department of Assigned User:</label>
                                    <select id="department-filter" multiple="multiple">
                                        <option value="All">All</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?= htmlspecialchars($department['name']) ?>">
                                                <?= htmlspecialchars($department['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif ?>
                            <div class="filter-date">
                                <div class="filter-dropdown">
                                    <label for="start-date">Start Date:</label>
                                    <input type="date" id="start-date">
                                </div>
                                <div class="filter-dropdown">
                                    <label for="end-date">End Date:</label>
                                    <input type="date" id="end-date">
                                </div>
                            </div>
                        </div>
                    </div>

                    <h3>Tasks In Progress</h3>
                    <div class="status-filter-container">
                        <label for="status-filter">Filter by Status:</label>
                        <select id="status-filter" multiple="multiple">
                            <option value="All">All</option>
                            <option value="Assigned" selected>Assigned</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Hold">Hold</option>
                            <option value="Reinstated">Reinstated</option>
                            <option value="Reassigned">Reassigned</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <table class="table table-striped table-hover align-middle text-center" id="pending-tasks">
                        <thead>
                            <tr class="align-middle">
                                <th>#</th>
                                <th>Project Name</th>
                                <th>Task Name</th>
                                <th>Assigned To</th>
                                <?php if ((hasPermission('update_tasks') && in_array($user_id, array_column($pendingStartedTasks, 'assigned_by_id'))) || hasPermission('update_tasks_all')): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $taskCountStart = 1;
                            foreach ($pendingStartedTasks as $row): ?>
                                <tr class="align-middle" data-task-id="<?= htmlspecialchars($row['task_id']) ?>"
                                    data-status="<?= htmlspecialchars($row['status']) ?>">
                                    <td><?= $taskCountStart++ ?></td>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <td><?= htmlspecialchars($row['task_name']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_to']) ?>
                                        (<?= htmlspecialchars($row['assigned_to_department']) ?>)</td>
                                    <?php if ((hasPermission('update_tasks') && $row['assigned_by_id'] == $user_id) || hasPermission('update_tasks_all')): ?>
                                        <td>
                                            <div class="button-container">
                                                <a href="edit-tasks.php?id=<?= $row['task_id'] ?>"
                                                    class="edit-button btn">Edit</a>
                                                <button type="button" class="delete-button btn" data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal<?= $row['task_id'] ?>">Delete</button>
                                                <a href="#" class="btn btn-secondary view-timeline-btn"
                                                    data-task-id="<?= $row['task_id'] ?>">View Timeline</a>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>

                                <div class="modal fade" id="deleteModal<?= $row['task_id'] ?>" tabindex="-1"
                                    aria-labelledby="deleteModalLabel<?= $row['task_id'] ?>" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="deleteModalLabel<?= $row['task_id'] ?>">Delete
                                                    Task</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                    aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>Are you sure you want to delete the task
                                                    "<strong><?= htmlspecialchars($row['task_name']) ?></strong>"?</p>
                                                <form id="deleteForm<?= $row['task_id'] ?>" method="POST"
                                                    action="delete-task.php">
                                                    <input type="hidden" name="task_id" value="<?= $row['task_id'] ?>">
                                                    <div class="mb-3">
                                                        <label for="reason<?= $row['task_id'] ?>" class="form-label">Reason
                                                            for Deletion</label>
                                                        <textarea class="form-control" id="reason<?= $row['task_id'] ?>"
                                                            name="reason" rows="3" required></textarea>
                                                    </div>
                                                </form>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary"
                                                    data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger"
                                                    form="deleteForm<?= $row['task_id'] ?>">Delete</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-data-alert-pending" class="alert alert-warning mt-3" style="display: none;">No data to
                        be displayed.</div>

                    <h3>Completed Tasks</h3>
                    <table class="table table-striped table-hover align-middle text-center" id="remaining-tasks">
                        <thead>
                            <tr class="align-middle">
                                <th>#</th>
                                <th>Project Name</th>
                                <th>Task Name</th>
                                <th>Assigned To</th>
                                <?php if ((hasPermission('update_tasks') && in_array($user_id, array_column($completedTasks, 'assigned_by_id'))) || hasPermission('update_tasks_all')): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $taskCountStart = 1;
                            foreach ($completedTasks as $row): ?>
                                <tr class="align-middle" data-task-id="<?= htmlspecialchars($row['task_id']) ?>"
                                    data-status="<?= htmlspecialchars($row['status']) ?>">
                                    <td><?= $taskCountStart++ ?></td>
                                    <td><?= htmlspecialchars($row['project_name']) ?></td>
                                    <td><?= htmlspecialchars($row['task_name']) ?></td>
                                    <td><?= htmlspecialchars($row['assigned_to']) ?>
                                        (<?= htmlspecialchars($row['assigned_to_department']) ?>)</td>
                                    <?php if ((hasPermission('update_tasks') && $row['assigned_by_id'] == $user_id) || hasPermission('update_tasks_all')): ?>
                                        <td>
                                            <div class="button-container">
                                                <a href="#" class="btn btn-secondary view-timeline-btn"
                                                    data-task-id="<?= $row['task_id'] ?>">View Timeline</a>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="no-data-alert-completed" class="alert alert-warning mt-3" style="display: none;">No data to
                        be displayed.</div>
                    <div class="pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="taskTimelineModal" tabindex="-1" aria-labelledby="taskTimelineModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskTimelineModalLabel">Task Timeline</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="task-timeline-content"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function () {
            const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            document.cookie = "user_timezone=" + userTimeZone;
            const tasksPerPage = 10;
            let currentPage = 1;

            $('#project-filter').select2({ placeholder: "Select projects to filter", allowClear: true, width: '300px' });
            $('#department-filter').select2({ placeholder: "Select departments to filter", allowClear: true, width: '300px' });
            $('#status-filter').select2({ placeholder: "Select statuses to filter", allowClear: true, width: '300px' });

            function applyFilters() {
                const selectedProjects = $('#project-filter').val() || [];
                const selectedDepartments = $('#department-filter').val() || [];
                const startDate = $('#start-date').val();
                const endDate = $('#end-date').val();

                const pendingVisibleRows = filterAndPaginateTable('#pending-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);
                const completedVisibleRows = filterAndPaginateTable('#remaining-tasks', selectedProjects, selectedDepartments, startDate, endDate, currentPage);
                updatePagination(pendingVisibleRows, completedVisibleRows);
            }

            function filterAndPaginateTable(tableId, selectedProjects, selectedDepartments, startDate, endDate, currentPage) {
                const rows = $(`${tableId} tbody tr`);
                let visibleRows = [];
                const selectedStatuses = tableId === '#pending-tasks' ? ($('#status-filter').val() || []) : [];

                rows.each(function () {
                    const projectName = $(this).find('td:nth-child(2)').text().trim();
                    const departmentName = $(this).find('td:nth-child(4)').text().trim().match(/\(([^)]+)\)/)?.[1] || '';
                    const taskStatus = $(this).attr('data-status') || ''; // Read from data-status attribute

                    let dateInRange = true; // Simplified for this page; adjust if date columns are added back
                    if (startDate && endDate) {
                        // Add date filtering logic if date columns are added back in the future
                    }

                    const projectMatch = selectedProjects.length === 0 || selectedProjects.includes('All') || selectedProjects.includes(projectName);
                    const departmentMatch = selectedDepartments.length === 0 || selectedDepartments.includes('All') || departmentName.split(', ').some(dept => selectedDepartments.includes(dept));
                    const statusMatch = tableId !== '#pending-tasks' || selectedStatuses.length === 0 || selectedStatuses.includes('All') || selectedStatuses.includes(taskStatus);

                    if (projectMatch && departmentMatch && statusMatch && dateInRange) {
                        visibleRows.push(this);
                    }
                });

                rows.hide();
                const startIndex = (currentPage - 1) * tasksPerPage;
                const endIndex = startIndex + tasksPerPage;
                const rowsToShow = visibleRows.slice(startIndex, endIndex);

                if (rowsToShow.length > 0) {
                    rowsToShow.forEach((row, index) => {
                        $(row).find('td:first-child').text(startIndex + index + 1);
                        $(row).show();
                    });
                }

                const noDataAlert = $(`${tableId} + .alert`);
                if (visibleRows.length === 0 || rowsToShow.length === 0) {
                    noDataAlert.show();
                } else {
                    noDataAlert.hide();
                }

                return visibleRows.length;
            }
            function resetTaskNumbering(tableId) {
                const rows = $(`${tableId} tbody tr`);
                rows.each(function (index) {
                    $(this).find('td:first-child').text(index + 1);
                });
            }

            $('#status-filter').on('change', function () {
                currentPage = 1;
                applyFilters();
            });

            function updatePagination(pendingVisibleRows, completedVisibleRows) {
                const totalPages = Math.max(Math.ceil(pendingVisibleRows / tasksPerPage), Math.ceil(completedVisibleRows / tasksPerPage));
                const pagination = $('.pagination');
                pagination.empty();

                if (currentPage > 1) {
                    pagination.append(`<a href="#" class="page-link" data-page="${currentPage - 1}">Previous</a>`);
                }

                for (let i = 1; i <= totalPages; i++) {
                    pagination.append(`<a href="#" class="page-link ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</a>`);
                }

                if (currentPage < totalPages) {
                    pagination.append(`<a href="#" class="page-link" data-page="${currentPage + 1}">Next</a>`);
                }
            }

            $(document).on('click', '.page-link', function (e) {
                e.preventDefault();
                currentPage = parseInt($(this).data('page'));
                applyFilters();
            });

            $('#project-filter, #department-filter, #start-date, #end-date').on('change', function () {
                currentPage = 1;
                applyFilters();
            });

            function resetFilters() {
                $('#project-filter').val(null).trigger('change');
                $('#department-filter').val(null).trigger('change');
                $('#start-date').val('');
                $('#end-date').val('');
                $('#status-filter').val(['Assigned']).trigger('change');
                currentPage = 1;
                resetTaskNumbering('#pending-tasks');
                resetTaskNumbering('#remaining-tasks');
                $('#no-data-alert-pending').hide();
                $('#no-data-alert-completed').hide();
                applyFilters();
            }

            $('.btn-primary[onclick="resetFilters()"]').on('click', resetFilters);
            applyFilters();

            $(document).on('click', '.view-timeline-btn', function (e) {
                e.preventDefault(); // Prevent the default anchor behavior (scrolling to top)
                const taskId = $(this).data('task-id');
                $.ajax({
                    url: 'fetch-task-timeline.php',
                    type: 'GET',
                    data: { task_id: taskId },
                    success: function (response) {
                        $('#task-timeline-content').html(response);
                        $('#taskTimelineModal').modal('show');
                    },
                    error: function (error) {
                        console.error('Error fetching task timeline:', error);
                        $('#task-timeline-content').html('<p>Error loading task timeline.</p>');
                        $('#taskTimelineModal').modal('show');
                    }
                });
            });

            const deleteButtons = document.querySelectorAll('.delete-button');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const targetModalId = button.getAttribute('data-bs-target');
                    const targetModal = document.querySelector(targetModalId);
                    if (targetModal) {
                        const modal = new bootstrap.Modal(targetModal);
                        modal.show();
                    } else {
                        console.error(`Modal not found: ${targetModalId}`);
                    }
                });
            });
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>