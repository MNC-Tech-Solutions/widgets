<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load configuration
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) die('Config file not found');
$configData = json_decode(file_get_contents($configFile), true);
$companyId = 'suezcap_1776';
$config = $configData['companies'][$companyId];

// Constants
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

if (!file_exists(LOG_DIR)) mkdir(LOG_DIR, 0755, true);

function logMessage(string $message): void {
    $logData = sprintf("Timestamp: %s (UTC+08)\n%s\n----------------------------------------\n", date('Y-m-d H:i:s'), $message);
    file_put_contents(LOG_FILE, $logData, FILE_APPEND);
}

// Helper: Fetch Twilio Data
function twilioRequest($url, $accountSid) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:" . AUTH_TOKEN);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200) ? json_decode($res, true) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postData = $_POST;
    $accountSid = $postData['AccountSid'] ?? '';
    $callSid = $postData['CallSid'] ?? '';

    // --- CRITICAL STEP: RESPOND TO TWILIO IMMEDIATELY ---
    // This stops the 502/15003 Timeout error
    if (function_exists('fastcgi_finish_request')) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received']);
        session_write_close();
        fastcgi_finish_request(); // The script keeps running, but Twilio is disconnected
    }

    // Now we can take our time (the background process starts here)
    logMessage("Processing background task for CallSid: $callSid");

    // 1. Fetch Call Details
    $detailsUrl = TWILIO_API_BASE . "/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}.json";
    $callDetails = twilioRequest($detailsUrl, $accountSid);

    if ($callDetails) {
        // Wait a small amount of time for Twilio to finalize metadata (not 30s!)
        sleep(5); 

        // 2. Fetch Recordings
        $recUrl = TWILIO_API_BASE . ($callDetails['subresource_uris']['recordings'] ?? "");
        $recordings = twilioRequest($recUrl, $accountSid);
        
        // 3. Fetch Events
        $eventsUrl = TWILIO_API_BASE . "/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}/Events.json";
        $callEvents = twilioRequest($eventsUrl, $accountSid);

        // 4. Extract Answer Info (simplified for clarity)
        $answeredBy = null;
        if ($callEvents && isset($callEvents['events'])) {
            foreach ($callEvents['events'] as $e) {
                if (($e['request']['parameters']['dial_bridged'] ?? '') === 'true') {
                    // Logic to find the number from the TwiML response in events
                    if (preg_match('/<Number>([^<]+)<\/Number>/', $e['response']['response_body'] ?? '', $m)) {
                        $answeredBy = $m[1];
                    }
                }
            }
        }

        // 5. Match User in GHL
        $answeredByUserId = null;
        if ($answeredBy) {
            $ghlUrl = "https://services.leadconnectorhq.com/users/?locationId=" . LOCATION_ID;
            $ch = curl_init($ghlUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . ACCESS_TOKEN, "Version: 2021-07-28"]);
            $ghlRes = json_decode(curl_exec($ch), true);
            curl_close($ch);

            foreach (($ghlRes['users'] ?? []) as $u) {
                if (($u['phone'] ?? '') === $answeredBy) {
                    $answeredByUserId = $u['id'];
                    break;
                }
            }
        }

        // 6. Format Recording URLs
        $urls = [];
        if ($recordings && isset($recordings['recordings'])) {
            foreach ($recordings['recordings'] as $r) {
                $urls[] = "https://api.twilio.com" . str_replace(".json", ".mp3", $r['uri']);
            }
        }

        // 7. Send to SJ360
        $combinedData = [
            'postData' => $postData,
            'recordingMediaUrls' => implode(',', $urls),
            'answeredBy' => $answeredBy,
            'answeredByUserId' => $answeredByUserId,
            'status' => $postData['CallStatus'] ?? 'completed'
        ];

        $ch = curl_init(SJ360_WEBHOOK_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($combinedData));
        $sjResponse = curl_exec($ch);
        curl_close($ch);

        logMessage("SJ360 Response: " . $sjResponse);
    }
    exit;
}
?>