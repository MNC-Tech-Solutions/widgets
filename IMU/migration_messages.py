import http.client
import json
import time
import os
from datetime import datetime

from dotenv import load_dotenv
load_dotenv()

from supabase import create_client

# ============================================================================
# CONFIG
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
    "GHL_TOKEN": GHL_TOKEN, "LOCATION_ID": LOCATION_ID,
    "CHATDADDY_TOKEN": CHATDADDY_TOKEN, "CHATDADDY_ACCOUNT_ID": CHATDADDY_ACCOUNT_ID,
    "NOTE_USER_ID": NOTE_USER_ID, "SUPABASE_URL": SUPABASE_URL, "SUPABASE_KEY": SUPABASE_KEY,
}
missing = [k for k, v in REQUIRED.items() if not v]
if missing:
    print(f"[ERROR] Missing env vars: {', '.join(missing)}")
    exit(1)

supabase = create_client(SUPABASE_URL, SUPABASE_KEY)
LOG_FILE = datetime.now().strftime("messages_%Y%m%d_%H%M%S.log")

# ============================================================================
# LOGGING
# ============================================================================
def log(msg, level="INFO"):
    entry = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] [{level}] {msg}"
    print(msg)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(entry + "\n")

def log_error(m):   log(f"✗ {m}", "ERROR")
def log_success(m): log(f"✓ {m}", "SUCCESS")
def log_warning(m): log(f"⚠ {m}", "WARNING")
def log_info(m):    log(f"  {m}", "INFO")

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
            data = json.loads(res.read().decode("utf-8"))
            if res.status != 200:
                log_error(f"Error fetching messages page {page_num} (status {res.status}): {data}")
                break
            messages = data.get("messages", [])
            next_cursor = data.get("nextPage")
            all_messages.extend(messages)
            log_info(f"  Messages page {page_num}: {len(messages)} fetched (total: {len(all_messages)})")
            if not next_cursor:
                break
            cursor = next_cursor
            time.sleep(0.2)
        except Exception as e:
            log_error(f"Exception fetching messages page {page_num}: {e}")
            break
        finally:
            conn.close()
    return all_messages

# ============================================================================
# GHL — PUSH MESSAGE
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
        log_error(f"Message push failed ({res.status}): {json.loads(data.decode('utf-8'))}")
        return False
    except Exception as e:
        log_error(f"Exception pushing message: {e}")
        return False
    finally:
        conn.close()

def push_message_to_ghl(message, contact_id, conversation_id):
    text            = (message.get('text') or "").strip()
    attachments_raw = message.get('attachments') or []
    attachment_urls = [a["url"] for a in attachments_raw if a.get("url")]

    is_note    = message.get('status') == 'note'
    has_action = 'action' in message
    if not text and not attachment_urls and (is_note or has_action):
        log_info("Skipping system/action message")
        return True
    if not text and not attachment_urls:
        log_info("Skipping empty message")
        return True

    try:
        iso_date = message.get("timestamp", "").replace("Z", "+00:00") or datetime.now().isoformat()
    except:
        iso_date = datetime.now().isoformat()

    direction = "outbound" if (
        message.get("fromMe") is True or
        str(message.get("senderContactId", "")).startswith("603")
    ) else "inbound"

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
# GHL — CREATE NOTE (for messages with status=note)
# ============================================================================
def create_contact_note(contact_id, title, body):
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    payload = json.dumps({"userId": NOTE_USER_ID, "body": body, "title": title, "color": "#FFAA00", "pinned": False})
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': f'Bearer {GHL_TOKEN}', 'Version': '2023-02-21'}
    try:
        conn.request("POST", f"/contacts/{contact_id}/notes", payload, headers)
        res = conn.getresponse()
        data = json.loads(res.read().decode("utf-8"))
        if res.status in (200, 201):
            log_info(f"Note created: [{title}]")
            return True
        log_error(f"Error creating note [{title}]: {data}")
        return False
    except Exception as e:
        log_error(f"Exception creating note: {e}")
        return False
    finally:
        conn.close()

# ============================================================================
# MAIN
# ============================================================================
def run():
    with open(LOG_FILE, 'w', encoding='utf-8') as f:
        f.write("=" * 70 + "\n")
        f.write("GHL MIGRATION — SCRIPT 2: MESSAGES\n")
        f.write("=" * 70 + "\n")
        f.write(f"Started:   {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Cutoff:    on or before {MESSAGE_CUTOFF_DATE}\n")
        f.write(f"Test Mode: {'ON (limit: ' + str(TEST_LIMIT) + ')' if TEST_MODE else 'OFF'}\n")
        f.write("=" * 70 + "\n\n")

    # Read all contacts from Supabase that have a conversation but no messages pushed yet
    try:
        result = supabase.table("contacts").select(
            "phone_number, name, ghl_contact_id, ghl_convo_id"
        ).not_.is_("ghl_convo_id", "null").eq("messages_done", False).execute()
        pending = result.data
        log_info(f"{len(pending)} contact(s) pending message sync")
    except Exception as e:
        log_error(f"Failed to load pending contacts from Supabase: {e}")
        return

    if not pending:
        log_info("Nothing to do — all messages already synced.")
        return

    if TEST_MODE and len(pending) > TEST_LIMIT:
        pending = pending[:TEST_LIMIT]
        log_warning(f"TEST MODE: capped at {TEST_LIMIT}")

    stats = {'total': len(pending), 'with_messages': 0, 'no_messages': 0, 'messages_synced': 0, 'notes': 0}

    for idx, row in enumerate(pending, 1):
        phone           = row['phone_number']
        name            = row['name'] or phone
        contact_id      = row['ghl_contact_id']
        conversation_id = row['ghl_convo_id']

        log(f"\n[{idx}/{stats['total']}] {name} ({phone})")

        # Fetch all messages then filter by cutoff
        messages = fetch_messages(phone)
        before   = len(messages)
        messages = [m for m in messages if (m.get("timestamp") or "")[:10] <= MESSAGE_CUTOFF_DATE]
        dropped  = before - len(messages)
        if dropped:
            log_info(f"Dropped {dropped} message(s) after {MESSAGE_CUTOFF_DATE}")

        if not messages:
            stats['no_messages'] += 1
            log_info("No messages in range")
            try:
                supabase.table("contacts").update({
                    "has_messages":    False,
                    "messages_done":   True,
                    "messages_done_at": datetime.utcnow().isoformat(),
                }).eq("phone_number", phone).execute()
            except Exception as e:
                log_error(f"Supabase write failed: {e}")
            time.sleep(0.5)
            continue

        stats['with_messages'] += 1
        synced = 0

        for message in messages:
            if message.get('status') == 'note':
                note_text = message.get('text', '').strip()
                if note_text:
                    ts = message.get('timestamp', datetime.now().isoformat())
                    if create_contact_note(contact_id, f"Note ({ts[:10]})", note_text):
                        stats['notes'] += 1
                time.sleep(0.3)
                continue

            if push_message_to_ghl(message, contact_id, conversation_id):
                synced += 1
                stats['messages_synced'] += 1
            time.sleep(0.3)

        log_success(f"Synced {synced}/{len(messages)} messages")

        try:
            supabase.table("contacts").update({
                "has_messages":    True,
                "messages_done":   True,
                "messages_done_at": datetime.utcnow().isoformat(),
            }).eq("phone_number", phone).execute()
        except Exception as e:
            log_error(f"Supabase write failed: {e}")

        time.sleep(1)

    log(f"\nSCRIPT 2 COMPLETE")
    log(f"  Total: {stats['total']} | With messages: {stats['with_messages']} | No messages: {stats['no_messages']}")
    log(f"  Messages synced: {stats['messages_synced']} | Notes created: {stats['notes']}")
    log(f"  Log: {LOG_FILE}")

if __name__ == "__main__":
    run()
