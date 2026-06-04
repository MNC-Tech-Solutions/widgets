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
        return $data ?: ['visitor_name' => 'Unknown', 'image_url' => '', 'visitation_date' => ''];
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
$twilio_account_sid = 'ACca73e5834d56cc841d1ba7cb07aad201';
$twilio_auth_token = '5d4e5f4f07af376cf17eca35db07d92b';
$twilio_whatsapp_number = '+60145500532';
$messaging_service_sid = 'MGcbb564952ffcda04a57c4719d6e31cae';
$reply_template = 'HXa8d082b3f803b6449bc25fd608b42349';

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
$button_payload = isset($_POST['ButtonPayload']) ? strtolower($_POST['ButtonPayload']) : '';
$body = strtolower($_POST['Body'] ?? '');

// Log incoming message
writeLog("Received WhatsApp response from $from: ButtonPayload=$button_payload, Body=$body", $log_file);

// Process response if valid
if ($from && ($button_payload === 'yes')) {
    $phone_number = str_replace('whatsapp:', '', $from);
    $user_data = loadUserData($phone_number, $log_file);
    
    if (!$user_data) {
        writeLog("No user data found for $phone_number, cannot proceed", $log_file);
        http_response_code(400);
        exit;
    }

    $visitor_name = $user_data['visitor_name'];
    $image_url = $user_data['image_url'];
    $visitation_date = $user_data['visitation_date'];
    $project_name = $user_data['project_name'];
    $unit_no = $user_data['unit_no'];
    $car_plate_no = $user_data['car_plate_no'];
    $floor_level = $user_data['floor_level'];

    try {
        // Send reply template
        $template_message = $twilio->messages->create(
            "whatsapp:$phone_number",
            [
                'contentSid' => $reply_template,
                'from' => "whatsapp:$twilio_whatsapp_number",
                'messagingServiceSid' => $messaging_service_sid
            ]
        );
        writeLog("Reply template sent successfully. Message SID: {$template_message->sid}", $log_file);

        // Send QR code image message
        $image_message = $twilio->messages->create(
            "whatsapp:$phone_number",
            [
                'from' => "whatsapp:$twilio_whatsapp_number",
                'mediaUrl' => [$image_url],
                'messagingServiceSid' => $messaging_service_sid,
            ]
        );
        writeLog("Image message sent successfully. Message SID: {$image_message->sid}", $log_file);

        sleep(2);

        // Send additional message with visitation details
        $details_message_body = "Project Name: $project_name\n" .
                               "Unit No: $unit_no\n" .
                               "Visitor Name: $visitor_name\n" .
                               "Date & Time In: $visitation_date\n" .
                               "Floor Level: $floor_level\n" .
                               "Car Plate No: $car_plate_no";

        $details_message = $twilio->messages->create(
            "whatsapp:$phone_number",
            [
                'from' => "whatsapp:$twilio_whatsapp_number",
                'body' => $details_message_body,
                'messagingServiceSid' => $messaging_service_sid,
            ]
        );
        writeLog("Details message sent successfully. Message SID: {$details_message->sid}", $log_file);

        // Delete user data after sending both messages
        deleteUserData($phone_number, $log_file);
    } catch (Exception $e) {
        writeLog("Failed to send message: {$e->getMessage()}", $log_file);
        http_response_code(500);
        exit;
    }
} elseif ($from && $button_payload === 'no') {
    $phone_number = str_replace('whatsapp:', '', $from);
    $user_data = loadUserData($phone_number, $log_file);
    
    if (!$user_data) {
        writeLog("No user data found for $phone_number, cannot proceed", $log_file);
        http_response_code(400);
        exit;
    }

    $visitor_name = $user_data['visitor_name'];

    try {
        // Send response for "No"
        $message = $twilio->messages->create(
            "whatsapp:$phone_number",
            [
                'from' => "whatsapp:$twilio_whatsapp_number",
                'body' => "Thank you for letting us know. Please contact the resident to update your visitation details, or ignore this if not applicable.",
                'messagingServiceSid' => $messaging_service_sid,
            ]
        );
        writeLog("Response sent for 'No'. Message SID: {$message->sid}", $log_file);
        deleteUserData($phone_number, $log_file);
    } catch (Exception $e) {
        writeLog("Failed to send 'No' response: {$e->getMessage()}", $log_file);
        http_response_code(500);
        exit;
    }
} else {
    writeLog("Invalid or no response from $from: ButtonPayload=$button_payload, Body=$body", $log_file);
}

http_response_code(200); // Acknowledge Twilio webhook
?>