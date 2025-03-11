<?php
require '../config.php';
require 'permissions.php';

session_start(); // Add session_start() to align with tasks.php and permissions

$config = include '../config.php';

// Database connection
$dbHost = 'localhost';
$dbUsername = $config['dbUsername'];
$dbPassword = $config['dbPassword'];
$dbName = 'new';

$conn = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

header('Content-Type: application/json'); // Ensure JSON response for all outputs

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? 'create';
        $projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : null;
        $newProjectName = trim($_POST['new_project_name'] ?? '');
        $createdByUserId = isset($_POST['created_by_user_id']) ? (int) $_POST['created_by_user_id'] : null;

        if ($action === 'check_tasks') {
            if ($projectId === null || $projectId === 0) {
                echo json_encode(['success' => false, 'message' => 'No project selected for task check.']);
                exit;
            }

            // Check if there are tasks associated with the project
            $stmt = $conn->prepare("SELECT COUNT(*) as task_count FROM tasks WHERE project_id = ?");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $taskCount = (int) $row['task_count'];

            echo json_encode([
                'success' => true,
                'task_count' => $taskCount
            ]);
            $stmt->close();
            exit;
        }

        if ($action === 'create') {
            if (empty($newProjectName)) {
                echo json_encode(['success' => false, 'message' => 'Project name is required.']);
                exit;
            }
            if ($createdByUserId === null) {
                echo json_encode(['success' => false, 'message' => 'Creator user ID is required.']);
                exit;
            }

            // Create new project
            $stmt = $conn->prepare("INSERT INTO projects (project_name, created_by_user_id) VALUES (?, ?)");
            $stmt->bind_param("si", $newProjectName, $createdByUserId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Project created successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error creating project: ' . $stmt->error]);
            }
            $stmt->close();
        } elseif ($action === 'edit') {
            if ($projectId === null || $projectId === 0) {
                echo json_encode(['success' => false, 'message' => 'No project selected for editing.']);
                exit;
            }
            if (empty($newProjectName)) {
                echo json_encode(['success' => false, 'message' => 'Project name is required.']);
                exit;
            }

            // Debug: Log the incoming data
            error_log("Edit Project - ID: $projectId, New Name: $newProjectName");

            // Edit existing project
            $stmt = $conn->prepare("UPDATE projects SET project_name = ? WHERE id = ?");
            $stmt->bind_param("si", $newProjectName, $projectId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode(['success' => true, 'message' => 'Project updated successfully.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'No changes made to the project name or project not found.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating project: ' . $stmt->error]);
            }
            $stmt->close();
        } elseif ($action === 'delete') {
            if ($projectId === null || $projectId === 0) {
                echo json_encode(['success' => false, 'message' => 'No project selected for deletion.']);
                exit;
            }

            // Check for associated tasks before deletion
            $stmt = $conn->prepare("SELECT COUNT(*) as task_count FROM tasks WHERE project_id = ?");
            $stmt->bind_param("i", $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $taskCount = (int) $row['task_count'];
            $stmt->close();

            if ($taskCount > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete project. It has $taskCount associated task(s). Please delete these tasks first."
                ]);
                exit;
            }

            // Delete existing project
            $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->bind_param("i", $projectId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Project deleted successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting project: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error processing request: ' . $e->getMessage()]);
    }
}

$conn->close();
?>