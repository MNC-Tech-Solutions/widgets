<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set header for HTML response
header('Content-Type: text/html; charset=UTF-8');

// Twilio Auth Token
$authToken = '1c46b5593e219a0ddbc1f6a315adc5e0';

// Webhook URL
$webhookUrl = 'https://salesjourney360.com/widget/twilio/twilio_webhook.php';

// Preset Twilio payload data (from your new sample)
$presetData = [
    'Called' => '+60360430611',
    'ToState' => 'Wilayah Persekutuan',
    'CallerCountry' => 'MY',
    'Direction' => 'inbound',
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

$presetData['Timestamp'] = gmdate('D, d M Y H:i:s O');

// Preset headers (excluding X-Twilio-Signature, as it will be calculated)
$presetHeaders = [
    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
    'I-Twilio-Idempotency-Token' => '33d81ec1-e736-4f2b-b098-e579392fdc10',
    'Accept' => '*/*',
    'Content-Length' => '601',
    'X-Home-Region' => 'us1',
    'Host' => 'salesjourney360.com',
    'User-Agent' => 'TwilioProxy/1.1',
    'Connection' => 'close',
    'X-HTTPS' => '1'
];

// Function to compute Twilio signature (from your provided code)
function computeTwilioSignature($authToken, $url, $postData) {
    ksort($postData);
    $data = $url;
    foreach ($postData as $key => $value) {
        $data .= $key . $value;
    }
    return base64_encode(hash_hmac('sha1', $data, $authToken, true));
}

// Initialize variables
$responseMessage = '';
$debugInfo = [];
$computedSignature = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $postData = $_POST;

    // Compute X-Twilio-Signature
    $computedSignature = computeTwilioSignature($authToken, $webhookUrl, $postData);

    // Prepare cURL request to simulate Twilio
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Twilio-Signature: ' . $computedSignature,
        'I-Twilio-Idempotency-Token: ' . $presetHeaders['I-Twilio-Idempotency-Token'],
        'Accept: */*',
        'X-Home-Region: us1',
        'User-Agent: TwilioProxy/1.1',
        'Connection: close',
        'X-HTTPS: 1'
    ]);

    // Execute cURL request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Store debug info
    $debugInfo['sent_data'] = $postData;
    $debugInfo['computed_signature'] = $computedSignature;
    $debugInfo['response'] = $response;
    $debugInfo['http_code'] = $httpCode;

    // Set response message
    if ($httpCode === 200) {
        $responseMessage = 'Data sent successfully to webhook.';
    } else {
        $responseMessage = "Failed to send data. HTTP Code: $httpCode. Response: " . htmlspecialchars($response);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Twilio Webhook Simulator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { padding: 20px; }
        .form-section { margin-bottom: 20px; }
        pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mt-4">Twilio Webhook Simulator</h1>
        <p>Use this form to simulate sending Twilio webhook data to your webhook.</p>

        <?php if ($responseMessage): ?>
            <div class="alert <?php echo $httpCode === 200 ? 'alert-success' : 'alert-danger'; ?> mt-3" role="alert">
                <?php echo htmlspecialchars($responseMessage); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="mt-4">
            <div class="form-section">
                <h3>Twilio Payload Data</h3>
                <?php foreach ($presetData as $key => $value): ?>
                    <div class="mb-3">
                        <label for="<?php echo htmlspecialchars($key); ?>" class="form-label"><?php echo htmlspecialchars($key); ?></label>
                        <input type="text" class="form-control" id="<?php echo htmlspecialchars($key); ?>" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">Send to Webhook</button>
        </form>

        <?php if (!empty($debugInfo)): ?>
            <h2 class="mt-4">Debug Information</h2>
            <h3>Sent Data:</h3>
            <pre><?php echo htmlspecialchars(print_r($debugInfo['sent_data'], true)); ?></pre>
            <h3>Computed X-Twilio-Signature:</h3>
            <pre><?php echo htmlspecialchars($debugInfo['computed_signature']); ?></pre>
            <h3>Response from Webhook:</h3>
            <pre><?php echo htmlspecialchars($debugInfo['response']); ?></pre>
            <h3>HTTP Code:</h3>
            <pre><?php echo htmlspecialchars($debugInfo['http_code']); ?></pre>
            <p>Received at: <?php echo date('Y-m-d H:i:s'); ?> (UTC+08)</p>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>