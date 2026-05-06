import requests
import pandas as pd
import time
import logging
import os

# --- CONFIGURATION ---
API_KEY = "pit-c81f9086-6c6f-4d6f-aee8-92f8898d634a"
LOCATION_ID = "E0H7r1mN6ry0kv4uGZ6L"
LIMIT = 500
VERSION = "2023-02-21"
OUTPUT_FILE = "ghl_messages_export.csv"

# ✅ Exact target columns in the exact order you want
COLUMNS = [
    "id",
    "direction",
    "status",
    "type",
    "locationId",
    "body",
    "contactId",
    "contentType",
    "conversationId",
    "dateAdded",
    "dateUpdated",
    "userId",       # Often missing in payload — will be None
    "altId",
    "from",
    "to",
    "messageType",
    "attachments",  # Often missing — will be None
    "source",       # Often missing — will be None
    "error"         # Often missing — will be None
]

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[logging.FileHandler("export_log.log"), logging.StreamHandler()]
)

def map_message_to_row(msg: dict) -> dict:
    """
    Explicitly map each API field to the correct column.
    Any field not present in the payload is set to None (empty cell).
    No auto-inference — every field is named explicitly.
    """
    return {
        "id":             msg.get("id",             None),
        "direction":      msg.get("direction",      None),
        "status":         msg.get("status",         None),
        "type":           msg.get("type",           None),
        "locationId":     msg.get("locationId",     None),
        "body":           msg.get("body",           None),
        "contactId":      msg.get("contactId",      None),
        "contentType":    msg.get("contentType",    None),
        "conversationId": msg.get("conversationId", None),
        "dateAdded":      msg.get("dateAdded",      None),
        "dateUpdated":    msg.get("dateUpdated",    None),
        "userId":         msg.get("userId",         None),   # ← explicitly None if missing
        "altId":          msg.get("altId",          None),
        "from":           msg.get("from",           None),
        "to":             msg.get("to",             None),
        "messageType":    msg.get("messageType",    None),
        "attachments":    msg.get("attachments",    None),
        "source":         msg.get("source",         None),
        "error":          msg.get("error",          None),
    }

def export_messages():
    url = "https://services.leadconnectorhq.com/conversations/messages/export"
    headers = {
        "Version": VERSION,
        "Accept": "application/json",
        "Authorization": f"Bearer {API_KEY}"
    }
    params = {
        "locationId": LOCATION_ID,
        "limit": LIMIT,
        "sortOrder": "desc"
    }

    cursor = None
    batch_count = 0
    total_fetched = 0
    file_exists = os.path.isfile(OUTPUT_FILE)

    logging.info(f"Starting export. Output: {OUTPUT_FILE}")

    while True:
        if cursor:
            params["cursor"] = cursor

        try:
            response = requests.get(url, headers=headers, params=params, timeout=30)

            if response.status_code == 200:
                data = response.json()
                messages = data.get("messages", [])
                next_cursor = data.get("nextCursor")

                if not messages:
                    logging.info("No more messages found.")
                    break

                # ✅ Explicitly map every message field-by-field
                rows = [map_message_to_row(msg) for msg in messages]

                # ✅ Build DataFrame with enforced column order
                df_batch = pd.DataFrame(rows, columns=COLUMNS)

                # ✅ Write to CSV — header only on first write
                df_batch.to_csv(
                    OUTPUT_FILE,
                    mode='a',
                    index=False,
                    header=not file_exists
                )

                file_exists = True
                cursor = next_cursor
                batch_count += 1
                total_fetched += len(df_batch)

                logging.info(f"Batch {batch_count} | Rows written: {len(df_batch)} | Total: {total_fetched}")
                logging.info(f"Next cursor: {cursor}")

                if not cursor:
                    logging.info("All pages fetched. Export complete.")
                    break

            elif response.status_code == 429:
                logging.warning("Rate limit hit (429). Waiting 20 seconds...")
                time.sleep(20)
                continue  # Retry same cursor

            else:
                logging.error(f"Error at batch {batch_count + 1} | Status: {response.status_code}")
                logging.error(f"Response: {response.text}")
                logging.error(f"Resume cursor: {cursor}")
                break

        except Exception as e:
            logging.error(f"Exception: {str(e)}")
            logging.error(f"Resume cursor: {cursor}")
            break

    logging.info(f"Export ended. Total rows in {OUTPUT_FILE}: {total_fetched}")

if __name__ == "__main__":
    export_messages()