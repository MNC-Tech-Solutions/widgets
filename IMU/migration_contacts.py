import http.client
import json
import time
import os
import smtplib
from email.mime.text import MIMEText
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
NOTE_USER_ID         = os.getenv("NOTE_USER_ID")
SUPABASE_URL         = os.getenv("SUPABASE_URL")
SUPABASE_KEY         = os.getenv("SUPABASE_KEY")
TEST_MODE            = os.getenv("TEST_MODE", "false").lower() == "true"
TEST_LIMIT           = int(os.getenv("TEST_LIMIT", "5"))

GMAIL_USER       = os.getenv("GMAIL_USER")
GMAIL_PASSWORD   = os.getenv("GMAIL_APP_PASSWORD")
NOTIFY_EMAIL     = os.getenv("NOTIFY_EMAIL")

REQUIRED = {
    "GHL_TOKEN": GHL_TOKEN, "LOCATION_ID": LOCATION_ID,
    "CHATDADDY_TOKEN": CHATDADDY_TOKEN, "NOTE_USER_ID": NOTE_USER_ID,
    "SUPABASE_URL": SUPABASE_URL, "SUPABASE_KEY": SUPABASE_KEY,
}
missing = [k for k, v in REQUIRED.items() if not v]
if missing:
    print(f"[ERROR] Missing env vars: {', '.join(missing)}")
    exit(1)

supabase = create_client(SUPABASE_URL, SUPABASE_KEY)
LOG_FILE = datetime.now().strftime("contacts_%Y%m%d_%H%M%S.log")

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
# CHATDADDY — FETCH CONTACTS
# ============================================================================
def fetch_all_contacts():
    all_contacts = []
    cursor = None
    page_num = 0
    log_info("Fetching contacts from ChatDaddy...")
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
            data = json.loads(res.read().decode("utf-8"))
            if res.status != 200:
                log_error(f"Failed to fetch page {page_num}: {data}")
                break
            contacts = data.get("contacts", [])
            next_cursor = data.get("nextPage")
            if page_num == 1 and "total" in data:
                log_info(f"Total contacts reported by API: {data['total']}")
            log_info(f"Page {page_num}: {len(contacts)} contacts (running total: {len(all_contacts) + len(contacts)})")
            all_contacts.extend(contacts)
            if TEST_MODE and len(all_contacts) >= TEST_LIMIT:
                all_contacts = all_contacts[:TEST_LIMIT]
                log_warning(f"TEST MODE: capped at {TEST_LIMIT}")
                break
            if not next_cursor:
                log_info("All pages fetched")
                break
            cursor = next_cursor
            time.sleep(0.3)
        except Exception as e:
            log_error(f"Exception on page {page_num}: {e}")
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
        data = json.loads(res.read().decode("utf-8"))
        if res.status in (200, 201):
            contact_id = data.get('contact', {}).get('id') or data.get('id')
            log_success(f"GHL contact created: {name} → {contact_id}")
            return contact_id, None
        else:
            log_error(f"Error creating contact {name}: {data}")
            return None, data
    except Exception as e:
        log_error(f"Exception creating contact {name}: {e}")
        return None, {'error': str(e)}
    finally:
        conn.close()

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
        data = json.loads(res.read().decode("utf-8"))
        if res.status in (200, 201):
            convo_id = data.get('conversation', {}).get('id') or data.get('id')
            log_info(f"GHL conversation created: {convo_id}")
            return convo_id, None
        else:
            log_error(f"Error creating conversation: {data}")
            return None, data
    except Exception as e:
        log_error(f"Exception creating conversation: {e}")
        return None, {'error': str(e)}
    finally:
        conn.close()

# ============================================================================
# GHL — CREATE NOTE
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

def send_completion_email(stats):
    if not GMAIL_USER or not GMAIL_PASSWORD or not NOTIFY_EMAIL:
        log_warning("Email config missing — skipping notification")
        return
    subject = "✅ GHL Migration Script 1 Complete — Ready for your review"
    body = f"""Script 1 (Contacts & Conversations) has finished.

Results:
  Total contacts processed : {stats['total']}
  Created in GHL           : {stats['created']}
  Skipped (already done)   : {stats['skipped']}
  Failed                   : {stats['failed']}
  Notes created            : {stats['notes']}
  Log file                 : {LOG_FILE}

Please check:
  1. Supabase → contacts table — verify the data looks correct
  2. GHL — spot check a few contacts and conversations

When ready, SSH into the VM and run Script 2:
  screen -r migration
  source ~/venv/bin/activate
  python -u migration_messages.py
"""
    try:
        msg = MIMEText(body)
        msg['Subject'] = subject
        msg['From']    = GMAIL_USER
        msg['To']      = NOTIFY_EMAIL
        with smtplib.SMTP_SSL('smtp.gmail.com', 465) as server:
            server.login(GMAIL_USER, GMAIL_PASSWORD)
            server.send_message(msg)
        log_success(f"Notification email sent to {NOTIFY_EMAIL}")
    except Exception as e:
        log_error(f"Failed to send email: {e}")

def build_tags_note_body(tags):
    lines = []
    for tag in tags:
        name  = tag.get('name', '').strip()
        value = tag.get('value', '').strip() if tag.get('value') else ''
        lines.append(f"{name}: {value}" if value else name)
    return "\n".join(filter(None, lines))

# ============================================================================
# MAIN
# ============================================================================
def run():
    with open(LOG_FILE, 'w', encoding='utf-8') as f:
        f.write("=" * 70 + "\n")
        f.write("GHL MIGRATION — SCRIPT 1: CONTACTS\n")
        f.write("=" * 70 + "\n")
        f.write(f"Started:   {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Test Mode: {'ON (limit: ' + str(TEST_LIMIT) + ')' if TEST_MODE else 'OFF'}\n")
        f.write("=" * 70 + "\n\n")

    # Load existing Supabase state (for resumability)
    try:
        rows = supabase.table("contacts").select(
            "phone_number, ghl_contact_id, ghl_convo_id, contacts_done"
        ).execute()
        existing = {r["phone_number"]: r for r in rows.data}
        log_info(f"Loaded {len(existing)} existing rows from Supabase")
    except Exception as e:
        log_error(f"Failed to load Supabase state: {e}")
        existing = {}

    contacts = fetch_all_contacts()
    if not contacts:
        log_error("No contacts fetched. Exiting.")
        return

    stats = {'total': len(contacts), 'created': 0, 'skipped': 0, 'failed': 0, 'notes': 0}

    for idx, contact in enumerate(contacts, 1):
        platform_names = contact.get('platformNames') or []
        phone          = contact.get('phoneNumber', '').strip()
        name           = platform_names[0].strip() if platform_names else phone

        log(f"\n[{idx}/{stats['total']}] {name} ({phone})")

        # Fully done — skip
        if existing.get(phone, {}).get("contacts_done"):
            log_info("Already done — skipping")
            stats['skipped'] += 1
            continue

        # Contact created but conversation missing — retry convo only
        if existing.get(phone, {}).get("ghl_contact_id"):
            log_info("Contact exists, conversation missing — retrying conversation")
            contact_id = existing[phone]["ghl_contact_id"]
        else:
            # Create GHL contact
            contact_id, err = create_contact(name, phone)
            if not contact_id:
                stats['failed'] += 1
                try:
                    supabase.table("contacts").upsert({
                        "phone_number": phone, "name": name,
                        "error": json.dumps(err),
                    }).execute()
                except Exception as e:
                    log_error(f"Supabase write failed: {e}")
                time.sleep(1)
                continue

            # Save contact ID immediately (so a crash here doesn't lose it)
            try:
                supabase.table("contacts").upsert({
                    "phone_number": phone, "name": name,
                    "ghl_contact_id": contact_id,
                }).execute()
            except Exception as e:
                log_error(f"Supabase write failed (contact): {e}")

            stats['created'] += 1
            time.sleep(0.3)

            # Create note for tags
            tags = contact.get('tags') or []
            if tags:
                body = build_tags_note_body(tags)
                if body:
                    create_contact_note(contact_id, "Tags", body)
                    stats['notes'] += 1
                    time.sleep(0.3)

        # Create GHL conversation
        convo_id, err = create_conversation(contact_id)
        if not convo_id:
            stats['failed'] += 1
            try:
                supabase.table("contacts").update({
                    "error": json.dumps(err)
                }).eq("phone_number", phone).execute()
            except Exception as e:
                log_error(f"Supabase write failed (convo error): {e}")
            time.sleep(1)
            continue

        # Write final record
        try:
            supabase.table("contacts").update({
                "ghl_convo_id":     convo_id,
                "contacts_done":    True,
                "contacts_done_at": datetime.utcnow().isoformat(),
                "error":            None,
            }).eq("phone_number", phone).execute()
        except Exception as e:
            log_error(f"Supabase write failed (final): {e}")

        time.sleep(1)

    log(f"\nSCRIPT 1 COMPLETE")
    log(f"  Total: {stats['total']} | Created: {stats['created']} | Skipped: {stats['skipped']} | Failed: {stats['failed']} | Notes: {stats['notes']}")
    log(f"  Log: {LOG_FILE}")
    send_completion_email(stats)

if __name__ == "__main__":
    run()
