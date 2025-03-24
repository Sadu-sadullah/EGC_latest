<?php
require '../config.php';
require 'permissions.php';

session_start();

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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    // Log all POST data for debugging
    error_log("manage-project.php - Received POST data: " . json_encode($_POST));

    $action = $_POST['action'] ?? 'create';
    // Try to get projectId from 'project_id' first, then fall back to 'existing_projects'
    $projectId = isset($_POST['project_id']) && !empty($_POST['project_id']) ? (int) $_POST['project_id'] : null;
    if ($projectId === null && isset($_POST['existing_projects']) && !empty($_POST['existing_projects'])) {
        $projectId = (int) $_POST['existing_projects'];
    }
    $newProjectName = trim($_POST['new_project_name'] ?? '');
    $createdByUserId = isset($_POST['created_by_user_id']) ? (int) $_POST['created_by_user_id'] : null;
    $projectType = trim($_POST['project_type'] ?? 'Internal');
    $customerName = $projectType === 'External' ? trim($_POST['customer_name'] ?? '') : null;
    $customerEmail = $projectType === 'External' ? trim($_POST['customer_email'] ?? '') : null;
    $customerMobile = $projectType === 'External' ? trim($_POST['customer_mobile'] ?? '') : null;
    $cost = $projectType === 'External' && !empty($_POST['cost']) ? (float) $_POST['cost'] : null;
    $projectManager = $projectType === 'External' ? trim($_POST['project_manager'] ?? '') : null;

    error_log("manage-project.php - Action: $action, Project ID: " . var_export($projectId, true));

    if ($action === 'check_tasks') {
        if ($projectId === null || $projectId === 0) {
            echo json_encode(['success' => false, 'message' => 'No project selected for task check.']);
            exit;
        }

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
        if (!in_array($projectType, ['Internal', 'External'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid project type.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO projects (project_name, project_type, created_by_user_id, customer_name, customer_email, customer_mobile, cost, project_manager) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssisssds", $newProjectName, $projectType, $createdByUserId, $customerName, $customerEmail, $customerMobile, $cost, $projectManager);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Project created successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating project: ' . $stmt->error]);
        }
        $stmt->close();
    } elseif ($action === 'edit') {
        if ($projectId === null || $projectId === 0) {
            error_log("Edit failed - No valid project_id received");
            echo json_encode(['success' => false, 'message' => 'No project selected for editing.']);
            exit;
        }
        if (empty($newProjectName)) {
            echo json_encode(['success' => false, 'message' => 'Project name is required.']);
            exit;
        }
        if (!in_array($projectType, ['Internal', 'External'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid project type.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE projects SET project_name = ?, project_type = ?, customer_name = ?, customer_email = ?, customer_mobile = ?, cost = ?, project_manager = ? WHERE id = ?");
        $stmt->bind_param("sssssdsi", $newProjectName, $projectType, $customerName, $customerEmail, $customerMobile, $cost, $projectManager, $projectId);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Project updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'No changes made to the project or project not found.']);
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

$conn->close();
?>