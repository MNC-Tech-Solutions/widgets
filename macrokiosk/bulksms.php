<?php
// Capture the raw POST data from GoHighLevel
$rawData = file_get_contents('php://input');

// Decode the JSON into a PHP array
$data = json_decode($rawData, true);

// Create a log entry with a timestamp
$logEntry = "[" . date("Y-m-d H:i:s") . "] Incoming GHL Data: " . $rawData . PHP_EOL;

// Save to a local file called log.txt
file_put_contents('log.txt', $logEntry, FILE_APPEND);

// Respond back to GHL so it knows the delivery was successful
http_response_code(200);
echo json_encode(["status" => "success", "message" => "Data received"]);
?>