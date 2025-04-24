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
$selected_role_id = $_SESSION['selected_role_id'] ?? null;

if ($user_id === null || $selected_role_id === null) {
    header("Location: portal-login.html");
    exit;
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
$dbName = 'new'; // For now, using the same DB; later, switch to an archived DB

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

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

// Fetch departments and projects for filters (for now, from the main DB)
$departments = $conn->query("SELECT id, name FROM departments")->fetch_all(MYSQLI_ASSOC);
$projectQuery = $conn->query("SELECT id, project_name FROM projects");
if ($projectQuery) {
    $projects = $projectQuery->fetch_all(MYSQLI_ASSOC);
} else {
    die("Error fetching projects: " . $conn->error);
}

// Fetch user details
$userQuery = $conn->prepare("
    SELECT u.id, u.username, u.email, GROUP_CONCAT(d.name SEPARATOR ', ') AS departments, r.name AS role
    FROM users u
    JOIN user_departments ud ON u.id = ud.user_id
    JOIN departments d ON ud.department_id = d.id
    JOIN roles r ON r.id = ?
    WHERE u.id = ?
    GROUP BY u.id
");
$userQuery->bind_param("ii", $selected_role_id, $user_id);
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

// Placeholder for archived tasks (no data yet)
$pendingStartedTasks = [];
$completedTasks = [];
$pendingStatuses = ['Assigned', 'In Progress', 'Hold', 'Reinstated', 'Reassigned', 'Cancelled'];
$completedStatuses = ['Completed on Time', 'Delayed Completion', 'Closed'];
$pendingCounts = array_fill_keys($pendingStatuses, 0);
$completedCounts = array_fill_keys($completedStatuses, 0);

// Dummy earliest date for filter range (will adjust when connecting to archived DB)
$earliestDate = date("Y-m-d", strtotime("-1 year"));
$earliestDateTime = new DateTime($earliestDate);
$maxDateTime = clone $earliestDateTime;
$maxDateTime->modify('+1 year');
$minDate = $earliestDateTime->format('Y-m-d');
$maxDate = $maxDateTime->format('Y-m-d');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Tasks</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Same CSS as tasks.php */
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

        .task-container {
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

        .no-tasks {
            text-align: center;
            color: #888;
            padding: 20px;
        }

        .logout-button {
            text-align: right;
            margin-bottom: 20px;
        }

        .logout-button a {
            background-color: #002c5f;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }

        .logout-button a:hover {
            background-color: #004080;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            min-width: 1200px;
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

        .filter-buttons .btn-secondary {
            background-color: #457b9d;
        }

        .filter-buttons .btn-secondary:hover {
            background-color: #1d3557;
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

        .status-counts {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .status-box {
            background-color: #f9f9f9;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 10px 15px;
            text-align: center;
            min-width: 150px;
        }

        .status-box h4 {
            margin: 0;
            font-size: 14px;
            color: #333;
        }

        .status-box span {
            font-size: 18px;
            font-weight: bold;
            color: #002c5f;
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
            <?php if (hasPermission('task_actions')): ?>
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
                <h2>Archived Tasks</h2>
                <div class="container mt-4">
                    <div class="filter-container">
                        <div class="filter-buttons">
                            <button onclick="resetFilters()" class="btn btn-primary">Reset</button>
                            <?php if (hasPermission('export_tasks')): ?>
                                <a href="export_archived_tasks.php" class="btn btn-success">Export to CSV</a>
                            <?php endif; ?>
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
                                    <input type="date" id="start-date" min="<?= htmlspecialchars($minDate) ?>"
                                        max="<?= htmlspecialchars($maxDate) ?>">
                                </div>
                                <div class="filter-dropdown">
                                    <label for="end-date">End Date:</label>
                                    <input type="date" id="end-date" min="<?= htmlspecialchars($minDate) ?>"
                                        max="<?= htmlspecialchars($maxDate) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="status-counts">
                        <h3>Task Status Overview</h3>
                        <div style="width: 100%; text-align: center;">
                            <h4>In Progress</h4>
                        </div>
                        <?php foreach ($pendingStatuses as $status): ?>
                            <div class="status-box">
                                <h4><?= htmlspecialchars($status) ?></h4>
                                <span><?= $pendingCounts[$status] ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div style="width: 100%; text-align: center;">
                            <h4>Completed</h4>
                        </div>
                        <?php foreach ($completedStatuses as $status): ?>
                            <div class="status-box">
                                <h4><?= htmlspecialchars($status) ?></h4>
                                <span><?= $completedCounts[$status] ?></span>
                            </div>
                        <?php endforeach; ?>
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
                    <div class="alert alert-warning mt-3">No tasks available to display.</div>

                    <h3>Completed Tasks</h3>
                    <div class="alert alert-warning mt-3">No tasks available to display.</div>
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

            $('#project-filter').select2({ placeholder: "Select projects to filter", allowClear: true, width: '300px' });
            $('#department-filter').select2({ placeholder: "Select departments to filter", allowClear: true, width: '300px' });
            $('#status-filter').select2({ placeholder: "Select statuses to filter", allowClear: true, width: '300px' });

            function applyFilters() {
                // Placeholder for future filter logic when tasks are added
                const selectedProjects = $('#project-filter').val() || [];
                const selectedDepartments = $('#department-filter').val() || [];
                let startDate = $('#start-date').val();
                let endDate = $('#end-date').val();

                const minDate = $('#start-date').attr('min');
                const maxDate = $('#start-date').attr('max');

                if (startDate) {
                    const startDateObj = new Date(startDate);
                    const minDateObj = new Date(minDate);
                    const maxDateObj = new Date(maxDate);
                    if (startDateObj < minDateObj) {
                        startDate = minDate;
                        $('#start-date').val(minDate);
                    } else if (startDateObj > maxDateObj) {
                        startDate = maxDate;
                        $('#start-date').val(maxDate);
                    }
                }

                if (endDate) {
                    const endDateObj = new Date(endDate);
                    const minDateObj = new Date(minDate);
                    const maxDateObj = new Date(maxDate);
                    if (endDateObj < minDateObj) {
                        endDate = minDate;
                        $('#end-date').val(minDate);
                    } else if (endDateObj > maxDateObj) {
                        endDate = maxDate;
                        $('#end-date').val(maxDate);
                    }
                }
            }

            $('#project-filter, #department-filter, #start-date, #end-date, #status-filter').on('change', function () {
                applyFilters();
            });

            function resetFilters() {
                $('#project-filter').val(null).trigger('change');
                $('#department-filter').val(null).trigger('change');
                $('#start-date').val('');
                $('#end-date').val('');
                $('#status-filter').val(['Assigned']).trigger('change');
                applyFilters();
            }

            $('.btn-primary[onclick="resetFilters()"]').on('click', resetFilters);
            applyFilters();
        });
    </script>
</body>

</html>

<?php
// Close the connection at the very end
$conn->close();
?>