# Prompt: Adapt `master_sync.py` from NexCRM → ChatDaddy

## Context

I have an existing working Python script (`master_sync.py`) that migrates contacts and chat history from **NexCRM** into **GoHighLevel (GHL)**. It runs in two phases:

1. **Phase 1** — reads a CSV of prospects, creates a GHL contact + conversation for each one, saves a mapping JSON.
2. **Phase 2** — for each mapped contact, fetches messages from NexCRM and pushes them into the GHL conversation as inbound messages.

I now need to **reuse the same script structure for a new project**, but the **source has changed from NexCRM to ChatDaddy**. The GHL side stays identical. Only the source-side input format, API endpoints, auth, and field names change. Keep the file/logging/retry scaffolding intact — just rewire the source.

I'll attach `master_sync.py` (the old version) and `sample_contact.json` (a sample of the new ChatDaddy contact payload). Produce a new file `master_sync_chatdaddy.py`.

---

## What stays the same

- All GHL logic: `create_contact`, `create_conversation`, `push_message_to_ghl`, the `GHL_TOKEN` / `LOCATION_ID` constants, the `Version` headers, the endpoints (`/contacts/`, `/conversations/`, `/conversations/messages/inbound`).
- File management: `get_unique_filename`, the three output files (`contact_mapping.json`, `failed_contacts.json`, `migration.log`).
- Logging helpers: `log`, `log_error`, `log_success`, `log_warning`, `log_info`.
- Two-phase structure and final summary stats.
- Rate-limit sleeps (`time.sleep(0.5)` / `time.sleep(1)` / `time.sleep(0.3)`).
- Failed-row tracking with `row_number`, `failed_step`, `error`, `timestamp`.

---

## What changes

### 1. Input source: JSON file, not CSV

Replace `CSV_FILE = "sample_contacts.json"` + `csv.DictReader` with a JSON loader that reads `sample_contact.json`. The file looks like:

```json
{
  "contacts": [
    {
      "accountId": "acc_30528e6f-8033-4303-8d_25a7",
      "id": "60177154297@s.whatsapp.net",
      "phoneNumber": "60177154297",
      "platformNames": ["𝓙𝓪𝓷𝓲𝓬𝓮"],
      ...
    },
    ...
  ],
  "nextPage": "6a051a993ce59c2c95a1bcaa"
}
```

Iterate over `data["contacts"]`. Field mapping:

| Old (CSV/NexCRM) | New (ChatDaddy JSON) | Notes |
|---|---|---|
| `row['name']` | `contact['platformNames'][0]` | Array; take first; fallback to `phoneNumber` if empty |
| `row['contact']` | `contact['phoneNumber']` | Used as GHL phone |
| `row['prospect_token']` | `contact['accountId']` + `contact['phoneNumber']` | Both needed to fetch messages later |

Store **both** `accountId` and `phoneNumber` in the `mapping_data` entry (replacing the single `prospect_token` field) so Phase 2 can use them.

> Note: `platformNames` can contain unicode display names (e.g. `𝓙𝓪𝓷𝓲𝓬𝓮`). Keep them as-is; GHL accepts unicode. The `ensure_ascii=False` already in the JSON dumps must stay.

### 2. Note on pagination (out of scope for demo, but flag it)

The ChatDaddy contact payload has a top-level `nextPage` cursor. For this demo, only process the contacts in the one provided file — **do not** implement pagination. But add a `# TODO:` comment near the contacts loop noting that real runs will need to follow `nextPage` to paginate.

### 3. Remove NexCRM auth entirely

Delete:
- `NEXCRM_API` constant
- `current_access_token`, `current_refresh_token`, `token_expiry_time` globals
- `ensure_valid_token()` and any token refresh logic
- The 401-retry block inside `fetch_messages`

Replace with a single hardcoded ChatDaddy bearer token constant at the top:

```python
CHATDADDY_TOKEN = "REPLACE_ME"   # paste accessToken here
```

### 4. Rewrite `fetch_messages`

New endpoint:
```
GET https://api.chatdaddy.tech/im/messages/{accountId}/{phoneNumber}
Authorization: Bearer {CHATDADDY_TOKEN}
```

- Signature changes to `fetch_messages(account_id, phone_number)`.
- Use `http.client.HTTPSConnection("api.chatdaddy.tech")`.
- No body on GET — pass `""` or omit.
- Response shape: `{ "messages": [ ... ] }` — return `response.get("messages", [])`.
- On non-200, log and return `[]`. No token-refresh path.

### 5. Rewrite `push_message_to_ghl` for ChatDaddy message shape

ChatDaddy messages look like the samples below. The function must handle three things the old version didn't:

**a) Direction detection.** Use this rule, in order:
1. If `message.get("fromMe") is True` → `direction = "outbound"`.
2. Else if `senderContactId` starts with `"603"` (or, more generally, doesn't match the chat contact's phone) → `direction = "outbound"`.
3. Otherwise → `direction = "inbound"`.

Prefer the `fromMe` check; the `603` heuristic is the fallback for messages that omit `fromMe`.

> GHL's `/conversations/messages/inbound` endpoint is for inbound. For outbound messages, use the **outbound** endpoint `POST /conversations/messages` with `type: "SMS"`, the same `conversationId`/`contactId`, and the `direction` field. Confirm in GHL docs if unsure — but the migration must record both directions correctly, not flatten everything to inbound like the old script did. (The old script hardcoded the `/inbound` endpoint; this needs to branch.)

**b) Timestamp parsing.** ChatDaddy timestamps are ISO 8601 with `Z` (e.g. `"2026-04-18T04:10:00.319Z"`). The old script parsed `"%Y-%m-%d %H:%M:%S"`, which won't match. Use:
```python
iso_date = message["timestamp"].replace("Z", "+00:00")
# optionally: datetime.fromisoformat(iso_date).isoformat()
```
Fall back to `datetime.now().isoformat()` on parse failure, same as before.

**c) Attachments.** If `message.get("attachments")` is a non-empty list, include the attachment URLs in the GHL payload. GHL's inbound message endpoint accepts an `attachments` array of URLs. Send `[a["url"] for a in message["attachments"] if a.get("url")]`. The `text` field may be empty when there's only an attachment — in that case send `message` as `""` or `"-"` (match what GHL accepts; the old code defaulted to `"-"`).

**d) Skip non-message rows.** Some entries are action/note rows, e.g.:
```json
{ "status": "note", "action": { "type": "ASSIGNEE_CHANGED", ... } }
```
These have no `text` and no `attachments` worth syncing. Skip any message where **all** of these are true: no `text`, no non-empty `attachments`, and `status == "note"` (or an `action` field is present). Log it as `log_info("Skipping system/note message")` and continue.

### 6. Phase 2 loop adjustment

In the existing Phase 2 loop, replace:
```python
prospect_token = contact['prospect_token']
messages = fetch_messages(prospect_token)
```
with:
```python
account_id = contact['account_id']
phone_number = contact['phone_number']
messages = fetch_messages(account_id, phone_number)
```

Update the log line accordingly (`log_info(f"Account: {account_id} / Phone: {phone_number}")`).

### 7. Stats and failed-row dicts

Wherever `prospect_token` is referenced in `mapping_data` / `failed_data` entries, replace with `account_id` + `phone_number`. The stats dict keys can stay the same (`total_prospects`, `contacts_created`, etc.) — they're generic enough.

---

## Deliverable

A single file `master_sync_chatdaddy.py` that:

1. Reads `sample_contact.json` (path configurable via a `CONTACTS_FILE` constant).
2. Phase 1: creates GHL contact + conversation per ChatDaddy contact, saves mapping.
3. Phase 2: fetches messages from ChatDaddy per `(accountId, phoneNumber)`, pushes each to GHL with correct direction, timestamp, and attachments.
4. Produces the same three output files with the same logging style.
5. Has a `# TODO: paginate via nextPage` comment near the contacts loop.

Keep the code style, comment banners, and emoji log prefixes (`✓ ✗ ⚠`) consistent with the original. Don't refactor beyond what's needed for the source-side change.

---

## Reference: relevant ChatDaddy message fields

```
accountId, chatId, id, timestamp, status,
fromMe (optional bool),
senderContactId,
text (optional),
attachments: [{ type, mimetype, url, filename }],
action (optional, indicates system message),
quoted (optional, reply context — can be ignored for sync)
```
