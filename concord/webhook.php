<?php
// ============================================================
// GHL → Engage Admissions Middleware
// Single-file PHP webhook
// ============================================================

// ---------- CONFIG (edit these) ----------
define('GHL_API_KEY',       getenv('GHL_API_KEY')       ?: 'pit-06ccdb86-8c8b-4f96-a4d9-c2e68d77d926');
define('GHL_OBJECT_ID',     getenv('GHL_OBJECT_ID')     ?: '674d6d99a0e71e8acb3535bc');
define('ENGAGE_BASE_URL',   getenv('ENGAGE_BASE_URL')   ?: 'https://api.concordcollege.onengagecloud.com');
define('ENGAGE_USERNAME',   getenv('ENGAGE_USERNAME')   ?: 'chloe.ooi@mnctechsolutions.com');
define('ENGAGE_PASSWORD',   getenv('ENGAGE_PASSWORD')   ?: 'Cciss@2026');
define('ENGAGE_SCHOOL_ID',  getenv('ENGAGE_SCHOOL_ID')  ?: 'Sandbox');
// -----------------------------------------

// Token cache file (sits next to this file)
define('TOKEN_CACHE_FILE', __DIR__ . '/token_cache.json');

// Log folder
define('LOG_DIR', __DIR__ . '/logs');

// ============================================================
// LOGGER
// ============================================================
function logMsg(string $level, string $message): void {
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    $date     = date('Y-m-d');
    $time     = date('Y-m-d H:i:s');
    $line     = "[$time] [$level] $message" . PHP_EOL;
    $logFile  = LOG_DIR . "/$date.log";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    // Also echo to stdout (visible in cPanel error log)
    echo $line;
}

function logInfo(string $msg)    { logMsg('INFO ', $msg); }
function logOk(string $msg)      { logMsg('OK   ', $msg); }
function logWarn(string $msg)    { logMsg('WARN ', $msg); }
function logError(string $msg)   { logMsg('ERROR', $msg); }

// ============================================================
// HTTP HELPER with RETRY
// ============================================================
function httpRequest(string $method, string $url, array $headers = [], $body = null, string $label = 'request'): array {
    $delays = [1, 3, 7];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // 1. FIX FOR GHL: Handle GZIP/Compression (prevents scrambled text)
        curl_setopt($ch, CURLOPT_ENCODING, ''); 

        // 2. FIX FOR ENGAGE: Bypass 403 by simulating Postman
        $defaultHeaders = [
            'User-Agent: PostmanRuntime/7.32.3',
            'Accept: */*',
            'Connection: keep-alive',
        ];

        // Merge custom headers into defaults
        $headerLines = $defaultHeaders;
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        // Optional: Bypass SSL issues if your server's certs are old
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            logWarn("[$label] Attempt $attempt/3 curl error: $curlError");
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'code' => $httpCode, 'body' => $response];
        } else {
            // Log the body so we can see EXACTLY why Engage or GHL rejected it
            logWarn("[$label] Attempt $attempt/3 HTTP $httpCode: $response");
        }

        if ($attempt < 3) {
            $wait = $delays[$attempt - 1];
            logInfo("[$label] Retrying in {$wait}s...");
            sleep($wait);
        }
    }

    logError("[$label] All 3 attempts failed");
    return ['ok' => false, 'code' => 0, 'body' => ''];
}

// ============================================================
// ENGAGE AUTH - token cached in file
// ============================================================
function getEngageToken(): ?string {
    // Check cache
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        // Use 'access_token' here to match what we save below
        if ($cache && isset($cache['access_token'], $cache['expires_at']) && time() < $cache['expires_at']) {
            logInfo('[Engage] Using cached token');
            return $cache['access_token'];
        }
    }

    logInfo('[Engage] Fetching new token...');
    $body = http_build_query([
        'username' => ENGAGE_USERNAME,
        'password' => ENGAGE_PASSWORD,
    ]);

    $result = httpRequest('POST', ENGAGE_BASE_URL . '/api/gettoken', [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], $body, 'getToken');

    if (!$result['ok']) {
        logError('[Engage] Failed to get token');
        return null;
    }

    $data = json_decode($result['body'], true);
    
    // --- FIX HERE: Engage returns 'access_token', not 'token' ---
    $token = $data['access_token'] ?? null;

    if (!$token) {
        logError('[Engage] Token missing from response: ' . $result['body']);
        return null;
    }

    // Cache for 50 minutes
    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'access_token' => $token,
        'expires_at'   => time() + (50 * 60),
    ]));

    logOk('[Engage] Token obtained and cached');
    return $token;
}

// ============================================================
// GHL - FETCH STUDENTS
// ============================================================
function fetchStudents(string $locationId, string $contactId): array {
    logInfo("[GHL] Fetching students for contact: $contactId");

    $body = json_encode([
        'locationId' => $locationId,
        'page'       => 1,
        'pageLimit'  => 100,
        'filters'    => [[
            'field'    => 'relations',
            'operator' => 'nested',
            'value'    => [[
                'field'    => 'recordId',
                'operator' => 'eq',
                'value'    => $contactId,
            ]],
        ]],
    ]);

    $result = httpRequest('POST',
        'https://services.leadconnectorhq.com/objects/' . GHL_OBJECT_ID . '/records/search',
        [
            'Authorization' => 'Bearer ' . GHL_API_KEY,
            'Version'       => '2021-07-28',
            'Content-Type'  => 'application/json',
        ],
        $body,
        'fetchStudents'
    );

    if (!$result['ok']) {
        logError('[GHL] Failed to fetch students');
        return [];
    }

    $data    = json_decode($result['body'], true);
    $records = $data['records'] ?? [];
    logOk('[GHL] Found ' . count($records) . ' student(s)');
    return $records;
}

// ============================================================
// ENGAGE - CREATE PUPIL
// ============================================================
function createPupil(string $token, array $pupilData): ?string {
    logInfo("[Engage] Creating pupil: {$pupilData['forename']} {$pupilData['surname']} | DOB: {$pupilData['dob']}");

    $body = json_encode([
        'titleid'       => 3012,
        'forename'      => $pupilData['forename'],
        'surname'       => $pupilData['surname'],
        'middleName'    => '',
        'genderid'      => 20003,
        'dob'           => $pupilData['dob'],
        'firstlanguage' => 2902,
        'allowPhoto'    => true,
    ]);

    $result = httpRequest('POST',
        ENGAGE_BASE_URL . '/api/v1/admissions/addprpupilbasic/' . ENGAGE_SCHOOL_ID,
        [
            'Authorization' => $token,
            'Content-Type'  => 'application/json',
        ],
        $body,
        'createPupil'
    );

    logInfo('[Engage] createPupil raw response: ' . $result['body']);

    if (!$result['ok']) {
        logError('[Engage] Failed to create pupil');
        return null;
    }

    $data    = json_decode($result['body'], true);
    $pupilId = $data['pupilId'] ?? $data['id'] ?? $data['data']['pupilId'] ?? null;

    if (!$pupilId) {
        logError('[Engage] pupilId not found in response — check raw response above');
        return null;
    }

    logOk("[Engage] Pupil created. pupilId: $pupilId");
    return (string) $pupilId;
}

// ============================================================
// ENGAGE - CHECK CONTACT EXISTS
// ============================================================
function checkContactExists(string $token, array $parent): array {
    logInfo("[Engage] Checking if contact exists: {$parent['email']}");

    $body = json_encode([
        'firstName'    => $parent['first_name'],
        'lastName'     => $parent['last_name'],
        'telephone'    => $parent['phone'],
        'emailAddress' => $parent['email'],
    ]);

    $result = httpRequest('POST',
        ENGAGE_BASE_URL . '/api/v1/admissions/iscontactexisting/' . ENGAGE_SCHOOL_ID,
        [
            'Authorization' => $token,
            'Content-Type'  => 'application/json',
        ],
        $body,
        'checkContact'
    );

    logInfo('[Engage] iscontactexisting raw response: ' . $result['body']);

    $data      = json_decode($result['body'], true);
    $exists    = $data['exists'] ?? $data['contactExists'] ?? false;
    $contactId = $data['contactId'] ?? $data['id'] ?? null;

    logInfo("[Engage] Contact exists: " . ($exists ? 'yes' : 'no') . " | contactId: $contactId");
    return ['exists' => (bool)$exists, 'contactId' => $contactId];
}

// ============================================================
// ENGAGE - LINK EXISTING CONTACT
// ============================================================
function linkExistingContact(string $token, string $pupilId, string $contactId): void {
    logInfo("[Engage] Linking existing contact $contactId to pupil $pupilId");

    $body = json_encode([
        'relationshipid'    => 2100,
        'iscontactparental' => true,
        'priority'          => 1,
        'pupilid'           => $pupilId,
        'contactid'         => $contactId,
    ]);

    $result = httpRequest('POST',
        ENGAGE_BASE_URL . '/api/v1/admissions/linkexistingcontact',
        [
            'Authorization' => $token,
            'Content-Type'  => 'application/json',
        ],
        $body,
        'linkContact'
    );

    logOk('[Engage] Link response: ' . $result['body']);
}

// ============================================================
// ENGAGE - CREATE NEW CONTACT
// ============================================================
function createNewContact(string $token, string $pupilId, array $parent): void {
    logInfo("[Engage] Creating new contact for pupil $pupilId: {$parent['full_name']}");

    $body = json_encode([
        'relationshipid'    => 2100,
        'pupilid'           => $pupilId,
        'titleid'           => 3012,
        'iscontactparental' => true,
        'priority'          => 1,
        'firstname'         => $parent['first_name'],
        'lastname'          => $parent['last_name'],
        'contactname'       => $parent['full_name'],
        'greeting'          => 'Hello ' . $parent['first_name'],
        'genderid'          => 20003,
        'languageid'        => 2902,
        'professionid'      => 2101,
    ]);

    $result = httpRequest('POST',
        ENGAGE_BASE_URL . '/api/v1/admissions/addcontactbasicinfo',
        [
            'Authorization' => $token,
            'Content-Type'  => 'application/json',
        ],
        $body,
        'createContact'
    );

    logOk('[Engage] New contact response: ' . $result['body']);
}

// ============================================================
// MAIN WEBHOOK HANDLER
// ============================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse incoming JSON
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// GHL sends either top-level or nested under raw_body
$body = $payload['raw_body'] ?? $payload;

$contactId  = $body['contact_id'] ?? null;
$locationId = $body['location']['id'] ?? null;

logInfo('========== WEBHOOK RECEIVED ==========');
logInfo('Raw payload: ' . $rawBody);

if (!$contactId || !$locationId) {
    logError('Missing contact_id or location.id');
    http_response_code(400);
    echo json_encode(['error' => 'Missing contact_id or location.id']);
    exit;
}

$parent = [
    'first_name' => $body['first_name'] ?? '',
    'last_name'  => $body['last_name']  ?? '',
    'full_name'  => $body['full_name']  ?? '',
    'email'      => $body['email']      ?? '',
    'phone'      => $body['phone']      ?? '',
];

logInfo("Contact ID: $contactId | Location ID: $locationId");
logInfo("Parent: {$parent['full_name']} | {$parent['email']} | {$parent['phone']}");

// Respond to GHL immediately (so it doesn't timeout)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'received', 'contactId' => $contactId]);

// Flush response to GHL before processing
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_flush();
    flush();
}

// ============================================================
// BACKGROUND PROCESSING (after response sent)
// ============================================================

logInfo('===== START PROCESSING =====');

// Step 1: Fetch students
$students = fetchStudents($locationId, $contactId);

if (empty($students)) {
    logWarn('No students found. Nothing to do.');
    exit;
}

// Step 2: Get Engage token
$token = getEngageToken();
if (!$token) {
    logError('Cannot proceed without Engage token');
    exit;
}

// Step 3: Process each student
foreach ($students as $student) {
    $studentId = $student['id'] ?? 'unknown';

    logInfo("--- Student: $studentId | name: " . ($student['properties']['first_name'] ?? '?') . " ---");

    $pupilData = [
        'forename' => $student['properties']['first_name']    ?? 'Unknown',
        'surname'  => $parent['last_name'],
        'dob'      => $student['properties']['date_of_birth'] ?? '2000-01-01',
    ];

    // Create pupil
    $pupilId = createPupil($token, $pupilData);

    if (!$pupilId) {
        logError("Skipping contact link for student $studentId — no pupilId");
        continue;
    }

    // Check if parent contact exists
    $contactCheck = checkContactExists($token, $parent);

    if ($contactCheck['exists'] && $contactCheck['contactId']) {
        linkExistingContact($token, $pupilId, $contactCheck['contactId']);
    } else {
        createNewContact($token, $pupilId, $parent);
    }

    logOk("Student $studentId fully processed");
}

logInfo('===== DONE =====');
