import http.client
import json
import time
import os
from datetime import datetime

# ============================================================================
# CONFIGURATION
# ============================================================================
GHL_TOKEN = "pit-02cea63b-562a-4808-a724-db8959c228ae"
LOCATION_ID = "THkkSZ21VAMIoOp3RFax"
CONTACTS_FILE = "sample_contact.json"

# ChatDaddy API credentials
CHATDADDY_TOKEN = "apit_eMROwmrJF_i759xkq4KJBUX7v1FtHjYLAmGLAjr0CEE"   # paste accessToken here

# ============================================================================
# FILE MANAGEMENT
# ============================================================================
def get_unique_filename(base_filename):
    """If file exists, append a timestamp to avoid overwriting"""
    if not os.path.exists(base_filename):
        return base_filename

    name, ext = os.path.splitext(base_filename)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return f"{name}_{timestamp}{ext}"

# Initialize unique file paths
suffix_timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
OUTPUT_FILE = get_unique_filename("contact_mapping.json")
FAILED_CONTACTS_FILE = get_unique_filename("failed_contacts.json")
LOG_FILE = get_unique_filename("migration.log")

# ============================================================================
# LOGGING FUNCTIONS
# ============================================================================
def log(message, level="INFO"):
    """Log message to both console and file"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f"[{timestamp}] [{level}] {message}"

    print(message)

    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(log_entry + "\n")

def log_error(message):
    log(f"✗ {message}", "ERROR")

def log_success(message):
    log(f"✓ {message}", "SUCCESS")

def log_warning(message):
    log(f"⚠ {message}", "WARNING")

def log_info(message):
    log(f"  {message}", "INFO")

# ============================================================================
# GHL CONTACT & CONVERSATION CREATION
# ============================================================================
def create_contact(first_name, phone):
    """Create a contact in GHL and return the contact ID"""
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")

    payload = json.dumps({
        "firstName": first_name,
        "lastName": "-",
        "name": f"{first_name} -",
        "locationId": LOCATION_ID,
        "phone": phone
    })

    headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': f'Bearer {GHL_TOKEN}',
        'Version': '2021-07-28'
    }

    try:
        conn.request("POST", "/contacts/", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))

        if res.status == 200 or res.status == 201:
            contact_id = response.get('contact', {}).get('id') or response.get('id')
            log_success(f"Contact created: {first_name} - ID: {contact_id}")
            return contact_id, None
        else:
            log_error(f"Error creating contact {first_name}: {response}")
            return None, response
    except Exception as e:
        log_error(f"Exception creating contact {first_name}: {str(e)}")
        return None, {'error': str(e)}
    finally:
        conn.close()

def create_conversation(contact_id):
    """Create a conversation in GHL and return the conversation ID"""
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")

    payload = json.dumps({
        "locationId": LOCATION_ID,
        "contactId": contact_id
    })

    headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': f'Bearer {GHL_TOKEN}',
        'Version': '2021-04-15'
    }

    try:
        conn.request("POST", "/conversations/", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))

        if res.status == 200 or res.status == 201:
            conversation_id = response.get('conversation', {}).get('id') or response.get('id')
            log_info(f"Conversation created: {conversation_id}")
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
# MESSAGE SYNC FUNCTIONS
# ============================================================================
def fetch_messages(account_id, phone_number):
    """Fetch messages from ChatDaddy API for a specific contact"""
    conn = http.client.HTTPSConnection("api.chatdaddy.tech")

    headers = {
        'Authorization': f'Bearer {CHATDADDY_TOKEN}',
        'Content-Type': 'application/json'
    }

    try:
        conn.request("GET", f"/im/messages/acc_30528e6f-8033-4303-8d_3706/{phone_number}", "", headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))

        if res.status == 200:
            return response.get("messages", [])
        else:
            log_error(f"Error fetching messages (Status {res.status}): {response}")
            return []
    except Exception as e:
        log_error(f"Exception fetching messages: {str(e)}")
        return []
    finally:
        conn.close()

def push_message_to_ghl(message, contact_id, conversation_id):
    """Push a single ChatDaddy message to GHL"""
    text = message.get('text') or ""
    attachments_raw = message.get('attachments') or []
    attachment_urls = [a["url"] for a in attachments_raw if a.get("url")]
    has_action = 'action' in message
    is_note = message.get('status') == 'note'

    # Skip system/note messages with no content
    if not text and not attachment_urls and (is_note or has_action):
        log_info("Skipping system/note message")
        return True

    # Parse timestamp
    try:
        iso_date = message.get("timestamp", "").replace("Z", "+00:00") or datetime.now().isoformat()
    except:
        iso_date = datetime.now().isoformat()

    # Determine direction
    if message.get("fromMe") is True:
        direction = "outbound"
    elif str(message.get("senderContactId", "")).startswith("603"):
        direction = "outbound"
    else:
        direction = "inbound"

    # Always use /conversations/messages — supports both directions and attachments
    # /conversations/messages/inbound does NOT support the attachments field
    endpoint = "/conversations/messages/inbound"

    msg_type = "SMS" if attachment_urls else "WhatsApp"

    ghl_payload = {
        "type": msg_type,
        "conversationId": conversation_id,
        "contactId": contact_id,
        "message": text if text else "",
        "direction": direction,
        "date": iso_date,
    }
    if attachment_urls:
        ghl_payload["attachments"] = attachment_urls

    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': f'Bearer {GHL_TOKEN}',
        'Version': '2021-04-15'
    }

    try:
        conn.request("POST", endpoint, json.dumps(ghl_payload), headers)
        res = conn.getresponse()
        data = res.read()

        if res.status == 200 or res.status == 201:
            return True
        else:
            response = json.loads(data.decode("utf-8"))
            log_error(f"Message sync failed: {response}")
            return False
    except Exception as e:
        log_error(f"Exception pushing message: {str(e)}")
        return False
    finally:
        conn.close()

# ============================================================================
# MAIN MIGRATION PROCESS
# ============================================================================
def run_migration():
    """Main migration function - creates contacts, conversations, and syncs messages"""

    # Initialize log file
    with open(LOG_FILE, 'w', encoding='utf-8') as f:
        f.write("=" * 70 + "\n")
        f.write("GHL MIGRATION LOG (ChatDaddy source)\n")
        f.write("=" * 70 + "\n")
        f.write(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Output File: {OUTPUT_FILE}\n")
        f.write(f"Log File: {LOG_FILE}\n")
        f.write("=" * 70 + "\n\n")

    log("\n" + "=" * 70)
    log("STARTING GHL MIGRATION - CONTACTS, CONVERSATIONS & MESSAGES")
    log("=" * 70 + "\n")

    # Statistics
    stats = {
        'total_prospects': 0,
        'contacts_created': 0,
        'conversations_created': 0,
        'contacts_failed': 0,
        'messages_synced': 0,
        'prospects_with_messages': 0
    }

    mapping_data = []
    failed_data = []

    try:
        # ====================================================================
        # PHASE 1: CREATE CONTACTS & CONVERSATIONS
        # ====================================================================
        log("\n" + "=" * 70)
        log("PHASE 1: CREATING CONTACTS & CONVERSATIONS")
        log("=" * 70 + "\n")

        with open(CONTACTS_FILE, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # TODO: paginate via nextPage — real runs should follow data["nextPage"]
        #       to fetch subsequent pages until nextPage is absent or empty.
        for idx, contact in enumerate(data["contacts"], 1):
            stats['total_prospects'] += 1

            platform_names = contact.get('platformNames') or []
            name = platform_names[0] if platform_names else contact.get('phoneNumber', '')
            phone = contact.get('phoneNumber', '').strip()
            account_id = contact.get('accountId', '').strip()

            log(f"\n[{idx}] Processing: {name} ({phone})")

            # Create contact
            contact_id, contact_error = create_contact(name, phone)

            if contact_id:
                stats['contacts_created'] += 1
                time.sleep(0.5)

                # Create conversation
                conversation_id, conversation_error = create_conversation(contact_id)

                if conversation_id:
                    stats['conversations_created'] += 1

                    mapping_data.append({
                        "name": name,
                        "phone": phone,
                        "account_id": account_id,
                        "phone_number": phone,
                        "contact_id": contact_id,
                        "conversation_id": conversation_id,
                        "created_at": datetime.now().isoformat()
                    })
                    log_info("Mapping saved")
                else:
                    failed_data.append({
                        "row_number": idx,
                        "name": name,
                        "phone": phone,
                        "account_id": account_id,
                        "phone_number": phone,
                        "contact_id": contact_id,
                        "failed_step": "create_conversation",
                        "error": conversation_error,
                        "timestamp": datetime.now().isoformat()
                    })
                    log_error("Failed to create conversation")
            else:
                stats['contacts_failed'] += 1
                failed_data.append({
                    "row_number": idx,
                    "name": name,
                    "phone": phone,
                    "account_id": account_id,
                    "phone_number": phone,
                    "contact_id": None,
                    "failed_step": "create_contact",
                    "error": contact_error,
                    "timestamp": datetime.now().isoformat()
                })
                log_error("Failed to create contact")

            time.sleep(1)

        # Save contact mapping
        with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
            json.dump(mapping_data, f, indent=2, ensure_ascii=False)

        if failed_data:
            with open(FAILED_CONTACTS_FILE, 'w', encoding='utf-8') as f:
                json.dump(failed_data, f, indent=2, ensure_ascii=False)

        # Phase 1 Summary
        log("\n" + "=" * 70)
        log("PHASE 1 COMPLETE - SUMMARY")
        log("=" * 70)
        log(f"Total prospects processed: {stats['total_prospects']}")
        log_success(f"Contacts created: {stats['contacts_created']}")
        log_success(f"Conversations created: {stats['conversations_created']}")
        log_error(f"Failed: {stats['contacts_failed']}")
        log("=" * 70 + "\n")

        if not mapping_data:
            log_error("No contacts created successfully. Exiting.")
            return

        # ====================================================================
        # PHASE 2: SYNC MESSAGES
        # ====================================================================
        log("\n" + "=" * 70)
        log("PHASE 2: SYNCING MESSAGES FROM CHATDADDY")
        log("=" * 70 + "\n")

        log(f"\nSyncing messages for {len(mapping_data)} contacts\n")

        for idx, contact in enumerate(mapping_data, 1):
            name = contact['name']
            account_id = contact['account_id']
            phone_number = contact['phone_number']
            contact_id = contact['contact_id']
            conversation_id = contact['conversation_id']

            log(f"\n[{idx}/{len(mapping_data)}] Syncing: {name}")
            log_info(f"Account: {account_id} / Phone: {phone_number}")

            # Fetch messages
            messages = fetch_messages(account_id, phone_number)

            if messages:
                stats['prospects_with_messages'] += 1
                log_info(f"Found {len(messages)} messages")

                synced = 0
                for message in messages:
                    if push_message_to_ghl(message, contact_id, conversation_id):
                        synced += 1
                        stats['messages_synced'] += 1
                    time.sleep(0.3)

                log_success(f"Synced {synced}/{len(messages)} messages")
            else:
                log_info("No messages found")

            time.sleep(1)

        # ====================================================================
        # FINAL SUMMARY
        # ====================================================================
        log("\n" + "=" * 70)
        log("MIGRATION COMPLETE - FINAL SUMMARY")
        log("=" * 70)
        log(f"\nPHASE 1 - CONTACTS & CONVERSATIONS:")
        log(f"  Total prospects:        {stats['total_prospects']}")
        log_success(f"  Contacts created:       {stats['contacts_created']}")
        log_success(f"  Conversations created:  {stats['conversations_created']}")
        log_error(f"  Failed:                 {stats['contacts_failed']}")

        log(f"\nPHASE 2 - MESSAGE SYNC:")
        log_success(f"  Prospects with messages: {stats['prospects_with_messages']}")
        log_success(f"  Total messages synced:   {stats['messages_synced']}")

        log(f"\nOUTPUT FILES:")
        log(f"  - Success mapping: {OUTPUT_FILE}")
        if failed_data:
            log(f"  - Failed contacts: {FAILED_CONTACTS_FILE}")
        log(f"  - Complete log:    {LOG_FILE}")
        log("=" * 70 + "\n")

    except FileNotFoundError:
        log_error(f"Contacts file '{CONTACTS_FILE}' not found!")
    except Exception as e:
        log_error(f"Error during migration: {str(e)}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    run_migration()
