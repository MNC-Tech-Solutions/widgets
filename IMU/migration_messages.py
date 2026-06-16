import http.client
import json
import os
import smtplib
import sys
import time
import traceback
from collections import deque
from datetime import datetime
from email.mime.text import MIMEText
from pathlib import Path

from dotenv import load_dotenv
_env_file = sys.argv[1] if len(sys.argv) > 1 else None
load_dotenv(dotenv_path=_env_file)

import firebase_admin
from firebase_admin import credentials, firestore

# ============================================================================
# CONFIG
# ============================================================================
GHL_TOKEN            = os.getenv("GHL_TOKEN")
LOCATION_ID          = os.getenv("LOCATION_ID")
CHATDADDY_TOKEN      = os.getenv("CHATDADDY_TOKEN")
MESSAGE_CUTOFF_DATE  = os.getenv("MESSAGE_CUTOFF_DATE", "2025-12-31")
NOTE_USER_ID         = os.getenv("NOTE_USER_ID")
FIREBASE_PROJECT_ID  = os.getenv("FIREBASE_PROJECT_ID")
COLLECTION_NAME      = os.getenv("COLLECTION_NAME", "contacts")
TEST_MODE            = os.getenv("TEST_MODE", "false").lower() == "true"
TEST_LIMIT           = int(os.getenv("TEST_LIMIT", "5"))

_GMAIL_USER = os.getenv("GMAIL_USER")
_GMAIL_PASS = os.getenv("GMAIL_APP_PASSWORD")

REQUIRED = {
    "GHL_TOKEN": GHL_TOKEN,
    "LOCATION_ID": LOCATION_ID,
    "CHATDADDY_TOKEN": CHATDADDY_TOKEN,
    "NOTE_USER_ID": NOTE_USER_ID,
    "FIREBASE_PROJECT_ID": FIREBASE_PROJECT_ID,
}
missing = [k for k, v in REQUIRED.items() if not v]
if missing:
    print(f"[ERROR] Missing env vars: {', '.join(missing)}")
    exit(1)

cred_path = os.getenv("GOOGLE_APPLICATION_CREDENTIALS")
if cred_path:
    firebase_admin.initialize_app(credentials.Certificate(cred_path), {"projectId": FIREBASE_PROJECT_ID})
else:
    firebase_admin.initialize_app(options={"projectId": FIREBASE_PROJECT_ID})

db = firestore.client()

_BASE    = Path(__file__).parent
_MSG_DIR = _BASE / "logs" / "migration_messages"
_MSG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = str(_MSG_DIR / datetime.now().strftime("messages_%Y%m%d_%H%M%S.log"))

# ============================================================================
# LOGGING
# ============================================================================
def send_email(subject, body):
    if not _GMAIL_USER or not _GMAIL_PASS:
        return
    msg = MIMEText(body)
    msg["Subject"] = f"[Migration:{COLLECTION_NAME}] {subject}"
    msg["From"]    = _GMAIL_USER
    msg["To"]      = _GMAIL_USER
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465) as s:
            s.login(_GMAIL_USER, _GMAIL_PASS)
            s.send_message(msg)
    except Exception as e:
        print(f"[EMAIL FAILED] {e}", flush=True)

def log(msg, level="INFO"):
    entry = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] [{level}] {msg}"
    print(entry)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(entry + "\n")

def log_error(m):   log(f"x {m}", "ERROR")
def log_success(m): log(f"✓ {m}", "SUCCESS")
def log_warning(m): log(f"! {m}", "WARNING")
def log_info(m):    log(f"  {m}", "INFO")

# ============================================================================
# RATE LIMITER
# ============================================================================
class RateLimiter:
    def __init__(self, max_per_window=100, window_seconds=10, daily_limit=200_000):
        self.max_per_window = max_per_window
        self.window = window_seconds
        self.timestamps = deque()
        self.daily_count = 0
        self.daily_limit = daily_limit

    def wait(self):
        if self.daily_count >= self.daily_limit:
            msg = f"Daily GHL limit ({self.daily_limit:,}) reached — stopping. Rerun tomorrow to continue."
            log_error(msg)
            send_email("Daily GHL limit reached", msg)
            raise SystemExit(0)

        now = time.time()
        while self.timestamps and now - self.timestamps[0] >= self.window:
            self.timestamps.popleft()

        if len(self.timestamps) >= self.max_per_window:
            sleep_for = self.window - (now - self.timestamps[0]) + 0.05
            if sleep_for > 0:
                time.sleep(sleep_for)

        self.timestamps.append(time.time())
        self.daily_count += 1

rate_limiter = RateLimiter()

# ============================================================================
# CHATDADDY — FETCH MESSAGES
# ============================================================================
def fetch_messages(account_id, chatdaddy_id):
    all_messages = []
    cursor = None
    page_num = 0
    while True:
        page_num += 1
        conn = http.client.HTTPSConnection("api.chatdaddy.tech", timeout=30)
        url = f"/im/messages/{account_id}/{chatdaddy_id}"
        if cursor:
            url += f"?beforeId={cursor}"
        headers = {"Authorization": f"Bearer {CHATDADDY_TOKEN}", "Content-Type": "application/json"}
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
    rate_limiter.wait()
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com", timeout=30)
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {GHL_TOKEN}",
        "Version": "2021-04-15",
    }
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
    text            = (message.get("text") or "").strip()
    attachments_raw = message.get("attachments") or []
    attachment_urls = [a["url"] for a in attachments_raw if a.get("url")]

    is_note    = message.get("status") == "note"
    has_action = "action" in message
    if not text and not attachment_urls and (is_note or has_action):
        log_info("Skipping system/action message")
        return True
    if not text and not attachment_urls:
        log_info("Skipping empty message")
        return True

    try:
        iso_date = message.get("timestamp", "").replace("Z", "+00:00") or datetime.now().isoformat()
    except Exception:
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
# GHL — CREATE NOTE
# ============================================================================
def create_contact_note(contact_id, title, body):
    rate_limiter.wait()
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com", timeout=30)
    payload = json.dumps({"userId": NOTE_USER_ID, "body": body, "title": title, "color": "#FFAA00", "pinned": False})
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {GHL_TOKEN}",
        "Version": "2023-02-21",
    }
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
# FIRESTORE
# ============================================================================
def get_pending(limit=500):
    return list(db.collection(COLLECTION_NAME).where("status", "==", "convo_created").limit(limit).stream())

# ============================================================================
# MAIN
# ============================================================================
def run():
    with open(LOG_FILE, "w", encoding="utf-8") as f:
        f.write("=" * 70 + "\n")
        f.write("GHL MIGRATION — MESSAGES\n")
        f.write("=" * 70 + "\n")
        f.write(f"Started:   {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Cutoff:    on or before {MESSAGE_CUTOFF_DATE}\n")
        f.write(f"Test Mode: {'ON (limit: ' + str(TEST_LIMIT) + ')' if TEST_MODE else 'OFF'}\n")
        f.write("=" * 70 + "\n\n")

    processed = 0

    while True:
        batch = get_pending()
        if not batch:
            log_info("No pending contacts — all messages synced.")
            break

        if TEST_MODE and processed + len(batch) > TEST_LIMIT:
            batch = batch[:TEST_LIMIT - processed]

        log_info(f"Batch of {len(batch)} contacts to process...")

        for doc in batch:
            d            = doc.to_dict()
            phone        = doc.id
            name         = d.get("name") or phone
            contact_id   = d.get("ghl_contact_id")
            convo_id     = d.get("ghl_convo_id")
            account_id   = d.get("account_id") or ""
            chatdaddy_id = d.get("chatdaddy_id") or phone

            log_info(f"[{phone}] {name}")

            try:
                all_messages = fetch_messages(account_id, chatdaddy_id)
                has_msg_before_cutoff = any(
                    (m.get("timestamp") or "")[:10] <= MESSAGE_CUTOFF_DATE for m in all_messages
                )
                messages = [m for m in all_messages if (m.get("timestamp") or "")[:10] <= MESSAGE_CUTOFF_DATE]
                dropped  = len(all_messages) - len(messages)
                if dropped:
                    log_info(f"Dropped {dropped} message(s) after {MESSAGE_CUTOFF_DATE}")

                synced = 0
                notes  = 0

                for message in messages:
                    if message.get("status") == "note":
                        note_text = message.get("text", "").strip()
                        if note_text:
                            ts = message.get("timestamp", datetime.now().isoformat())
                            if create_contact_note(contact_id, f"Note ({ts[:10]})", note_text):
                                notes += 1
                        time.sleep(0.3)
                        continue

                    if push_message_to_ghl(message, contact_id, convo_id):
                        synced += 1
                    time.sleep(0.3)

                log_success(f"Synced {synced}/{len(messages)} messages, {notes} notes — {name} ({phone})")
                update = {
                    "status":               "messages_done",
                    "msg_before_cutoff":    has_msg_before_cutoff,
                    "updated_at":           firestore.SERVER_TIMESTAMP,
                }
                doc.reference.update(update)

            except SystemExit:
                raise
            except Exception as e:
                log_error(f"Failed {phone}: {e}")
                doc.reference.update({
                    "status":     "error_messages",
                    "error":      str(e),
                    "updated_at": firestore.SERVER_TIMESTAMP,
                })

            processed += 1

        if TEST_MODE and processed >= TEST_LIMIT:
            log_warning(f"TEST MODE: reached limit of {TEST_LIMIT}")
            break

    log_info(f"Done — {processed} contacts processed. Log: {LOG_FILE}")
    send_email("Migration complete", f"All messages migrated.\n\nCollection: {COLLECTION_NAME}\nProcessed: {processed}\nLog: {LOG_FILE}")

if __name__ == "__main__":
    try:
        run()
    except SystemExit:
        raise
    except Exception as e:
        tb = traceback.format_exc()
        log_error(f"Unhandled crash: {e}\n{tb}")
        send_email("CRASH — migration stopped", f"Unhandled exception in migration_messages.py\n\nCollection: {COLLECTION_NAME}\n\n{tb}")
        raise
