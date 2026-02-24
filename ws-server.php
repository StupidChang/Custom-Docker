<?php
echo "[Init] ws-server.php is starting...\n";

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
$connections      = []; // 紀錄連接
$usernames        = [];

// 房間清理機制
$ws_worker->onWorkerStart = function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
    Timer::add(60, function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
        $now = time();
        foreach ($roomLastActive as $room => $last) {
            if (empty($rooms[$room]) || ($now - $last) > $roomIdleTimeout) {
                unset($rooms[$room], $roomLastActive[$room]);
                echo "[清理] 房間 {$room} 已被清除\n";
            }
        }
    });
};

// 新連線
$ws_worker->onConnect = function(TcpConnection $conn) use (&$connections) {
    echo "新客戶端連接: {$conn->id}\n";
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
        case 'createRoom':
            if (!$room) {
                $conn->send(json_encode(['type' => 'error', 'message' => '房間代碼不可為空']));
                return;
            }

            if (isset($rooms[$room])) {
                $conn->send(json_encode(['type' => 'createFail', 'message' => '房間已存在']));
                return;
            }

            // 建立房間
            $rooms[$room] = [];
            $roomOwners[$room] = $conn->id;
            $roomLastActive[$room] = time();
            $rooms[$room][$conn->id] = $conn;
            $conn->room = $room;
            $usernames[$conn->id] = $username;

            $conn->send(json_encode([
                'type' => 'createSuccess',
                'room' => $room,
                'username' => $username,
                'isOwner' => true,
            ]));

            // 回傳目前房內所有使用者（只有自己）
            $conn->send(json_encode(['type' => 'currentUsers', 'users' => [$username]]));

            echo "[Create] Room {$room} created by {$conn->id}\n";
            break;

        case 'joinRoom':
            if (!$room) {
                $conn->send(json_encode(['type'=>'error', 'message'=>'Room code required']));
                return;
            }

            // 不存在房間時自動建立
            if (!isset($rooms[$room])) {
                $conn->send(json_encode(['type' => 'error', 'message' => '房間不存在']));
                return;
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
                $conn->hasLeft = true; // ⬅️ 標記已手動離開

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
                    foreach ($rooms[$room] as $other) {
                        $other->send(json_encode(['type' => 'info', 'message' => '房主已離開或房間已空，房間解散']));
                    }
                }
            }
            break;

        case 'deleteRoom':
            $room = $msg['room'] ?? null;
            if ($room && $roomOwners[$room] === $conn->id) {
                foreach ($rooms[$room] as $other) {
                    $other->send(json_encode(['type'=>'info', 'message'=>'房間已被刪除']));
                    $other->close();
                }
                unset($rooms[$room], $roomOwners[$room], $roomLastActive[$room]);
                $conn->send(json_encode(['type'=>'deleteSuccess']));
            }
            break;

        case 'syncStart':
            $room = $conn->room ?? null;
            if ($room && isset($rooms[$room])) {
                $roomLastActive[$room] = time();
                $startTime = (int)(hrtime(true) / 1000000 + 5000); // ← 比 microtime 更穩定

                foreach ($rooms[$room] as $other) {
                    // if ($other !== $conn) {
                        $other->send(json_encode([
                            'type' => 'syncStart',
                            'conn_id' => $conn->id,
                            'sync_start_time' => $startTime,
                        ]));
                    // }
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
    if (!empty($conn->hasLeft)) {
        // 🛡️ 已處理過 leaveRoom，略過
        return;
    }

    $room = $conn->room ?? null;
    $name = $usernames[$conn->id] ?? null;
    if ($room && isset($rooms[$room][$conn->id])) {
        unset($rooms[$room][$conn->id], $usernames[$conn->id]);
        unset($usernames[$conn->id]);
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

echo "[Run] Calling Worker::runAll()\n";
Worker::runAll();
