<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image_url = filter_input(INPUT_POST, 'image_url', FILTER_SANITIZE_URL);
    $visitor_name = filter_input(INPUT_POST, 'visitor_name', FILTER_SANITIZE_STRING);
    $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
    
    // Ensure phone number starts with +60
    $phone_number = preg_replace('/^0/', '+60', trim($phone_number));
    
    // Prepare data for webhook
    $data = [
        'image_url' => $image_url,
        'visitor_name' => $visitor_name,
        'phone_number' => $phone_number
    ];
    
    // Send data to webhook
    $webhook_url = 'https://salesjourney360.com/widget/visitor_qr/process_avaland_data_webhook.php';
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];
    
    $context  = stream_context_create($options);
    $result = file_get_contents($webhook_url, false, $context);
    
    // Display success/error message
    $message = $result ? 'Data sent successfully!' : 'Error sending data to webhook.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avaland Simulation Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">Avaland Visitor Form</h2>
        
        <?php if (isset($message)): ?>
            <div class="alert <?= $result ? 'alert-success' : 'alert-danger' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="image_url" class="form-label">Image URL</label>
                <input type="url" class="form-control" id="image_url" name="image_url" 
                       placeholder="https://example.com/image.jpg" required>
            </div>
            
            <div class="mb-3">
                <label for="visitor_name" class="form-label">Visitor Name</label>
                <input type="text" class="form-control" id="visitor_name" name="visitor_name" 
                       placeholder="John Doe" required>
            </div>
            
            <div class="mb-3">
                <label for="phone_number" class="form-label">Phone Number (+60)</label>
                <input type="tel" class="form-control" id="phone_number" name="phone_number" 
                       placeholder="0123456789" pattern="[0-9]{9,10}" required>
                <small class="form-text text-muted">Enter number without country code (e.g., 0123456789)</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>