<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Prepare the data packet
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip'        => $_SERVER['REMOTE_ADDR'],
        'headers'   => getallheaders(),
        'payload'   => $_POST,
        // Also capture raw JSON if the sender uses application/json
        'raw_body'  => json_decode(file_get_contents('php://input'), true)
    ];

    // 2. Convert to JSON and add a newline for easy parsing later
    $jsonEntry = json_encode($logEntry) . PHP_EOL;

    // 3. Append to a local file (make sure this file is writable!)
    file_put_contents('logs.jsonl', $jsonEntry, FILE_APPEND);

    echo "Data captured successfully.";
} else {
    http_response_code(405);
    echo "Method Not Allowed. Please send a POST request.";
}