import requests
import json

# --- Configuration ---
BASE_URL = "https://api.concordcollege.onengagecloud.com/api"
AUTH_URL = f"{BASE_URL}/gettoken"
LOOKUP_BASE_URL = f"{BASE_URL}/v1/lookups/getlookup/Sandbox"

CREDENTIALS = {
    "username": "chloe.ooi@mnctechsolutions.com",
    "password": "Cciss@2026"
}

LOOKUP_IDS = {
    "gender": "20003",
    "title": "3012",
    "language": "2902",
    "relationship": "2100",
    "profession": "2101"
}

def get_access_token():
    print("Authenticating...")
    # x-www-form-urlencoded is handled by the 'data' parameter in requests
    response = requests.post(AUTH_URL, data=CREDENTIALS)
    
    if response.status_code == 200:
        # Adjust 'access_token' key if the API returns a different key name (e.g., 'token')
        return response.json().get("access_token")
    else:
        print(f"Failed to authenticate: {response.status_code} - {response.text}")
        return None

def fetch_lookup_data(token):
    headers = {"Authorization": f"Bearer {token}"}
    all_data = {}

    for name, lookup_id in LOOKUP_IDS.items():
        print(f"Fetching data for {name} (ID: {lookup_id})...")
        url = f"{LOOKUP_BASE_URL}/{lookup_id}"
        response = requests.get(url, headers=headers)
        
        if response.status_code == 200:
            all_data[name] = response.json()
        else:
            print(f"Error fetching {name}: {response.status_code}")
            all_data[name] = {"error": f"HTTP {response.status_code}"}

    return all_data

def main():
    token = get_access_token()
    if not token:
        return

    lookup_results = fetch_lookup_data(token)

    # Record all data in a JSON file
    filename = "lookup_data.json"
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(lookup_results, f, indent=4)
    
    print(f"\nSuccess! All data has been saved to {filename}")

if __name__ == "__main__":
    main()