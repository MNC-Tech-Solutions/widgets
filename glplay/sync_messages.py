import http.client
import json
import time
from datetime import datetime, timedelta
from urllib.parse import urlencode

# Configuration
GHL_TOKEN = "pit-a0089695-3fb5-4dcd-8659-f41c038f6611"
MAPPING_FILE = "contact_mapping.json"

# Third-party API credentials
NEXCRM_CLIENT_ID = "bda337e5a7519be61e3a1b127688fd1f"
NEXCRM_CLIENT_SECRET = "995c8a37-1be2-44ee-b1a4-77b0e74d5d19"

# Token management
current_access_token = None
current_refresh_token = None
token_expiry_time = None

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
            print(f"✓ NexCRM refresh token obtained")
            return response['refresh_token']
        else:
            print(f"✗ Error getting refresh token: {response}")
            return None
    except Exception as e:
        print(f"✗ Exception getting refresh token: {str(e)}")
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
            print(f"✓ NexCRM access token obtained (expires in {response.get('expires_in', 3600)}s)")
            return {
                'access_token': response['access_token'],
                'refresh_token': response.get('refresh_token'),  # API returns new refresh token
                'expires_in': response.get('expires_in', 3600)
            }
        else:
            print(f"✗ Error getting access token: {response}")
            return None
    except Exception as e:
        print(f"✗ Exception getting access token: {str(e)}")
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
    
    print("\n🔄 Refreshing authentication tokens...")
    
    # Get new refresh token
    if not current_refresh_token:
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None
    
    # Get access token
    token_data = get_nexcrm_access_token(current_refresh_token)
    if not token_data:
        # If failed, try getting a completely new refresh token
        print("⚠ Retrying with new refresh token...")
        current_refresh_token = get_nexcrm_refresh_token()
        if not current_refresh_token:
            return None
        token_data = get_nexcrm_access_token(current_refresh_token)
        if not token_data:
            return None
    
    # Update global token state
    current_access_token = token_data['access_token']
    if token_data.get('refresh_token'):
        current_refresh_token = token_data['refresh_token']  # Use new refresh token
    token_expiry_time = datetime.now() + timedelta(seconds=token_data['expires_in'])
    
    print(f"✓ Token valid until: {token_expiry_time.strftime('%Y-%m-%d %H:%M:%S')}\n")
    
    return current_access_token

def fetch_messages(prospect_token):
    """Fetch messages from NexCRM API for a specific prospect"""
    # Ensure we have a valid token
    access_token = ensure_valid_token()
    if not access_token:
        print("  ✗ Failed to get valid access token")
        return []
    
    conn = http.client.HTTPSConnection("www.nexcrmapis.com")
    
    payload = json.dumps({
        "limit": "1",  # Adjust as needed
        "page": "1",
        "start_date": "2025-01-01",  # Adjust date range as needed
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
            # Token expired during request, force refresh and retry once
            print("  ⚠ Token expired, refreshing...")
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
            print(f"  ✗ Error fetching messages after retry: {response}")
            return []
        else:
            print(f"  ✗ Error fetching messages (Status {res.status}): {response}")
            return []
    except Exception as e:
        print(f"  ✗ Exception fetching messages: {str(e)}")
        return []
    finally:
        conn.close()

def push_message_to_ghl(message, contact_id, conversation_id):
    """Push a single message to GHL"""
    conn = http.client.HTTPSConnection("services.leadconnectorhq.com")
    
    # Determine endpoint based on direction
    endpoint = "/conversations/messages/inbound"
    
    # Parse timestamp
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
            print(f"    ✗ Message sync failed: {response}")
            return False
    except Exception as e:
        print(f"    ✗ Exception pushing message: {str(e)}")
        return False
    finally:
        conn.close()

def process_messages():
    """Main function to process all contacts and sync their messages"""
    try:
        # Load contact mapping
        with open(MAPPING_FILE, 'r', encoding='utf-8') as f:
            contacts = json.load(f)
        
        print(f"\n{'='*60}")
        print(f"Starting Message Migration to GHL")
        print(f"{'='*60}\n")
        
        # Initialize authentication (get first token)
        if not ensure_valid_token():
            print("✗ Failed to authenticate with NexCRM. Exiting.")
            return
        
        print(f"\nFound {len(contacts)} contacts to process\n")
        
        total_messages_synced = 0
        contacts_processed = 0
        
        for idx, contact in enumerate(contacts, 1):
            name = contact['name']
            prospect_token = contact['prospect_token']
            contact_id = contact['contact_id']
            conversation_id = contact['conversation_id']
            
            print(f"[{idx}/{len(contacts)}] Processing: {name}")
            print(f"  Prospect Token: {prospect_token}")
            
            # Fetch messages from NexCRM (token refresh handled internally)
            messages = fetch_messages(prospect_token)
            
            if messages:
                print(f"  ✓ Found {len(messages)} messages")
                
                synced = 0
                for msg_idx, message in enumerate(messages, 1):
                    # Push to GHL
                    if push_message_to_ghl(message, contact_id, conversation_id):
                        synced += 1
                    
                    # Rate limiting
                    time.sleep(0.3)
                
                print(f"  ✓ Synced {synced}/{len(messages)} messages\n")
                total_messages_synced += synced
                contacts_processed += 1
            else:
                print(f"  ℹ No messages found\n")
            
            # Delay between contacts
            time.sleep(1)
        
        print(f"\n{'='*60}")
        print(f"✓ Migration Complete!")
        print(f"Contacts processed: {contacts_processed}/{len(contacts)}")
        print(f"Total messages synced: {total_messages_synced}")
        print(f"{'='*60}\n")
        
    except FileNotFoundError:
        print(f"✗ Error: Mapping file '{MAPPING_FILE}' not found!")
        print(f"Please run '1_create_contacts_conversations.py' first.")
    except Exception as e:
        print(f"✗ Error processing messages: {str(e)}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    process_messages()