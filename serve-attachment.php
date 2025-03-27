<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Unauthorized access.';
    exit;
}

// Get the filename from the query parameter
$filename = $_GET['file'] ?? '';
if (!$filename) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'No file specified.';
    exit;
}

// Define the uploads directory
$uploadDir = realpath(__DIR__ . '/uploads/');
$filePath = $uploadDir . '/' . basename($filename); // Use basename to prevent directory traversal

// Verify the file exists and is within the uploads directory
if (file_exists($filePath) && strpos($filePath, $uploadDir) === 0) {
    // Set appropriate headers
    $mimeType = mime_content_type($filePath);
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"'); // "inline" to view, "attachment" to force download
    header('Content-Length: ' . filesize($filePath));

    // Serve the file
    readfile($filePath);
    exit;
} else {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'File not found.';
    exit;
}
?>