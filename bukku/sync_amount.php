<?php

// ─── CONFIG ────────────────────────────────────────────────────────────────

const BUKKU_TOKEN            = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2F2YW5zZXIuYnVra3UubXkvc2V0dGluZ3MvYXBpIiwiaWF0IjoxNzc3ODY2NjY2LCJuYmYiOjE3Nzc4NjY2NjYsImp0aSI6IkZBcEkzb04yWmNZWmlCNWoiLCJzdWIiOiIxMTE2MDMiLCJwcnYiOiIyOWZjOGNlNzRmNWMwZjkxNmNjYTc0YTg2NmJjOGUzMWZlMDY0ZDdhIn0.7QwfGL1Rt7zXTdLESY_Hx8CZQuA1wKxNvqofU46z8VA";
const BUKKU_SUBDOMAIN        = "avanser";

const GHL_API_KEY            = "pit-0bb6cc87-02d3-442a-be3a-c323cd23498b";           // replace
const GHL_AMOUNT_FIELD_ID    = "KOIiM8OhaiInogZlExUX";   // replace — opportunity custom field ID for invoice amount

const CLIENT_MAP_FILE        = "client_map.json";

// Set to true to print what would be pushed WITHOUT actually calling GHL
const DRY_RUN                = true;

// ─── HELPERS ───────────────────────────────────────────────────────────────

function bukkuGet(string $path): array {
    $url = "https://api.bukku.my" . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . BUKKU_TOKEN,
            "Company-Subdomain: "    . BUKKU_SUBDOMAIN,
            "Accept: application/json",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("Bukku GET $path failed ($status): $body");
    }
    return json_decode($body, true);
}

function ghlPutOpportunity(string $opportunityId, float $amount): array {
    $url     = "https://services.leadconnectorhq.com/opportunities/{$opportunityId}";
    $payload = json_encode([
        'customFields' => [
            [
                'id'               => GHL_AMOUNT_FIELD_ID,
                'field_value'      => number_format($amount, 2, '.', ''),
            ],
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => "PUT",
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . GHL_API_KEY,
            "Version: 2021-07-28",
            "Content-Type: application/json",
            "Accept: application/json",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 && $status !== 201) {
        throw new RuntimeException("GHL PUT opportunity $opportunityId failed ($status): $body");
    }
    return json_decode($body, true) ?? [];
}

// ─── LOAD CLIENT MAP ───────────────────────────────────────────────────────

if (!file_exists(CLIENT_MAP_FILE)) {
    echo "ERROR: " . CLIENT_MAP_FILE . " not found. Run map_bukku_ghl.php first.\n";
    exit(1);
}

$map     = json_decode(file_get_contents(CLIENT_MAP_FILE), true);
$clients = $map['matched'] ?? [];

if (empty($clients)) {
    echo "No matched clients found in " . CLIENT_MAP_FILE . ". Exiting.\n";
    exit(0);
}

echo (DRY_RUN ? "[DRY RUN] " : "") . "Starting sync for " . count($clients) . " client(s)...\n";
echo str_repeat("─", 60) . "\n\n";

// ─── SYNC ──────────────────────────────────────────────────────────────────

$results = ['success' => [], 'failed' => []];

foreach ($clients as $client) {
    $name          = $client['name'];
    $bukkuId       = $client['bukku_contact_id'];
    $ghlOpportunityId = $client['ghl_opportunity_id'];

    echo "Client : $name\n";
    echo "Bukku  : contact #$bukkuId\n";
    echo "GHL    : opportunity $ghlOpportunityId\n";

    try {
        // Fetch latest invoice for this Bukku contact
        $data     = bukkuGet("/sales/invoices?contact_id={$bukkuId}&per_page=1&sort=date&order=desc");
        $invoices = $data['data'] ?? $data['items'] ?? $data;

        if (empty($invoices)) {
            throw new RuntimeException("No invoices found in Bukku for contact #$bukkuId");
        }

        $latest = $invoices[0];
        $amount = (float) ($latest['amount'] ?? $latest['total'] ?? 0);
        $invNo  = $latest['number'] ?? $latest['id'];

        echo "Invoice: $invNo  →  RM " . number_format($amount, 2) . "\n";

        if (DRY_RUN) {
            echo "Action : [DRY RUN] would push RM " . number_format($amount, 2) . " to GHL opportunity\n";
        } else {
            ghlPutOpportunity($ghlOpportunityId, $amount);
            echo "Action : ✓ Pushed to GHL opportunity\n";
        }

        $results['success'][] = [
            'name'               => $name,
            'bukku_contact_id'   => $bukkuId,
            'ghl_opportunity_id' => $ghlOpportunityId,
            'invoice_number'     => $invNo,
            'amount'             => $amount,
            'dry_run'            => DRY_RUN,
        ];

    } catch (RuntimeException $e) {
        echo "ERROR  : " . $e->getMessage() . "\n";
        $results['failed'][] = [
            'name'  => $name,
            'error' => $e->getMessage(),
        ];
    }

    echo "\n";
}

// ─── SUMMARY ───────────────────────────────────────────────────────────────

echo str_repeat("─", 60) . "\n";
echo "Done. " . count($results['success']) . " succeeded, " . count($results['failed']) . " failed.\n";

if (!empty($results['failed'])) {
    echo "\nFailed clients:\n";
    foreach ($results['failed'] as $f) {
        echo "  - {$f['name']}: {$f['error']}\n";
    }
}

$logFile = "sync_log_" . date('Ymd_His') . ".json";
file_put_contents($logFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nLog saved to $logFile\n";