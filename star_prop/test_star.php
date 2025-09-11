<?php

// Function to write to log file
function write_log($message, $log_file = 'test_star_leads_simple.log') {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}

// Function to handle verbose cURL output
function curl_verbose_callback($ch, $data) {
    $verbose_log = 'curl_verbose.log';
    $timestamp = date('Y-m-d H:i:s ');
    file_put_contents($verbose_log, $timestamp . $data, FILE_APPEND | LOCK_EX);
    return strlen($data);
}

// Function to get public IP address
function get_public_ip() {
    $ch = curl_init('https://api.ipify.org?format=json');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        return $data['ip'] ?? 'Unknown';
    }
    return 'Unable to retrieve IP';
}

// Function to fetch leads from Star Property API
function fetch_star_leads() {
    $api_key = '123997@FNAmglcSIKrISUHdsO7bk85FuwEFrANVVZEkO0lK'; // Replace with actual API key ending with IK
    $query_date = '2025-08-29'; // Hardcoded for today
    $url = "https://api.starproperty.my/api/v1/rest/developer_leads?created_date=$query_date";
    $headers = [
        "Authorization: Bearer $api_key",
        "User-Agent: PostmanRuntime/7.40.0"
    ];
    
    // Get and log the public IP
    $public_ip = get_public_ip();
    write_log("API call initiated from public IP: $public_ip");
    
    write_log("Fetching leads for date: $query_date");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, fopen('php://temp', 'w+')); // Alternative if callback not supported
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'curl_verbose_callback'); // Callback for headers (use if stderr doesn't work)
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        $error = "cURL error: $curl_error";
        write_log("ERROR: $error");
        echo "$error\n";
        return;
    }
    
    if ($http_code == 200) {
        $result = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!$result['error'] && !empty($result['data'])) {
                write_log("Retrieved " . count($result['data']) . " leads: " . json_encode($result['data']));
                echo "Successfully retrieved " . count($result['data']) . " leads\n";
            } else {
                $message = "No leads found or API error: " . json_encode($result);
                write_log($message);
                echo "$message\n";
            }
        } else {
            $error = "Invalid JSON response: " . json_last_error_msg();
            write_log("ERROR: $error");
            echo "$error\n";
        }
    } else {
        $error = "Failed to retrieve leads: HTTP $http_code - $response";
        write_log("ERROR: $error");
        echo "$error\n";
    }
}

// Main execution
write_log("Starting test script execution.");
fetch_star_leads();
write_log("Test script execution completed.");

?>