<?php
require 'permissions.php';

$taskId = $_GET['task_id'];

// Enable error reporting for debugging (optional, remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Fetch task timeline history including details
$timelineHistoryQuery = $conn->prepare("
    SELECT tt.action, tt.previous_status, tt.new_status, tt.changed_timestamp, tt.details, u.username AS changed_by
    FROM task_timeline tt
    JOIN users u ON tt.changed_by_user_id = u.id
    WHERE tt.task_id = ?
    ORDER BY tt.changed_timestamp ASC
");
$timelineHistoryQuery->bind_param("i", $taskId);
$timelineHistoryQuery->execute();
$timelineHistoryResult = $timelineHistoryQuery->get_result();
$timelineHistory = $timelineHistoryResult->fetch_all(MYSQLI_ASSOC);


// Process timeline history to format details
$processedHistory = []; // Use a new array to avoid reference issues
foreach ($timelineHistory as $entry) {
    $formattedDetails = '(No details available)';
    if (!empty($entry['details']) && $entry['details'] !== null) {
        $details = json_decode($entry['details'], true);
        if ($details !== null && is_array($details)) {
            if ($entry['action'] === 'task_reassigned' && isset($details['reassigned_to_username'])) {
                $formattedDetails = "Reassigned to: " . htmlspecialchars($details['reassigned_to_username']);
            }
        } else {
            $formattedDetails = "Invalid JSON: " . htmlspecialchars($entry['details']);
        }
    }
    $entry['formatted_details'] = $formattedDetails;
    $processedHistory[] = $entry; // Add to new array
}
$timelineHistory = $processedHistory; // Replace original array
$conn->close();
?>

<div class="container mt-4">
    <h2>Task Timeline</h2>
    <p><strong>Task Name:</strong> <?= htmlspecialchars($taskCreationRow['task_name'] ?? 'N/A') ?></p>
    <p><strong>Created By:</strong> <?= htmlspecialchars($taskCreationRow['created_by'] ?? 'N/A') ?></p>
    <p><strong>Created On:</strong> <?= htmlspecialchars($taskCreationRow['recorded_timestamp'] ?? 'N/A') ?></p>

    <h3>Timeline History</h3>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Action</th>
                <th>Previous Status</th>
                <th>New Status</th>
                <th>Changed By</th>
                <th>Timestamp</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($timelineHistory as $entry): ?>
                <tr>
                    <td><?= htmlspecialchars($entry['action'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($entry['previous_status'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($entry['new_status'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($entry['changed_by'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($entry['changed_timestamp'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($entry['formatted_details']) ?></td> <!-- Ensure escaping here too -->
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>