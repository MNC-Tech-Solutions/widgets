import http.client
import json
import csv
import time
from datetime import datetime

# Configuration
GHL_TOKEN = "pit-a0089695-3fb5-4dcd-8659-f41c038f6611"
LOCATION_ID = "e9bA8duUxZTuI4HbWSaY"
GHL_CONTACTS_CSV = "ghl_contacts.csv"  # Your GHL export file
PROSPECT_CSV = "prospect_copy_2.csv"  # Your prospect mapping file
OUTPUT_FILE = "contact_conversation_mapping.json"
OUTPUT_CSV_FILE = "contact_conversation_mapping.csv"
ERROR_LOG_FILE = "mapping_error_log.txt"

def log_error(message):
    """Log error message to file with timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    with open(ERROR_LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(f"[{timestamp}] {message}\n")

def get_or_create_conversation(contact_id):
    """Get existing conversation or create new one, return conversation ID and status"""
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
        
        # Check if conversation already exists
        if 'conversationId' in response and response.get('message') == 'Conversation already exists':
            conversation_id = response['conversationId']
            return conversation_id, 'existing', None
        
        # New conversation created
        if res.status == 200 or res.status == 201:
            conversation_id = response.get('conversation', {}).get('id') or response.get('id')
            return conversation_id, 'created', None
        
        # Error occurred
        return None, 'error', response
        
    except Exception as e:
        return None, 'exception', {'error': str(e)}
    finally:
        conn.close()

def load_prospect_mapping():
    """Load prospect.csv and create phone->prospect_token mapping"""
    mapping = {}
    try:
        with open(PROSPECT_CSV, 'r', encoding='utf-8') as file:
            csv_reader = csv.DictReader(file)
            for row in csv_reader:
                phone = row.get('contact', '').strip()
                prospect_token = row.get('prospect_token', '').strip()
                name = row.get('name', '').strip()
                email = row.get('email', '').strip()
                
                # Clean phone number for matching
                phone_clean = clean_phone(phone)
                
                # Store mapping data
                mapping_data = {
                    'name': name,
                    'prospect_token': prospect_token,
                    'email': email,
                    'original_phone': phone
                }
                
                # Store with original cleaned number
                mapping[phone_clean] = mapping_data
                
                # Malaysian numbers (start with 0)
                # 0127747857 -> 60127747857
                if phone_clean.startswith('0'):
                    mapping['60' + phone_clean[1:]] = mapping_data
                    mapping[phone_clean[1:]] = mapping_data
                
                # International numbers (GHL adds 60 prefix incorrectly)
                # 34644583008 -> 6034644583008
                # 6590088004 -> 606590088004
                # 23055075369 -> 6023055075369
                if not phone_clean.startswith('0') and not phone_clean.startswith('60'):
                    mapping['60' + phone_clean] = mapping_data
        
        print(f"✓ Loaded prospect mappings (created {len(mapping)} lookup keys)\n")
        return mapping
    except FileNotFoundError:
        print(f"✗ Error: Prospect CSV file '{PROSPECT_CSV}' not found!")
        return {}

def clean_phone(phone):
    """Clean phone number for matching - removes all non-numeric characters"""
    if not phone:
        return ""
    # Remove all non-numeric characters
    cleaned = ''.join(char for char in phone if char.isdigit())
    return cleaned

def process_contacts():
    """Process GHL contacts and map with prospect tokens"""
    # Initialize error log
    with open(ERROR_LOG_FILE, 'w', encoding='utf-8') as f:
        f.write(f"=== GHL Contact-Conversation Mapping Error Log ===\n")
        f.write(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n")
    
    # Load prospect mapping
    prospect_mapping = load_prospect_mapping()
    if not prospect_mapping:
        print("✗ No prospect mapping loaded. Exiting.")
        return
    
    # Process GHL contacts
    mapping_data = []
    errors = []
    stats = {
        'total': 0,
        'matched': 0,
        'unmatched': 0,
        'existing_conversation': 0,
        'new_conversation': 0,
        'errors': 0
    }
    
    try:
        with open(GHL_CONTACTS_CSV, 'r', encoding='utf-8') as file:
            csv_reader = csv.DictReader(file)
            
            print(f"{'='*60}")
            print(f"Starting Contact-Conversation Mapping")
            print(f"{'='*60}\n")
            
            for idx, row in enumerate(csv_reader, 1):
                stats['total'] += 1
                
                contact_id = row.get('Contact Id', '').strip()
                first_name = row.get('First Name', '').strip()
                phone = row.get('Phone', '').strip()
                email = row.get('Email', '').strip()
                
                print(f"[{idx}] Processing: {first_name} - {phone} (ID: {contact_id})")
                
                # Clean phone for matching
                phone_clean = clean_phone(phone)
                
                # Find prospect token
                prospect_info = prospect_mapping.get(phone_clean)
                
                if not prospect_info:
                    stats['unmatched'] += 1
                    error_msg = f"No prospect token found for phone: {phone} ({first_name})"
                    print(f"  ⚠ {error_msg}")
                    log_error(error_msg)
                    errors.append({
                        'row_number': idx,
                        'contact_id': contact_id,
                        'phone': phone,
                        'name': first_name,
                        'error': 'No matching prospect token',
                        'timestamp': datetime.now().isoformat()
                    })
                    print()
                    continue
                
                stats['matched'] += 1
                
                # Get or create conversation
                conversation_id, status, error = get_or_create_conversation(contact_id)
                
                if conversation_id:
                    if status == 'existing':
                        stats['existing_conversation'] += 1
                        print(f"  ✓ Conversation already exists: {conversation_id}")
                    else:
                        stats['new_conversation'] += 1
                        print(f"  ✓ Conversation created: {conversation_id}")
                    
                    # Add to mapping
                    mapping_data.append({
                        "name": prospect_info['name'] or first_name,
                        "phone": prospect_info['original_phone'],
                        "prospect_token": prospect_info['prospect_token'],
                        "contact_id": contact_id,
                        "conversation_id": conversation_id,
                        "created_at": datetime.now().isoformat()
                    })
                    print(f"  ✓ Mapping saved")
                else:
                    stats['errors'] += 1
                    error_msg = f"Failed to get conversation for {first_name} ({phone}): {error}"
                    print(f"  ✗ {error_msg}")
                    log_error(error_msg)
                    errors.append({
                        'row_number': idx,
                        'contact_id': contact_id,
                        'phone': phone,
                        'name': first_name,
                        'prospect_token': prospect_info['prospect_token'],
                        'error': error,
                        'timestamp': datetime.now().isoformat()
                    })
                
                print()
                
                # Rate limiting
                time.sleep(0.5)
        
        # Save mapping to JSON
        with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
            json.dump(mapping_data, f, indent=2, ensure_ascii=False)
        
        # Save errors if any
        if errors:
            with open('mapping_errors.json', 'w', encoding='utf-8') as f:
                json.dump(errors, f, indent=2, ensure_ascii=False)
        
        # Print summary
        print(f"\n{'='*60}")
        print(f"✓ Process Complete!")
        print(f"{'='*60}")
        print(f"Total contacts processed: {stats['total']}")
        print(f"✓ Matched with prospect: {stats['matched']}")
        print(f"  - Existing conversations: {stats['existing_conversation']}")
        print(f"  - New conversations: {stats['new_conversation']}")
        print(f"⚠ Unmatched (no prospect token): {stats['unmatched']}")
        print(f"✗ Errors: {stats['errors']}")
        print(f"\nOutput files:")
        print(f"  - Mapping: {OUTPUT_FILE}")
        if errors:
            print(f"  - Errors: mapping_errors.json")
        print(f"  - Error log: {ERROR_LOG_FILE}")
        print(f"{'='*60}\n")
        
    except FileNotFoundError:
        print(f"✗ Error: GHL contacts CSV file '{GHL_CONTACTS_CSV}' not found!")
        log_error(f"GHL contacts CSV file '{GHL_CONTACTS_CSV}' not found!")
    except Exception as e:
        error_msg = f"Error processing contacts: {str(e)}"
        print(f"✗ {error_msg}")
        log_error(error_msg)
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    process_contacts()