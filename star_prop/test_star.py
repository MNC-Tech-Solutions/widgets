import requests
from datetime import datetime
import json

# Function to write to log file
def write_log(message, log_file='test_star_leads_simple.log'):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    log_message = f'[{timestamp}] {message}\n'
    with open(log_file, 'a') as f:
        f.write(log_message)

# Function to fetch leads from Star Property API
def fetch_star_leads():
    api_key = '123343@z3TpgPG0bVvEqpKz1olmBP8odIWi2eARVpQ1F252'  # Hardcoded API key
    query_date = '2025-08-29'  # Hardcoded for today
    url = f'https://api.starproperty.my/api/v1/rest/developer_leads?created_date={query_date}'
    headers = {
        'Authorization': f'Bearer {api_key}'
    }
    
    write_log(f'Fetching leads for date: {query_date}')
    try:
        response = requests.get(url, headers=headers, timeout=30)
        http_code = response.status_code
        
        if http_code == 200:
            try:
                result = response.json()
                if not result.get('error') and result.get('data'):
                    write_log(f'Retrieved {len(result["data"])} leads: {json.dumps(result["data"])}')
                    print(f'Successfully retrieved {len(result["data"])} leads')
                else:
                    message = f'No leads found or API error: {json.dumps(result)}'
                    write_log(message)
                    print(message)
            except json.JSONDecodeError as e:
                error = f'Invalid JSON response: {str(e)}'
                write_log(f'ERROR: {error}')
                print(error)
        else:
            error = f'Failed to retrieve leads: HTTP {http_code} - {response.text}'
            write_log(f'ERROR: {error}')
            print(error)
    except requests.RequestException as e:
        error = f'Request error: {str(e)}'
        write_log(f'ERROR: {error}')
        print(error)

# Main execution
if __name__ == '__main__':
    write_log('Starting test script execution.')
    fetch_star_leads()
    write_log('Test script execution completed.')