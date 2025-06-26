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

// Set default time zone to match your location (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// Hardcoded Twilio credentials
$twilio_account_sid = 'ACe217c9267706083594bb2a4cf26b2ae5';
$twilio_auth_token = '1c46b5593e219a0ddbc1f6a315adc5e0';
$twilio_whatsapp_number = '+15557822704';

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
    $from_address = "whatsapp:+15557822704";
    writeLog("Attempting to send with from address: $from_address", $log_file);

    // Save user data
    saveUserData($phone_number, $visitor_name, $image_url);

    // Initial template message
    $message = $twilio->messages->create(
        "whatsapp:$phone_number",
        [
            'contentSid' => "HXa8f21e784658e807c0789ef5918098ed",
            'from' => $from_address,
            "messagingServiceSid" => "MG0ab9b89d512561331c3be3dd6f17f9e5",
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