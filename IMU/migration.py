import http.client
import json
import time
import os
from datetime import datetime

from dotenv import load_dotenv
load_dotenv()

from supabase import create_client

# ============================================================================
# CONFIGURATION — loaded from .env
# ============================================================================
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

REQUIRED = {
    "GHL_TOKEN":            GHL_TOKEN,
    "LOCATION_ID":          LOCATION_ID,
    "CHATDADDY_TOKEN":      CHATDADDY_TOKEN,
    "CHATDADDY_ACCOUNT_ID": CHATDADDY_ACCOUNT_ID,
    "NOTE_USER_ID":         NOTE_USER_ID,
    "SUPABASE_URL":         SUPABASE_URL,
    "SUPABASE_KEY":         SUPABASE_KEY,
}
missing = [k for k, v in REQUIRED.items() if not v]
if missing:
    print(f"[ERROR] Missing required env vars: {', '.join(missing)}")
    print("[ERROR] Create a .env file from .env.example and fill in the values.")
    exit(1)

supabase = create_client(SUPABASE_URL, SUPABASE_KEY)

# ============================================================================
# FILE MANAGEMENT
# ============================================================================
def get_unique_filename(base_filename):
    if not os.path.exists(base_filename):
        return base_filename
    name, ext = os.path.splitext(base_filename)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return f"{name}_{timestamp}{ext}"

OUTPUT_FILE          = get_unique_filename("contact_mapping.json")
FAILED_CONTACTS_FILE = get_unique_filename("failed_contacts.json")
LOG_FILE             = datetime.now().strftime("migration_%Y%m%d_%H%M%S.log")

# ============================================================================
# LOGGING — stdout + timestamped log file
# ============================================================================
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

# ============================================================================
# CHATDADDY — FETCH CONTACTS (cursor-based pagination)
# ============================================================================
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

# ============================================================================
# GHL — CREATE CONTACT
# ============================================================================
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

# ============================================================================
# GHL — CREATE CONTACT NOTE
# ============================================================================
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

# ============================================================================
# GHL — CREATE CONVERSATION
# ============================================================================
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

# ============================================================================
# CHATDADDY — FETCH MESSAGES
# ============================================================================
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

# ============================================================================
# GHL — PUSH A SINGLE PAYLOAD TO /conversations/messages/inbound
# ============================================================================
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

# ============================================================================
# GHL — PUSH MESSAGE (splits text and attachments into separate calls)
# ============================================================================
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

# ============================================================================
# MAIN MIGRATION
# ============================================================================
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

    # Load existing Supabase state for resumability
    try:
        existing_rows = supabase.table("contacts").select(
            "phone_number, ghl_contact_id, ghl_convo_id, phase1_pushed"
        ).execute()
        existing = {row["phone_number"]: row for row in existing_rows.data}
        log_info(f"Loaded {len(existing)} existing rows from Supabase")
    except Exception as e:
        log_error(f"Failed to load existing state from Supabase: {str(e)}")
        existing = {}

    # ========================================================================
    # PHASE 2: CREATE GHL CONTACTS & CONVERSATIONS
    # ========================================================================
    for idx, contact in enumerate(contacts, 1):
        platform_names = contact.get('platformNames') or []
        phone      = contact.get('phoneNumber', '').strip()
        account_id = contact.get('accountId', '').strip()
        name       = platform_names[0].strip() if platform_names else phone
        if not name:
            name = phone

        log(f"\n[{idx}/{stats['total']}] Processing: {name} ({phone})")

        if existing.get(phone, {}).get("ghl_contact_id"):
            log_info(f"Skipping {phone} — already created in GHL")
            if existing.get(phone, {}).get("ghl_convo_id"):
                mapping_data.append({
                    "name":            name,
                    "phone":           phone,
                    "account_id":      account_id,
                    "phone_number":    phone,
                    "contact_id":      existing[phone]["ghl_contact_id"],
                    "conversation_id": existing[phone]["ghl_convo_id"],
                    "created_at":      datetime.now().isoformat(),
                })
            continue

        contact_id, contact_error = create_contact(name, phone)
        if not contact_id:
            stats['contacts_failed'] += 1
            failed_data.append({"row": idx, "name": name, "phone": phone, "account_id": account_id, "failed_step": "create_contact", "error": contact_error, "timestamp": datetime.now().isoformat()})
            try:
                supabase.table("contacts").upsert({
                    "phone_number": phone,
                    "name":         name,
                    "account_id":   account_id,
                    "error":        json.dumps(contact_error),
                }).execute()
            except Exception as e:
                log_error(f"Supabase write failed (contact error): {str(e)}")
            time.sleep(1)
            continue

        stats['contacts_created'] += 1
        try:
            supabase.table("contacts").upsert({
                "phone_number":   phone,
                "name":           name,
                "account_id":     account_id,
                "ghl_contact_id": contact_id,
            }).execute()
        except Exception as e:
            log_error(f"Supabase write failed (contact created): {str(e)}")
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
            try:
                supabase.table("contacts").upsert({
                    "phone_number": phone,
                    "name":         name,
                    "account_id":   account_id,
                    "error":        json.dumps(convo_error),
                }).execute()
            except Exception as e:
                log_error(f"Supabase write failed (convo error): {str(e)}")
            log_error("Failed to create conversation — skipping message sync")
            time.sleep(1)
            continue

        stats['conversations_created'] += 1
        try:
            supabase.table("contacts").update({
                "ghl_convo_id": conversation_id
            }).eq("phone_number", phone).execute()
        except Exception as e:
            log_error(f"Supabase write failed (convo created): {str(e)}")

        mapping_data.append({"name": name, "phone": phone, "account_id": account_id, "phone_number": phone, "contact_id": contact_id, "conversation_id": conversation_id, "created_at": datetime.now().isoformat()})
        time.sleep(1)

    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(mapping_data, f, indent=2, ensure_ascii=False)
    if failed_data:
        with open(FAILED_CONTACTS_FILE, 'w', encoding='utf-8') as f:
            json.dump(failed_data, f, indent=2, ensure_ascii=False)

    # ========================================================================
    # PHASE 3: FETCH & PUSH MESSAGES
    # ========================================================================
    for idx, contact in enumerate(mapping_data, 1):
        name            = contact['name']
        account_id      = contact['account_id']
        phone_number    = contact['phone_number']
        contact_id      = contact['contact_id']
        conversation_id = contact['conversation_id']

        log(f"\n[{idx}/{len(mapping_data)}] Syncing messages: {name} ({phone_number})")

        if existing.get(phone_number, {}).get("phase1_pushed"):
            log_info(f"Skipping {phone_number} — messages already pushed")
            continue

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
            try:
                supabase.table("contacts").update({
                    "has_messages":     False,
                    "phase1_pushed":    True,
                    "phase1_pushed_at": datetime.utcnow().isoformat(),
                }).eq("phone_number", phone_number).execute()
            except Exception as e:
                log_error(f"Supabase write failed (no messages): {str(e)}")
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
            try:
                supabase.table("contacts").update({
                    "has_messages":     True,
                    "phase1_pushed":    True,
                    "phase1_pushed_at": datetime.utcnow().isoformat(),
                }).eq("phone_number", phone_number).execute()
            except Exception as e:
                log_error(f"Supabase write failed (messages pushed): {str(e)}")

        time.sleep(1)

    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(mapping_data, f, indent=2, ensure_ascii=False)

    log(f"\nMIGRATION COMPLETE")
    log(f"  Total: {stats['total']} | Created: {stats['contacts_created']} | Failed: {stats['contacts_failed']}")
    log(f"  Messages synced: {stats['messages_synced']} | Notes: {stats['notes_created']}")
    log(f"  Log file: {LOG_FILE}")
    log(f"  (On Cloud Run, retrieve logs with: gcloud run jobs executions logs tail EXECUTION_NAME)")

if __name__ == "__main__":
    run_migration()
