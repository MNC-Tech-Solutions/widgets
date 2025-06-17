<?php
// Twilio Auth Token
$authToken = '1c46b5593e219a0ddbc1f6a315adc5e0'; // Replace with your Twilio Auth Token

// Webhook URL
$url = 'https://salesjourney360.com/widget/twilio/twilio_webhook.php';

// POST data (must match Postman form data exactly)
$postData = [
    'Called' => '+60360430611',
    'ToState' => 'Wilayah Persekutuan',
    'CallerCountry' => 'MY',
    'Direction' => 'inbound',
    'Timestamp' => 'Tue, 20 May 2025 09:52:22 +0000',
    'CallbackSource' => 'call-progress-events',
    'CallerState' => '',
    'ToZip' => '',
    'SequenceNumber' => '0',
    'CallSid' => 'CAf75f7e01b9874fd68fd6b37b027f284e',
    'To' => '+60360430611',
    'CallerZip' => '',
    'ToCountry' => 'MY',
    'CalledZip' => '',
    'ApiVersion' => '2010-04-01',
    'CalledCity' => '',
    'CallStatus' => 'completed',
    'Duration' => '1',
    'From' => '+60163931826',
    'CallDuration' => '11',
    'AccountSid' => 'ACe217c9267706083594bb2a4cf26b2ae5',
    'CalledCountry' => 'MY',
    'CallerCity' => '',
    'ToCity' => '',
    'FromCountry' => 'MY',
    'Caller' => '+60163931826',
    'FromCity' => '',
    'CalledState' => 'Wilayah Persekutuan',
    'FromZip' => '',
    'FromState' => ''
];

// Sort POST data by keys
ksort($postData);

// Construct data string
$data = $url;
foreach ($postData as $key => $value) {
    $data .= $key . $value;
}

// Generate HMAC-SHA1 hash and encode in Base64
$signature = base64_encode(hash_hmac('sha1', $data, $authToken, true));

echo "X-Twilio-Signature: " . $signature . "\n";
?>