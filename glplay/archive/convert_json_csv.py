import json
import csv
from datetime import datetime

# Configuration
INPUT_JSON = "contact_conversation_mapping.json"
OUTPUT_CSV = "contact_conversation_mapping.csv"
VALIDATION_REPORT = "validation_report.txt"

def validate_record(record, idx):
    """Validate a single record and return issues"""
    issues = []
    
    # Check for missing required fields
    if not record.get('contact_id'):
        issues.append("Missing contact_id")
    if not record.get('conversation_id'):
        issues.append("Missing conversation_id")
    if not record.get('prospect_token'):
        issues.append("Missing prospect_token")
    if not record.get('phone'):
        issues.append("Missing phone")
    
    # Check for valid formats
    if record.get('contact_id') and len(record.get('contact_id', '')) < 10:
        issues.append(f"Contact ID seems too short: {record.get('contact_id')}")
    if record.get('conversation_id') and len(record.get('conversation_id', '')) < 10:
        issues.append(f"Conversation ID seems too short: {record.get('conversation_id')}")
    if record.get('prospect_token') and len(record.get('prospect_token', '')) < 20:
        issues.append(f"Prospect token seems too short: {record.get('prospect_token')}")
    
    return issues

def json_to_csv_with_validation():
    """Convert JSON to CSV and create validation report"""
    
    validation_log = []
    
    try:
        # Read JSON file
        with open(INPUT_JSON, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not data:
            print("✗ JSON file is empty or has no data!")
            return
        
        print(f"\n{'='*60}")
        print(f"Converting JSON to CSV with Validation")
        print(f"{'='*60}\n")
        print(f"Found {len(data)} records\n")
        
        # Define CSV columns
        fieldnames = ['row', 'name', 'phone', 'prospect_token', 'contact_id', 'conversation_id', 'created_at', 'status']
        
        # Validation counters
        valid_count = 0
        invalid_count = 0
        
        # Write to CSV
        with open(OUTPUT_CSV, 'w', newline='', encoding='utf-8') as csvfile:
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            
            # Write header
            writer.writeheader()
            
            # Write data rows with validation
            for idx, record in enumerate(data, 1):
                # Validate record
                issues = validate_record(record, idx)
                
                status = "✓ Valid" if not issues else "✗ Has Issues"
                
                if issues:
                    invalid_count += 1
                    validation_log.append({
                        'row': idx,
                        'name': record.get('name', ''),
                        'phone': record.get('phone', ''),
                        'issues': issues
                    })
                else:
                    valid_count += 1
                
                writer.writerow({
                    'row': idx,
                    'name': record.get('name', ''),
                    'phone': record.get('phone', ''),
                    'prospect_token': record.get('prospect_token', ''),
                    'contact_id': record.get('contact_id', ''),
                    'conversation_id': record.get('conversation_id', ''),
                    'created_at': record.get('created_at', ''),
                    'status': status
                })
                
                if idx % 100 == 0:
                    print(f"  Processed {idx} records...")
        
        # Write validation report
        with open(VALIDATION_REPORT, 'w', encoding='utf-8') as f:
            f.write("=" * 60 + "\n")
            f.write("VALIDATION REPORT\n")
            f.write("=" * 60 + "\n")
            f.write(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write(f"Total Records: {len(data)}\n")
            f.write(f"Valid Records: {valid_count}\n")
            f.write(f"Invalid Records: {invalid_count}\n")
            f.write("=" * 60 + "\n\n")
            
            if validation_log:
                f.write("ISSUES FOUND:\n")
                f.write("-" * 60 + "\n")
                for log in validation_log:
                    f.write(f"\nRow {log['row']}: {log['name']} ({log['phone']})\n")
                    for issue in log['issues']:
                        f.write(f"  - {issue}\n")
            else:
                f.write("No issues found! All records are valid.\n")
        
        # Print summary
        print(f"\n{'='*60}")
        print(f"✓ Conversion Complete!")
        print(f"{'='*60}")
        print(f"Total records: {len(data)}")
        print(f"✓ Valid: {valid_count}")
        print(f"✗ Invalid: {invalid_count}")
        print(f"\nOutput files:")
        print(f"  - CSV: {OUTPUT_CSV}")
        print(f"  - Validation Report: {VALIDATION_REPORT}")
        print(f"{'='*60}\n")
        
        # Show sample of first 5 records
        print("Sample data (first 5 records):")
        print("-" * 120)
        print(f"{'Row':<5} {'Name':<15} {'Phone':<15} {'Contact ID':<25} {'Conv ID':<25} {'Status':<15}")
        print("-" * 120)
        with open(OUTPUT_CSV, 'r', encoding='utf-8') as f:
            reader = csv.DictReader(f)
            for row in reader:
                if int(row['row']) <= 5:
                    print(f"{row['row']:<5} {row['name']:<15} {row['phone']:<15} {row['contact_id']:<25} {row['conversation_id']:<25} {row['status']:<15}")
        print("-" * 120)
        
        # Show issues summary
        if validation_log:
            print(f"\n⚠ Found {invalid_count} records with issues. Check {VALIDATION_REPORT} for details.\n")
        else:
            print(f"\n✓ All records are valid!\n")
        
    except FileNotFoundError:
        print(f"✗ Error: JSON file '{INPUT_JSON}' not found!")
        print(f"Make sure the file exists in the current directory.")
    except json.JSONDecodeError as e:
        print(f"✗ Error: Invalid JSON format in '{INPUT_JSON}'")
        print(f"Error details: {str(e)}")
    except Exception as e:
        print(f"✗ Error: {str(e)}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    json_to_csv_with_validation()