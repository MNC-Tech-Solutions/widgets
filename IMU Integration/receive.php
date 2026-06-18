<?php
/**
 * Simple data receiver.
 *
 * POST  -> accepts JSON body (or form-encoded fallback), logs it, returns JSON ack.
 * GET   -> shows the most recent received entries so you can confirm data is arriving.
 *
 * Optional shared-secret check: set $required_token below to require callers to
 * send it as header "X-Auth-Token: <token>" or query string "?token=<token>".
 * Leave it as an empty string to accept requests from anyone (fine for quick testing).
 */

date_default_timezone_set('Asia/Kuala_Lumpur');

$required_token = ''; // e.g. 'change-me' to require a token
$log_dir  = __DIR__ . '/data';
$log_file = $log_dir . '/received.jsonl';

if (!file_exists($log_dir)) {
    mkdir($log_dir, 0777, true);
}

function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Optional auth check
if ($required_token !== '') {
    $given = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals($required_token, $given)) {
        respond(401, ['error' => 'Invalid or missing token']);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Not JSON — fall back to whatever PHP parsed from form-encoded data
        $data = !empty($_POST) ? $_POST : ['raw' => $raw];
    }

    $entry = [
        'received_at' => date('Y-m-d H:i:s'),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        'data'        => $data,
    ];

    file_put_contents($log_file, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

    respond(200, ['status' => 'ok', 'received_at' => $entry['received_at']]);
}

// GET — show the last 20 received entries for quick verification
$entries = [];
if (file_exists($log_file)) {
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -20);
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if ($decoded !== null) {
            $entries[] = $decoded;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Data Receiver</title>
    <style>
        body { font-family: monospace; margin: 2rem; }
        pre { background: #f4f4f4; padding: 1rem; border-radius: 6px; overflow-x: auto; }
        h1 { font-size: 1.2rem; }
    </style>
</head>
<body>
    <h1>Last <?= count($entries) ?> received entries</h1>
    <p>POST JSON to this same URL to add more.</p>
    <?php if (empty($entries)): ?>
        <p>No data received yet.</p>
    <?php else: ?>
        <?php foreach ($entries as $e): ?>
            <pre><?= htmlspecialchars(json_encode($e, JSON_PRETTY_PRINT)) ?></pre>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
