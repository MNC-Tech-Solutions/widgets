<?php
// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Constants
const LOG_DIR = __DIR__ . '/logs';
const LOG_FILE = LOG_DIR . '/twilio_log.txt';
const DATA_FILE = LOG_DIR . '/twilio_data.txt';
const CALL_DETAILS_FILE = LOG_DIR . '/latest_call_details.json';
const AUTH_TOKEN = '1c46b5593e219a0ddbc1f6a315adc5e0'; // Replace with env variable in production
const WEBHOOK_URL = 'https://salesjourney360.com/widget/twilio/twilio_webhook.php';
const TWILIO_API_BASE = 'https://api.twilio.com';
const SJ360_WEBHOOK_URL = 'https://services.leadconnectorhq.com/hooks/WphrMU0x3Ocd2pEpBJcH/webhook-trigger/ws0VbykKVbhPnH8K9AH2';

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
        logMessage("LeadConnector Users: " . print_r($data, true));
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
                    $users = fetchLeadConnectorUsers('WphrMU0x3Ocd2pEpBJcH', 'pit-43dd0be6-ab49-42f6-aace-d7c0746adc19'); 
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Twilio Webhook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { padding: 20px; }
        pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mt-4">Twilio Webhook</h1>
        <p>This page displays call details, recordings, and answer point data fetched from the Twilio API after receiving a webhook request.</p>

        <div id="errorMessage" class="alert alert-danger d-none mt-3" role="alert"></div>

        <h2 class="mt-4">Call Details</h2>
        <table id="callDetails" class="table table-bordered">
            <thead>
                <tr>
                    <th>Call SID</th>
                    <th>Account SID</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Start Time</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <h2 class="mt-4">Recordings</h2>
        <table id="recordings" class="table table-bordered">
            <thead>
                <tr>
                    <th>Recording SID</th>
                    <th>Duration (s)</th>
                    <th>Status</th>
                    <th>Date Created</th>
                    <th>Media URL</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <h2 class="mt-4">Answer Points</h2>
        <table id="answerPoints" class="table table-bordered">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Call SID</th>
                    <th>Status</th>
                    <th>Bridged</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <h2 class="mt-4">Answered By</h2>
        <p id="answeredBy">Loading...</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check for error message from PHP
            const errorMessage = <?php echo json_encode($errorMessage); ?>;
            if (errorMessage) {
                const errorDiv = document.getElementById('errorMessage');
                errorDiv.textContent = errorMessage;
                errorDiv.classList.remove('d-none');
            }

            // Get call details, recordings, and answer point data from PHP
            const latestCallDetails = <?php echo json_encode($latestCallDetails ?? null); ?>;
            if (latestCallDetails) {
                // Populate call details table
                const callDetails = latestCallDetails.callDetails;
                if (callDetails) {
                    const tableBody = document.querySelector('#callDetails tbody');
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${callDetails.sid || ''}</td>
                        <td>${callDetails.account_sid || ''}</td>
                        <td>${callDetails.from || ''}</td>
                        <td>${callDetails.to || ''}</td>
                        <td>${callDetails.status || ''}</td>
                        <td>${callDetails.duration || ''}</td>
                        <td>${callDetails.start_time || ''}</td>
                    `;
                    tableBody.appendChild(row);
                }

                // Populate recordings table
                const recordings = latestCallDetails.recordings?.recordings || [];
                if (recordings.length > 0) {
                    const recordingsTableBody = document.querySelector('#recordings tbody');
                    recordings.forEach(rec => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${rec.sid || ''}</td>
                            <td>${rec.duration || ''}</td>
                            <td>${rec.status || ''}</td>
                            <td>${rec.date_created || ''}</td>
                            <td><a href="${rec.media_url || ''}" target="_blank">${rec.media_url ? 'View Media' : ''}</a></td>
                        `;
                        recordingsTableBody.appendChild(row);
                    });
                }

                // Populate answer points table
                const answerPoints = latestCallDetails.answerPointData?.answerPoints || [];
                if (answerPoints.length > 0) {
                    const answerPointsTableBody = document.querySelector('#answerPoints tbody');
                    answerPoints.forEach(point => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${point.number || ''}</td>
                            <td>${point.call_sid || ''}</td>
                            <td>${point.status || ''}</td>
                            <td>${point.bridged || ''}</td>
                        `;
                        answerPointsTableBody.appendChild(row);
                    });
                }

                // Populate answered by
                const answeredBy = latestCallDetails.answerPointData?.answeredBy || 'Not answered';
                document.getElementById('answeredBy').textContent = answeredBy;
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>