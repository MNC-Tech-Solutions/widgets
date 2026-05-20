<?php

// ─── CONFIG ────────────────────────────────────────────────────────────────

const BUKKU_TOKEN     = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2F2YW5zZXIuYnVra3UubXkvc2V0dGluZ3MvYXBpIiwiaWF0IjoxNzc3ODY2NjY2LCJuYmYiOjE3Nzc4NjY2NjYsImp0aSI6IkZBcEkzb04yWmNZWmlCNWoiLCJzdWIiOiIxMTE2MDMiLCJwcnYiOiIyOWZjOGNlNzRmNWMwZjkxNmNjYTc0YTg2NmJjOGUzMWZlMDY0ZDdhIn0.7QwfGL1Rt7zXTdLESY_Hx8CZQuA1wKxNvqofU46z8VA";
const BUKKU_SUBDOMAIN = "avanser";

const GHL_API_KEY     = "pit-0bb6cc87-02d3-442a-be3a-c323cd23498b";
const GHL_LOCATION_ID = "BXuCudh2EKUEmv1gC4ai";

const OUTPUT_FILE     = "client_map.json";

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

function ghlGet(string $url): array {
    if (GHL_API_KEY === '' || GHL_API_KEY === 'YOUR_GHL_API_KEY') {
        throw new RuntimeException("GHL_API_KEY is not configured. Set it to a valid LeadConnector Private Integration Token.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . GHL_API_KEY,
            "Version: 2021-07-28",
            "Accept: application/json",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("GHL GET $url failed ($status): $body");
    }
    return json_decode($body, true);
}

/** Normalise a name for fuzzy comparison: lowercase, strip punctuation/extra spaces */
function normaliseName(string $name): string {
    $name = mb_strtolower($name);
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);   // strip punctuation
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name;
}

// ─── STEP 1 : FETCH ALL BUKKU CONTACTS (paginated) ─────────────────────────

echo "Fetching Bukku contacts...\n";

$bukkuContacts = [];
$page          = 1;

do {
    $data     = bukkuGet("/contacts?page={$page}&per_page=100");
    $contacts = $data['contacts'] ?? [];

    foreach ($contacts as $c) {
        $name = trim($c['legal_name'] ?? $c['display_name'] ?? '');
        if ($name === '') continue;

        $bukkuContacts[] = [
            'id'   => $c['id'],
            'name' => $name,
            'norm' => normaliseName($name),
        ];
    }

    $total   = $data['paging']['total']    ?? 0;
    $perPage = $data['paging']['per_page'] ?? 100;
    $page++;
} while (count($bukkuContacts) < $total && !empty($contacts));

echo "  → " . count($bukkuContacts) . " Bukku contacts loaded.\n\n";

// ─── STEP 2 : FETCH ALL GHL OPPORTUNITIES (paginated) ──────────────────────

echo "Fetching GHL opportunities...\n";

$ghlOpportunities = [];
$ghlPage          = 1;

do {
    $url  = "https://services.leadconnectorhq.com/opportunities/search"
          . "?location_id=" . GHL_LOCATION_ID
          . "&limit=100&page={$ghlPage}";
    $data = ghlGet($url);

    $opps = $data['opportunities'] ?? [];
    foreach ($opps as $opp) {
        $name = trim($opp['name'] ?? $opp['contact']['name'] ?? '');
        if ($name === '') continue;

        $ghlOpportunities[] = [
            'opportunity_id' => $opp['id'],
            'contact_id'     => $opp['contactId'] ?? null,
            'name'           => $name,
            'norm'           => normaliseName($name),
        ];
    }

    $total = $data['meta']['total'] ?? count($opps);
    $ghlPage++;
} while (count($ghlOpportunities) < $total && !empty($opps));

echo "  → " . count($ghlOpportunities) . " GHL opportunities loaded.\n\n";

// ─── STEP 3 : MATCH BY NAME ────────────────────────────────────────────────

echo "Matching by name...\n";

// Build a lookup map from normalised name → bukku contact
$bukkuByNorm = [];
foreach ($bukkuContacts as $c) {
    $bukkuByNorm[$c['norm']] = $c;
}

$matched   = [];
$unmatched = [];

foreach ($ghlOpportunities as $opp) {
    if (isset($bukkuByNorm[$opp['norm']])) {
        $bukku = $bukkuByNorm[$opp['norm']];
        $matched[] = [
            'name'               => $bukku['name'],
            'bukku_contact_id'   => $bukku['id'],
            'ghl_opportunity_id' => $opp['opportunity_id'],
            'ghl_contact_id'     => $opp['contact_id'],
        ];
        echo "  ✓ Matched: {$bukku['name']}\n";
    } else {
        $unmatched[] = $opp['name'];
    }
}

echo "\n  → " . count($matched)   . " matched\n";
echo "  → " . count($unmatched)  . " unmatched\n\n";

if (!empty($unmatched)) {
    echo "Unmatched GHL opportunities (no Bukku contact found):\n";
    foreach ($unmatched as $n) {
        echo "  - $n\n";
    }
    echo "\n";
}

// ─── STEP 4 : WRITE OUTPUT ─────────────────────────────────────────────────

$output = [
    'generated_at' => date('Y-m-d H:i:s'),
    'matched'      => $matched,
    'unmatched'    => $unmatched,
];

file_put_contents(OUTPUT_FILE, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved to " . OUTPUT_FILE . "\n";
