# Prompt: Refactor Migration Script for Cloud Run + Supabase

## YOUR ROLE

You are a senior Python and Google Cloud engineer. Your job is to refactor an existing Python migration script and produce all supporting files needed to run it on **Google Cloud Run Jobs** with **Supabase (Postgres)** as the state store.

Deliver exactly 4 files:
- `migration.py` — full refactored script 
- `Dockerfile` — container definition
- `schema.sql` — Supabase table + indexes
- `deploy.sh` — all gcloud CLI commands in order with inline comments

Do not add explanations outside the files. Each file must be complete, correct, and ready to use.

---

## BACKGROUND

This is a one-time data migration. It reads contacts from **ChatDaddy** (a WhatsApp CRM) and creates them in **GHL (GoHighLevel)**, including conversations, tags as notes, and full message history.

The script currently runs locally and writes state to local JSON files. These files are wiped when a Cloud Run container exits, so they must be replaced with **Supabase**.

The migration is large (~90k contacts, ~40k conversations, unknown messages per conversation) and will likely take multiple hours. The job must be **safe to re-run** — if it crashes halfway, re-running it must skip already-completed contacts and resume from where it left off.

---

## CURRENT SCRIPT

```python
import http.client
import json
import time
import os
from datetime import datetime

# ============================================================================
# CONFIGURATION
# ============================================================================
GHL_TOKEN            = "pit-02cea63b-562a-4808-a724-db8959c228ae"
LOCATION_ID          = "THkkSZ21VAMIoOp3RFax"
CHATDADDY_TOKEN      = "apit_eMROwmrJF_i759xkq4KJBUX7v1FtHjYLAmGLAjr0CEE"
CHATDADDY_ACCOUNT_ID = "acc_30528e6f-8033-4303-8d_3706"
NOTE_USER_ID         = "zUL9CjEu2tK00WbHnvnB"
TEST_MODE            = True
TEST_LIMIT           = 5

def get_unique_filename(base_filename):
    if not os.path.exists(base_filename):
        return base_filename
    name, ext = os.path.splitext(base_filename)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return f"{name}_{timestamp}{ext}"

OUTPUT_FILE          = get_unique_filename("contact_mapping.json")
FAILED_CONTACTS_FILE = get_unique_filename("failed_contacts.json")
LOG_FILE             = get_unique_filename("migration.log")

def log(message, level="INFO"):
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] [{level}] {message}"
    print(message)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(log_entry + "\n")

def log_error(message):   log(f"✗ {message}", "ERROR")
def log_success(message): log(f"✓ {message}", "SUCCESS")
def log_warning(message): log(f"⚠ {message}", "WARNING")
def log_info(message):    log(f"  {message}", "INFO")

def fetch_all_contacts():
    all_contacts = []
    cursor = None
    page_num = 0
    log_info("Fetching contacts from ChatDaddy API...")
    while True:
        page_num += 1
        conn = http.client.HTTPSConnection("api.chatdaddy.tech")
        params = "returnTotalCount=true"
        if cursor:
            params += f"&page={cursor}"
        headers = {'Authorization': f'Bearer {CHATDADDY_TOKEN}', 'Content-Type': 'application/json'}
        try:
            conn.request("GET", f"/im/contacts?{params}", "", headers)
            res = conn.getresponse()
            data = res.read()
            response = json.loads(data.decode("utf-8"))
            if res.status != 200:
                log_error(f"Failed to fetch contacts page {page_num}: {response}")
                break
            contacts = response.get("contacts", [])
            next_cursor = response.get("nextPage")
            if page_num == 1 and "total" in response:
                log_info(f"Total contacts reported by API: {response['total']}")
            log_info(f"Page {page_num}: fetched {len(contacts)} contacts (running total: {len(all_contacts) + len(contacts)})")
            all_contacts.extend(contacts)
            if TEST_MODE and len(all_contacts) >= TEST_LIMIT:
                all_contacts = all_contacts[:TEST_LIMIT]
                log_warning(f"TEST MODE: capped at {TEST_LIMIT} contacts")
                break
            if not next_cursor:
                log_info("No more pages — all contacts fetched")
                break
            cursor = next_cursor
            time.sleep(0.3)
        except Exception as e:
            log_error(f"Exception fetching contacts page {page_num}: {str(e)}")
            break
        finally:
            conn.close()
    log_success(f"Total contacts fetched: {len(all_contacts)}")
    return all_contacts

def create_contact(name, phone):
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    payload = json.dumps({"firstName": name, "lastName": "-", "name": f"{name} -", "locationId": LOCATION_ID, "phone": phone})
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': f'Bearer {GHL_TOKEN}', 'Version': '2021-07-28'}
    try:
        conn.request("POST", "/contacts/", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        if res.status in (200, 201):
            contact_id = response.get('contact', {}).get('id') or response.get('id')
            log_success(f"GHL contact created: {name} → {contact_id}")
            return contact_id, None
        else:
            log_error(f"Error creating contact {name}: {response}")
            return None, response
    except Exception as e:
        log_error(f"Exception creating contact {name}: {str(e)}")
        return None, {'error': str(e)}
    finally:
        conn.close()

def create_contact_note(contact_id, title, body):
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    payload = json.dumps({"userId": NOTE_USER_ID, "body": body, "title": title, "color": "#FFAA00", "pinned": False})
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': f'Bearer {GHL_TOKEN}', 'Version': '2023-02-21'}
    try:
        conn.request("POST", f"/contacts/{contact_id}/notes", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        if res.status in (200, 201):
            log_info(f"Note created: [{title}]")
            return True
        else:
            log_error(f"Error creating note [{title}]: {response}")
            return False
    except Exception as e:
        log_error(f"Exception creating note [{title}]: {str(e)}")
        return False
    finally:
        conn.close()

def build_tags_note_body(tags):
    lines = []
    for tag in tags:
        tag_name  = tag.get('name', '').strip()
        tag_value = tag.get('value', '').strip() if tag.get('value') else ''
        if tag_name and tag_value:
            lines.append(f"{tag_name}: {tag_value}")
        elif tag_name:
            lines.append(tag_name)
    return "\n".join(lines)

def create_conversation(contact_id):
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    payload = json.dumps({"locationId": LOCATION_ID, "contactId": contact_id})
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': f'Bearer {GHL_TOKEN}', 'Version': '2021-04-15'}
    try:
        conn.request("POST", "/conversations/", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        if res.status in (200, 201):
            conversation_id = response.get('conversation', {}).get('id') or response.get('id')
            log_info(f"GHL conversation created: {conversation_id}")
            return conversation_id, None
        else:
            log_error(f"Error creating conversation: {response}")
            return None, response
    except Exception as e:
        log_error(f"Exception creating conversation: {str(e)}")
        return None, {'error': str(e)}
    finally:
        conn.close()

def fetch_messages(account_id, phone_number):
    all_messages = []
    cursor = None
    page_num = 0
    while True:
        page_num += 1
        conn = http.client.HTTPSConnection("api.chatdaddy.tech")
        url = f"/im/messages/{account_id}/{phone_number}"
        if cursor:
            url += f"?beforeId={cursor}"
        headers = {'Authorization': f'Bearer {CHATDADDY_TOKEN}', 'Content-Type': 'application/json'}
        try:
            conn.request("GET", url, "", headers)
            res = conn.getresponse()
            data = res.read()
            response = json.loads(data.decode("utf-8"))
            if res.status != 200:
                log_error(f"Error fetching messages page {page_num} (Status {res.status}): {response}")
                break
            messages = response.get("messages", [])
            next_cursor = response.get("nextPage")
            all_messages.extend(messages)
            log_info(f"Messages page {page_num}: {len(messages)} fetched (total: {len(all_messages)})")
            if not next_cursor:
                break
            cursor = next_cursor
            time.sleep(0.2)
        except Exception as e:
            log_error(f"Exception fetching messages page {page_num}: {str(e)}")
            break
        finally:
            conn.close()
    return all_messages

def _post_ghl_message(payload):
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': f'Bearer {GHL_TOKEN}', 'Version': '2021-04-15'}
    try:
        conn.request("POST", "/conversations/messages/inbound", json.dumps(payload), headers)
        res = conn.getresponse()
        data = res.read()
        if res.status in (200, 201):
            return True
        else:
            response = json.loads(data.decode("utf-8"))
            log_error(f"Message push failed ({res.status}): {response}")
            return False
    except Exception as e:
        log_error(f"Exception pushing message: {str(e)}")
        return False
    finally:
        conn.close()

def push_message_to_ghl(message, contact_id, conversation_id):
    text = (message.get('text') or "").strip()
    attachments_raw = message.get('attachments') or []
    attachment_urls = [a["url"] for a in attachments_raw if a.get("url")]
    has_action = 'action' in message
    is_note = message.get('status') == 'note'
    if not text and not attachment_urls and (is_note or has_action):
        log_info("Skipping system/action message with no content")
        return True
    if not text and not attachment_urls:
        log_info("Skipping empty message")
        return True
    try:
        iso_date = message.get("timestamp", "").replace("Z", "+00:00") or datetime.now().isoformat()
    except:
        iso_date = datetime.now().isoformat()
    if message.get("fromMe") is True:
        direction = "outbound"
    elif str(message.get("senderContactId", "")).startswith("603"):
        direction = "outbound"
    else:
        direction = "inbound"
    base = {"conversationId": conversation_id, "contactId": contact_id, "direction": direction, "date": iso_date}
    success = True
    if text:
        ok = _post_ghl_message({**base, "type": "WhatsApp", "message": text})
        if not ok:
            success = False
        elif attachment_urls:
            time.sleep(0.2)
    for url in attachment_urls:
        ok = _post_ghl_message({**base, "type": "SMS", "message": "", "attachments": [url]})
        if not ok:
            success = False
        time.sleep(0.2)
    return success

def run_migration():
    stats = {'total': 0, 'contacts_created': 0, 'conversations_created': 0,
             'contacts_failed': 0, 'notes_created': 0, 'messages_synced': 0,
             'contacts_with_messages': 0, 'contacts_no_messages': 0}
    mapping_data = []
    failed_data  = []

    # Phase 1: Fetch contacts from ChatDaddy
    contacts = fetch_all_contacts()
    stats['total'] = len(contacts)
    if not contacts:
        log_error("No contacts fetched. Exiting.")
        return

    # Phase 2: Create GHL contacts, notes, conversations
    for idx, contact in enumerate(contacts, 1):
        platform_names = contact.get('platformNames') or []
        phone      = contact.get('phoneNumber', '').strip()
        account_id = contact.get('accountId', '').strip()
        name       = platform_names[0].strip() if platform_names else phone
        if not name:
            name = phone

        log(f"\n[{idx}/{stats['total']}] Processing: {name} ({phone})")

        contact_id, contact_error = create_contact(name, phone)
        if not contact_id:
            stats['contacts_failed'] += 1
            failed_data.append({"row": idx, "name": name, "phone": phone, "account_id": account_id, "failed_step": "create_contact", "error": contact_error, "timestamp": datetime.now().isoformat()})
            time.sleep(1)
            continue

        stats['contacts_created'] += 1
        time.sleep(0.3)

        tags = contact.get('tags') or []
        if tags:
            tags_body = build_tags_note_body(tags)
            if tags_body:
                create_contact_note(contact_id, "Tags", tags_body)
                time.sleep(0.3)

        time.sleep(0.3)

        conversation_id, convo_error = create_conversation(contact_id)
        if not conversation_id:
            failed_data.append({"row": idx, "name": name, "phone": phone, "account_id": account_id, "contact_id": contact_id, "failed_step": "create_conversation", "error": convo_error, "timestamp": datetime.now().isoformat()})
            log_error("Failed to create conversation — skipping message sync")
            time.sleep(1)
            continue

        stats['conversations_created'] += 1
        mapping_data.append({"name": name, "phone": phone, "account_id": account_id, "phone_number": phone, "contact_id": contact_id, "conversation_id": conversation_id, "created_at": datetime.now().isoformat()})
        time.sleep(1)

    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(mapping_data, f, indent=2, ensure_ascii=False)
    if failed_data:
        with open(FAILED_CONTACTS_FILE, 'w', encoding='utf-8') as f:
            json.dump(failed_data, f, indent=2, ensure_ascii=False)

    # Phase 3: Fetch and push messages
    for idx, contact in enumerate(mapping_data, 1):
        name            = contact['name']
        account_id      = contact['account_id']
        phone_number    = contact['phone_number']
        contact_id      = contact['contact_id']
        conversation_id = contact['conversation_id']

        log(f"\n[{idx}/{len(mapping_data)}] Syncing messages: {name} ({phone_number})")
        messages = fetch_messages(account_id, phone_number)

        if not messages:
            stats['contacts_no_messages'] += 1
            contact['has_messages'] = False
            log_info("No messages found — flagged False")
        else:
            stats['contacts_with_messages'] += 1
            contact['has_messages'] = True
            log_info(f"Found {len(messages)} messages")
            synced = 0
            for message in messages:
                if message.get('status') == 'note':
                    note_text = message.get('text', '').strip()
                    if note_text:
                        ts = message.get('timestamp', datetime.now().isoformat())
                        note_title = f"Note ({ts[:10]})"
                        if create_contact_note(contact_id, note_title, note_text):
                            stats['notes_created'] += 1
                    time.sleep(0.3)
                    continue
                if push_message_to_ghl(message, contact_id, conversation_id):
                    synced += 1
                    stats['messages_synced'] += 1
                time.sleep(0.3)
            log_success(f"Synced {synced}/{len(messages)} messages")

        time.sleep(1)

    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(mapping_data, f, indent=2, ensure_ascii=False)

    log("\nMIGRATION COMPLETE")
    log(f"  Total: {stats['total']} | Created: {stats['contacts_created']} | Failed: {stats['contacts_failed']}")
    log(f"  Messages synced: {stats['messages_synced']} | Notes: {stats['notes_created']}")

if __name__ == "__main__":
    run_migration()
```

---

## WHAT YOU MUST CHANGE

### 1. Credentials — move all to environment variables

Remove every hardcoded value. Read from `os.environ` at the top of the script. Use `os.environ["KEY"]` (not `.get()`) so the script fails immediately with a clear error if any are missing:

```python
GHL_TOKEN            = os.environ["GHL_TOKEN"]
LOCATION_ID          = os.environ["LOCATION_ID"]
CHATDADDY_TOKEN      = os.environ["CHATDADDY_TOKEN"]
CHATDADDY_ACCOUNT_ID = os.environ["CHATDADDY_ACCOUNT_ID"]
NOTE_USER_ID         = os.environ["NOTE_USER_ID"]
SUPABASE_URL         = os.environ["SUPABASE_URL"]
SUPABASE_KEY         = os.environ["SUPABASE_KEY"]
TEST_MODE            = os.environ.get("TEST_MODE", "false").lower() == "true"
TEST_LIMIT           = int(os.environ.get("TEST_LIMIT", "5"))
```

### 2. Logging — stdout only, no file writes

Cloud Run captures stdout as logs in Google Cloud Logging. Remove all file-based logging. Keep `log`, `log_error`, `log_success`, `log_warning`, `log_info` but only `print()` — no file open/write.

Also remove `get_unique_filename`, `OUTPUT_FILE`, `FAILED_CONTACTS_FILE`, `LOG_FILE` entirely.

### 3. Replace JSON file state with Supabase

Install and use the official `supabase-py` client:

```python
from supabase import create_client
supabase = create_client(SUPABASE_URL, SUPABASE_KEY)
```

**Write to Supabase immediately after each step — do not batch at the end.** The exact write points and operations are:

**After GHL contact created successfully:**
```python
supabase.table("contacts").upsert({
    "phone_number":   phone,
    "name":           name,
    "account_id":     account_id,
    "ghl_contact_id": contact_id,
}).execute()
```

**After GHL conversation created successfully:**
```python
supabase.table("contacts").update({
    "ghl_convo_id": conversation_id
}).eq("phone_number", phone).execute()
```

**After messages pushed successfully for a contact:**
```python
supabase.table("contacts").update({
    "has_messages":     True,
    "phase1_pushed":    True,
    "phase1_pushed_at": datetime.utcnow().isoformat(),
}).eq("phone_number", phone).execute()
```

**If no messages found for a contact:**
```python
supabase.table("contacts").update({
    "has_messages":     False,
    "phase1_pushed":    True,
    "phase1_pushed_at": datetime.utcnow().isoformat(),
}).eq("phone_number", phone).execute()
```

**If any step errors (create_contact or create_conversation fails):**
```python
supabase.table("contacts").upsert({
    "phone_number": phone,
    "name":         name,
    "account_id":   account_id,
    "error":        json.dumps(error_detail),
}).execute()
```

### 4. Make every phase resumable on re-run

Before the Phase 2 loop, query Supabase for all existing rows and build a lookup dict:

```python
existing_rows = supabase.table("contacts").select("phone_number, ghl_contact_id, ghl_convo_id, phase1_pushed").execute()
existing = {row["phone_number"]: row for row in existing_rows.data}
```

**At the start of Phase 2 per-contact loop:** if `existing.get(phone, {}).get("ghl_contact_id")` is not None, log "Skipping — already in GHL" and `continue`.

**At the start of Phase 3 per-contact loop:** if `existing.get(phone_number, {}).get("phase1_pushed")` is True, log "Skipping — messages already pushed" and `continue`.

This means on a re-run the script will skip completed contacts and only process the ones that were not yet done or errored.

---

## WHAT YOU MUST NOT CHANGE

These things are confirmed working and must be preserved exactly:

- All API base URLs: `api.chatdaddy.tech`, `services.leadconnectorhq.com`
- All API endpoint paths and HTTP methods
- All request header `Version` values (e.g. `2021-07-28`, `2021-04-15`, `2023-02-21`)
- All request payload field names and structures
- Message push endpoint must remain `/conversations/messages/inbound` for both inbound and outbound `direction`
- Text messages must remain type `"WhatsApp"`, attachment messages must remain type `"SMS"`
- The logic for determining `direction` (`fromMe`, `senderContactId` starts with `603`)
- The `_post_ghl_message` helper and `push_message_to_ghl` split logic (text as WhatsApp, each attachment as separate SMS call)
- Note messages (`status == "note"`) routed to `create_contact_note`, not pushed to conversation
- Empty/system messages skipped
- All `time.sleep()` delays — do not remove or change values
- `TEST_MODE` / `TEST_LIMIT` behaviour — cap contacts at TEST_LIMIT when TEST_MODE is true
- `build_tags_note_body` format — `name: value` per line or just `name` if no value

---

## FILE 2 — Dockerfile

```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY migration.py .

RUN pip install --no-cache-dir supabase

CMD ["python", "-u", "migration.py"]
```

Note: `-u` flag ensures stdout is unbuffered so logs appear in real time in Cloud Logging.

---

## FILE 3 — schema.sql

Provide the exact SQL to create the Supabase table. Use this schema:

```sql
CREATE TABLE IF NOT EXISTS contacts (
  phone_number        TEXT PRIMARY KEY,
  name                TEXT,
  account_id          TEXT,
  ghl_contact_id      TEXT,
  ghl_convo_id        TEXT,
  has_messages        BOOLEAN,
  phase1_pushed       BOOLEAN DEFAULT FALSE,
  phase1_pushed_at    TIMESTAMPTZ,
  phase2_pushed       BOOLEAN DEFAULT FALSE,
  phase2_pushed_at    TIMESTAMPTZ,
  error               TEXT,
  created_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ghl_contact_id  ON contacts (ghl_contact_id);
CREATE INDEX IF NOT EXISTS idx_phase1_pending  ON contacts (phase1_pushed) WHERE phase1_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_phase2_pending  ON contacts (phase2_pushed) WHERE phase1_pushed = TRUE AND phase2_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_errors          ON contacts (error) WHERE error IS NOT NULL;
```

---

## FILE 4 — deploy.sh

Provide a shell script with every command needed, in order, with a comment above each command explaining what it does. Use these defaults:

- Region: `asia-southeast1`
- Job name: `ghl-migration`
- Memory: `1Gi`
- Task timeout: `86400` (24 hours — the migration may run for several hours)
- CPU: `1`
- Max retries: `0` (do not auto-retry; re-runs are manual after inspecting logs)

Commands to include in this order:
1. Export `PROJECT_ID` and `REGION` shell variables (with `YOUR_PROJECT_ID` as placeholder)
2. Set the active gcloud project
3. Enable required APIs: `run.googleapis.com`, `containerregistry.googleapis.com`
4. Configure Docker to authenticate with GCR
5. Build the Docker image tagged as `gcr.io/$PROJECT_ID/ghl-migration:latest`
6. Push the image to GCR
7. Create the Cloud Run Job with all env vars, memory, timeout, CPU, region — use `YOUR_VALUE` placeholders for each secret value
8. A separate `gcloud run jobs update` command showing how to update env vars after the job is already created (for when tokens change)
9. Execute the job (trigger a run)
10. Stream live logs using `gcloud beta run jobs executions logs tail` or equivalent
11. A one-liner to query just ERRORs from Cloud Logging using `gcloud logging read`

At the top of the file add a comment block listing all values the user must fill in before running.

---

## OUTPUT REQUIREMENTS

- Deliver all 4 files in full — no truncation, no `# ... rest unchanged`
- `migration.py` must be completely self-contained and runnable with `python migration.py` once env vars are set
- No placeholder values left in `migration.py` — all config comes from env vars
- `deploy.sh` placeholder values must use the format `YOUR_PROJECT_ID`, `YOUR_GHL_TOKEN` etc. so they are easy to find and replace
- Python 3.11 compatible, synchronous only — no async/await
