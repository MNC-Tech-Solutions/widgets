import os
import requests
import json


def load_env_file(path):
    # Same concord/.env file webhook.php reads; real env vars take precedence
    if not os.path.isfile(path):
        return
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = (part.strip() for part in line.split("=", 1))
            os.environ.setdefault(key, value.strip("\"'"))


load_env_file(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env"))

# --- Configuration ---
BASE_URL = os.environ.get("ENGAGE_BASE_URL", "https://api.concordcollege.onengagecloud.com") + "/api"
AUTH_URL = f"{BASE_URL}/gettoken"

CREDENTIALS = {
    "username": os.environ.get("ENGAGE_USERNAME", ""),
    "password": os.environ.get("ENGAGE_PASSWORD", ""),
}

# Same flags as webhook.php ?env=...
SCHOOLS = {
    "sandbox": "Sandbox",
    "production": "CL1-CCIS",
}

# getlookup/{school}/{id}
LOOKUP_IDS = {
    "title": "3012",
    "gender": "20003",
    "language": "2902",
    "nationality": "2900",
    "boarder_status": "3013",
    "entry_term": "2200",
    "year_group": "3004",
    "relationship": "2100",
    "email_type": "1221",
    "phone_type": "2904",
}

# Lookups with their own endpoint: {endpoint}/{school}
SPECIAL_LOOKUPS = {
    "organisation": "getorganisationlookup",
    "enquiry_source": "getenquirysourcelookup",
}

HEADERS = {"User-Agent": "PostmanRuntime/7.32.3"}


def get_access_token():
    print("Authenticating...")
    # x-www-form-urlencoded is handled by the 'data' parameter in requests
    response = requests.post(AUTH_URL, data=CREDENTIALS, headers=HEADERS)

    if response.status_code == 200:
        return response.json().get("access_token")
    else:
        print(f"Failed to authenticate: {response.status_code} - {response.text}")
        return None


def to_title_id_list(items):
    # Keep only {title, id}; drop blank placeholder rows
    return [
        {"title": item["description"].strip(), "id": item["id"]}
        for item in items
        if (item.get("description") or "").strip()
    ]


def fetch_lookup_data(token, school_id):
    headers = {**HEADERS, "Authorization": f"Bearer {token}"}
    urls = {name: f"{BASE_URL}/v1/lookups/getlookup/{school_id}/{lookup_id}" for name, lookup_id in LOOKUP_IDS.items()}
    urls.update({name: f"{BASE_URL}/v1/lookups/{endpoint}/{school_id}" for name, endpoint in SPECIAL_LOOKUPS.items()})

    all_data = {}
    for name, url in urls.items():
        print(f"  Fetching {name}...")
        response = requests.get(url, headers=headers)

        if response.status_code == 200:
            all_data[name] = to_title_id_list(response.json())
        else:
            print(f"  Error fetching {name}: {response.status_code}")
            all_data[name] = {"error": f"HTTP {response.status_code}"}

    return all_data


def main():
    if not CREDENTIALS["username"] or not CREDENTIALS["password"]:
        print("ENGAGE_USERNAME / ENGAGE_PASSWORD not set — add them to concord/.env")
        return

    token = get_access_token()
    if not token:
        return

    lookup_results = {}
    for env, school_id in SCHOOLS.items():
        print(f"School {school_id} ({env}):")
        lookup_results[env] = fetch_lookup_data(token, school_id)

    # Record all data in a JSON file
    filename = "lookup_data.json"
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(lookup_results, f, indent=4, ensure_ascii=False)

    print(f"\nSuccess! All data has been saved to {filename}")


if __name__ == "__main__":
    main()
