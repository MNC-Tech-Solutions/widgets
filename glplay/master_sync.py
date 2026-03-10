import http.client
import json
import csv
import time
import os
from datetime import datetime, timedelta
from urllib.parse import urlencode

# ============================================================================
# CONFIGURATION
# ============================================================================
GHL_TOKEN = "pit-a0089695-3fb5-4dcd-8659-f41c038f6611"
LOCATION_ID = "e9bA8duUxZTuI4HbWSaY"
CSV_FILE = "prospect_1.csv"

# NexCRM API credentials
NEXCRM_CLIENT_ID = "bda337e5a7519be61e3a1b127688fd1f"
NEXCRM_CLIENT_SECRET = "995c8a37-1be2-44ee-b1a4-77b0e74d5d19"

# Token management
current_access_token = None
current_refresh_token = None
token_expiry_time = None

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
    
    # Print to console
    print(message)
    
    # Write to log file
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(log_entry + "\n")

def log_error(message):
    """Log error message"""
    log(f"✗ {message}", "ERROR")

def log_success(message):
    """Log success message"""
    log(f"✓ {message}", "SUCCESS")

def log_warning(message):
    """Log warning message"""
    log(f"⚠ {message}", "WARNING")

def log_info(message):
    """Log info message"""
    log(f"  {message}", "INFO")

# ============================================================================
# NEXCRM TOKEN MANAGEMENT
# ============================================================================
def get_nexcrm_refresh_token():
    """Get refresh token from NexCRM API using client credentials"""
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    
    params = {
        'client_id': NEXCRM_CLIENT_ID,
        'client_secret': NEXCRM_CLIENT_SECRET
    }
    
    try:
        conn.request("GET", f"/cloud/conversationapis/v1/oauth_login?{urlencode(params)}")
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        
        if 'refresh_token' in response:
            log_success("NexCRM refresh token obtained")
            return response['refresh_token']
        else:
            log_error(f"Error getting refresh token: {response}")
            return None
    except Exception as e:
        log_error(f"Exception getting refresh token: {str(e)}")
        return None
    finally:
        conn.close()

def get_nexcrm_access_token(refresh_token):
    """Get access token from NexCRM API using refresh token"""
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    
    params = {
        'refresh_token': refresh_token,
        'client_id': NEXCRM_CLIENT_ID
    }
    
    try:
        conn.request("GET", f"/cloud/conversationapis/v1/oauth_access?{urlencode(params)}")
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        
        if 'access_token' in response:
            log_success(f"NexCRM access token obtained (expires in {response.get('expires_in', 3600)}s)")
            return {
                'access_token': response['access_token'],
                'refresh_token': response.get('refresh_token'),
                'expires_in': response.get('expires_in', 3600)
            }
        else:
            log_error(f"Error getting access token: {response}")
            return None
    except Exception as e:
        log_error(f"Exception getting access token: {str(e)}")
        return None
    finally:
        conn.close()

def ensure_valid_token():
    """Ensure we have a valid access token, refresh if needed"""
    global current_access_token, current_refresh_token, token_expiry_time
    
    # Check if token is still valid (with 5 minute buffer)
    if (current_access_token and token_expiry_time and 
        datetime.now() < token_expiry_time - timedelta(minutes=5)):
        return current_access_token
    
    log_info("🔄 Refreshing authentication tokens...")
    
    # Get new refresh token
    if not current_refresh_token:
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None
    
    # Get access token
    token_data = get_nexcrm_access_token(current_refresh_token)
    if not token_data:
        log_warning("Retrying with new refresh token...")
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None
        token_data = get_nexcrm_access_token(current_refresh_token)
        if not token_data:
            return None
    
    # Update global token state
    current_access_token = token_data['access_token']
    if token_data.get('refresh_token'):
        current_refresh_token = token_data['refresh_token']
    token_expiry_time = datetime.now() + timedelta(seconds=token_data['expires_in'])
    
    log_success(f"Token valid until: {token_expiry_time.strftime('%Y-%m-%d %H:%M:%S')}")
    
    return current_access_token

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
def fetch_messages(prospect_token):
    """Fetch messages from NexCRM API for a specific prospect"""
    access_token = ensure_valid_token()
    if not access_token:
        log_error("Failed to get valid access token")
        return []
    
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    
    payload = json.dumps({
        "limit": "100",
        "page": "1",
        "start_date": "2025-01-01",
        "end_date": "2026-12-31",
        "prospect_token": prospect_token
    })
    
    headers = {
        'Authorization': f'Bearer {access_token}',
        'Content-Type': 'application/json'
    }
    
    try:
        conn.request("GET", "/cloud/conversationapis/v1/conversations", payload, headers)
        res = conn.getresponse()
        data = res.read()
        response = json.loads(data.decode("utf-8"))
        
        if res.status == 200 and 'data' in response:
            messages = []
            for conversation in response['data']:
                if 'message' in conversation:
                    messages.extend(conversation['message'])
            return messages
        elif res.status == 401:
            log_warning("Token expired, refreshing...")
            global token_expiry_time
            token_expiry_time = None
            access_token = ensure_valid_token()
            if access_token:
                headers['Authorization'] = f'Bearer {access_token}'
                conn.request("GET", "/cloud/conversationapis/v1/conversations", payload, headers)
                res = conn.getresponse()
                data = res.read()
                response = json.loads(data.decode("utf-8"))
                if res.status == 200 and 'data' in response:
                    messages = []
                    for conversation in response['data']:
                        if 'message' in conversation:
                            messages.extend(conversation['message'])
                    return messages
            log_error(f"Error fetching messages after retry: {response}")
            return []
        else:
            log_error(f"Error fetching messages (Status {res.status}): {response}")
            return []
    except Exception as e:
        log_error(f"Exception fetching messages: {str(e)}")
        return []
    finally:
        conn.close()

def push_message_to_ghl(message, contact_id, conversation_id):
    """Push a single message to GHL"""
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    
    endpoint = "/conversations/messages/inbound"
    
    try:
        timestamp = datetime.strptime(message['timestamp'], "%Y-%m-%d %H:%M:%S")
        iso_date = timestamp.isoformat()
    except:
        iso_date = datetime.now().isoformat()
    
    payload = json.dumps({
        "type": "SMS",
        "conversationId": conversation_id,
        "contactId": contact_id,
        "message": message.get('text') or "-",
        "direction": message.get('direction', 'inbound'),
        "date": iso_date
    })
    
    headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': f'Bearer {GHL_TOKEN}',
        'Version': '2021-04-15'
    }
    
    try:
        conn.request("POST", endpoint, payload, headers)
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
        f.write("GHL MIGRATION LOG\n")
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
        
        with open(CSV_FILE, 'r', encoding='utf-8') as file:
            csv_reader = csv.DictReader(file)
            
            for idx, row in enumerate(csv_reader, 1):
                stats['total_prospects'] += 1
                
                name = row.get('name', '').strip()
                contact = row.get('contact', '').strip()
                prospect_token = row.get('prospect_token', '').strip()
                
                log(f"\n[{idx}] Processing: {name} ({contact})")
                
                # Create contact
                contact_id, contact_error = create_contact(name, contact)
                
                if contact_id:
                    stats['contacts_created'] += 1
                    time.sleep(0.5)
                    
                    # Create conversation
                    conversation_id, conversation_error = create_conversation(contact_id)
                    
                    if conversation_id:
                        stats['conversations_created'] += 1
                        
                        mapping_data.append({
                            "name": name,
                            "phone": contact,
                            "prospect_token": prospect_token,
                            "contact_id": contact_id,
                            "conversation_id": conversation_id,
                            "created_at": datetime.now().isoformat()
                        })
                        log_info("Mapping saved")
                    else:
                        failed_data.append({
                            "row_number": idx,
                            "name": name,
                            "phone": contact,
                            "prospect_token": prospect_token,
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
                        "phone": contact,
                        "prospect_token": prospect_token,
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
        log("PHASE 2: SYNCING MESSAGES FROM NEXCRM")
        log("=" * 70 + "\n")
        
        # Initialize NexCRM authentication
        if not ensure_valid_token():
            log_error("Failed to authenticate with NexCRM. Skipping message sync.")
            return
        
        log(f"\nSyncing messages for {len(mapping_data)} contacts\n")
        
        for idx, contact in enumerate(mapping_data, 1):
            name = contact['name']
            prospect_token = contact['prospect_token']
            contact_id = contact['contact_id']
            conversation_id = contact['conversation_id']
            
            log(f"\n[{idx}/{len(mapping_data)}] Syncing: {name}")
            log_info(f"Prospect Token: {prospect_token}")
            
            # Fetch messages
            messages = fetch_messages(prospect_token)
            
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
        log_error(f"CSV file '{CSV_FILE}' not found!")
    except Exception as e:
        log_error(f"Error during migration: {str(e)}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    run_migration()