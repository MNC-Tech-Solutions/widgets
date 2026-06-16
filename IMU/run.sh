#!/bin/bash
# GHL Migration — auto-restarts until all contacts are migrated, then shuts down the VM.
#
# Usage:
#   screen -S migration
#   bash run.sh
#   Ctrl+A, D  to detach
#   screen -r migration  to reattach

cd /mnt/c/xampp/htdocs/widgets/IMU
source ~/venv/bin/activate

check_done() {
    python3 - <<'EOF'
from dotenv import load_dotenv; load_dotenv()
import os, firebase_admin
from firebase_admin import credentials, firestore

if not firebase_admin._apps:
    firebase_admin.initialize_app(
        credentials.Certificate(os.getenv("GOOGLE_APPLICATION_CREDENTIALS")),
        {"projectId": os.getenv("FIREBASE_PROJECT_ID")}
    )
db = firestore.client()

col = os.getenv("COLLECTION_NAME", "contacts")
state = db.collection("migration_state").document(col).get().to_dict() or {}
fetch_complete = state.get("fetch_complete", False)

has_fetched  = any(True for _ in db.collection(col).where("status","==","fetched").limit(1).stream())
has_pending  = any(True for _ in db.collection(col).where("status","==","contact_created").limit(1).stream())

if fetch_complete and not has_fetched and not has_pending:
    print("DONE")
else:
    print("MORE")
EOF
}

echo ""
echo "============================================================"
echo " GHL Migration — auto-restart loop"
echo " Started: $(date)"
echo "============================================================"

RUN=1
while true; do
    echo ""
    echo "--- Run #$RUN started at $(date) ---"
    python3 -u migration.py || true
    echo "--- Run #$RUN finished at $(date) ---"

    STATUS=$(check_done)

    if [ "$STATUS" = "DONE" ]; then
        echo ""
        echo "============================================================"
        echo " ALL CONTACTS MIGRATED — shutting down VM at $(date)"
        echo "============================================================"
        sudo shutdown -h now
        break
    fi

    echo "More work remaining — restarting in 5 seconds..."
    sleep 5
    RUN=$((RUN + 1))
done
