<?php
date_default_timezone_set('Asia/Seoul');
header('Content-Type: application/json');

$servername = "127.0.0.1";
$username = "root";
$password = "jancok123";
$database = "casino";

$dbConn = new mysqli($servername, $username, $password, $database);
if ($dbConn->connect_error) {
    die("Connection failed: " . $dbConn->connect_error);
}

const CALLBACK_TOKEN = '85c268d3-6eb8-4c91-ace6-17b7a7d28616';
$logPath = '/var/www/html/.cbk';
const MAX_RETRIES = 3; 
const RETRY_DELAY = 2;

const API_RESPONSE_CODES = [
    'OK' => 0,
    'UNDER_MAINTENANCE' => 1,
    'INTERNAL_SERVER_ERROR' => 1001,
    'VALIDATION_ERROR' => 1002,
    'SERVICE_EXCEPTION' => 1003,
    'TOKEN_NOT_FOUND' => 1007,
    'TOKEN_INVALID' => 1009,
    'PERMISSION_ERROR' => 1010,
    'PROVIDER_ERROR' => 1011,
    'PARAMETERS_INVALID' => 1012,
    'CALLBACK_ERROR' => 1015,
    'SERVER_IS_BUSY' => 1018,
    'IP_NOT_ALLOWED' => 1020,
    'AGENT_NOT_FOUND' => 2001,
    'USER_NOT_FOUND' => 2002,
    'GAME_NOT_FOUND' => 2003,
    'POINT_NOT_ENOUGH' => 2005,
    'BALANCE_NOT_ENOUGH' => 2006,
    'PROVIDER_NOT_FOUND' => 2007,
    'BONUSCALL_DOUBLE' => 2011,
    'BONUSCALL_ALREADY_ENDED' => 2012,
    'ROUND_NOT_FOUND' => 2013,
    'CURRENCY_NOT_SUPPORTED' => 2014,
];

function logMessage($message) {
    global $logPath;
    file_put_contents($logPath, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

function send_response($statusCode, $message, $data = []) {
    global $dbConn;
    $result = [
        'code' => API_RESPONSE_CODES[$statusCode] ?? 99,
        'message' => $message,
        'data' => $data,
    ];
    logMessage("Response: " . json_encode($result));
    $dbConn->close();
    echo json_encode($result);
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

function retryRequest($command, $data, $retryCount = 0) {
    global $dbConn, $userInfo, $betInfo;

    try {
        switch ($command) {
            case 'authenticate':
                $user_id = $data->account ?? '';
                $sql = "SELECT * FROM user_casino WHERE user_id='{$user_id}'";
                $result = $dbConn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $userInfo = $result->fetch_assoc();
                    send_response('OK', 'Authentication successful', [
                        'account' => $userInfo['user_id'],
                        'balance' => $userInfo['money']
                    ]);
                } else {
                    throw new Exception('User not found');
                }
                break;

            case 'balance':
                send_response('OK', 'Balance retrieved', [
                    'balance' => $userInfo['money']
                ]);
                break;

            case 'bet':
                $trans_id = $data->trans_id ?? '';
                $amount = intval($data->amount ?? 0);
                $game_id = $data->game_code ?? '';
                $round_id = $data->round_id ?? '';

                $sql = "INSERT INTO bet_casino (trans_id, user_id, game_id, round_id, sort, money, request_datetime)
                        VALUES ('{$trans_id}', '{$userInfo['user_id']}', '{$game_id}', '{$round_id}', 'BET', '{$amount}', '".time()."')";
                $result = $dbConn->query($sql);

                if ($result) {
                    $user_money = $userInfo['money'] - $amount;
                    $sql = "UPDATE user_casino SET money='{$user_money}' WHERE user_id='{$userInfo['user_id']}'";
                    $dbConn->query($sql);

                    send_response('OK', 'Bet placed', ['balance' => $user_money]);
                } else {
                    throw new Exception('Failed to place bet');
                }
                break;

            case 'win':
                $trans_id = $data->trans_id ?? '';
                $amount = intval($data->amount ?? 0);
                $game_id = $data->game_code ?? '';
                $round_id = $data->round_id ?? '';

                $sql = "INSERT INTO bet_casino (trans_id, user_id, game_id, round_id, sort, money, request_datetime)
                        VALUES ('{$trans_id}', '{$userInfo['user_id']}', '{$game_id}', '{$round_id}', 'WIN', '{$amount}', '".time()."')";
                $result = $dbConn->query($sql);

                if ($result) {
                    $user_money = $userInfo['money'] + $amount;
                    $sql = "UPDATE user_casino SET money='{$user_money}' WHERE user_id='{$userInfo['user_id']}'";
                    $dbConn->query($sql);

                    send_response('OK', 'Win recorded', ['balance' => $user_money]);
                } else {
                    throw new Exception('Failed to record win');
                }
                break;

            case 'cancel':
                if ($betInfo['sort'] != "CANCEL") {
                    $money = $betInfo['sort'] == "BET" ? $betInfo['money'] : -$betInfo['money'];
                    $sql = "UPDATE bet_casino SET sort='CANCEL' WHERE trans_id='{$betInfo['trans_id']}'";
                    $result = $dbConn->query($sql);

                    if ($result) {
                        $user_money = $userInfo['money'] + $money;
                        $sql = "UPDATE user_casino SET money='{$user_money}' WHERE user_id='{$userInfo['user_id']}'";
                        $dbConn->query($sql);

                        send_response('OK', 'Bet canceled', ['balance' => $user_money]);
                    } else {
                        throw new Exception('Failed to cancel bet');
                    }
                } else {
                    send_response('OK', 'Bet already canceled', ['balance' => $userInfo['money']]);
                }
                break;

            case 'status':
                $status = $betInfo['sort'] == "CANCEL" ? "CANCELED" : "OK";
                send_response('OK', 'Status retrieved', [
                    'trans_id' => $data->trans_id,
                    'trans_status' => $status
                ]);
                break;

            default:
                // Handling various commands that are passed to the API provider
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
                // Send the response from the API provider back to the client
                send_response($response['code'] ?? 'CALLBACK_ERROR', $response['message'] ?? 'An error occurred', $response['data'] ?? []);
                break;
        }
    } catch (Exception $e) {
        logMessage("Error: " . $e->getMessage());
        
        if ($retryCount < MAX_RETRIES) {
            logMessage("Retrying... Attempt " . ($retryCount + 1));
            sleep(RETRY_DELAY); 
            retryRequest($command, $data, $retryCount + 1);
        } else {
            send_response('CALLBACK_ERROR', 'Max retries reached. ' . $e->getMessage());
        }
    }
}

$tokenReceived = $_SERVER['HTTP_CALLBACK_TOKEN'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    logMessage("Invalid HTTP method: $method");
    http_response_code(405);
    send_response('CALLBACK_ERROR', 'Invalid HTTP method');
}

if ($tokenReceived !== CALLBACK_TOKEN) {
    logMessage("Invalid Callback Token: $tokenReceived");
    http_response_code(401);
    send_response('TOKEN_INVALID', 'Invalid Callback Token');
}

$requestData = file_get_contents('php://input');
$oData = json_decode($requestData, true);  // Convert JSON to associative array

$command = $oData['command'] ?? '';
$aCheckItem = explode(',', $oData['check'] ?? '');

$userInfo = '';
$betInfo = '';

foreach ($aCheckItem as $check) {
    switch ($check) {
        case 21:
            $user_id = $oData['data']['account'] ?? '';
            $sql = "SELECT * FROM user_casino WHERE user_id='{$user_id}'";
            $result = $dbConn->query($sql);
            if ($result->num_rows > 0) {
                $userInfo = $result->fetch_assoc();
            } else {
                send_response('USER_NOT_FOUND', 'User not found');
            }
            break;

        case 22:
            if ($userInfo['status'] != "Active") {
                send_response('PERMISSION_ERROR', 'User not active');
            }
            break;

        case 31:
            $amount = intval($oData['data']['amount'] ?? 0);
            if ($userInfo['money'] < $amount) {
                send_response('BALANCE_NOT_ENOUGH', 'Balance not enough', ['balance' => $userInfo['money']]);
            }
            break;

        case 41:
            $trans_id = $oData['data']['trans_id'] ?? '';
            $sql = "SELECT * FROM bet_casino WHERE trans_id='{$trans_id}'";
            $result = $dbConn->query($sql);
            if ($result->num_rows > 0) {
                send_response('CALLBACK_ERROR', 'Transaction already exists', ['balance' => $userInfo['money']]);
            }
            break;

        case 42:
            $trans_id = $oData['data']['trans_id'] ?? '';
            $sql = "SELECT * FROM bet_casino WHERE trans_id='{$trans_id}'";
            $result = $dbConn->query($sql);
            if ($result->num_rows > 0) {
                $betInfo = $result->fetch_assoc();
            } else {
                send_response('ROUND_NOT_FOUND', 'Round not found', ['balance' => $userInfo['money']]);
            }
            break;
    }
}

retryRequest($command, $oData['data']);
logMessage("Processed Command: " . $command . " with response: " . json_encode($response ?? []));
?>
