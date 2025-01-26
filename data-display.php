<?php
// Start output buffering to prevent output before headers
ob_start();

// Start session
session_start();

// Check if the user is not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // Redirect to login page if not logged in
    header("Location: portal-login.html");
    exit;
}

// Retrieve the username, role, and department from the session
$username = $_SESSION['username'] ?? 'Unknown'; // Fallback to 'Unknown' if not set
$department = $_SESSION['department'] ?? 'Unknown'; // Fallback to 'Unknown' if not set

// Session timeout (Optional)
$timeout_duration = 1200;

// Check if 'last_activity' is set and if it has exceeded the timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // If the session is expired, destroy it and redirect to login page
    session_unset();
    session_destroy();
    header("Location: portal-login.html");
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_contact_form_db';

// Establish database connection
$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch distinct services and countries
$queryServices = "SELECT DISTINCT services FROM contact_form_submissions";
$queryCountries = "SELECT DISTINCT country FROM contact_form_submissions";

$resultServices = $conn->query($queryServices);
$resultCountries = $conn->query($queryCountries);

$services = [];
$countries = [];

if ($resultServices && $resultServices->num_rows > 0) {
    while ($row = $resultServices->fetch_assoc()) {
        if (isset($row['services'])) {
            $services[] = $row['services'];
        }
    }
}

if ($resultCountries && $resultCountries->num_rows > 0) {
    while ($row = $resultCountries->fetch_assoc()) {
        if (isset($row['country'])) {
            $countries[] = $row['country'];
        }
    }
}

// Get filter values from GET request
$selectedService = isset($_GET['service']) ? $_GET['service'] : '';
$selectedCountry = isset($_GET['country']) ? $_GET['country'] : '';

// Handle lead quality update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_quality']) && isset($_POST['id'])) {
    $leadQuality = $_POST['lead_quality'];
    $id = $_POST['id'];

    // Prepare and bind the update statement
    $updateQuery = "UPDATE contact_form_submissions SET lead_quality = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("si", $leadQuality, $id);

    if ($updateStmt->execute()) {
        // Update successful
        // Refresh the page to show updated data
        header("Location: " . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    } else {
        // Update failed
        echo '<script>alert("Failed to update lead quality.");</script>';
    }

    $updateStmt->close();
}

// Build SQL query based on filters
$query = "SELECT * FROM contact_form_submissions";
$firstCondition = true;

if (!empty($selectedService)) {
    if ($firstCondition) {
        $query .= " WHERE";
        $firstCondition = false;
    } else {
        $query .= " AND";
    }
    $query .= " services LIKE ?";
}

if (!empty($selectedCountry)) {
    if ($firstCondition) {
        $query .= " WHERE";
    } else {
        $query .= " AND";
    }
    $query .= " country = ?";
}

$query .= " ORDER BY id DESC";

// Prepare and bind
$stmt = $conn->prepare($query);
$params = [];
$types = '';

if (!empty($selectedService)) {
    $selectedService = '%' . $selectedService . '%'; // Use LIKE for partial matching
    $types .= 's';
    $params[] = &$selectedService;
}

if (!empty($selectedCountry)) {
    $types .= 's';
    $params[] = &$selectedCountry;
}

if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Export data to CSV (Triggered via GET request)
if (isset($_GET['export'])) {
    // Start output buffering to send the CSV file
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="data_export.csv"');

    // Open PHP output stream for writing the CSV
    $output = fopen('php://output', 'w');

    // Output column headers for the CSV
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Dial Code', 'Phone', 'Country', 'Email', 'Submitted At', 'Services', 'Message', 'Lead Quality']);

    // Loop through and output data rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['first_name'],
            $row['last_name'],
            $row['dial_code'],
            $row['phone'],
            $row['country'],
            $row['email'],
            $row['submitted_at'],
            $row['services'],
            $row['message'],
            $row['lead_quality']
        ]);
    }

    // Close the output stream
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Display</title>
    <link rel="icon" type="image/png" sizes="56x56" href="images/logo/logo-2.1.ico" />
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }

        .table-container {
            width: 100%;
            max-width: 1300px;
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

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table,
        th,
        td {
            border: 1px solid #ccc;
        }

        th,
        td {
            width: auto;
            padding: 10px;
            border-bottom: 1px solid #ccc;
            text-align: left;
            background-color: #ffffff;
            /* Ensure each cell has a white background */
        }

        th {
            background-color: #002c5f;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .no-data {
            text-align: center;
            color: #888;
            padding: 20px;
        }

        .container {
            width: 100%;
        }

        .filter-form {
            margin-bottom: 20px;
        }

        .filter-form select {
            padding: 8px;
            margin-right: 10px;
        }

        .filter-form button {
            padding: 8px 16px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .filter-form button:hover {
            background-color: #001a3d;
        }

        .lead-quality-select {
            padding: 5px;
            width: 100%;
        }

        .update-button {
            padding: 5px 10px;
            background-color: #002c5f;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .update-button:hover {
            background-color: #001a3d;
        }

        .export-button {
            text-align: center;
            margin-top: 20px;
        }

        .export-button a {
            padding: 8px 16px;
            background-color: #003366;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .export-button a:hover {
            background-color: #00264d;
        }

        .user-info {
            max-width: 1300px;
            text-align: center;
            margin: 25px auto;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .user-info p {
            margin: 5px 0;
            font-size: 16px;
            color: #333;
        }

        .user-info .session-warning {
            color: grey;
            /* Red color for warning */
            font-weight: bold;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="user-info">
        <p>Logged in as: <strong><?= htmlspecialchars($username) ?></strong> | Department:
            <strong><?= htmlspecialchars($department) ?></strong>
        </p>
        <p class="session-warning">Information: Your session will timeout after 20 minutes of inactivity.</p>
    </div>

    <div class="table-container">
        <!-- Logout Button -->
        <div class="logout-button">
            <a href="welcome.php">Back</a>
        </div>

        <!-- Filter Form -->
        <div class="filter-form">
            <form method="GET" action="">
                <label for="service">Service:</label>
                <select id="service" name="service">
                    <option value="">All Services</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?php echo htmlspecialchars($service, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($selectedService === $service)
                                 echo ' selected'; ?>>
                            <?php echo htmlspecialchars($service, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="country">Country:</label>
                <select id="country" name="country">
                    <option value="">All Countries</option>
                    <?php foreach ($countries as $country): ?>
                        <option value="<?php echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($selectedCountry === $country)
                                 echo ' selected'; ?>>
                            <?php echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Filter</button>
            </form>
        </div>

        <!-- Export Button -->
        <div class="export-button">
            <a
                href="?export=true<?php echo $selectedService ? '&service=' . urlencode($selectedService) : ''; ?><?php echo $selectedCountry ? '&country=' . urlencode($selectedCountry) : ''; ?>">Export
                Data (CSV)</a>
        </div>

        <h2>Data Records</h2>

        <?php
        if ($result->num_rows > 0) {
            echo '<table>';
            echo '<tr>';
            echo '<th>ID</th>';
            echo '<th>First Name</th>';
            echo '<th>Last Name</th>';
            echo '<th>Dial Code</th>';
            echo '<th>Phone</th>';
            echo '<th>Country</th>';
            echo '<th>Email</th>';
            echo '<th>Submitted At</th>';
            echo '<th>Services</th>';
            echo '<th>Message</th>';
            echo '<th>Lead Quality</th>';
            echo '</tr>';

            $counter = 1;

            // Loop through and display data rows
            while ($row = $result->fetch_assoc()) {
                $id = htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8');
                $firstName = htmlspecialchars($row['first_name'] ?? '', ENT_QUOTES, 'UTF-8');
                $lastName = htmlspecialchars($row['last_name'] ?? '', ENT_QUOTES, 'UTF-8');
                $dialCode = htmlspecialchars($row['dial_code'] ?? '', ENT_QUOTES, 'UTF-8');
                $phone = htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8');
                $country = htmlspecialchars($row['country'] ?? '', ENT_QUOTES, 'UTF-8');
                $email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
                $submittedAt = htmlspecialchars($row['submitted_at'] ?? '', ENT_QUOTES, 'UTF-8');
                $services = htmlspecialchars($row['services'] ?? '', ENT_QUOTES, 'UTF-8');
                $message = htmlspecialchars($row['message'] ?? '', ENT_QUOTES, 'UTF-8');
                $leadQuality = htmlspecialchars($row['lead_quality'] ?? '', ENT_QUOTES, 'UTF-8');

                echo '<tr>';
                echo '<td>' . $counter . '</td>'; // Incremented number for display
                echo '<td>' . $firstName . '</td>';
                echo '<td>' . $lastName . '</td>';
                echo '<td>' . $dialCode . '</td>';
                echo '<td>' . $phone . '</td>';
                echo '<td>' . $country . '</td>';
                echo '<td>' . $email . '</td>';
                echo '<td>' . $submittedAt . '</td>';
                echo '<td>' . $services . '</td>';
                echo '<td>' . $message . '</td>';
                echo '<td>';
                echo '<form method="POST" action="">';
                echo '<select name="lead_quality" class="lead-quality-select">';
                echo '<option value="">Select Quality</option>';
                echo '<option value="High"' . ($leadQuality === 'High' ? ' selected' : '') . '>High</option>';
                echo '<option value="Medium"' . ($leadQuality === 'Medium' ? ' selected' : '') . '>Medium</option>';
                echo '<option value="Low"' . ($leadQuality === 'Low' ? ' selected' : '') . '>Low</option>';
                echo '</select>';
                echo '<input type="hidden" name="id" value="' . $id . '">';
                echo '<button type="submit" name="update_lead_quality" value="' . $id . '" class="update-button">Update</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';

                $counter++;
            }

            echo '</table>';
        } else {
            echo '<p class="no-data">No data found.</p>';
        }

        // Close statement and connection
        $stmt->close();
        $conn->close();
        ?>
    </div>

</body>

</html>

<?php
// End output buffering and send output to the browser
ob_end_flush();
?>