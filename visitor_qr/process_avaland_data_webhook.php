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

// Function to format phone number to +601[1-9][0-9]{7,8}
function formatPhoneNumber($input_phone) {
    // Remove non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $input_phone);
    
    // Handle prefixes
    if (substr($phone, 0, 3) === '601') {
        // Already starts with 601, keep it
    } elseif (substr($phone, 0, 2) === '01') {
        // Starts with 01, prepend 6
        $phone = '6' . $phone;
    } elseif (substr($phone, 0, 1) === '1') {
        // Starts with 1, prepend 60
        $phone = '60' . $phone;
    } else {
        // No recognizable prefix, prepend 601
        $phone = '601' . $phone;
    }
    
    // Add + prefix if not already present
    if (substr($phone, 0, 1) !== '+') {
        $phone = '+' . $phone;
    }
    
    // Validate: must match +601[0-9]{8,9}
    if (!preg_match('/^\+601[0-9]{8,9}$/', $phone)) {
        return false;
    }
    
    return $phone;
}

// Function to save user data
function saveUserData($phone_number, $visitor_name, $image_url, $visitation_date, $project_name, $unit_no, $car_plate_no, $floor_level) {
    $data = [
        'visitor_name' => $visitor_name,
        'image_url' => $image_url,
        'visitation_date' => $visitation_date,
        'project_name' => $project_name,
        'unit_no' => $unit_no,
        'car_plate_no' => $car_plate_no,
        'floor_level' => $floor_level
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
$twilio_account_sid = 'ACca73e5834d56cc841d1ba7cb07aad201';
$twilio_auth_token = '5d4e5f4f07af376cf17eca35db07d92b';
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
$required_fields = ['image_url', 'visitor_name', 'phone_number', 'visitation_date'];
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
$raw_phone_number = trim($input['phone_number']);
$visitation_date = trim($input['visitation_date']);
$project_name = trim($input['project_name']);
$unit_no = trim($input['unit_no']);
$car_plate_no = trim($input['car_plate_no']);
$floor_level = trim($input['floor_level']);

// Format and validate phone number
$phone_number = formatPhoneNumber($raw_phone_number);
if ($phone_number === false) {
    writeLog("Invalid phone number format: $raw_phone_number", $log_file);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format. Must be a valid Malaysian mobile number starting with +601 followed by a digit 1-9 and 7-8 more digits (e.g., +60163931826 or +601639318269)']);
    exit;
}

// Log received data
writeLog("Received data: image_url=$image_url, visitor_name=$visitor_name, raw_phone_number=$raw_phone_number, formatted_phone_number=$phone_number, visitation_date=$visitation_date, project_name=$project_name, unit_no=$unit_no, car_plate_no=$car_plate_no, floor_level=$floor_level", $log_file);

// Validate image URL
if (!$image_url) {
    writeLog('Invalid image URL', $log_file);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image URL']);
    exit;
}

try {
    // Log the from address being used
    $from_address = "whatsapp:+60145500532";
    writeLog("Attempting to send with from address: $from_address", $log_file);

    // Save user data
    saveUserData($phone_number, $visitor_name, $image_url, $visitation_date, $project_name, $unit_no, $car_plate_no, $floor_level);

    // Initial template message with content variables
    $message = $twilio->messages->create(
        "whatsapp:$phone_number",
        [
            'contentSid' => "HXcca95aefb64616f8512d67b3c7d9e76e",
            'from' => $from_address,
            "messagingServiceSid" => "MGcbb564952ffcda04a57c4719d6e31cae",
            'contentVariables' => json_encode([
                '1' => $visitor_name,
                '2' => $visitation_date
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