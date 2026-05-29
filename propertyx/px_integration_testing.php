<?php
/**
 * TESTING MODE — nothing is sent to Property-X or Telegram.
 * Logs what was received and what would have been sent.
 */

$mappingsFile = 'mappings.json';
$testLog      = 'test_log.jsonl';

// --- 1. LOG INCOMING DATA ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed. Use POST."]);
    exit;
}

$inputRaw  = file_get_contents('php://input');
$inputJSON = json_decode($inputRaw, true);

$received = [
    'timestamp' => date('Y-m-d H:i:s'),
    'event'     => 'RECEIVED',
    'ip'        => $_SERVER['REMOTE_ADDR'],
    'headers'   => getallheaders(),
    'raw_body'  => $inputJSON,
    'post'      => $_POST,
];
file_put_contents($testLog, json_encode($received) . PHP_EOL, FILE_APPEND);

// --- 2. HELPERS ---

function normalize($string) {
    $clean = str_replace(['@', '(', ')', '-', '/', '\\'], '', $string);
    return strtolower(trim(preg_replace('/\s+/', ' ', $clean)));
}

function testFail($msg, $extra = []) {
    global $testLog;
    $entry = ['timestamp' => date('Y-m-d H:i:s'), 'event' => 'VALIDATION_FAILED', 'message' => $msg, 'details' => $extra];
    file_put_contents($testLog, json_encode($entry) . PHP_EOL, FILE_APPEND);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => $msg, "details" => $extra]);
    exit;
}

// --- 3. VALIDATION & PROCESSING ---

$raw = isset($inputJSON['raw_body']) ? $inputJSON['raw_body'] : $inputJSON;

if (!$raw || empty($raw)) {
    testFail("Empty payload received.");
}

if (!file_exists($mappingsFile)) {
    testFail("Server Error: mappings.json missing.");
}
$mappings = json_decode(file_get_contents($mappingsFile), true);

// Project Validation
$inputProject    = $raw['(Contact) Interested Project'] ?? 'Unknown';
$normalizedInput = normalize($inputProject);
$projectId       = null;

foreach ($mappings['projects'] as $name => $id) {
    if (normalize($name) === $normalizedInput) {
        $projectId = $id;
        break;
    }
}

if (!$projectId) {
    testFail("Project Match Failed.", [
        'project_input'      => $inputProject,
        'available_projects' => array_keys($mappings['projects']),
        'lead'               => $raw['full_name'] ?? 'Unknown',
    ]);
}

// Sales Person Validation
$fName       = $raw['user']['firstName'] ?? '';
$lName       = $raw['user']['lastName'] ?? '';
$searchNames = [normalize("$fName $lName"), normalize("$lName $fName"), normalize($fName)];

$salesPersonId = null;
foreach ($mappings['sales_persons'] as $mapName => $id) {
    if (in_array(normalize($mapName), $searchNames)) {
        $salesPersonId = $id;
        break;
    }
}

// Lead Source Mapping
$leadSourceId    = null;
$inputLeadSource = $raw['(Contact) PX Lead Source'] ?? null;
if ($inputLeadSource && strtoupper(trim($inputLeadSource)) === 'AI') {
    $leadSourceId = $mappings['lead_source']['ai'] ?? null;
}

// --- 4. BUILD PAYLOAD (do NOT send) ---

$cleanPhone = str_replace(['+', ' ', '-'], '', $raw['phone'] ?? '');
$apiPayload = [
    "fullName"  => $raw['full_name'] ?? 'No Name',
    "phoneNo"   => $cleanPhone,
    "email"     => $raw['email'] ?? '',
    "projectId" => $projectId,
    "accountId" => $mappings['account_settings']['default_account_id'],
];

if ($salesPersonId) { $apiPayload["salesPersonId"] = $salesPersonId; }
if ($leadSourceId)  { $apiPayload["leadSourceId"]  = $leadSourceId; }

// --- 5. LOG WHAT WOULD HAVE BEEN SENT ---

$wouldSend = [
    'timestamp'            => date('Y-m-d H:i:s'),
    'event'                => 'WOULD_SEND',
    'endpoint'             => 'https://sales-api.property-x.asia/api/Microsite',
    'payload'              => $apiPayload,
    'sales_person_input'   => trim("$fName $lName"),
    'sales_person_matched' => $salesPersonId !== null,
    'lead_source_input'    => $inputLeadSource,
    'lead_source_mapped'   => $leadSourceId !== null,
];
file_put_contents($testLog, json_encode($wouldSend) . PHP_EOL, FILE_APPEND);

// --- 6. RETURN MOCK RESPONSE ---

header('Content-Type: application/json');
echo json_encode([
    "status"     => "test_ok",
    "message"    => "TEST MODE — nothing was sent to Property-X or Telegram.",
    "received"   => $received['raw_body'],
    "would_send" => [
        "endpoint" => $wouldSend['endpoint'],
        "payload"  => $apiPayload,
    ],
]);