import http.client
import json
import time
import logging
from datetime import datetime, timedelta
from urllib.parse import urlencode

# ── Logging setup ──────────────────────────────────────────────────────────────
LOG_FILE = f"sync_messages_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=[
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
        logging.StreamHandler()          # still prints to console
    ]
)
log = logging.getLogger(__name__)

# ── Configuration ──────────────────────────────────────────────────────────────
GHL_TOKEN = "pit-a0089695-3fb5-4dcd-8659-f41c038f6611"
MAPPING_FILE = "contact_mapping_20260311_101246.json"

NEXCRM_CLIENT_ID     = "bda337e5a7519be61e3a1b127688fd1f"
NEXCRM_CLIENT_SECRET = "995c8a37-1be2-44ee-b1a4-77b0e74d5d19"

# ── Token state ────────────────────────────────────────────────────────────────
current_access_token  = None
current_refresh_token = None
token_expiry_time     = None


# ── Auth helpers ───────────────────────────────────────────────────────────────

def get_nexcrm_refresh_token():
    """Get refresh token from NexCRM API using client credentials."""
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    params = {
        'client_id':     NEXCRM_CLIENT_ID,
        'client_secret': NEXCRM_CLIENT_SECRET
    }
    try:
        conn.request("GET", f"/cloud/conversationapis/v1/oauth_login?{urlencode(params)}")
        res  = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))

        if 'refresh_token' in response:
            log.info("NexCRM refresh token obtained")
            return response['refresh_token']
        else:
            log.error(f"Failed to get refresh token: {response}")
            return None
    except Exception as e:
        log.error(f"Exception getting refresh token: {e}", exc_info=True)
        return None
    finally:
        conn.close()


def get_nexcrm_access_token(refresh_token):
    """Get access token from NexCRM API using a refresh token."""
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    params = {
        'refresh_token': refresh_token,
        'client_id':     NEXCRM_CLIENT_ID
    }
    try:
        conn.request("GET", f"/cloud/conversationapis/v1/oauth_access?{urlencode(params)}")
        res  = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))

        if 'access_token' in response:
            log.info(f"NexCRM access token obtained (expires in {response.get('expires_in', 3600)}s)")
            return {
                'access_token':  response['access_token'],
                'refresh_token': response.get('refresh_token'),
                'expires_in':    response.get('expires_in', 3600)
            }
        else:
            log.error(f"Failed to get access token: {response}")
            return None
    except Exception as e:
        log.error(f"Exception getting access token: {e}", exc_info=True)
        return None
    finally:
        conn.close()


def ensure_valid_token():
    """Return a valid access token, refreshing if necessary."""
    global current_access_token, current_refresh_token, token_expiry_time

    if (current_access_token and token_expiry_time and
            datetime.now() < token_expiry_time - timedelta(minutes=5)):
        return current_access_token

    log.info("Refreshing authentication tokens…")

    if not current_refresh_token:
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None

    token_data = get_nexcrm_access_token(current_refresh_token)
    if not token_data:
        log.warning("Retrying with a brand-new refresh token…")
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None
        token_data = get_nexcrm_access_token(current_refresh_token)
        if not token_data:
            return None

    current_access_token = token_data['access_token']
    if token_data.get('refresh_token'):
        current_refresh_token = token_data['refresh_token']
    token_expiry_time = datetime.now() + timedelta(seconds=token_data['expires_in'])

    log.info(f"Token valid until {token_expiry_time.strftime('%Y-%m-%d %H:%M:%S')}")
    return current_access_token


# ── Core API calls ─────────────────────────────────────────────────────────────

def fetch_messages(prospect_token, contact_name="?"):
    """Fetch messages from NexCRM for a specific prospect. Returns list (may be empty)."""
    access_token = ensure_valid_token()
    if not access_token:
        log.error(f"[{contact_name}] Cannot fetch messages — no valid access token")
        return []

    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    payload = json.dumps({
        "limit":           "1",
        "page":            "1",
        "start_date":      "2025-01-01",
        "end_date":        "2026-12-31",
        "prospect_token":  prospect_token
    })
    headers = {
        'Authorization': f'Bearer {access_token}',
        'Content-Type':  'application/json'
    }

    try:
        conn.request("GET", "/cloud/conversationapis/v1/conversations", payload, headers)
        res  = conn.getresponse()
        data = res.read()

        if res.status == 401:
            log.warning(f"[{contact_name}] Token expired mid-request — refreshing and retrying…")
            global token_expiry_time
            token_expiry_time = None
            access_token = ensure_valid_token()
            if access_token:
                headers['Authorization'] = f'Bearer {access_token}'
                conn.request("GET", "/cloud/conversationapis/v1/conversations", payload, headers)
                res  = conn.getresponse()
                data = res.read()
            else:
                log.error(f"[{contact_name}] Token refresh failed — skipping contact")
                return []

        response = json.loads(data.decode("utf-8"))

        if res.status in (200, 201) and 'data' in response:
            messages = []
            for conversation in response['data']:
                if 'message' in conversation:
                    messages.extend(conversation['message'])
            return messages
        else:
            log.error(f"[{contact_name}] Fetch messages failed (HTTP {res.status}): {response}")
            return []

    except Exception as e:
        log.error(f"[{contact_name}] Exception fetching messages: {e}", exc_info=True)
        return []
    finally:
        conn.close()


def push_message_to_ghl(message, contact_id, conversation_id, contact_name="?", msg_idx=0):
    """Push a single message to GHL. Returns True on success."""
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")

    try:
        timestamp = datetime.strptime(message['timestamp'], "%Y-%m-%d %H:%M:%S")
        iso_date  = timestamp.isoformat()
    except Exception:
        iso_date = datetime.now().isoformat()

    payload = json.dumps({
        "type":           "SMS",
        "conversationId": conversation_id,
        "contactId":      contact_id,
        "message":        message.get('text') or "-",
        "direction":      message.get('direction', 'inbound'),
        "date":           iso_date
    })
    headers = {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': f'Bearer {GHL_TOKEN}',
        'Version':       '2021-04-15'
    }

    try:
        conn.request("POST", "/conversations/messages/inbound", payload, headers)
        res  = conn.getresponse()
        data = res.read()

        if res.status in (200, 201):
            log.debug(f"[{contact_name}] Message #{msg_idx} pushed OK")
            return True
        else:
            response = json.loads(data.decode("utf-8"))
            log.error(
                f"[{contact_name}] Message #{msg_idx} push failed "
                f"(HTTP {res.status}): {response}"
            )
            return False
    except Exception as e:
        log.error(f"[{contact_name}] Exception pushing message #{msg_idx}: {e}", exc_info=True)
        return False
    finally:
        conn.close()


# ── Main orchestrator ──────────────────────────────────────────────────────────

def process_messages():
    """Load the contact mapping and sync every contact's messages to GHL."""
    log.info("=" * 60)
    log.info("Starting Message Migration to GHL")
    log.info(f"Log file: {LOG_FILE}")
    log.info("=" * 60)

    # ── Load mapping file ──────────────────────────────────────────────────────
    try:
        with open(MAPPING_FILE, 'r', encoding='utf-8') as f:
            contacts = json.load(f)
    except FileNotFoundError:
        log.critical(
            f"Mapping file '{MAPPING_FILE}' not found. "
            "Run '1_create_contacts_conversations.py' first."
        )
        return
    except json.JSONDecodeError as e:
        log.critical(f"Mapping file is not valid JSON: {e}")
        return
    except Exception as e:
        log.critical(f"Unexpected error loading mapping file: {e}", exc_info=True)
        return

    log.info(f"Loaded {len(contacts)} contacts from {MAPPING_FILE}")

    # ── Initial authentication ─────────────────────────────────────────────────
    if not ensure_valid_token():
        log.critical("Authentication with NexCRM failed — aborting.")
        return

    # ── Per-contact stats ──────────────────────────────────────────────────────
    total_synced   = 0
    total_failed   = 0
    contacts_ok    = 0
    contacts_err   = 0          # contacts that raised an unexpected exception
    skipped        = []         # contacts with errors (for summary)

    for idx, contact in enumerate(contacts, 1):
        name            = contact.get('name', 'Unknown')
        prospect_token  = contact.get('prospect_token', '')
        contact_id      = contact.get('contact_id', '')
        conversation_id = contact.get('conversation_id', '')

        log.info(f"[{idx}/{len(contacts)}] Processing: {name}  (prospect_token={prospect_token})")

        # ── Validate required fields ───────────────────────────────────────────
        if not all([prospect_token, contact_id, conversation_id]):
            log.error(
                f"[{name}] Missing required field(s) — "
                f"prospect_token={bool(prospect_token)}, "
                f"contact_id={bool(contact_id)}, "
                f"conversation_id={bool(conversation_id)} — SKIPPING"
            )
            skipped.append((name, "missing required fields"))
            contacts_err += 1
            continue

        # ── Fetch messages ─────────────────────────────────────────────────────
        try:
            messages = fetch_messages(prospect_token, name)
        except Exception as e:
            log.error(f"[{name}] Unhandled exception during fetch — SKIPPING: {e}", exc_info=True)
            skipped.append((name, f"fetch exception: {e}"))
            contacts_err += 1
            continue

        if not messages:
            log.info(f"[{name}] No messages found — skipping")
            continue

        log.info(f"[{name}] Found {len(messages)} message(s) — syncing…")

        synced  = 0
        failed  = 0

        for msg_idx, message in enumerate(messages, 1):
            try:
                ok = push_message_to_ghl(message, contact_id, conversation_id, name, msg_idx)
                if ok:
                    synced += 1
                else:
                    failed += 1
            except Exception as e:
                log.error(
                    f"[{name}] Unhandled exception on message #{msg_idx} — skipping message: {e}",
                    exc_info=True
                )
                failed += 1

            time.sleep(0.3)   # rate-limit per message

        log.info(f"[{name}] Done — synced {synced}/{len(messages)}, failed {failed}")

        total_synced += synced
        total_failed += failed
        contacts_ok  += 1

        if failed:
            skipped.append((name, f"{failed}/{len(messages)} messages failed"))

        time.sleep(1)   # rate-limit between contacts

    # ── Summary ────────────────────────────────────────────────────────────────
    log.info("=" * 60)
    log.info("Migration Complete")
    log.info(f"  Contacts processed (OK):  {contacts_ok}")
    log.info(f"  Contacts with errors:     {contacts_err}")
    log.info(f"  Total messages synced:    {total_synced}")
    log.info(f"  Total messages failed:    {total_failed}")

    if skipped:
        log.info("  Issues encountered:")
        for name, reason in skipped:
            log.info(f"    • {name}: {reason}")

    log.info("=" * 60)
    log.info(f"Full log saved to: {LOG_FILE}")


if __name__ == "__main__":
    process_messages()