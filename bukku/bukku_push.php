<?php

/**
 * GHL Webhook Receiver → Bukku Daily Sync
 *
 * GHL sends a POST with trigger_word: "map_bukku"
 * This script validates it, fetches yesterday's Bukku invoices + contacts,
 * and POSTs the enriched data back to a GHL webhook.
 *
 * Host this file at a public URL, e.g.:
 *   https://yourdomain.com/bukku_receiver.php
 * Then point your GHL workflow HTTP action to that URL.
 */

// ─── CONFIG ────────────────────────────────────────────────────────────────

const BUKKU_TOKEN       = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2F2YW5zZXIuYnVra3UubXkvc2V0dGluZ3MvYXBpIiwiaWF0IjoxNzc3ODY2NjY2LCJuYmYiOjE3Nzc4NjY2NjYsImp0aSI6IkZBcEkzb04yWmNZWmlCNWoiLCJzdWIiOiIxMTE2MDMiLCJwcnYiOiIyOWZjOGNlNzRmNWMwZjkxNmNjYTc0YTg2NmJjOGUzMWZlMDY0ZDdhIn0.7QwfGL1Rt7zXTdLESY_Hx8CZQuA1wKxNvqofU46z8VA";
const BUKKU_SUBDOMAIN   = "avanser";

const GHL_WEBHOOK_URL   = "https://services.leadconnectorhq.com/hooks/BXuCudh2EKUEmv1gC4ai/webhook-trigger/470500f6-d141-479f-aec6-36240425643c"; 

const EXPECTED_TRIGGER  = "map_bukku";

// Override date for testing e.g. "2026-05-04". Leave null to use yesterday.
const DATE_OVERRIDE     = "2026-03-05";

const LOG_DIR           = __DIR__ . "/logs";

// ─── INIT ──────────────────────────────────────────────────────────────────

if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0755, true);

header('Content-Type: application/json');

// ─── READ INCOMING WEBHOOK ─────────────────────────────────────────────────

$rawBody = file_get_contents('php://input');
$incoming = json_decode($rawBody, true);

// Log raw incoming payload for debugging
file_put_contents(
    LOG_DIR . "/incoming_" . date('Ymd_His') . ".json",
    json_encode($incoming, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// ─── VALIDATE TRIGGER WORD ─────────────────────────────────────────────────

// GHL sends trigger_word inside customData
$triggerWord = $incoming['raw_body']['customData']['trigger_word']
            ?? $incoming['customData']['trigger_word']
            ?? $incoming['trigger_word']
            ?? null;

if ($triggerWord !== EXPECTED_TRIGGER) {
    http_response_code(200); // Always return 200 to GHL to avoid retries
    echo json_encode([
        'success' => false,
        'message' => "Ignored: trigger_word was '{$triggerWord}', expected '" . EXPECTED_TRIGGER . "'",
    ]);
    exit;
}

// Acknowledge immediately so GHL doesn't time out waiting
// (actual sync runs below)
echo json_encode(['success' => true, 'message' => 'Trigger accepted, syncing...']);

// Flush response to GHL before doing the heavy work
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // Nginx/PHP-FPM: returns response to GHL immediately
} else {
    ob_end_flush();
    flush();
}

// ─── HELPERS ───────────────────────────────────────────────────────────────

function log_msg(string $msg): void {
    $line = "[" . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line; // visible in error_log if script runs in background
    file_put_contents(LOG_DIR . "/sync_" . date('Ymd') . ".log", $line, FILE_APPEND);
}

function bukkuGet(string $path): array {
    $url = "https://api.bukku.my" . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer "  . BUKKU_TOKEN,
            "Company-Subdomain: "     . BUKKU_SUBDOMAIN,
            "Accept: application/json",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("Bukku GET $path failed ($status): $body");
    }
    return json_decode($body, true) ?? [];
}

function postWebhook(string $url, array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Accept: application/json",
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

// ─── DETERMINE TARGET DATE ─────────────────────────────────────────────────

$targetDate = DATE_OVERRIDE ?? date('Y-m-d', strtotime('yesterday'));
log_msg("Triggered by GHL (trigger_word: map_bukku). Syncing date: $targetDate");


// ─── STEP 1: FETCH ALL INVOICES FOR TARGET DATE (paginated) ────────────────

log_msg("Fetching invoices dated $targetDate...");

$allInvoices = [];
$page        = 1;

do {
    $data     = bukkuGet("/sales/invoices?date_from={$targetDate}&date_to={$targetDate}&per_page=100&page={$page}&sort=date&order=asc");
    $invoices = $data['transactions'] ?? [];

    foreach ($invoices as $inv) {
        $allInvoices[] = $inv;
    }

    $total = $data['paging']['total'] ?? count($allInvoices);
    $page++;
} while (count($allInvoices) < $total && !empty($invoices));

log_msg(count($allInvoices) . " invoice(s) found.");

if (empty($allInvoices)) {
    log_msg("No invoices for $targetDate. Exiting.");
    exit;
}

// ─── STEP 2: FETCH FULL DETAIL + CONTACT FOR EACH INVOICE ──────────────────

$contactCache = [];

function getContact(int $contactId): array {
    global $contactCache;
    if (isset($contactCache[$contactId])) return $contactCache[$contactId];

    try {
        $data    = bukkuGet("/contacts/{$contactId}");
        $contact = $data['contact'] ?? $data;
        $contactCache[$contactId] = $contact;
        return $contact;
    } catch (RuntimeException $e) {
        log_msg("Warning: could not fetch contact #$contactId — " . $e->getMessage());
        return [];
    }
}

$records = [];

foreach ($allInvoices as $inv) {
    $invoiceId = $inv['id'];
    log_msg("Processing invoice #{$invoiceId} ({$inv['number']})...");

    try {
        $detail      = bukkuGet("/sales/invoices/{$invoiceId}");
        $transaction = $detail['transaction'] ?? $detail;
    } catch (RuntimeException $e) {
        log_msg("Error fetching invoice #{$invoiceId}: " . $e->getMessage());
        continue;
    }

    $contactId = $transaction['contact_id'] ?? null;
    $contact   = $contactId ? getContact((int) $contactId) : [];

    // ── ALL invoice fields ──────────────────────────────────────────────────
    $invoiceData = [
        'id'                         => $transaction['id']                          ?? null,
        'number'                     => $transaction['number']                      ?? null,
        'number2'                    => $transaction['number2']                     ?? null,
        'type'                       => $transaction['type']                        ?? null,
        'status'                     => $transaction['status']                      ?? null,
        'date'                       => $transaction['date']                        ?? null,
        'contact_id'                 => $transaction['contact_id']                  ?? null,
        'contact_name'               => $transaction['contact_name']                ?? null,
        'billing_contact_person_id'  => $transaction['billing_contact_person_id']   ?? null,
        'billing_contact_person'     => $transaction['billing_contact_person']      ?? null,
        'shipping_contact_person_id' => $transaction['shipping_contact_person_id']  ?? null,
        'shipping_contact_person'    => $transaction['shipping_contact_person']     ?? null,
        'group_id'                   => $transaction['group_id']                    ?? null,
        'group_name'                 => $transaction['group_name']                  ?? null,
        'billing_party'              => $transaction['billing_party']               ?? null,
        'shipping_party'             => $transaction['shipping_party']              ?? null,
        'shipping_info'              => $transaction['shipping_info']               ?? null,
        'show_shipping'              => $transaction['show_shipping']               ?? null,
        'payment_mode'               => $transaction['payment_mode']                ?? null,
        'currency_code'              => $transaction['currency_code']               ?? null,
        'currency_symbol'            => $transaction['currency_symbol']             ?? null,
        'exchange_rate'              => $transaction['exchange_rate']               ?? null,
        'tax_mode'                   => $transaction['tax_mode']                    ?? null,
        'rounding_on'                => $transaction['rounding_on']                 ?? null,
        'rounding_amount'            => $transaction['rounding_amount']             ?? null,
        'amount'                     => $transaction['amount']                      ?? null,
        'balance'                    => $transaction['balance']                     ?? null,
        'tag_ids'                    => $transaction['tag_ids']                     ?? [],
        'tag_names'                  => $transaction['tag_names']                   ?? [],
        'title'                      => $transaction['title']                       ?? null,
        'description'                => $transaction['description']                 ?? null,
        'internal_note'              => $transaction['internal_note']               ?? null,
        'remarks'                    => $transaction['remarks']                     ?? null,
        'short_link'                 => $transaction['short_link']                  ?? null,
        'snapshotted_at'             => $transaction['snapshotted_at']              ?? null,
        'myinvois_action'            => $transaction['myinvois_action']             ?? null,
        'myinvois_document_id'       => $transaction['myinvois_document_id']        ?? null,
        'myinvois_document_uuid'     => $transaction['myinvois_document_uuid']      ?? null,
        'myinvois_document_status'   => $transaction['myinvois_document_status']    ?? null,
        'myinvois_document_long_id'  => $transaction['myinvois_document_long_id']   ?? null,
        'issued_at'                  => $transaction['issued_at']                   ?? null,
        'validated_at'               => $transaction['validated_at']                ?? null,
        'validation_results'         => $transaction['validation_results']          ?? null,
        'rejected_at'                => $transaction['rejected_at']                 ?? null,
        'reject_message'             => $transaction['reject_message']              ?? null,
        'rejected_reason'            => $transaction['rejected_reason']             ?? null,
        'cancelled_at'               => $transaction['cancelled_at']                ?? null,
        'cancel_message'             => $transaction['cancel_message']              ?? null,
        'is_consolidated'            => $transaction['is_consolidated']             ?? null,
        'void_reason'                => $transaction['void_reason']                 ?? null,
        'voided_at'                  => $transaction['voided_at']                   ?? null,
        'customs_form_no'            => $transaction['customs_form_no']             ?? null,
        'customs_k2_form_no'         => $transaction['customs_k2_form_no']          ?? null,
        'incoterms'                  => $transaction['incoterms']                   ?? null,

        'line_items' => array_map(fn($item) => [
            'id'                      => $item['id']                       ?? null,
            'line'                    => $item['line']                     ?? null,
            'type'                    => $item['type']                     ?? null,
            'account_id'              => $item['account_id']               ?? null,
            'account_name'            => $item['account_name']             ?? null,
            'account_code'            => $item['account_code']             ?? null,
            'description'             => $item['description']              ?? null,
            'product_id'              => $item['product_id']               ?? null,
            'product_name'            => $item['product_name']             ?? null,
            'product_sku'             => $item['product_sku']              ?? null,
            'product_bin_location'    => $item['product_bin_location']     ?? null,
            'product_unit_id'         => $item['product_unit_id']          ?? null,
            'product_unit_label'      => $item['product_unit_label']       ?? null,
            'location_id'             => $item['location_id']              ?? null,
            'location_code'           => $item['location_code']            ?? null,
            'quantity'                => $item['quantity']                 ?? null,
            'base_quantity'           => $item['base_quantity']            ?? null,
            'base_product_unit_label' => $item['base_product_unit_label']  ?? null,
            'unit_price'              => $item['unit_price']               ?? null,
            'amount'                  => $item['amount']                   ?? null,
            'discount'                => $item['discount']                 ?? null,
            'discount_amount'         => $item['discount_amount']          ?? null,
            'tax_code_id'             => $item['tax_code_id']              ?? null,
            'tax_code'                => $item['tax_code']                 ?? null,
            'tax_amount'              => $item['tax_amount']               ?? null,
            'net_amount'              => $item['net_amount']               ?? null,
            'classification_code'     => $item['classification_code']      ?? null,
            'classification_name'     => $item['classification_name']      ?? null,
            'service_date'            => $item['service_date']             ?? null,
            'transfer_item_id'        => $item['transfer_item_id']         ?? null,
            'transfer_transaction'    => $item['transfer_transaction']     ?? null,
        ], $transaction['form_items'] ?? []),

        'term_items' => array_map(fn($t) => [
            'id'          => $t['id']          ?? null,
            'term_id'     => $t['term_id']     ?? null,
            'term_name'   => $t['term_name']   ?? null,
            'date'        => $t['date']        ?? null,
            'payment_due' => $t['payment_due'] ?? null,
            'description' => $t['description'] ?? null,
            'amount'      => $t['amount']      ?? null,
            'balance'     => $t['balance']     ?? null,
        ], $transaction['term_items'] ?? []),

        'expiry_date'        => (function() use ($transaction) {
            foreach ($transaction['fields'] ?? [] as $f) {
                if ($f['id'] === 755) return $f['value'] ?? null;
            }
            return null;
        })(),
        'custom_fields'      => $transaction['fields']             ?? [],
        'linked_items'       => $transaction['linked_items']       ?? [],
        'reconciliations'    => $transaction['reconciliations']    ?? [],
        'deposit_items'      => $transaction['deposit_items']      ?? [],
        'costing_info_items' => $transaction['costing_info_items'] ?? [],
        'files'              => $transaction['files']              ?? [],
    ];

    // ── ALL contact fields ──────────────────────────────────────────────────
    $contactData = [
        'id'                      => $contact['id']                       ?? null,
        'legal_name'              => $contact['legal_name']               ?? null,
        'other_name'              => $contact['other_name']               ?? null,
        'display_name'            => $contact['display_name']             ?? null,
        'company_name'            => $contact['company_name']             ?? null,
        'billing_first_name'      => $contact['billing_first_name']       ?? null,
        'billing_last_name'       => $contact['billing_last_name']        ?? null,
        'shipping_first_name'     => $contact['shipping_first_name']      ?? null,
        'shipping_last_name'      => $contact['shipping_last_name']       ?? null,
        'reg_no'                  => $contact['reg_no']                   ?? null,
        'reg_no_type'             => $contact['reg_no_type']              ?? null,
        'old_reg_no'              => $contact['old_reg_no']               ?? null,
        'tax_id_no'               => $contact['tax_id_no']                ?? null,
        'sst_reg_no'              => $contact['sst_reg_no']               ?? null,
        'entity_type'             => $contact['entity_type']              ?? null,
        'types'                   => $contact['types']                    ?? [],
        'email'                   => $contact['email']                    ?? null,
        'emails'                  => $contact['emails']                   ?? null,
        'email_status'            => $contact['email_status']             ?? null,
        'email_note'              => $contact['email_note']               ?? null,
        'email_updated_at'        => $contact['email_updated_at']         ?? null,
        'phone_no'                => $contact['phone_no']                 ?? null,
        'billing_party'           => $contact['billing_party']            ?? null,
        'shipping_party'          => $contact['shipping_party']           ?? null,
        'group_names'             => $contact['group_names']              ?? null,
        'receivable_amount'       => $contact['receivable_amount']        ?? null,
        'payable_amount'          => $contact['payable_amount']           ?? null,
        'net_receivable_amount'   => $contact['net_receivable_amount']    ?? null,
        'field_1'                 => $contact['field_1']                  ?? null,
        'field_2'                 => $contact['field_2']                  ?? null,
        'field_7'                 => $contact['field_7']                  ?? null,
        'file_count'              => $contact['file_count']               ?? null,
        'mandate'                 => $contact['mandate']                  ?? null,
        'is_archived'             => $contact['is_archived']              ?? null,
        'is_active'               => isset($contact['id']) && !($contact['is_archived'] ?? false),
        'is_myinvois_ready'       => $contact['is_myinvois_ready']        ?? null,
        'is_myinvois_validated'   => $contact['is_myinvois_validated']    ?? null,
        'default_myinvois_action' => $contact['default_myinvois_action']  ?? null,
        'created_at'              => $contact['created_at']               ?? null,
        'updated_at'              => $contact['updated_at']               ?? null,
    ];

    $records[] = [
        'invoice' => $invoiceData,
        'contact' => $contactData,
        'meta'    => [
            'sync_date' => $targetDate,
            'synced_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

log_msg(count($records) . " record(s) built.");

// ─── STEP 3: BUILD FINAL PAYLOAD ───────────────────────────────────────────

$payload = [
    'event'     => 'bukku_daily_invoice_sync',
    'date'      => $targetDate,
    'synced_at' => date('Y-m-d H:i:s'),
    'total'     => count($records),
    'records'   => $records,
];

// Save local copy
$logFile = LOG_DIR . "/sync_{$targetDate}.json";
file_put_contents($logFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
log_msg("Local log saved: $logFile");

// ─── STEP 4: POST BACK TO GHL WEBHOOK ─────────────────────────────────────

log_msg("Posting results to GHL webhook...");

$response = postWebhook(GHL_WEBHOOK_URL, $payload);

if ($response['status'] >= 200 && $response['status'] < 300) {
    log_msg("GHL webhook accepted (HTTP {$response['status']})");
} else {
    log_msg("GHL webhook FAILED (HTTP {$response['status']}): {$response['body']}");
}

log_msg("Done.");