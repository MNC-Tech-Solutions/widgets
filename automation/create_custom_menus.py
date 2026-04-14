import json
import requests
import time
from pathlib import Path

# Configuration
CONFIG_FILE = Path(__file__).parent.parent / "SAR_config.json"
API_URL = "https://services.leadconnectorhq.com/custom-menus/"
FIXED_TOKEN = "pit-04c07340-9ec6-4ec2-b7f2-fa7342018a0a"
VERSION = "2021-07-28"

HEADERS = {
    "Authorization": f"Bearer {FIXED_TOKEN}",
    "Version": VERSION,
    "Content-Type": "application/json",
    "Accept": "application/json"
}

def run_script():
    # 1. Load the JSON configuration
    try:
        with open(CONFIG_FILE, 'r') as f:
            project_list = json.load(f)
    except FileNotFoundError:
        print(f"Error: {CONFIG_FILE} not found. Please ensure the file exists in the same directory.")
        return
    except json.JSONDecodeError:
        print(f"Error: Failed to decode {CONFIG_FILE}. Check if the JSON format is valid.")
        return

    found_agb = False
    count = 0

    print("Starting process...")

    for entry in project_list:
        project_name = entry.get("name")
        location_id = entry.get("defaultLocationId")

        # Skip entries until we find "AGB"
        if not found_agb:
            if project_name == "AGB":
                found_agb = True
                print(f"--- Found 'AGB'. Starting creation from the next project. ---")
            continue

        # If we reach here, we are after AGB
        print(f"Creating menu for: {project_name} (ID: {location_id})")

        payload = {
            "title": "Sales Activity Report",
            "url": f"https://widget.salesjourney360.com/widget/report/sales_activity_report.html?locationId={location_id}",
            "icon": {
                "name": "chart-area",
                "fontFamily": "far"
            },
            "showOnCompany": False,
            "showOnLocation": True,
            "showToAllLocations": False,
            "openMode": "iframe",
            "locations": [location_id],
            "userRole": "admin",
            "allowCamera": False,
            "allowMicrophone": False
        }

        try:
            response = requests.post(API_URL, headers=HEADERS, json=payload)
            
            if response.status_code in [200, 201]:
                print(f"Successfully created: {project_name}")
                count += 1
            else:
                print(f"Failed for {project_name}: {response.status_code} - {response.text}")
        
        except Exception as e:
            print(f"An error occurred while processing {project_name}: {e}")

        # Short delay to prevent hitting rate limits
        time.sleep(0.3)

    print(f"\nFinished! Total custom menus created: {count}")

if __name__ == "__main__":
    run_script()