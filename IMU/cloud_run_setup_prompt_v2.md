# Prompt: Refactor Migration Script for Cloud Run + Supabase (v2)

## YOUR ROLE

You are a senior Python and Google Cloud engineer. Your job is to refactor an existing Python migration script and produce all supporting files needed to run it on **Google Cloud Run Jobs** with **Supabase (Postgres)** as the state store.

Deliver exactly 5 files:
- `migration.py` — full refactored script
- `.env.example` — template of all required environment variables
- `Dockerfile` — container definition
- `schema.sql` — Supabase table + indexes
- `deploy.sh` — all gcloud CLI commands in order with inline comments

Do not add explanations outside the files. Each file must be complete, correct, and ready to use.

---

## BACKGROUND

This is a one-time data migration. It reads contacts from **ChatDaddy** (a WhatsApp CRM) and creates them in **GHL (GoHighLevel)**, including conversations, tags as notes, and full message history up to a cutoff date.

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

GHL_TOKEN            = "pit-02cea63b-562a-4808-a724-db8959c228ae"
LOCATION_ID          = "THkkSZ21VAMIoOp3RFax"
CHATDADDY_TOKEN      = "apit_eMROwmrJF_i759xkq4KJBUX7v1FtHjYLAmGLAjr0CEE"
CHATDADDY_ACCOUNT_ID = "acc_30528e6f-8033-4303-8d_3706"
MESSAGE_CUTOFF_DATE  = "2025-12-31"
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

NOTE_USER_ID = "zUL9CjEu2tK00WbHnvnB"

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

def fetch_messages(phone_number):
    all_messages = []
    cursor = None
    page_num = 0
    while True:
        page_num += 1
        conn = http.client.HTTPSConnection("api.chatdaddy.tech")
        url = f"/im/messages/{CHATDADDY_ACCOUNT_ID}/{phone_number}"
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
    with open(LOG_FILE, 'w', encoding='utf-8') as f:
        f.write("=" * 70 + "\n")
        f.write("GHL MIGRATION LOG\n")
        f.write("=" * 70 + "\n")
        f.write(f"Started:    {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Test Mode:  {TEST_MODE} (limit: {TEST_LIMIT})\n" if TEST_MODE else "Test Mode:  OFF\n")
        f.write(f"Cutoff:     {MESSAGE_CUTOFF_DATE}\n")
        f.write("=" * 70 + "\n\n")

    stats = {
        'total': 0, 'contacts_created': 0, 'conversations_created': 0,
        'contacts_failed': 0, 'notes_created': 0, 'messages_synced': 0,
        'contacts_with_messages': 0, 'contacts_no_messages': 0,
    }
    mapping_data = []
    failed_data  = []

    contacts = fetch_all_contacts()
    stats['total'] = len(contacts)
    if not contacts:
        log_error("No contacts fetched. Exiting.")
        return

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

    for idx, contact in enumerate(mapping_data, 1):
        name            = contact['name']
        account_id      = contact['account_id']
        phone_number    = contact['phone_number']
        contact_id      = contact['contact_id']
        conversation_id = contact['conversation_id']

        log(f"\n[{idx}/{len(mapping_data)}] Syncing messages: {name} ({phone_number})")
        messages = fetch_messages(phone_number)

        before = len(messages)
        messages = [m for m in messages if (m.get("timestamp") or "")[:10] <= MESSAGE_CUTOFF_DATE]
        dropped = before - len(messages)
        if dropped:
            log_info(f"Dropped {dropped} message(s) after {MESSAGE_CUTOFF_DATE}")

        if not messages:
            stats['contacts_no_messages'] += 1
            contact['has_messages'] = False
            log_info("No messages — flagged False")
        else:
            stats['contacts_with_messages'] += 1
            contact['has_messages'] = True
            synced = 0
            for message in messages:
                if message.get('status') == 'note':
                    note_text = message.get('text', '').strip()
                    if note_text:
                        ts = message.get('timestamp', datetime.now().isoformat())
                        if create_contact_note(contact_id, f"Note ({ts[:10]})", note_text):
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

    log(f"\nMIGRATION COMPLETE")
    log(f"  Total: {stats['total']} | Created: {stats['contacts_created']} | Failed: {stats['contacts_failed']}")
    log(f"  Messages synced: {stats['messages_synced']} | Notes: {stats['notes_created']}")

if __name__ == "__main__":
    run_migration()
```

---

## WHAT YOU MUST CHANGE

### 1. Credentials — load from a `.env` file using `python-dotenv`

Do NOT use `os.environ` directly. Load credentials from a `.env` file at the top of the script:

```python
from dotenv import load_dotenv
load_dotenv()  # loads .env from the same directory
import os

GHL_TOKEN            = os.getenv("GHL_TOKEN")
LOCATION_ID          = os.getenv("LOCATION_ID")
CHATDADDY_TOKEN      = os.getenv("CHATDADDY_TOKEN")
CHATDADDY_ACCOUNT_ID = os.getenv("CHATDADDY_ACCOUNT_ID")
MESSAGE_CUTOFF_DATE  = os.getenv("MESSAGE_CUTOFF_DATE", "2025-12-31")
NOTE_USER_ID         = os.getenv("NOTE_USER_ID")
SUPABASE_URL         = os.getenv("SUPABASE_URL")
SUPABASE_KEY         = os.getenv("SUPABASE_KEY")
TEST_MODE            = os.getenv("TEST_MODE", "false").lower() == "true"
TEST_LIMIT           = int(os.getenv("TEST_LIMIT", "5"))
```

After loading, validate all required variables are present and exit early with a clear error message if any are missing:

```python
REQUIRED = {
    "GHL_TOKEN": GHL_TOKEN,
    "LOCATION_ID": LOCATION_ID,
    "CHATDADDY_TOKEN": CHATDADDY_TOKEN,
    "CHATDADDY_ACCOUNT_ID": CHATDADDY_ACCOUNT_ID,
    "NOTE_USER_ID": NOTE_USER_ID,
    "SUPABASE_URL": SUPABASE_URL,
    "SUPABASE_KEY": SUPABASE_KEY,
}
missing = [k for k, v in REQUIRED.items() if not v]
if missing:
    print(f"[ERROR] Missing required env vars: {', '.join(missing)}")
    print("[ERROR] Create a .env file from .env.example and fill in the values.")
    exit(1)
```

### 2. Logging — write to BOTH stdout AND a timestamped log file

Keep the existing `log()` function behaviour (print to stdout) but also write every log entry to a file. The log file must be created at script startup with a timestamp in the filename so each run produces a unique file and old logs are never overwritten.

Log file naming format: `migration_YYYYMMDD_HHMMSS.log`

Example: `migration_20260520_143022.log`

The `log()` function already does both print and file write — keep this exactly as-is. Do not remove or simplify it. The log file path must be set at startup before `run_migration()` is called, same as the current `LOG_FILE` pattern.

On Cloud Run, the log file will not persist after the container stops. To retrieve it, the script should print a final message at the very end telling the user to download it with `gcloud run jobs executions logs`. But the file must still be written during the run for local use.

### 3. Replace JSON file state with Supabase

Install and use the official `supabase-py` client:

```python
from supabase import create_client
supabase = create_client(SUPABASE_URL, SUPABASE_KEY)
```

**Write to Supabase immediately after each step succeeds — do not batch at the end.** The exact write points and operations:

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

**After messages pushed for a contact (has messages):**
```python
supabase.table("contacts").update({
    "has_messages":     True,
    "phase1_pushed":    True,
    "phase1_pushed_at": datetime.utcnow().isoformat(),
}).eq("phone_number", phone).execute()
```

**After message check — contact has no messages:**
```python
supabase.table("contacts").update({
    "has_messages":     False,
    "phase1_pushed":    True,
    "phase1_pushed_at": datetime.utcnow().isoformat(),
}).eq("phone_number", phone).execute()
```

**If create_contact or create_conversation fails:**
```python
supabase.table("contacts").upsert({
    "phone_number": phone,
    "name":         name,
    "account_id":   account_id,
    "error":        json.dumps(error_detail),
}).execute()
```

Keep writing to `contact_mapping.json` and `failed_contacts.json` as well — these are still useful for local test runs. On Cloud Run they won't persist, but Supabase is the source of truth there.

### 4. Make every phase resumable on re-run

At the start of `run_migration()`, after fetching contacts from ChatDaddy, query Supabase for all existing rows and build a lookup dict keyed by `phone_number`:

```python
existing_rows = supabase.table("contacts").select(
    "phone_number, ghl_contact_id, ghl_convo_id, phase1_pushed"
).execute()
existing = {row["phone_number"]: row for row in existing_rows.data}
```

**In the Phase 2 loop (create GHL contact/conversation):**
At the top of the loop, before calling `create_contact`, check:
```python
if existing.get(phone, {}).get("ghl_contact_id"):
    log_info(f"Skipping {phone} — already created in GHL")
    # still add to mapping_data if ghl_convo_id also exists, so Phase 3 can process it
    continue
```

**In the Phase 3 loop (push messages):**
At the top of the loop, before fetching messages, check:
```python
if existing.get(phone_number, {}).get("phase1_pushed"):
    log_info(f"Skipping {phone_number} — messages already pushed")
    continue
```

---

## WHAT YOU MUST NOT CHANGE

These are confirmed working and must be preserved exactly:

- All API base URLs: `api.chatdaddy.tech`, `services.leadconnectorhq.com`
- All API endpoint paths and HTTP methods
- All request header `Version` values (`2021-07-28`, `2021-04-15`, `2023-02-21`)
- Message push endpoint must remain `/conversations/messages/inbound` for both inbound and outbound `direction`
- Text messages must remain type `"WhatsApp"`, attachment messages must remain type `"SMS"`
- The `direction` logic (`fromMe`, `senderContactId` starts with `603`)
- The `_post_ghl_message` helper and `push_message_to_ghl` split logic (text as WhatsApp, each attachment as a separate SMS call)
- Note messages (`status == "note"`) must be routed to `create_contact_note`, not pushed to conversation
- Empty/system messages must be skipped
- Message cutoff filter: `(m.get("timestamp") or "")[:10] <= MESSAGE_CUTOFF_DATE`
- All `time.sleep()` delays — do not remove or change the values
- `TEST_MODE` / `TEST_LIMIT` behaviour — cap at TEST_LIMIT contacts when TEST_MODE is true
- `build_tags_note_body` format — `name: value` per line or just `name` if no value
- The `get_unique_filename` helper and unique output filenames pattern
- The log file must be written to disk (do not reduce to stdout-only)

---

## FILE 2 — .env.example

Provide a `.env.example` template with every variable, a comment above each one explaining what it is and where to find it, and placeholder values:

```
# GoHighLevel — Settings → Integrations → Private Integrations → your token
GHL_TOKEN=your_ghl_private_integration_token

# GoHighLevel — Settings → Business Profile → Location ID
LOCATION_ID=your_ghl_location_id

# ChatDaddy — Settings → API → Access Token
CHATDADDY_TOKEN=your_chatdaddy_access_token

# ChatDaddy — Settings → Account ID (format: acc_xxxxx)
CHATDADDY_ACCOUNT_ID=acc_your_account_id

# Only sync messages on or before this date (YYYY-MM-DD)
MESSAGE_CUTOFF_DATE=2025-12-31

# GoHighLevel — the user ID used as author for notes created in GHL
# Find it: Settings → Team → click the user → ID is in the browser URL
NOTE_USER_ID=your_ghl_user_id

# Supabase — Project Settings → API → Project URL
SUPABASE_URL=https://your-project-ref.supabase.co

# Supabase — Project Settings → API → service_role key (NOT the anon key)
SUPABASE_KEY=your_supabase_service_role_key

# Set to "true" to process only the first TEST_LIMIT contacts (for testing)
TEST_MODE=false

# Number of contacts to process when TEST_MODE=true
TEST_LIMIT=5
```

---

## FILE 3 — Dockerfile

```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY migration.py .
COPY .env .

RUN pip install --no-cache-dir supabase python-dotenv

CMD ["python", "-u", "migration.py"]
```

Note: `-u` ensures stdout is unbuffered so logs stream in real time to Cloud Logging.
The `.env` file is copied into the image at build time. This is acceptable for a one-off internal migration job that is never pushed to a public registry.

---

## FILE 4 — schema.sql

Provide the exact SQL to run in Supabase SQL Editor:

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

CREATE INDEX IF NOT EXISTS idx_ghl_contact_id ON contacts (ghl_contact_id);
CREATE INDEX IF NOT EXISTS idx_phase1_pending ON contacts (phase1_pushed) WHERE phase1_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_phase2_pending ON contacts (phase2_pushed) WHERE phase1_pushed = TRUE AND phase2_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_errors         ON contacts (error) WHERE error IS NOT NULL;
```

---

## FILE 5 — deploy.sh

Provide a shell script with every command needed, in order. Add a comment above each command explaining what it does.

At the top of the file add a clearly marked block listing all values the user must fill in before running, in the format `YOUR_PROJECT_ID`, `YOUR_REGION` etc.

Use these defaults:
- Region: `asia-southeast1`
- Job name: `ghl-migration`
- Memory: `1Gi`
- CPU: `1`
- Task timeout: `86400` (24 hours)
- Max retries: `0` (manual re-run after inspecting logs)

Include these commands in order:
1. Export `PROJECT_ID` and `REGION` shell variables
2. Set the active gcloud project
3. Enable required GCP APIs: `run.googleapis.com` and `containerregistry.googleapis.com`
4. Configure Docker to authenticate with GCR
5. Build the Docker image tagged as `gcr.io/$PROJECT_ID/ghl-migration:latest`
6. Push the image to GCR
7. Create the Cloud Run Job with memory, timeout, CPU, region — no env vars needed since they are baked into the image via `.env`
8. A separate command showing how to rebuild and redeploy if `.env` values change (rebuild image, push, update job)
9. Execute the job
10. View live logs using `gcloud run jobs executions logs tail`
11. A `gcloud logging read` one-liner to filter only ERROR-level entries from the job

---

## OUTPUT REQUIREMENTS

- Deliver all 5 files in full — no truncation, no `# ... rest unchanged`
- `migration.py` must be completely self-contained and runnable locally with `python migration.py` after creating a `.env` file
- All placeholder values in `deploy.sh` must use `YOUR_` prefix so they are easy to spot and replace
- Python 3.11 compatible, synchronous only — no async/await
- Wrap every Supabase call in a try/except and log the error — a Supabase write failure must never crash the migration loop
