<?php
require '../config.php';
require 'permissions.php';

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? 'create';
        $projectId = $_POST['project_id'] ?? null;
        $newProjectName = $_POST['new_project_name'] ?? '';
        $createdByUserId = $_POST['created_by_user_id'] ?? null; // Ensure this is included

        if ($action === 'create') {
            // Create new project
            $stmt = $conn->prepare("INSERT INTO projects (project_name, created_by_user_id) VALUES (?, ?)");
            $stmt->bind_param("si", $newProjectName, $createdByUserId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Project created successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error creating project: ' . $stmt->error]);
            }
        } elseif ($action === 'edit') {
            // Edit existing project
            $stmt = $conn->prepare("UPDATE projects SET project_name = ? WHERE id = ?");
            $stmt->bind_param("si", $newProjectName, $projectId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Project updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating project: ' . $stmt->error]);
            }
        } elseif ($action === 'delete') {
            // Delete existing project
            $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->bind_param("i", $projectId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Project deleted successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting project: ' . $stmt->error]);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error processing request: ' . $e->getMessage()]);
    }
}
?>