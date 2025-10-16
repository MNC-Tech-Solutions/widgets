import requests
import json
from collections import defaultdict
from requests.auth import HTTPBasicAuth

# Configuration
username = "chloe.ooi@mnctechsolutions.com"
password = "chloeooi2004@"
client_id = 5061
date_from = "2025-01-01"
limit = 10000

# URLs
questions_url = f"https://api.avanser.com/JSON?action=evaluationDetails&client_id={client_id}"
cdr_url = f"https://api.avanser.com/JSON?action=getCDR&client_id={client_id}&date_from={date_from}&limit={limit}&evaluations=yes"

# Fetch questions
response_questions = requests.get(questions_url, auth=HTTPBasicAuth(username, password))
if response_questions.status_code != 200:
    print(f"Error fetching questions: {response_questions.status_code}")
    print(response_questions.text)
    exit(1)

questions_data = response_questions.json()
# Assuming the questions are in a key like 'questions' or similar; adjust based on actual structure
# From user's example, it's {"questions": [...]}
questions = questions_data.get("questions", [])

# Map id to question details
question_map = {}
for q in questions:
    q_id = q["id"]
    question_map[q_id] = {
        "title": q["title"],
        "text": q["text"],
    }

# After fetching CDR
response_cdr = requests.get(cdr_url, auth=HTTPBasicAuth(username, password))
if response_cdr.status_code != 200:
    print(f"Error fetching CDR: {response_cdr.status_code}")
    print(response_cdr.text)
    exit(1)

cdr_data = response_cdr.json()
print("CDR Data:", json.dumps(cdr_data, indent=2))  # Debug: Print the data structure
records = cdr_data.get('calls', [])  # Adjust based on actual key

# Count yes/no for each eval_id
counts = defaultdict(lambda: {"yes": 0, "no": 0, "total": 0})

for record in records:
    for key, value in record.items():
        if key.startswith("eval_"):
            eval_id = key.split("_")[1]
            if value.lower() == "yes":
                counts[eval_id]["yes"] += 1
                counts[eval_id]["total"] += 1
            elif value.lower() == "no":
                counts[eval_id]["no"] += 1
                counts[eval_id]["total"] += 1
            # Ignore empty

# Generate HTML report
html = """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { text-align: center; }
        h2 { color: #333; }
        p { margin: 5px 0; }
        hr { border: 0; border-top: 1px solid #ccc; margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Evaluation Report</h1>
"""

for q_id, details in question_map.items():
    if q_id in counts:
        c = counts[q_id]
        total = c["total"]
        if total == 0:
            content = "<p>No evaluations found.</p>"
        else:
            yes_count = c["yes"]
            no_count = c["no"]
            yes_pct = f"{(yes_count / total * 100):.2f}%" if total > 0 else "0%"
            no_pct = f"{(no_count / total * 100):.2f}%" if total > 0 else "0%"
            content = f"""
            <p>Yes: {yes_count} ({yes_pct}, {yes_count}/{total})</p>
            <p>No: {no_count} ({no_pct}, {no_count}/{total})</p>
            """
    else:
        content = "<p>No evaluations found.</p>"
    
    html += f"""
    <h2>{details['title']}</h2>
    <p>{details['text']}</p>
    {content}
    <hr>
    """

html += """
</body>
</html>
"""

print(html)