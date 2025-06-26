<?php
require __DIR__ . '/vendor/autoload.php';
use Twilio\Rest\Client;

// Log file path
$log_file = __DIR__ . '/avaland_webhook.log';

// Function to write to log file
function writeLog($message, $log_file) {
    $timestamp = date('Y-m-d H:i:s', time());
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Function to load user data
function loadUserData($phone_number, $log_file) {
    $filename = __DIR__ . '/user_data/' . str_replace('+', '_', $phone_number) . '.json';
    if (file_exists($filename)) {
        $data = json_decode(file_get_contents($filename), true);
        writeLog("Loaded user data for $phone_number: " . json_encode($data), $log_file);
        return $data ?: ['visitor_name' => 'Unknown', 'image_url' => ''];
    }
    writeLog("No user data found for $phone_number", $log_file);
    return null;
}

// Function to delete user data
function deleteUserData($phone_number, $log_file) {
    $filename = __DIR__ . '/user_data/' . str_replace('+', '_', $phone_number) . '.json';
    if (file_exists($filename)) {
        unlink($filename);
        writeLog("Deleted user data file for $phone_number", $log_file);
    }
}

// Set default time zone to match your location (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// Hardcoded Twilio credentials
$twilio_account_sid = 'ACe217c9267706083594bb2a4cf26b2ae5'; // Updated to match JSON
$twilio_auth_token = '1c46b5593e219a0ddbc1f6a315adc5e0';
$twilio_whatsapp_number = '+15557822704';
$messaging_service_sid = 'MG0ab9b89d512561331c3be3dd6f17f9e5'; // Match JSON if used

// Initialize Twilio client
try {
    $twilio = new Client($twilio_account_sid, $twilio_auth_token);
} catch (Exception $e) {
    writeLog("Failed to initialize Twilio client: {$e->getMessage()}", $log_file);
    http_response_code(500);
    exit;
}

// Log raw POST data for debugging
$all_post_vars = print_r($_POST, true);
writeLog("All POST vars: " . $all_post_vars, $log_file);

// Get Twilio webhook data
$from = $_POST['From'] ?? '';
$body = strtolower($_POST['Body'] ?? '');

// Log incoming message
writeLog("Received WhatsApp response from $from: $body", $log_file);

// Process reply if valid
if ($from && in_array($body, ['yes', 'hi'])) {
    $phone_number = str_replace('whatsapp:', '', $from);
    $user_data = loadUserData($phone_number, $log_file);
    if ($user_data) {
        $visitor_name = $user_data['visitor_name'];
        $image_url = $user_data['image_url'];

        try {
            $message = $twilio->messages->create(
                "whatsapp:$phone_number",
                [
                    'from' => "whatsapp:+15557822704",
                    'body' => "Hello $visitor_name! Here's your QR code:",
                    'mediaUrl' => [$image_url],
                    'messagingServiceSid' => "MG0ab9b89d512561331c3be3dd6f17f9e5",
                ]
            );
            writeLog("Image message sent successfully. Message SID: {$message->sid}", $log_file);
            deleteUserData($phone_number, $log_file);
        } catch (Exception $e) {
            writeLog("Failed to send image message: {$e->getMessage()}", $log_file);
        }
    }
} else {
    writeLog("No action taken for response from $from: $body", $log_file);
}

http_response_code(200); // Acknowledge Twilio webhook
?>