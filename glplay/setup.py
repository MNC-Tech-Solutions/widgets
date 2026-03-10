import http.client
import json
import csv
import time
import os  # Added for file checking
from datetime import datetime

# Configuration
GHL_TOKEN = "pit-a0089695-3fb5-4dcd-8659-f41c038f6611"
LOCATION_ID = "e9bA8duUxZTuI4HbWSaY"
CSV_FILE = "prospect.csv"

def get_unique_filename(base_filename):
    """If file exists, append a timestamp to avoid overwriting"""
    if not os.path.exists(base_filename):
        return base_filename
    
    name, ext = os.path.splitext(base_filename)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return f"{name}_{timestamp}{ext}"

# Initialize unique file paths based on current run
suffix_timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
OUTPUT_FILE = get_unique_filename("contact_mapping.json")
FAILED_LOG_FILE = get_unique_filename("failed_contacts.json")
ERROR_LOG_FILE = get_unique_filename("error_log.txt")

def log_error(message):
    """Log error message to file with timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    with open(ERROR_LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(f"[{timestamp}] {message}\n")

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
            print(f"✓ Contact created: {first_name} - ID: {contact_id}")
            return contact_id, None
        else:
            error_msg = f"Error creating contact {first_name}: {response}"
            print(f"✗ {error_msg}")
            log_error(error_msg)
            return None, response
    except Exception as e:
        error_msg = f"Exception creating contact {first_name}: {str(e)}"
        print(f"✗ {error_msg}")
        log_error(error_msg)
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
            print(f"  ✓ Conversation created: {conversation_id}")
            return conversation_id, None
        else:
            error_msg = f"Error creating conversation: {response}"
            print(f"  ✗ {error_msg}")
            log_error(error_msg)
            return None, response
    except Exception as e:
        error_msg = f"Exception creating conversation: {str(e)}"
        print(f"  ✗ {error_msg}")
        log_error(error_msg)
        return None, {'error': str(e)}
    finally:
        conn.close()

def process_csv():
    """Process the CSV file and create contacts and conversations"""
    mapping_data = []
    failed_data = []
    
    # Initialize error log file
    with open(ERROR_LOG_FILE, 'w', encoding='utf-8') as f:
        f.write(f"=== GHL Contact Creation Error Log ===\n")
        f.write(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Unique Output: {OUTPUT_FILE}\n\n")
    
    try:
        with open(CSV_FILE, 'r', encoding='utf-8') as file:
            csv_reader = csv.DictReader(file)
            
            print(f"\n{'='*60}")
            print(f"Starting GHL Contact & Conversation Creation")
            print(f"Results will be saved to: {OUTPUT_FILE}")
            print(f"{'='*60}\n")
            
            for idx, row in enumerate(csv_reader, 1):
                name = row.get('name', '').strip()
                contact = row.get('contact', '').strip()
                prospect_token = row.get('prospect_token', '').strip()
                
                print(f"[{idx}] Processing: {name} ({contact})")
                
                # Create contact
                contact_id, contact_error = create_contact(name, contact)
                
                if contact_id:
                    # Small delay to avoid rate limiting
                    time.sleep(0.5)
                    
                    # Create conversation
                    conversation_id, conversation_error = create_conversation(contact_id)
                    
                    if conversation_id:
                        mapping_data.append({
                            "name": name,
                            "phone": contact,
                            "prospect_token": prospect_token,
                            "contact_id": contact_id,
                            "conversation_id": conversation_id,
                            "created_at": datetime.now().isoformat()
                        })
                        print(f"  ✓ Mapping saved\n")
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
                        print(f"  ✗ Failed to create conversation\n")
                else:
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
                    print(f"  ✗ Failed to create contact\n")
                
                # Rate limiting delay
                time.sleep(1)
        
        # Save mapping to JSON file
        with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
            json.dump(mapping_data, f, indent=2, ensure_ascii=False)
        
        # Save failed records to JSON file
        if failed_data:
            with open(FAILED_LOG_FILE, 'w', encoding='utf-8') as f:
                json.dump(failed_data, f, indent=2, ensure_ascii=False)
        
        # Print summary
        print(f"\n{'='*60}")
        print(f"✓ Process Complete!")
        print(f"{'='*60}")
        print(f"Total contacts processed: {len(mapping_data) + len(failed_data)}")
        print(f"✓ Successful: {len(mapping_data)}")
        print(f"✗ Failed: {len(failed_data)}")
        print(f"\nOutput files:")
        print(f"  - Success mapping: {OUTPUT_FILE}")
        if failed_data:
            print(f"  - Failed records: {FAILED_LOG_FILE}")
        print(f"  - Error log: {ERROR_LOG_FILE}")
        print(f"{'='*60}\n")
        
        if failed_data:
            print(f"\n{'='*60}")
            print(f"Failed Records Summary")
            print(f"{'='*60}")
            
            error_types = {}
            for failed in failed_data:
                error_msg = failed['error'].get('message', 'Unknown error') if isinstance(failed['error'], dict) else str(failed['error'])
                if error_msg not in error_types:
                    error_types[error_msg] = []
                error_types[error_msg].append(failed)
            
            for error_type, records in error_types.items():
                print(f"\n{error_type}: {len(records)} record(s)")
                for record in records[:5]:
                    print(f"  - Row {record['row_number']}: {record['name']} ({record['phone']})")
                if len(records) > 5:
                    print(f"  ... and {len(records) - 5} more")
            
            print(f"\n{'='*60}\n")
        
    except FileNotFoundError:
        print(f"✗ Error: CSV file '{CSV_FILE}' not found!")
    except Exception as e:
        print(f"✗ Error processing CSV: {str(e)}")

if __name__ == "__main__":
    process_csv()