<?php
$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'euro_contact_form_db';

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get form data
$first_name = $_POST['first-name'];
$last_name = $_POST['last-name'];
$phone = $_POST['phone'];
$country = $_POST['country'];
$dial_code = $_POST['dialCode'];
$email = $_POST['email'];
$services = isset($_POST['services']) ? implode(", ", $_POST['services']) : '';
$message = $_POST['message'];

// Check for duplicate records (based on phone and email)
$checkQuery = "SELECT * FROM contact_form_submissions WHERE phone = ? AND email = ?";
$checkStmt = $conn->prepare($checkQuery);

// Check if preparation is successful
if (!$checkStmt) {
    die("Preparation failed: " . $conn->error);
}

// Bind parameters and execute
$checkStmt->bind_param("ss", $phone, $email);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

// If a duplicate is found, show an alert and go back to the form page
if ($checkResult->num_rows > 0) {
    echo "<script>
            alert('A record already exists with the same email address and phone number.');
            window.location.href= 'form.html'; // Redirect back to the form page
          </script>";
    $checkStmt->close();
    $conn->close();
    exit();
}

// If no duplicate is found, proceed with the insertion
$checkStmt->close();

// Prepare and bind SQL statement for insertion
$sql = "INSERT INTO contact_form_submissions (first_name, last_name, phone, country, dial_code, email, services, message) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

// Check if preparation is successful
if (!$stmt) {
    die("Preparation failed: " . $conn->error);
}

// Bind parameters
$stmt->bind_param("ssssssss", $first_name, $last_name, $phone, $country, $dial_code, $email, $services, $message);

// Execute the statement and check if it is successful
if ($stmt->execute()) {
    // Redirect to thank you page
    echo "<script>
            alert('Form submitted successfully!');
            window.location.href = 'thankyou.html'; // Redirect to 'Thank You' page
          </script>";
} else {
    echo "Error: " . $stmt->error;
}

// Close connections
$stmt->close();
$conn->close();
?>
