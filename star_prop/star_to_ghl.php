<?php

// Function to write to log file
function write_log($message, $log_file = 'star_to_ghl.log') {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}

// Function to load config from file
function load_config($config_path = 'starConfig.json') {
    if (file_exists($config_path)) {
        $config = json_decode(file_get_contents($config_path), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $config;
        } else {
            $error = "Invalid JSON in config file: " . json_last_error_msg();
            throw new Exception($error);
        }
    } else {
        $error = "Config file $config_path not found.";
        throw new Exception($error);
    }
}

// Function to update GHL contact
function update_ghl_contact($contactId, $lead, $ghl_config) {
    $url = "https://services.leadconnectorhq.com/contacts/" . $contactId;
    $headers = [
        "Authorization: Bearer " . $ghl_config['accessToken'],
        "Version: 2021-07-28",
        "Content-Type: application/json"
    ];
    
    $names = explode(" ", $lead['name']);
    $first_name = !empty($names) ? $names[0] : "";
    $last_name = count($names) > 1 ? implode(" ", array_slice($names, 1)) : "";
    
    $custom_fields = [
        [
            "id" => $ghl_config['customFieldIds']['propertyId'],
            "value" => (string)$lead['property_id']
        ],
        [
            "id" => $ghl_config['customFieldIds']['propertyName'],
            "value" => $lead['property_name']
        ],
        [
            "id" => $ghl_config['customFieldIds']['message'],
            "value" => $lead['description']
        ]
    ];
    
    $body = [
        "name" => $lead['name'],
        "firstName" => $first_name,
        "lastName" => $last_name,
        "email" => $lead['email'],
        "phone" => $lead['contact'],
        "customFields" => $custom_fields,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        echo "Successfully updated contact for " . $lead['name'] . "\n";
    } else {
        $error = "Failed to update contact for " . $lead['name'] . ": HTTP $http_code - $response";
        echo "$error\n";
    }
}

// Function to manage GHL contact (create or update)
function manage_ghl_contact($lead, $ghl_config) {
    $url = "https://services.leadconnectorhq.com/contacts/";
    $headers = [
        "Authorization: Bearer " . $ghl_config['accessToken'],
        "Version: 2021-07-28",
        "Content-Type: application/json"
    ];
    
    $names = explode(" ", $lead['name']);
    $first_name = !empty($names) ? $names[0] : "";
    $last_name = count($names) > 1 ? implode(" ", array_slice($names, 1)) : "";
    
    $custom_fields = [
        [
            "id" => $ghl_config['customFieldIds']['propertyId'],
            "value" => (string)$lead['property_id']
        ],
        [
            "id" => $ghl_config['customFieldIds']['propertyName'],
            "value" => $lead['property_name']
        ],
        [
            "id" => $ghl_config['customFieldIds']['message'],
            "value" => $lead['description']
        ]
    ];
    
    $body = [
        "locationId" => $ghl_config['defaultLocationId'],
        "name" => $lead['name'],
        "firstName" => $first_name,
        "lastName" => $last_name,
        "email" => $lead['email'],
        "phone" => $lead['contact'],
        "source" => "Star Property API",
        "customFields" => $custom_fields,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200 || $http_code == 201) {
        echo "Successfully created contact for " . $lead['name'] . "\n";
    } elseif ($http_code == 400 && strpos($response, "duplicated contacts") !== false) {
        $response_data = json_decode($response, true);
        if ($response_data && isset($response_data['meta']['contactId'])) {
            update_ghl_contact($response_data['meta']['contactId'], $lead, $ghl_config);
        } else {
            echo "Failed to extract contactId for " . $lead['name'] . ": $response\n";
        }
    } else {
        $error = "Failed to create contact for " . $lead['name'] . ": HTTP $http_code - $response";
        echo "$error\n";
    }
}

// Main function
function main() {
    try {
        $config = load_config();
        $ghl_config = $config['ghl'];
        $star_properties = $config['star_properties'] ?? [];
        
        if (empty($star_properties)) {
            echo "No Star Property configurations found in config.\n";
            return;
        }
        
        $query_date = '2025-08-29';
        write_log("Querying leads for date: $query_date");
        
        foreach ($star_properties as $index => $star_config) {
            $api_key = $star_config['api_key'];
            $url = "https://api.starproperty.my/api/v1/rest/developer_leads?created_date=$query_date";
            $headers = [
                "Authorization: Bearer $api_key",
                "User-Agent: PostmanRuntime/7.40.0"
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                echo "cURL error for API key $index: $curl_error\n";
                continue;
            }
            
            if ($http_code == 200) {
                $result = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && !$result['error'] && !empty($result['data'])) {
                    write_log("Retrieved " . count($result['data']) . " leads for API key $index: " . json_encode($result['data']));
                    foreach ($result['data'] as $lead) {
                        manage_ghl_contact($lead, $ghl_config);
                    }
                } else {
                    echo "No leads found or API error for API key $index: " . json_encode($result) . "\n";
                }
            } else {
                echo "Failed to retrieve leads for API key $index: HTTP $http_code - $response\n";
            }
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// Run the script
main();

?>