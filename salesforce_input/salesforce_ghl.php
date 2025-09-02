<?php

// Function to load configuration from JSON file
function loadConfig($file_path = '../salesforceConfig.json') {
    if (!file_exists($file_path)) {
        logMessage("Configuration file not found: $file_path");
        http_response_code(500);
        die("Configuration file not found");
    }

    $config_data = file_get_contents($file_path);
    $config = json_decode($config_data, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("Failed to parse configuration file: " . json_last_error_msg());
        http_response_code(500);
        die("Invalid configuration file");
    }

    return $config;
}

// Logging function
function logMessage($message) {
    $logFile = 'integration.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Function to get Salesforce access token
function getSalesforceAccessToken($config) {
    $url = $config['salesforce']['token_url'];
    $data = [
        'grant_type' => $config['salesforce']['grant_type'],
        'client_id' => $config['salesforce']['client_id'],
        'client_secret' => $config['salesforce']['client_secret'],
        'username' => $config['salesforce']['username'],
        'password' => $config['salesforce']['password']
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMessage("Failed to get Salesforce access token: HTTP $httpCode");
        return false;
    }

    $tokenData = json_decode($response, true);
    if (isset($tokenData['access_token']) && isset($tokenData['instance_url'])) {
        logMessage("Successfully retrieved Salesforce access token");
        return [
            'access_token' => $tokenData['access_token'],
            'instance_url' => $tokenData['instance_url']
        ];
    }

    logMessage("Invalid Salesforce token response: " . $response);
    return false;
}

// Function to check if Salesforce session is active
function isSalesforceSessionActive($instance_url, $access_token) {
    $url = $instance_url . '/services/data/v64.0/';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

// Function to create Salesforce lead
function createSalesforceLead($instance_url, $access_token, $post_data) {
    $url = $instance_url . '/services/data/v64.0/sobjects/Lead/';
    $payload = array_filter([
        'FirstName' => $post_data['FirstName'] ?? null,
        'LastName' => $post_data['LastName'] ?? null,
        'Phone' => $post_data['Phone'] ?? null,
        'Email' => $post_data['Email'] ?? null,
        'LeadSource' => $post_data['LeadSource'] ?? null,
        'SJ360_Remarks__c' => $post_data['SJ360_Remarks__c'] ?? null
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        logMessage("Failed to create Salesforce lead: HTTP $httpCode, Response: $response");
        return false;
    }

    $leadData = json_decode($response, true);
    if (isset($leadData['id'])) {
        logMessage("Successfully created Salesforce lead: " . $leadData['id']);
        return $leadData;
    }

    logMessage("Invalid Salesforce lead creation response: " . $response);
    return false;
}

// Function to update GHL contact
function updateGHLContact($config, $contact_id, $salesforce_lead_id) {
    $url = $config['ghl']['contact_url'] . $contact_id;
    $payload = [
        'customFields' => [
            [
                'id' => $config['ghl']['custom_field_ids']['salesforce_lead_id'],
                'key' => 'contact.salesforce_lead_id',
                'value' => $salesforce_lead_id
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['ghl']['api_token'],
        'Version: ' . $config['ghl']['api_version'],
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        logMessage("Failed to update GHL contact: HTTP $httpCode, Response: $response");
        return false;
    }

    logMessage("Successfully updated GHL contact with Salesforce lead ID: $salesforce_lead_id");
    return true;
}

// Function to update Salesforce lead
function updateSalesforceLead($instance_url, $access_token, $lead_id, $post_data) {
    $url = $instance_url . '/services/data/v64.0/sobjects/Lead/' . $lead_id;
    $payload = array_filter([
        'FirstName' => $post_data['FirstName'] ?? null,
        'LastName' => $post_data['LastName'] ?? null,
        'Phone' => $post_data['Phone'] ?? null,
        'Email' => $post_data['Email'] ?? null,
        'LeadSource' => $post_data['LeadSource'] ?? null,
        'SJ360_Remarks__c' => $post_data['SJ360_Remarks__c'] ?? null
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 204) {
        logMessage("Failed to update Salesforce lead: HTTP $httpCode, Response: $response");
        return false;
    }

    logMessage("Successfully updated Salesforce lead: $lead_id");
    return true;
}

// Main execution
function handleLead($config, $post_data) {
    // Get Salesforce access token
    $tokenData = getSalesforceAccessToken($config);
    if (!$tokenData) {
        return false;
    }

    $access_token = $tokenData['access_token'];
    $instance_url = $tokenData['instance_url'];

    // Check if session is active
    if (!isSalesforceSessionActive($instance_url, $access_token)) {
        logMessage("Salesforce session inactive, attempting to refresh token");
        $tokenData = getSalesforceAccessToken($config);
        if (!$tokenData) {
            return false;
        }
        $access_token = $tokenData['access_token'];
        $instance_url = $tokenData['instance_url'];
    }

    // Create or update lead based on whether lead_id exists in post_data
    if (isset($post_data['lead_id']) && !empty($post_data['lead_id'])) {
        // Update existing lead
        $result = updateSalesforceLead($instance_url, $access_token, $post_data['lead_id'], $post_data);
        return $result;
    } else {
        // Create new lead
        $leadData = createSalesforceLead($instance_url, $access_token, $post_data);
        if ($leadData && isset($leadData['id'])) {
            // Update GHL contact with Salesforce lead ID
            if (isset($post_data['ghl_contact_id']) && !empty($post_data['ghl_contact_id'])) {
                $ghlResult = updateGHLContact($config, $post_data['ghl_contact_id'], $leadData['id']);
                return $ghlResult && $leadData;
            }
            return $leadData;
        }
        return false;
    }
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    die("Method Not Allowed");
}

// Get POST data
$input = file_get_contents('php://input');
logMessage("Raw POST data received: " . $input);

$post_data_raw = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("Failed to parse POST data: " . json_last_error_msg());
    http_response_code(400);
    die("Invalid JSON payload");
}

// Filter and map relevant POST data
$post_data = [
    'lead_id' => $post_data_raw['Salesforce Lead Id'] ?? null,
    'ghl_contact_id' => $post_data_raw['contact_id'] ?? null,
    'FirstName' => empty($post_data_raw['first_name']) ? '-' : $post_data_raw['first_name'],
    'LastName' => empty($post_data_raw['last_name']) ? '-' : $post_data_raw['last_name'],
    'Email' => $post_data_raw['email'] ?? null,
    'Phone' => $post_data_raw['phone'] ?? null,
    'LeadSource' => $post_data_raw['contact_source'] ?? null,
    'SJ360_Remarks__c' => $post_data_raw['Message'] ?? null
];

logMessage("Filtered POST data: " . print_r($post_data, true));

// Load configuration
$config = loadConfig();

// Process the lead
$result = handleLead($config, $post_data);

if ($result) {
    logMessage("Lead processed successfully");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Lead processed successfully', 'data' => $result]);
} else {
    logMessage("Failed to process lead");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process lead']);
}

?>