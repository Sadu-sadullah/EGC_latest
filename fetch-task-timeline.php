<?php
require 'permissions.php';

$taskId = $_GET['task_id'];

$conn = new mysqli('localhost', $config['dbUsername'], $config['dbPassword'], 'new');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch task creation details
$taskCreationQuery = $conn->prepare("
    SELECT t.task_id, t.task_name, t.recorded_timestamp, u.username AS created_by
    FROM tasks t
    JOIN users u ON t.assigned_by_id = u.id
    WHERE t.task_id = ?
");
$taskCreationQuery->bind_param("i", $taskId);
$taskCreationQuery->execute();
$taskCreationResult = $taskCreationQuery->get_result();
$taskCreationRow = $taskCreationResult->fetch_assoc();

// Fetch task timeline history
$timelineHistoryQuery = $conn->prepare("
    SELECT tt.action, tt.previous_status, tt.new_status, tt.changed_timestamp, u.username AS changed_by
    FROM task_timeline tt
    JOIN users u ON tt.changed_by_user_id = u.id
    WHERE tt.task_id = ?
    ORDER BY tt.changed_timestamp
");
$timelineHistoryQuery->bind_param("i", $taskId);
$timelineHistoryQuery->execute();
$timelineHistoryResult = $timelineHistoryQuery->get_result();
$timelineHistory = $timelineHistoryResult->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<div class="container mt-4">
    <h2>Task Timeline</h2>
    <p><strong>Task Name:</strong> <?= htmlspecialchars($taskCreationRow['task_name']) ?></p>
    <p><strong>Created By:</strong> <?= htmlspecialchars($taskCreationRow['created_by']) ?></p>
    <p><strong>Created On:</strong> <?= htmlspecialchars($taskCreationRow['recorded_timestamp']) ?></p>

    <h3>Timeline History</h3>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Action</th>
                <th>Previous Status</th>
                <th>New Status</th>
                <th>Changed By</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($timelineHistory as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['action']) ?></td>
                    <td><?= htmlspecialchars($entry['previous_status']) ?></td>
                    <td><?= htmlspecialchars($entry['new_status']) ?></td>
                    <td><?= htmlspecialchars($entry['changed_by']) ?></td>
                    <td><?= htmlspecialchars($entry['changed_timestamp']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>