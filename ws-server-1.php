<?php
require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Redis\Client as RedisClient;

$ws_worker = new Worker("websocket://0.0.0.0:2346");
$ws_worker->count = 1;

// 2. 全域資料結構
//    $rooms:    [ roomCode => [ connId => TcpConnection, ... ], ... ]
//    $roomLastActive: [ roomCode => timestamp, ... ]
$rooms            = [];
$roomOwners       = [];
$roomLastActive   = [];
$roomIdleTimeout  = 3600; // 房間閒置超過 3600 秒(1h)自動刪除
$connections = [];
$usernames = [];   // [ connId => username ]

// 3. Worker 啟動時設置定時任務：檢查並清理閒置或空房間
$ws_worker->onWorkerStart = function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
    // 每 60 秒跑一次
    Timer::add(60, function() use (&$rooms, &$roomLastActive, $roomIdleTimeout) {
        $now = time();
        foreach ($roomLastActive as $room => $last) {
            $connCount = isset($rooms[$room]) ? count($rooms[$room]) : 0;
            // 空房或閒置超時
            if ($connCount === 0 || ($now - $last) > $roomIdleTimeout) {
                unset($rooms[$room], $roomLastActive[$room]);
                echo "[Cleanup] Room {$room} removed (idle or empty)\n";
            }
        }
    });
};


// 4. 有新的 TCP 連線到來
$ws_worker->onConnect = function (TcpConnection $connection) use (&$connections) {
    echo "Client connected: {$conn->id}\n";
    // 當 WebSocket 握手時呼叫
    $connection->onWebSocketConnect = function($http_header) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Allow-Headers: x-requested-with,content-type');
    };
    $connections[$connection->id] = $connection;
};


// 5. 收到前端訊息
$ws_worker->onMessage = function(TcpConnection $conn, $raw) use (&$rooms, &$roomLastActive) {
    $msg    = @json_decode($raw, true);
    $action = $msg['action'] ?? '';
    $room   = $msg['room'] ?? null;
    $username = $msg['username'] ?? null;

    switch ($action) {
        // 5.1 建立房間
        case 'createRoom':
            // 產生不重複 8 位房間碼
            do {
                $code = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            } while (isset($rooms[$code]));

            $rooms[$code]          = [];
            $roomOwners[$code]     = $conn->id;       // 設為房主
            $roomLastActive[$code] = time();
            // 同時把自己加入
            $rooms[$code][$conn->id] = $conn;
            $conn->room = $code;

            //在全域對應表裡記下名字
            $usernames[$conn->id] = $username;

            // 回傳給客戶端
            $conn->send(json_encode([
                'type'      => 'roomCreated',
                'room_code' => $code,
                'isOwner'   => true,
                'username'  => $username,
            ]));
            break;

        // 5.2 加入房間
        case 'joinRoom':
            if ($room && isset($rooms[$room])) {
                $rooms[$room][$conn->id] = $conn;
                $conn->room = $room;
                $roomLastActive[$room] = time();

                // 2) 在全域對應表裡記下名字
                $usernames[$conn->id] = $username;

                // 成功回應
                $conn->send(json_encode([
                    'type' => 'joinSuccess',
                    'room' => $room,
                    'username' => $username,
                ]));

                // 4) 先把「現在所有人」一次告訴這位新使用者
                $current = [];
                foreach ($rooms[$room] as $c) {
                    $current[] = $usernames[$c->id];
                }
                $conn->send(json_encode([
                    'type'  => 'currentUsers',
                    'users' => $current,
                ]));

                // 通知其他人
                foreach ($rooms[$room] as $other) {
                    if ($other !== $conn) {
                        $other->send(json_encode([
                            'type'    => 'userJoined',
                            'room' => $room,
                            'username' => $username,
                        ]));
                    }
                }
            } else {
                $conn->send(json_encode([
                    'type'    => 'error',
                    'message' => 'Room not found'
                ]));
            }
            break;

        // 5.3 離開房間（可由前端主動觸發）
        case 'leaveRoom':
            $currentRoom = $conn->room ?? null;
            if ($currentRoom && isset($rooms[$currentRoom][$conn->id])) {
                unset($rooms[$currentRoom][$conn->id]);
                unset($usernames[$conn->id]);      // 也別忘了從對應表裡移除
                unset($conn->room);
                $roomLastActive[$currentRoom] = time();

                // 告知自己離開成功
                $conn->send(json_encode([
                    'type' => 'leaveSuccess',
                    'room' => $currentRoom
                ]));

                // 如果房間內沒人了，則刪除房間
                // 通知其他人
                foreach ($rooms[$currentRoom] as $other) {
                    $other->send(json_encode([
                        'type'    => 'userLeft',
                        'room' => $currentRoom,
                        'username' => $username,
                    ]));
                }

                // 若離開後房間已空，或房主離開，自動清理
                if (empty($rooms[$currentRoom]) || $roomOwners[$currentRoom] === $conn->id) {
                    unset($rooms[$currentRoom], $roomOwners[$currentRoom], $roomLastActive[$currentRoom]);
                    echo "[Info] Room {$currentRoom} destroyed (owner left or empty)\n";
                }

            }
            break;

        // 5.4 房間內廣播任意訊息
        case 'broadcast':
            $currentRoom = $conn->room ?? null;
            $payload     = $msg['data'] ?? [];
            if ($currentRoom && isset($rooms[$currentRoom])) {
                $roomLastActive[$currentRoom] = time();
                foreach ($rooms[$currentRoom] as $other) {
                    $other->send(json_encode(array_merge(['type'=>'broadcast'], $payload)));
                }
            }
            break;

        // 5.5 房間內廣播音樂開始或暫停
        case 'syncStart':
            $currentRoom = $conn->room ?? null;
            if ($currentRoom && isset($rooms[$currentRoom])) {
                $roomLastActive[$currentRoom] = time();

                // 1. 先計算未來的「同步開始毫秒時間戳」
                //    microtime(true) 回傳 float(秒)，*1000 轉毫秒，再 + 5000毫秒(5秒)
                $startTimestamp = (int)((microtime(true) * 1000) + 5000);

                foreach ($rooms[$currentRoom] as $other) {
                    $other->send(json_encode([
                        'type' => 'syncStart',
                        'conn_id' => $conn->id,
                        'sync_start_time' => $startTimestamp, // 延遲 5 秒開始
                    ]));
                }

            }
            break;

        case 'syncStop':
            $currentRoom = $conn->room ?? null;
            if ($currentRoom && isset($rooms[$currentRoom])) {
                $roomLastActive[$currentRoom] = time();
                foreach ($rooms[$currentRoom] as $other) {
                    $other->send(json_encode([
                        'type' => 'syncStop',
                        'conn_id' => $conn->id,
                    ]));
                }
            }
            break;

        case 'deleteRoom':
            $currentRoom = $conn->room ?? null;
            if ($currentRoom && isset($rooms[$currentRoom][$conn->id])) {
                unset($rooms[$currentRoom], $roomOwners[$currentRoom], $roomLastActive[$currentRoom]);
                foreach ($rooms[$currentRoom] as $other) {
                    $other->send(json_encode([
                        'type' => 'roomDeleted',
                        'room' => $currentRoom,
                    ]));
                }
            }
            break;

        default:
            // 未知 action，可回錯誤或忽略
            $conn->send(json_encode([
                'type'    => 'error',
                'message' => 'Invalid action'
            ]));
    }
};

// 6. 連線關閉：自動從房間列表移除，並通知其他人
$ws_worker->onClose = function(TcpConnection $conn) use (&$rooms, &$roomLastActive) {
    $room = $conn->room ?? null;
    $name = $usernames[$conn->id] ?? null;
    if ($room && isset($rooms[$room][$conn->id], $usernames[$conn->id])) {
        unset($rooms[$room][$conn->id]);
        $roomLastActive[$room] = time();
        foreach ($rooms[$room] as $other) {
            $other->send(json_encode([
                'type'    => 'userLeft',
                'conn_id' => $conn->id,
                'username' => $name,
            ]));
        }

        // 若房主離線或房間空了，自動清理
        if ($roomOwners[$room] === $conn->id || empty($rooms[$room])) {
            unset($rooms[$room], $roomOwners[$room], $roomLastActive[$room]);
            echo "[Info] Room {$room} destroyed (owner disconnected or empty)\n";
        }
    }
    echo "Client disconnected: {$conn->id}\n";
};

// 7. 啟動 Worker
// 少了這一行，會導致整個 server 不會常駐
Worker::runAll();