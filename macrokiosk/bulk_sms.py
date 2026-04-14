import requests
import jwt
from datetime import datetime

# --- CONFIGURATION ---
USERNAME = "mnctechmu"
SERVICE_ID = "MNCT01"
API_KEY = "EmEIU+e7l9H9" 

AUTH_URL = "https://www.etracker.cc/bulksms/Authenticate"
SEND_URL = "https://www.etracker.cc/BulkSMS/Send"

# --- STEP 1: GENERATE INITIAL JWT & GET ACCESS TOKEN ---
payload = {
    "sub": SERVICE_ID,
    "iss": USERNAME,
    "aud": "API"
}

try:
    # Signing the initial token
    initial_jwt = jwt.encode(payload, API_KEY, algorithm="HS256")

    auth_headers = {
        "Authorization": f"Bearer {initial_jwt}",
        "Accept": "application/json"
    }

    print("Requesting Access Token via POST...")
    # CHANGED FROM .get TO .post
    auth_response = requests.post(AUTH_URL, headers=auth_headers)

    if auth_response.status_code == 200:
        access_token = auth_response.json().get("token")
        print("Access Token obtained successfully.")
        
        # --- STEP 2: SEND SMS ---
        sms_payload = {
            "to": "60163931826",
            "from": "mnctechmu",
            "text": "(Sunsuria): Celebrate CNY with us!",
            "servid": SERVICE_ID,
            "type": "0"
        }
        
        send_headers = {
            "Authorization": f"Bearer {access_token}",
            "Content-Type": "application/json",
            "Accept": "application/json"
        }
        
        print("Sending SMS...")
        sms_response = requests.post(SEND_URL, json=sms_payload, headers=send_headers)
        print(f"Status: {sms_response.status_code}")
        print(sms_response.json())
    else:
        print(f"Failed to get Access Token: {auth_response.status_code}")
        print(auth_response.text)

except Exception as e:
    print(f"An error occurred: {e}")