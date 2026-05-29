<?php
// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load configuration (minimal changes start here)
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    die('Config file not found: ' . $configFile);
}
$configData = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die('Invalid config file format');
}

// Hardcode the company ID here (change 'armani' to 'bhp' or another if needed)
$companyId = 'forum';
if (!isset($configData['companies'][$companyId])) {
    die("Invalid hardcoded company ID: $companyId");
}
$config = $configData['companies'][$companyId];

// Dynamically define constants from config (replaces your hardcoded const declarations)
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_FILE', LOG_DIR . '/' . $config['LOG_FILE']);
define('DATA_FILE', LOG_DIR . '/' . $config['DATA_FILE']);
define('CALL_DETAILS_FILE', LOG_DIR . '/' . $config['CALL_DETAILS_FILE']);
define('AUTH_TOKEN', $config['AUTH_TOKEN']);
define('WEBHOOK_URL', $config['WEBHOOK_URL']);
define('TWILIO_API_BASE', $config['TWILIO_API_BASE']);
define('SJ360_WEBHOOK_URL', $config['SJ360_WEBHOOK_URL']);
define('LOCATION_ID', $config['LOCATION_ID']);
define('ACCESS_TOKEN', $config['ACCESS_TOKEN']);

// Ensure log directory exists
if (!file_exists(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Log message function
function logMessage(string $message): void {
    $logData = sprintf("Timestamp: %s (UTC+08)\n%s\n----------------------------------------\n", 
        date('Y-m-d H:i:s'), $message);
    file_put_contents(LOG_FILE, $logData, FILE_APPEND);
}

// Validate Twilio signature
function validateSignature(string $authToken, string $url, array $postData, ?string $signature): bool {
    if (empty($signature)) return false;
    ksort($postData);
    $data = $url;
    foreach ($postData as $key => $value) {
        $data .= $key . $value;
    }
    $computedHash = base64_encode(hash_hmac('sha1', $data, $authToken, true));
    return hash_equals($computedHash, $signature);
}

// Twilio
// Fetch call details from Twilio API using cURL
function fetchCallDetails(string $accountSid, string $callSid): ?array {
    $apiUrl = TWILIO_API_BASE . "/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}.json";
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:" . AUTH_TOKEN);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        logMessage("Call Details: " . print_r($data, true));
        return $data;
    }
    logMessage("Failed to fetch call details. HTTP Code: $httpCode");
    return null;
}

// Fetch recordings from Twilio API using cURL
function fetchRecordings(string $recUrl, string $accountSid, string $authToken): ?array {
    if (strpos($recUrl, 'https://') !== 0) {
        $recUrl = TWILIO_API_BASE . $recUrl;
    }
    $ch = curl_init($recUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:" . $authToken);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        logMessage("Recordings: " . print_r($data, true));
        return $data;
    }
    logMessage("Failed to fetch recordings. HTTP Code: $httpCode");
    return null;
}

// Fetch call events from Twilio API using cURL with retry
function fetchCallEvents(string $accountSid, string $callSid): ?array {
    $apiUrl = TWILIO_API_BASE . "/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}/Events.json?PageSize=50";
    $maxRetries = 3;
    $retryDelay = 10; // seconds
    $attempt = 0;
    
    do {
        $attempt++;
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:" . AUTH_TOKEN);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            logMessage("Call Events (Attempt $attempt): " . print_r($data, true));
            // Check if call is completed and has dial events
            $hasCompletedDial = false;
            foreach ($data['events'] as $event) {
                if (isset($event['request']['parameters']['dial_call_status']) && 
                    $event['request']['parameters']['dial_call_status'] === 'completed') {
                    $hasCompletedDial = true;
                    break;
                }
            }
            if ($hasCompletedDial || $attempt >= $maxRetries) {
                return $data;
            }
            logMessage("No completed dial event found. Retrying in $retryDelay seconds...");
            sleep($retryDelay);
        } else {
            logMessage("Failed to fetch call events. HTTP Code: $httpCode, Attempt: $attempt");
            if ($attempt >= $maxRetries) {
                return null;
            }
            sleep($retryDelay);
        }
    } while ($attempt < $maxRetries);
    
    logMessage("Max retries ($maxRetries) reached for call events fetch.");
    return null;
}

// GHL
// fetch ghl user
function fetchLeadConnectorUsers(string $locationId, string $accessToken): ?array {
    $apiUrl = "https://services.leadconnectorhq.com/users/?locationId={$locationId}";
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Version: 2021-07-28" 
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        // logMessage("LeadConnector Users: " . print_r($data, true));
        return $data['users'] ?? null;
    }
    logMessage("Failed to fetch LeadConnector users. HTTP Code: $httpCode");
    return null;
}

// Parse call events to extract answer point data
function parseCallEvents(array $events): array {
    $answerPoints = [];
    $dialAttempts = []; // Store dialed numbers in order
    $answeredBy = null;
    $answeredByStatus = null;

    // First pass: Collect all dialed numbers in order from TwiML responses
    foreach ($events['events'] as $event) {
        $responseBody = $event['response']['response_body'] ?? '';
        if (preg_match('/<Number>([^<]+)<\/Number>/', $responseBody, $matches)) {
            $dialedNumber = $matches[1];
            // Initialize with default values
            $dialAttempts[] = [
                'number' => $dialedNumber,
                'call_sid' => null,
                'status' => null,
                'bridged' => null
            ];
        }
    }

    // Second pass: Match outcomes to dialed numbers
    foreach ($events['events'] as $event) {
        $parameters = $event['request']['parameters'] ?? [];
        if (isset($parameters['dial_call_sid'])) {
            $dialCallSid = $parameters['dial_call_sid'];
            $dialCallStatus = $parameters['dial_call_status'] ?? null;
            $dialBridged = $parameters['dial_bridged'] ?? null;

            // Find the first unprocessed dial attempt to assign the outcome
            for ($i = 0; $i < count($dialAttempts); $i++) {
                if ($dialAttempts[$i]['call_sid'] === null) {
                    $dialAttempts[$i]['call_sid'] = $dialCallSid;
                    $dialAttempts[$i]['status'] = $dialCallStatus;
                    $dialAttempts[$i]['bridged'] = $dialBridged;
                    
                    // Check for answered call
                    if ($dialCallStatus === 'completed' && $dialBridged === 'true') {
                        $answeredBy = $dialAttempts[$i]['number'];
                        $answeredByStatus = $dialCallStatus;
                    }
                    break; // Move to next event after assigning
                }
            }
        }
    }

    // If no call was answered, use the last dial attempt
    if ($answeredBy === null && !empty($dialAttempts)) {
        $lastAttempt = end($dialAttempts);
        $answeredBy = $lastAttempt['number'];
        $answeredByStatus = $lastAttempt['status'] ?? 'no-answer';
    }

    // Use the ordered dial attempts as answerPoints
    $answerPoints = $dialAttempts;

    return [
        'answerPoints' => $answerPoints,
        'answeredBy' => $answeredBy,
        'answeredByStatus' => $answeredByStatus
    ];
}

// Send combined data to SJ360 webhook
function sendToSJ360Webhook(array $data): bool {
    $ch = curl_init(SJ360_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300) {
        logMessage("Successfully sent to SJ360 webhook. Response: " . $response);
        return true;
    }
    logMessage("Failed to send to SJ360 webhook. HTTP Code: $httpCode, Response: " . $response);
    return false;
}

// Initialize variables
$errorMessage = null;
$accountSid = null;
$callSid = null;
$callDetails = null;
$debugInfo = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Webhook handling
    $postData = $_POST;
    $headers = getallheaders();
    $signature = $headers['X-Twilio-Signature'] ?? null;

    // Debug: Capture headers and body
    $debugInfo = ['headers' => $headers, 'body' => $postData];
    logMessage("Headers: " . print_r($headers, true) . "\nBody: " . print_r($postData, true));

    // Validate signature
    if (!validateSignature(AUTH_TOKEN, WEBHOOK_URL, $postData, $signature)) {
        $errorMessage = empty($signature) ? "Missing Twilio signature." : "Invalid Twilio signature.";
        http_response_code(403);
        logMessage("Error: $errorMessage");
    } else {
        $accountSid = trim($postData['AccountSid'] ?? '');
        $callSid = trim($postData['CallSid'] ?? '');

        if (empty($accountSid) || empty($callSid)) {
            $errorMessage = "Missing required data: " . (empty($accountSid) ? "AccountSid" : "") . 
                (empty($callSid) ? " CallSid" : "");
            http_response_code(400);
            logMessage("Error: $errorMessage");
        } else {
            // Save AccountSid and CallSid
            $dataToSave = sprintf("Timestamp: %s (UTC+08)\nAccountSid: %s\nCallSid: %s\n----------------------------------------\n", 
                date('Y-m-d H:i:s'), $accountSid, $callSid);
            file_put_contents(DATA_FILE, $dataToSave, FILE_APPEND);

            // Log for debugging CallSid mismatch
            logMessage("Processing CallSid from POST: $callSid");

            // Fetch call details
            $callDetails = fetchCallDetails($accountSid, $callSid);
            if ($callDetails) {
                // Initial 30-second delay to allow recordings and events to be available
                logMessage("Waiting 30 seconds before fetching recordings and events...");
                sleep(30);

                // Fetch recordings
                $recordingsUri = $callDetails['subresource_uris']['recordings'] ?? null;
                $recordings = $recordingsUri ? fetchRecordings($recordingsUri, $accountSid, AUTH_TOKEN) : null;

                // Fetch call events
                $callEvents = fetchCallEvents($accountSid, $callSid);
                $answerPointData = $callEvents ? parseCallEvents($callEvents) : [
                    'answerPoints' => [],
                    'answeredBy' => null,
                    'answeredByStatus' => null
                ];

                // fetch user id from ghl and match answeredBy phone number
                $answeredByUserId = null;
                if($answerPointData['answeredBy']) {
                    $users = fetchLeadConnectorUsers(LOCATION_ID, ACCESS_TOKEN); 
                    if ($users) {
                        foreach ($users as $user) {
                            $userPhone = $user['phone'] ?? null;
                            if ($userPhone && $userPhone === $answerPointData['answeredBy']) {
                                $answeredByUserId = $user['id'] ?? null;
                                logMessage("Matched answeredBy phone number with user ID: $answeredByUserId");
                                break;
                            }
                        }
                        if (!$answeredByUserId) {
                            logMessage("No user found matching answeredBy phone: {$answerPointData['answeredBy']}");
                        }
                    } else {
                        logMessage("Failed to retrieve users from LeadConnector API.");
                    }
                } else {
                    logMessage("No users found in LeadConnector.");
                }

                // Save final recordings and answer point data
                $dataToSave = [
                    'callDetails' => $callDetails,
                    'recordings' => $recordings,
                    'answerPointData' => $answerPointData,
                    'answeredByUserId' => $answeredByUserId
                ];
                file_put_contents(CALL_DETAILS_FILE, json_encode($dataToSave));

                // Combine and send data to SJ360 webhook
                $recordingMediaUrls = '';
                if ($recordings && isset($recordings['recordings']) && count($recordings['recordings']) > 0) {
                    $urls = array_map(function($rec) {
                        return $rec['media_url'] ?? '';
                    }, $recordings['recordings']); // Take all available recordings
                    $recordingMediaUrls = implode(',', array_filter($urls)); // Combine with comma, filter out empty
                    logMessage("Recordings found. Number of recordings: " . count($recordings['recordings']));
                } else {
                    logMessage("No recordings found.");
                }

                $combinedData = [
                    'postData' => $postData,
                    'recordingMediaUrls' => $recordingMediaUrls,
                    'answerPoints' => $answerPointData['answerPoints'],
                    'answeredBy' => $answerPointData['answeredBy'],
                    'answeredByUserId' => $answeredByUserId,
                    'status' => $answerPointData['answeredByStatus']
                ];
                logMessage("Sending combined data to SJ360 webhook: " . json_encode($combinedData));
                sendToSJ360Webhook($combinedData);
            } else {
                $errorMessage = "Failed to fetch call details from Twilio API.";
                http_response_code(500);
            }
        }
    }

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $errorMessage ? 'error' : 'success',
        'message' => $errorMessage ?: 'Data received and saved successfully',
        'data' => ['accountSid' => $accountSid, 'callSid' => $callSid],
        'callDetails' => $callDetails,
        'debug' => $debugInfo
    ]);
    exit;
}

// For GET requests, load the latest call details from the file
$latestCallDetails = file_exists(CALL_DETAILS_FILE) ? json_decode(file_get_contents(CALL_DETAILS_FILE), true) : null;
?>