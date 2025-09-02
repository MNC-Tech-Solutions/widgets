<?php

// Logging function
function logMessage($message) {
    $logFile = 'post_data.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Get raw POST data
$input = file_get_contents('php://input');
logMessage("Raw POST data received: " . $input);

// Parse JSON data
$post_data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("Failed to parse POST data: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit;
}

// Log parsed POST data
logMessage("Parsed POST data: " . print_r($post_data, true));

// Send success response
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'POST data received and logged',
    'data' => $post_data
]);

?>