<?php
require '../config.php';

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $projectId = $_POST['project_id'] ?? null;

        if ($projectId) {
            $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $project = $result->fetch_assoc();
                echo json_encode(['success' => true, 'project_name' => $project['project_name']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Project not found.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid project ID.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching project details: ' . $e->getMessage()]);
    }
}
?>