#!/bin/bash
# Runs Script 1 (contacts & conversations).
# When done, sends an email notification for manual review.
# Run Script 2 manually after reviewing:
#   source ~/venv/bin/activate && python -u migration_messages.py
#
# Usage:
#   screen -S migration
#   bash run.sh
#   Ctrl+A, D  to detach (keeps running after you close SSH)
#   screen -r migration  to reattach and check progress

set -e

echo ""
echo "============================================================"
echo " GHL Migration — Script 1: Contacts & Conversations"
echo " Started: $(date)"
echo "============================================================"
echo ""

source ~/venv/bin/activate
python -u migration_contacts.py

echo ""
echo "============================================================"
echo " Script 1 done at $(date)"
echo " Check your email, then run Script 2 when ready."
echo "============================================================"
