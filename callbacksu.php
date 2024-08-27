<?php
require_once 'config.php';
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json');

$logPath = '/var/www/html/cbk';

function logMessage($message) {
    global $logPath;
    file_put_contents($logPath, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

$headers = getallheaders();
logMessage("Headers received: " . print_r($headers, true));

$tokenReceived = $headers['Callback-Token'] ?? '';
logMessage("Token Received: $tokenReceived");
logMessage("Expected CALLBACK_TOKEN: " . CALLBACK_TOKEN);

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST' || $tokenReceived !== CALLBACK_TOKEN) {
    http_response_code(405);
    $error = json_encode(['error' => 'Invalid HTTP method or Callback Token']);
    echo $error;
    logMessage("Error: " . $error . " Method: $method, Token Received: $tokenReceived");
    exit;
}


$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['command'])) {
    http_response_code(400);
    $error = json_encode(['error' => 'Missing required command field']);
    echo $error;
    logMessage("Missing Command: " . $error);
    exit;
}

function processRequest($url, $data) {
    $headers = [
        "Authorization: Bearer " . API_ACCESS_TOKEN,
        "Content-Type: application/json",
        "Accept: application/json"
    ];
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        logMessage("CURL Error for $url: " . $err);
        return ['error' => $err];
    }
    return json_decode($response, true);
}

switch ($data['command']) {
    case 'agent_info':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/agent/info', []);
        break;
    case 'agent_rtp':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/agent/rtp', ['win_ratio' => $data['data']['win_ratio']]);
        break;
    case 'user_create':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/user/create', $data['data']);
        break;
    case 'user_info':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/user/info', $data['data']);
        break;
    case 'deposit':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/wallet/deposit', $data['data']);
        break;
    case 'withdraw':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/wallet/withdraw', $data['data']);
        break;
    case 'withdraw_all':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/wallet/withdraw-all', $data['data']);
        break;
    case 'providers':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/providers', $data['data']);
        break;
    case 'games':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/games', $data['data']);
        break;
    case 'game_url':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/game-url', $data['data']);
        break;
    case 'online_games':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/online-games', []);
        break;
    case 'call_start':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/call_start', $data['data']);
        break;
    case 'call_cancel':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/call_cancel', $data['data']);
        break;
    case 'transaction':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/transaction', $data['data']);
        break;
    case 'transaction_id':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/transaction-id', $data['data']);
        break;
    case 'round_details':
        $response = processRequest('https://sc4-api-en.dreamgates.net/v4/game/round-details', $data['data']);
        break;
    default:
        http_response_code(400);
        $error = json_encode(['error' => 'Unsupported command']);
        echo $error;
        logMessage("Unsupported Command: " . $error);
        exit;
}

echo json_encode($response);
logMessage("Processed Command: " . $data['command'] . " with response: " . json_encode($response));
?>

