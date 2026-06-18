<?php
/**
 * GHL → Zoho CRM Integration Webhook
 *
 * Two pipelines depending on incoming data:
 *
 *   New contact  (Zoho ID is empty):
 *     1. Get Zoho access token
 *     2. Create Zoho contact
 *     3. Write Zoho record ID back to GHL contact
 *     4. Add tags to Zoho contact
 *
 *   Existing contact (Zoho ID present + Conversation Summary present):
 *     1. Get Zoho access token
 *     5. Add note to existing Zoho contact
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

// ── Config (credentials kept out of git) ─────────────────────────────────────
require_once __DIR__ . '/config.php';

$log_file = __DIR__ . '/data/zoho_webhook.log';
if (!file_exists(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function writeLog(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND | LOCK_EX);
}

function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Thin cURL wrapper — returns ['code' => int, 'body' => string, 'error' => string].
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    return ['code' => $http_code, 'body' => $response ?: '', 'error' => $error];
}

// ── Step 1: Get Zoho Access Token ─────────────────────────────────────────────

function getZohoAccessToken(): ?string {
    writeLog('Step 1 — fetching Zoho access token');
    $res = httpRequest('GET', ZOHO_TOKEN_URL);

    if ($res['error']) {
        writeLog("  ✗ cURL error: {$res['error']}");
        return null;
    }

    $json = json_decode($res['body'], true);
    if (!$json || ($json['code'] ?? '') !== 'success') {
        writeLog("  ✗ Token request failed (HTTP {$res['code']}): {$res['body']}");
        return null;
    }

    $output = json_decode($json['details']['output'] ?? '{}', true);
    $token  = $output['accessToken'] ?? null;

    if ($token) {
        writeLog('  ✓ Access token obtained');
    } else {
        writeLog("  ✗ Could not parse accessToken from: {$res['body']}");
    }

    return $token;
}

// ── Step 2: Create Zoho Contact ───────────────────────────────────────────────

function createZohoContact(string $token, array $data): ?string {
    $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    writeLog("Step 2 — creating Zoho contact: $name <{$data['email']}>");

    $body = json_encode([
        'data' => [[
            'First_Name'  => $data['first_name']  ?? '',
            'Last_Name'   => $data['last_name']   ?? ($data['full_name'] ?? 'Unknown'),
            'Phone'       => $data['phone']        ?? '',
            'Email'       => $data['email']        ?? '',
            'Nationality' => $data['Nationality']  ?? '',
        ]]
    ]);

    $res = httpRequest('POST', ZOHO_API_BASE . '/Contacts', [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Content-Type: application/json',
    ], $body);

    if ($res['error']) {
        writeLog("  ✗ cURL error: {$res['error']}");
        return null;
    }

    $json   = json_decode($res['body'], true);
    $record = $json['data'][0] ?? null;

    if (!$record || ($record['status'] ?? '') !== 'success') {
        writeLog("  ✗ Create failed (HTTP {$res['code']}): {$res['body']}");
        return null;
    }

    $record_id = $record['details']['id'];
    writeLog("  ✓ Zoho contact created — record ID: $record_id");
    return $record_id;
}

// ── Step 3: Update GHL Contact with Zoho ID ──────────────────────────────────

function updateGHLContact(string $contact_id, string $zoho_record_id): bool {
    writeLog("Step 3 — updating GHL contact $contact_id with Zoho ID $zoho_record_id");

    $body = json_encode([
        'customFields' => [[
            'id'         => GHL_ZOHO_FIELD_ID,
            'key'        => 'contact.zoho_id',
            'fieldValue' => $zoho_record_id,
        ]]
    ]);

    $res = httpRequest('PUT', GHL_API_BASE . "/contacts/$contact_id", [
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: 2023-02-21',
        'Content-Type: application/json',
    ], $body);

    if ($res['error']) {
        writeLog("  ✗ cURL error: {$res['error']}");
        return false;
    }

    if ($res['code'] >= 200 && $res['code'] < 300) {
        writeLog('  ✓ GHL contact updated');
        return true;
    }

    writeLog("  ✗ GHL update failed (HTTP {$res['code']}): {$res['body']}");
    return false;
}

// ── Step 4: Add Tags to Zoho Contact ─────────────────────────────────────────

function addZohoTags(string $token, string $record_id, string $tags_raw): bool {
    $tag_names = array_filter(array_map('trim', explode(',', $tags_raw)));
    if (empty($tag_names)) {
        writeLog('Step 4 — no tags to add, skipping');
        return true;
    }

    writeLog("Step 4 — adding tags to Zoho contact $record_id: $tags_raw");

    // Omit color_code so existing tags (which may have a different colour) aren't rejected.
    // Zoho will use the tag's existing colour, or assign one automatically for new tags.
    $tags = array_map(fn($name) => ['name' => $name], $tag_names);
    $body = json_encode(['tags' => $tags]);

    $res = httpRequest('POST', ZOHO_API_BASE . "/Contacts/$record_id/actions/add_tags", [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Content-Type: application/json',
    ], $body);

    if ($res['error']) {
        writeLog("  ✗ cURL error: {$res['error']}");
        return false;
    }

    if ($res['code'] >= 200 && $res['code'] < 300) {
        writeLog('  ✓ Tags added');
        return true;
    }

    writeLog("  ✗ Add tags failed (HTTP {$res['code']}): {$res['body']}");
    return false;
}

// ── Step 5: Add Note to Existing Zoho Contact ─────────────────────────────────

function addZohoNote(string $token, string $zoho_id, string $summary): bool {
    writeLog("Step 5 — adding note to Zoho contact $zoho_id");

    $body = json_encode([
        'data' => [[
            'Note_Content' => $summary,
        ]]
    ]);

    $res = httpRequest('POST', ZOHO_API_BASE . "/Contacts/$zoho_id/Notes", [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Content-Type: application/json',
    ], $body);

    if ($res['error']) {
        writeLog("  ✗ cURL error: {$res['error']}");
        return false;
    }

    if ($res['code'] >= 200 && $res['code'] < 300) {
        writeLog('  ✓ Note added');
        return true;
    }

    writeLog("  ✗ Add note failed (HTTP {$res['code']}): {$res['body']}");
    return false;
}

// ── Main ──────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'POST only']);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    writeLog('Invalid JSON payload received');
    respond(400, ['error' => 'Invalid JSON payload']);
}

$zoho_id    = trim($data['Zoho ID']              ?? '');
$conv_summ  = trim($data['Conversation Summary'] ?? '');
$contact_id = trim($data['contact_id']           ?? '');
$tags       = trim($data['tags']                 ?? '');

writeLog("─── Webhook received ─── contact_id=$contact_id | zoho_id=" . ($zoho_id ?: '(none)') . ' | summary=' . ($conv_summ ? 'yes' : 'no'));

// Step 1 — always needed
$token = getZohoAccessToken();
if (!$token) {
    respond(500, ['error' => 'Failed to obtain Zoho access token']);
}

$result = ['status' => 'ok', 'actions' => []];

if ($zoho_id === '') {
    // ── New contact pipeline ──────────────────────────────────────────────────

    $record_id = createZohoContact($token, $data);
    if (!$record_id) {
        respond(500, ['error' => 'Failed to create Zoho contact']);
    }
    $result['actions'][]      = 'zoho_contact_created';
    $result['zoho_record_id'] = $record_id;

    if ($tags) {
        $ok = addZohoTags($token, $record_id, $tags);
        $result['actions'][] = $ok ? 'tags_added' : 'tags_failed';
    }

    if ($contact_id) {
        $ok = updateGHLContact($contact_id, $record_id);
        $result['actions'][] = $ok ? 'ghl_updated' : 'ghl_update_failed';
    }

} else {
    // ── Existing contact pipeline ─────────────────────────────────────────────

    $result['zoho_record_id'] = $zoho_id;

    if ($conv_summ !== '') {
        $ok = addZohoNote($token, $zoho_id, $conv_summ);
        $result['actions'][] = $ok ? 'note_added' : 'note_failed';
    } else {
        writeLog('Existing contact — no conversation summary, nothing to do');
        $result['actions'][] = 'no_action_needed';
    }
}

writeLog('Done → ' . json_encode($result));
respond(200, $result);
