/**
 * WIDGET: Evaluation Performance Cards
 * * PURPOSE: 
 * This widget analyzes call quality by aggregating "Yes/No" responses to specific 
 * evaluation questions linked to Call Detail Records (CDR).
 * * LOGIC:
 * 1. Double-Data Fetch: First, it fetches the definitions of all questions from 
 * the 'evaluationDetails' action. Second, it fetches the actual call records 
 * from 'getCDR' with evaluations enabled.
 * 2. ID Mapping: It dynamically extracts evaluation data from the CDR payload by 
 * identifying keys starting with 'eval_' and matching the suffix to the question ID.
 * 3. Sentiment Calculation: It calculates the percentage of "Yes" vs "No" 
 * responses per question to provide a "Positive Performance" score.
 * 4. Visualization: Renders results as a grid of interactive cards, each 
 * featuring a progress bar representing the professional compliance or success 
 * rate for that specific metric.
 * 5. Data Handling: Automatically handles cases where questions exist but 
 * have not yet received any responses (empty states).
 */

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Report</title>
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #34495e;
            --accent: #3498db;
            --success: #2ecc71;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --text: #2c3e50;
            --border: #bdc3c7;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            line-height: 1.5;
            color: var(--text);
            background-color: #f9fafb;
            padding: 15px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            margin-bottom: 25px;
            padding: 15px 0;
        }
        
        h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .subtitle {
            font-size: 0.95rem;
            color: #7f8c8d;
        }
        
        .cards-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .card-header {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background-color: #f8f9fa;
        }
        
        .card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--primary);
            line-height: 1.3;
        }
        
        .card-body {
            padding: 12px;
        }
        
        .question-text {
            font-size: 0.85rem;
            color: #5a6c7d;
            margin-bottom: 12px;
            line-height: 1.4;
            height: 60px;
            overflow: hidden;
        }
        
        .stats-container {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .stat {
            flex: 1;
            padding: 6px;
            border-radius: 6px;
            text-align: center;
        }
        
        .stat.yes {
            background-color: rgba(46, 204, 113, 0.08);
            border: 1px solid rgba(46, 204, 113, 0.2);
        }
        
        .stat.no {
            background-color: rgba(231, 76, 60, 0.08);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .stat.yes .stat-value {
            color: var(--success);
        }
        
        .stat.no .stat-value {
            color: var(--danger);
        }
        
        .stat-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: #7f8c8d;
        }
        
        .progress-container {
            margin-top: 10px;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #7f8c8d;
            margin-bottom: 4px;
        }
        
        .progress-bar {
            height: 5px;
            background-color: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background-color: var(--accent);
            border-radius: 3px;
            transition: width 0.8s ease;
        }
        
        .total-responses {
            font-size: 0.75rem;
            color: #7f8c8d;
            text-align: center;
            margin-top: 8px;
        }
        
        .no-data {
            text-align: center;
            padding: 12px 0;
            color: #95a5a6;
            font-size: 0.85rem;
        }
        
        footer {
            text-align: center;
            margin-top: 30px;
            padding: 15px;
            color: #95a5a6;
            font-size: 0.8rem;
        }
        
        @media (max-width: 1200px) {
            .cards-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .cards-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 600px) {
            .cards-container {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Evaluation Report</h1>
            <div class="subtitle">Question performance analysis</div>
        </header>
        
        <div class="cards-container">
            <?php
            // Configuration
            $username = "chloe.ooi@mnctechsolutions.com";
            $password = "chloeooi2004@"; // Used original password; change to "chloeooi2004@" if that's correct
            $client_id = 5061;
            $date_from = "2025-01-01";
            $limit = 10000;

            // URLs
            $questions_url = "https://api.avanser.com/JSON?action=evaluationDetails&client_id=" . $client_id;
            $cdr_url = "https://api.avanser.com/JSON?action=getCDR&client_id=" . $client_id . "&date_from=" . $date_from . "&limit=" . $limit . "&evaluations=yes";

            // Function to fetch data with basic auth
            function fetch_data($url, $username, $password) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code != 200) {
                    echo "Error fetching data: HTTP $http_code<br>";
                    echo htmlspecialchars($response);
                    exit(1);
                }
                
                return json_decode($response, true);
            }

            // Fetch questions
            $questions_data = fetch_data($questions_url, $username, $password);
            $questions = $questions_data['questions'] ?? [];

            // Map id to question details
            $question_map = [];
            foreach ($questions as $q) {
                $q_id = $q['id'];
                $question_map[$q_id] = [
                    'title' => $q['title'],
                    'text' => $q['text'],
                ];
            }

            // Fetch CDR
            $cdr_data = fetch_data($cdr_url, $username, $password);
            // Extract calls array from the response
            $records = $cdr_data['calls'] ?? []; // Use 'calls' key as per the payload structure

            // Count yes/no for each eval_id
            $counts = [];
            foreach ($records as $record) {
                foreach ($record as $key => $value) {
                    if (strpos($key, 'eval_') === 0) {
                        $eval_id = substr($key, 5);
                        if (!isset($counts[$eval_id])) {
                            $counts[$eval_id] = ['yes' => 0, 'no' => 0, 'total' => 0];
                        }
                        $value_lower = strtolower($value);
                        if ($value_lower === 'yes') {
                            $counts[$eval_id]['yes']++;
                            $counts[$eval_id]['total']++;
                        } elseif ($value_lower === 'no') {
                            $counts[$eval_id]['no']++;
                            $counts[$eval_id]['total']++;
                        }
                        // Ignore empty
                    }
                }
            }

            // Generate cards for each question
            foreach ($question_map as $q_id => $details) {
                $hasData = isset($counts[$q_id]);
                
                if ($hasData) {
                    $c = $counts[$q_id];
                    $total = $c['total'];
                    
                    if ($total > 0) {
                        $yes_count = $c['yes'];
                        $no_count = $c['no'];
                        $yes_pct = number_format(($yes_count / $total) * 100, 1);
                        $no_pct = number_format(($no_count / $total) * 100, 1);
                        
                        $cardContent = "
                        <div class='stats-container'>
                            <div class='stat yes'>
                                <div class='stat-value'>{$yes_count}</div>
                                <div class='stat-label'>Yes ({$yes_pct}%)</div>
                            </div>
                            <div class='stat no'>
                                <div class='stat-value'>{$no_count}</div>
                                <div class='stat-label'>No ({$no_pct}%)</div>
                            </div>
                        </div>
                        <div class='progress-container'>
                            <div class='progress-text'>
                                <span>Positive</span>
                                <span>{$yes_pct}%</span>
                            </div>
                            <div class='progress-bar'>
                                <div class='progress-fill' style='width: {$yes_pct}%'></div>
                            </div>
                        </div>
                        <div class='total-responses'>Total: {$total}</div>
                        ";
                    } else {
                        $cardContent = "<div class='no-data'>No evaluations</div>";
                    }
                } else {
                    $cardContent = "<div class='no-data'>No data</div>";
                }
                
                echo "
                <div class='card'>
                    <div class='card-header'>
                        <div class='card-title'>" . htmlspecialchars($details['title']) . "</div>
                    </div>
                    <div class='card-body'>
                        <div class='question-text'>" . htmlspecialchars($details['text']) . "</div>
                        {$cardContent}
                    </div>
                </div>
                ";
            }
            ?>
        </div>
    </div>
</body>
</html>