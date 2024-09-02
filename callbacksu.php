<?php
require_once 'config.php';
date_default_timezone_set('Asia/Seoul');
header('Content-Type: application/json');

$servername = "127.0.0.1";
$username = "root";
$password = "";
$database = "casino";

$dbConn = new mysqli($servername, $username, $password, $database);
if ($dbConn->connect_error) {
    die("Connection failed: " . $dbConn->connect_error);
}

const CALLBACK_TOKEN = '85c268d3-6eb8-4c91-ace6-17b7a7d28616';
$logPath = '/var/www/html/cbk';

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

$tokenReceived = $_SERVER['HTTP_CALLBACK_TOKEN'] ?? '';
logMessage("Token Received: $tokenReceived");
logMessage("Expected CALLBACK_TOKEN: " . CALLBACK_TOKEN);

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    logMessage("Invalid HTTP method: $method");
    http_response_code(405);
    send_response('TOKEN_INVALID', 'Invalid HTTP method');
}

if ($tokenReceived !== CALLBACK_TOKEN) {
    logMessage("Invalid Callback Token: $tokenReceived");
    http_response_code(401);
    send_response('TOKEN_INVALID', 'Invalid Callback Token');
}

$requestData = file_get_contents('php://input');
$oData = json_decode($requestData);

$command = $oData->command ?? '';
$aCheckItem = explode(',', $oData->check ?? '');

$userInfo = '';
$betInfo = '';

foreach ($aCheckItem as $check) {
    switch ($check) {
        case 21:
            $user_id = $oData->data->account ?? '';
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
            $amount = intval($oData->data->amount ?? 0);
            if ($userInfo['money'] < $amount) {
                send_response('BALANCE_NOT_ENOUGH', 'Balance not enough', ['balance' => $userInfo['money']]);
            }
            break;

        case 41:
            $trans_id = $oData->data->trans_id ?? '';
            $sql = "SELECT * FROM bet_casino WHERE trans_id='{$trans_id}'";
            $result = $dbConn->query($sql);
            if ($result->num_rows > 0) {
                send_response('CALLBACK_ERROR', 'Transaction already exists', ['balance' => $userInfo['money']]);
            }
            break;

        case 42:
            $trans_id = $oData->data->trans_id ?? '';
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

switch ($command) {
    case 'authenticate':
        send_response('OK', 'Authentication successful', [
            'account' => $userInfo['user_id'],
            'balance' => $userInfo['money']
        ]);
        break;

    case 'balance':
        send_response('OK', 'Balance retrieved', [
            'balance' => $userInfo['money']
        ]);
        break;

    case 'bet':
        $trans_id = $oData->data->trans_id ?? '';
        $amount = intval($oData->data->amount ?? 0);
        $game_id = $oData->data->game_code ?? '';
        $round_id = $oData->data->round_id ?? '';

        $sql = "INSERT INTO bet_casino (trans_id, user_id, game_id, round_id, sort, money, request_datetime)
                VALUES ('{$trans_id}', '{$userInfo['user_id']}', '{$game_id}', '{$round_id}', 'BET', '{$amount}', '".time()."')";
        $result = $dbConn->query($sql);

        if ($result) {
            $user_money = $userInfo['money'] - $amount;
            $sql = "UPDATE user_casino SET money='{$user_money}' WHERE user_id='{$userInfo['user_id']}'";
            $dbConn->query($sql);

            send_response('OK', 'Bet placed', ['balance' => $user_money]);
        } else {
            send_response('INTERNAL_SERVER_ERROR', 'Failed to place bet', ['balance' => $userInfo['money']]);
        }
        break;

    case 'win':
        $trans_id = $oData->data->trans_id ?? '';
        $amount = intval($oData->data->amount ?? 0);
        $game_id = $oData->data->game_code ?? '';
        $round_id = $oData->data->round_id ?? '';

        $sql = "INSERT INTO bet_casino (trans_id, user_id, game_id, round_id, sort, money, request_datetime)
                VALUES ('{$trans_id}', '{$userInfo['user_id']}', '{$game_id}', '{$round_id}', 'WIN', '{$amount}', '".time()."')";
        $result = $dbConn->query($sql);

        if ($result) {
            $user_money = $userInfo['money'] + $amount;
            $sql = "UPDATE user_casino SET money='{$user_money}' WHERE user_id='{$userInfo['user_id']}'";
            $dbConn->query($sql);

            send_response('OK', 'Win recorded', ['balance' => $user_money]);
        } else {
            send_response('INTERNAL_SERVER_ERROR', 'Failed to record win', ['balance' => $userInfo['money']]);
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
                send_response('INTERNAL_SERVER_ERROR', 'Failed to cancel bet', ['balance' => $userInfo['money']]);
            }
        } else {
            send_response('OK', 'Bet already canceled', ['balance' => $userInfo['money']]);
        }
        break;

    case 'status':
        $status = $betInfo['sort'] == "CANCEL" ? "CANCELED" : "OK";
        send_response('OK', 'Status retrieved', [
            'trans_id' => $oData->data->trans_id,
            'trans_status' => $status
        ]);
        break;

    default:
        send_response('PARAMETERS_INVALID', 'Unsupported command');
        break;
}

logMessage("Processed Command: " . $command . " with response: " . json_encode($response ?? []));
?>

