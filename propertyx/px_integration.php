<?php
/**
 * KLANGGROUP BRIDGE SCRIPT - UPDATED WITH FINAL PAYLOAD LOGGING
 */

// --- CONFIGURATION ---
$telegramToken  = '8784179877:AAFJHa6d_LZIl0XmltS_7YbEsD5socMb6DE'; 
$telegramChatId = '6364840867';
$mappingsFile   = __DIR__ . '/mappings.json';
$auditLog       = __DIR__ . '/logs.jsonl';
$activityLog    = __DIR__ . '/activity_log.jsonl';
$payloadLog     = __DIR__ . '/sent_payloads.jsonl';

// --- 1. CAPTURE INCOMING DATA ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed.";
    exit;
}

$inputRaw = file_get_contents('php://input');
$inputJSON = json_decode($inputRaw, true);

$captureEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip'        => $_SERVER['REMOTE_ADDR'],
    'headers'   => getallheaders(),
    'payload'   => $_POST,
    'raw_body'  => $inputJSON 
];
file_put_contents($auditLog, json_encode($captureEntry) . PHP_EOL, FILE_APPEND);

// --- 2. HELPERS ---

function normalize($string) {
    $clean = str_replace(['@', '(', ')', '-', '/', '\\'], '', $string); 
    return strtolower(trim(preg_replace('/\s+/', ' ', $clean)));
}

function sendAlert($message) {
    global $telegramToken, $telegramChatId;
    $text = "🚨 *Lead System Alert* 🚨\n\n" . $message;
    $url = "https://api.telegram.org/bot$telegramToken/sendMessage?chat_id=$telegramChatId&text=" . urlencode($text) . "&parse_mode=Markdown";
    @file_get_contents($url);
}

function terminateWithError($msg, $httpCode = 400, $extra = []) {
    global $activityLog;
    $logData = ['timestamp' => date('Y-m-d H:i:s'), 'status' => 'FAILED', 'message' => $msg, 'details' => $extra];
    file_put_contents($activityLog, json_encode($logData) . PHP_EOL, FILE_APPEND);
    
    sendAlert("❌ *FATAL ERROR*\n" . $msg . "\n\n*Project:* " . ($extra['project_input'] ?? 'None'));
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => $msg]);
    exit;
}

// --- 3. VALIDATION & PROCESSING ---

$raw = isset($inputJSON['raw_body']) ? $inputJSON['raw_body'] : $inputJSON;

if (!$raw || empty($raw)) {
    terminateWithError("Empty payload received.");
}

if (!file_exists($mappingsFile)) {
    terminateWithError("Server Error: mappings.json missing.");
}
$mappings = json_decode(file_get_contents($mappingsFile), true);

// Project Validation
$inputProject = $raw['(Contact) Interested Project'] ?? 'Unknown';
$normalizedInput = normalize($inputProject);
$projectId = null;

foreach ($mappings['projects'] as $name => $id) {
    if (normalize($name) === $normalizedInput) {
        $projectId = $id;
        break;
    }
}

if (!$projectId) {
    terminateWithError("Project Match Failed.", 400, [
        'project_input' => $inputProject,
        'lead' => $raw['full_name'] ?? 'Unknown'
    ]);
}

// Sales Person Validation
$fName = $raw['user']['firstName'] ?? '';
$lName = $raw['user']['lastName'] ?? '';
$searchNames = [normalize($fName . " " . $lName), normalize($lName . " " . $fName), normalize($fName)];

$salesPersonId = null;
foreach ($mappings['sales_persons'] as $mapName => $id) {
    if (in_array(normalize($mapName), $searchNames)) {
        $salesPersonId = $id;
        break;
    }
}

// Lead Source Mapping
$leadSourceId = null;
$inputLeadSource = $raw['(Contact) PX Lead Source'] ?? null;
if ($inputLeadSource && strtoupper(trim($inputLeadSource)) === 'AI') {
    $leadSourceId = $mappings['lead_source']['ai'] ?? null;
}

// --- 4. FORWARD TO PROPERTY-X ---

$cleanPhone = str_replace(['+', ' ', '-'], '', $raw['phone'] ?? '');
$apiPayload = [
    "fullName"  => $raw['full_name'] ?? 'No Name',
    "phoneNo"   => $cleanPhone,
    "email"     => $raw['email'] ?? '',
    "projectId" => $projectId,
    "accountId" => $mappings['account_settings']['default_account_id']
];

if ($salesPersonId) { $apiPayload["salesPersonId"] = $salesPersonId; }
if ($leadSourceId) { $apiPayload["leadSourceId"] = $leadSourceId; }

// --- NEW: LOG THE FINAL PAYLOAD HERE ---
$finalLogEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint'  => "https://sales-api.property-x.asia/api/Microsite",
    'sent_data' => $apiPayload
];
file_put_contents($payloadLog, json_encode($finalLogEntry) . PHP_EOL, FILE_APPEND);


$ch = curl_init("https://sales-api.property-x.asia/api/Microsite");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 5. FINAL LOGGING ---

if ($httpCode >= 200 && $httpCode < 300) {
    $successLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'SUCCESS',
        'lead' => $apiPayload['fullName'],
        'api_response' => json_decode($response, true)
    ];
    file_put_contents($activityLog, json_encode($successLog) . PHP_EOL, FILE_APPEND);
    echo json_encode(["status" => "success", "message" => "Forwarded."]);
} else {
    terminateWithError("API Rejected Lead", $httpCode, [
        'response' => $response, 
        'payload' => $apiPayload,
        'project_input' => $inputProject
    ]);
}