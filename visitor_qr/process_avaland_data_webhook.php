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

// Function to save user data
function saveUserData($phone_number, $visitor_name, $image_url) {
    $data = [
        'visitor_name' => $visitor_name,
        'image_url' => $image_url,
        'timestamp' => time()
    ];
    $filename = __DIR__ . '/user_data/' . str_replace('+', '_', $phone_number) . '.json';
    if (!file_exists(__DIR__ . '/user_data')) {
        mkdir(__DIR__ . '/user_data', 0777, true);
    }
    file_put_contents($filename, json_encode($data));
}

// Function to format timestamp to "6th of June, at 4:00 pm"
function formatTimestamp($timestamp) {
    $day = date('j', $timestamp);
    $suffix = 'th';
    if ($day % 10 == 1 && $day != 11) $suffix = 'st';
    elseif ($day % 10 == 2 && $day != 12) $suffix = 'nd';
    elseif ($day % 10 == 3 && $day != 13) $suffix = 'rd';
    return date("j{$suffix} \o\f F, \a\t g:i a", $timestamp);
}

// Set default time zone to match your location (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// Hardcoded Twilio credentials
$twilio_account_sid = 'ACca73e5834d56cc841d1ba7cb07aad201';
$twilio_auth_token = '9c048a45f4ec7ac08841b4ebeea37503';
$twilio_whatsapp_number = '+60145500532';

// Initialize Twilio client
try {
    $twilio = new Client($twilio_account_sid, $twilio_auth_token);
} catch (Exception $e) {
    writeLog("Failed to initialize Twilio client: {$e->getMessage()}", $log_file);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initialize Twilio client']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    writeLog('Invalid JSON received', $log_file);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$required_fields = ['image_url', 'visitor_name', 'phone_number'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        writeLog("Missing or empty field: $field", $log_file);
        http_response_code(400);
        echo json_encode(['error' => "Missing or empty field: $field"]);
        exit;
    }
}

$image_url = filter_var($input['image_url'], FILTER_VALIDATE_URL);
$visitor_name = filter_var($input['visitor_name'], FILTER_SANITIZE_STRING);
$phone_number = trim($input['phone_number']);

// Log received data
writeLog("Received data: image_url=$image_url, visitor_name=$visitor_name, phone_number=$phone_number", $log_file);

// Validate image URL
if (!$image_url) {
    writeLog('Invalid image URL', $log_file);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image URL']);
    exit;
}

// Ensure phone number starts with +60
if (!preg_match('/^\+60[0-9]{9,10}$/', $phone_number)) {
    writeLog('Invalid phone number format. Must start with +60 followed by 9-10 digits', $log_file);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format. Must start with +60 followed by 9-10 digits']);
    exit;
}

try {
    // Log the from address being used
    $from_address = "whatsapp:+60145500532";
    writeLog("Attempting to send with from address: $from_address", $log_file);

    // Save user data
    saveUserData($phone_number, $visitor_name, $image_url);

    // Format timestamp for the template
    $formatted_timestamp = formatTimestamp(time());

    // Initial template message with content variables
    $message = $twilio->messages->create(
        "whatsapp:$phone_number",
        [
            'contentSid' => "HXcca95aefb64616f8512d67b3c7d9e76e",
            'from' => $from_address,
            "messagingServiceSid" => "MGcbb564952ffcda04a57c4719d6e31cae",
            'contentVariables' => json_encode([
                '1' => $visitor_name,
                '2' => $formatted_timestamp
            ])
        ]
    );

    writeLog("Initial template message sent. Message SID: {$message->sid}", $log_file);
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Initial template sent. Awaiting user response.',
        'message_sid' => $message->sid
    ]);

} catch (Exception $e) {
    // Log detailed error
    writeLog("Failed to send WhatsApp message: {$e->getMessage()}", $log_file);
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to send WhatsApp message',
        'details' => $e->getMessage()
    ]);
}
?>