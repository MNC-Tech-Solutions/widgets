<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Link Generator</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            max-width: 600px;
            margin: 40px auto;
            background: #f4f7f6;
            padding: 20px;
        }
        h2 {
            color: #25D366;
            text-align: center;
            font-weight: 600;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative; 
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
            font-family: 'Segoe UI', Arial, sans-serif; 
            transition: border-color 0.3s ease;
        }
        input:focus, textarea:focus {
            border-color: #25D366;
            outline: none;
            box-shadow: 0 0 5px rgba(37, 211, 102, 0.3);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        input::placeholder, textarea::placeholder {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 16px;
            color: #999; 
            opacity: 1; 
        }
        .phone-input-wrapper {
            position: relative;
        }
        .phone-input-wrapper input {
            padding-left: 50px; 
        }
        .phone-input-wrapper::before {
            content: '+60';
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #333;
            font-size: 16px;
            font-weight: 500;
        }
        button {
            padding: 12px 30px;
            background: #25D366;
            color: white;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background 0.3s ease, transform 0.2s ease;
            display: block;
            margin: 0 auto;
        }
        button:hover {
            background: #20b958;
            transform: translateY(-2px);
        }
        button:active {
            transform: translateY(0);
        }
        .result {
            margin-top: 30px;
            padding: 15px;
            background: #f4f7f6;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        .result h3 {
            color: #25D366;
            margin-top: 0;
            font-size: 18px;
        }
        .result p {
            margin: 10px 0 0;
            word-wrap: break-word;
        }
        .result a {
            color: #007bff;
            text-decoration: none;
        }
        .result a:hover {
            text-decoration: underline;
        }
        .container {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>WhatsApp Link Generator</h2>
        
        <?php
        function generateWhatsappLink($phoneNumber, $message) {
            $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber); 
            if (substr($phoneNumber, 0, 1) === '1') { 
                $phoneNumber = '60' . $phoneNumber; 
            }
            $encodedMessage = urlencode($message);
            return "https://wa.me/{$phoneNumber}/?text={$encodedMessage}";
        }
        
        // Function to send phone number, message, and WhatsApp link to SJ360 webhook with +60 prefix
        function sendToSJ360($phoneNumber, $message, $whatsappLink, $webhookUrl) {
            // Format the phone number to include +60 prefix
            $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
            if (substr($phoneNumber, 0, 1) !== '+') {
                if (substr($phoneNumber, 0, 1) === '0') {
                    $phoneNumber = '+60' . substr($phoneNumber, 1); 
                } else {
                    $phoneNumber = '+60' . $phoneNumber; 
                }
            }

            $data = [
                'phone' => $phoneNumber,
                'contact' => [
                    'prescriptmsg' => $message 
                ],
                "WA_me_URL" => $whatsappLink 
            ];
            
            $payload = json_encode($data);
            
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [ 
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200; 
        }

        $result = '';
        $phoneNumber = '';
        $message = '';
        $sj360WebhookUrl = 'https://services.leadconnectorhq.com/hooks/BXuCudh2EKUEmv1gC4ai/webhook-trigger/96168d47-f633-4693-a1a3-d35044a79a50'; 

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $phoneNumber = $_POST['phone'] ?? '';
            $message = $_POST['message'] ?? '';
        
            if (!empty($phoneNumber) && !empty($message)) {
                $result = generateWhatsappLink($phoneNumber, $message);
        
                // Send phone number, message, and WhatsApp link to SJ360
                $success = sendToSJ360($phoneNumber, $message, $result, $sj360WebhookUrl);

                // Reset fields after successful generation
                $phoneNumber = ''; 
                $message = '';
            } else {
                $result = "Please fill in both fields.";
            }
        }
        
        ?>

        <form method="POST">
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <div class="phone-input-wrapper">
                    <input type="text" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($phoneNumber); ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="message">Pre-scripted Message</label>
                <textarea id="message" name="message" placeholder="e.g., Hi! I’m interested in the CNY Event!"><?php echo htmlspecialchars($message); ?></textarea>
            </div>
            
            <button type="submit">Generate Link</button>
        </form>

        <?php if ($result): ?>
            <div class="result">
                <h3>Generated WhatsApp Link</h3>
                <p><a href="<?php echo $result; ?>" target="_blank"><?php echo htmlspecialchars($result); ?></a></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>