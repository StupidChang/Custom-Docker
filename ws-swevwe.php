<?php
require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;

$ws_worker = new Worker("websocket://0.0.0.0:2346");
$ws_worker->count = 1;

$rooms            = []; // [ roomCode => [ connId => TcpConnection, ... ] ]
$roomOwners       = []; // [ roomCode => ownerConnId ]
$roomLastActive   = [];
$roomIdleTimeout  = 3600;
$connections      = [];
$usernames        = [];

// 房間清理機制
$ws_worker->onWorkerStart = function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
    Timer::add(60, function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
        $now = time();
        foreach ($roomLastActive as $room => $last) {
            if (empty($rooms[$room]) || ($now - $last) > $roomIdleTimeout) {
                unset($rooms[$room], $roomLastActive[$room]);
                echo "[Cleanup] Room {$room} removed\n";
            }
        }
    });
};

// 新連線
$ws_worker->onConnect = function(TcpConnection $conn) use (&$connections) {
    echo "Client connected: {$conn->id}\n";
    $connections[$conn->id] = $conn;
    $conn->onWebSocketConnect = function($http_header) {
        header('Access-Control-Allow-Origin: *');
    };
};

// 收到訊息
$ws_worker->onMessage = function(TcpConnection $conn, $raw) use (&$rooms, &$roomOwners, &$roomLastActive, &$usernames) {
    $msg = @json_decode($raw, true);
    $action = $msg['action'] ?? '';
    $room   = $msg['room'] ?? null;
    $username = $msg['username'] ?? ('匿名_' . substr($conn->id, -4));

    switch ($action) {
        case 'joinRoom':
            if (!$room) {
                $conn->send(json_encode(['type'=>'error', 'message'=>'Room code required']));
                return;
            }

            // 不存在房間時自動建立
            if (!isset($rooms[$room])) {
                $rooms[$room] = [];
                $roomOwners[$room] = $conn->id;
                echo "[AutoCreate] Room {$room} created\n";
            }

            $rooms[$room][$conn->id] = $conn;
            $conn->room = $room;
            $roomLastActive[$room] = time();
            $usernames[$conn->id] = $username;

            // 回應加入成功
            $conn->send(json_encode([
                'type'     => 'joinSuccess',
                'room'     => $room,
                'username' => $username,
                'isOwner'  => $roomOwners[$room] === $conn->id,
            ]));

            // 回傳目前房內所有使用者
            $current = [];
            foreach ($rooms[$room] as $c) {
                $current[] = $usernames[$c->id] ?? '匿名';
            }
            $conn->send(json_encode(['type'=>'currentUsers', 'users'=>$current]));

            // 通知他人
            foreach ($rooms[$room] as $other) {
                if ($other !== $conn) {
                    $other->send(json_encode([
                        'type'=>'userJoined',
                        'room'=>$room,
                        'username'=>$username,
                    ]));
                }
            }
            break;

        case 'leaveRoom':
            $room = $conn->room ?? null;
            if ($room && isset($rooms[$room][$conn->id])) {
                unset($rooms[$room][$conn->id], $usernames[$conn->id]);
                unset($conn->room);
                $roomLastActive[$room] = time();

                $conn->send(json_encode(['type'=>'leaveSuccess', 'room'=>$room]));

                foreach ($rooms[$room] as $other) {
                    $other->send(json_encode([
                        'type'=>'userLeft',
                        'room'=>$room,
                        'username'=>$username,
                    ]));
                }

                if (empty($rooms[$room]) || $roomOwners[$room] === $conn->id) {
                    unset($rooms[$room], $roomOwners[$room], $roomLastActive[$room]);
                    echo "[Destroy] Room {$room} due to owner leave or empty\n";
                }
            }
            break;

        case 'syncStart':
            $room = $conn->room ?? null;
            if ($room && isset($rooms[$room])) {
                $roomLastActive[$room] = time();
                $startTime = (int)((microtime(true) * 1000) + 5000);

                foreach ($rooms[$room] as $other) {
                    $other->send(json_encode([
                        'type' => 'syncStart',
                        'conn_id' => $conn->id,
                        'sync_start_time' => $startTime,
                    ]));
                }
            }
            break;

        case 'syncStop':
            $room = $conn->room ?? null;
            if ($room && isset($rooms[$room])) {
                $roomLastActive[$room] = time();
                foreach ($rooms[$room] as $other) {
                    $other->send(json_encode([
                        'type' => 'syncStop',
                        'conn_id' => $conn->id,
                    ]));
                }
            }
            break;

        case 'syncBPM':
            $room = $conn->room ?? null;
            $bpm = $msg['bpm'] ?? null;
            if ($room && isset($rooms[$room]) && is_numeric($bpm)) {
                $roomLastActive[$room] = time();
                foreach ($rooms[$room] as $other) {
                    if ($other !== $conn) {
                        $other->send(json_encode([
                            'type' => 'syncBPM',
                            'conn_id' => $conn->id,
                            'bpm' => (int)$bpm,
                        ]));
                    }
                }
            }
            break;

        default:
            $conn->send(json_encode(['type'=>'error', 'message'=>'Invalid action']));
    }
};

// 連線關閉
$ws_worker->onClose = function(TcpConnection $conn) use (&$rooms, &$roomOwners, &$roomLastActive, &$usernames) {
    $room = $conn->room ?? null;
    $name = $usernames[$conn->id] ?? null;
    if ($room && isset($rooms[$room][$conn->id])) {
        unset($rooms[$room][$conn->id], $usernames[$conn->id]);
        $roomLastActive[$room] = time();

        foreach ($rooms[$room] as $other) {
            $other->send(json_encode([
                'type'=>'userLeft',
                'conn_id'=>$conn->id,
                'username'=>$name
            ]));
        }

        if ($roomOwners[$room] === $conn->id || empty($rooms[$room])) {
            unset($rooms[$room], $roomOwners[$room], $roomLastActive[$room]);
            echo "[Close] Room {$room} cleared (disconnect or empty)\n";
        }
    }
    echo "Client disconnected: {$conn->id}\n";
};

Worker::runAll();
