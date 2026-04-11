#!/usr/bin/env php
<?php
/**
 * IPC hub 基准测试.
 *
 * 独立脚本,不依赖 WorkerManSrv,只依赖 vendor/workerman/workerman.
 * 通过启动 hub + N 对 sender/receiver 虚拟 worker,
 * 测量 hub 单进程在不同消息大小/并发下的吞吐和延迟.
 *
 * 使用:
 *   php bench_ipc.php --pairs=4 --duration=10 --size=64 --batch=200
 *
 * 参数:
 *   --pairs     发送/接收虚拟 worker 对数 (默认 4)
 *   --duration  测试时长秒数 (默认 10)
 *   --size      单条消息 payload 大小字节 (默认 64,最小 8 用于存时间戳)
 *   --batch     每次触发发送的消息条数 (默认 200)
 *   --interval  timer 模式下 tick 间隔毫秒 (默认 1,仅 mode=timer)
 *   --mode      发送模式:
 *               - drain  由 onConnect + onBufferDrain 驱动,带天然背压;
 *                        适合大消息测 hub 稳态吞吐 (默认)
 *               - timer  定时推送,不看 hub 处理速度,适合压测 + 小消息
 *   --sock      hub unix sock 文件 (默认 /tmp/bench_ipc.sock)
 */

declare(strict_types=1);

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;

require __DIR__ . '/vendor/autoload.php';

// ===== 协议常量 (与 WorkerManSrv 对齐) =====
const CH_REG     = 0x01;
const CH_TO      = 0x02;
const CH_ALL     = 0x03;
const CH_DELIVER = 0x10;
const CH_BROAD   = 0x11;

// ===== 参数解析 =====
$opts = getopt('', ['pairs::', 'duration::', 'size::', 'batch::', 'interval::', 'mode::', 'sock::']);
$pairs    = max(1, (int)($opts['pairs']    ?? 4));
$duration = max(1, (int)($opts['duration'] ?? 10));
$size     = max(16, (int)($opts['size']    ?? 64));
$batch    = max(1, (int)($opts['batch']    ?? 200));
$interval = max(1, (int)($opts['interval'] ?? 1));
$mode     = (string)($opts['mode'] ?? 'drain');
$sockFile = (string)($opts['sock'] ?? '/tmp/bench_ipc.sock');
if (!in_array($mode, ['drain', 'timer'], true)) {
    fwrite(STDERR, "invalid --mode: $mode (drain|timer)\n");
    exit(1);
}

@unlink($sockFile);

fprintf(STDERR, "[bench] pairs=%d duration=%ds size=%dB batch=%d mode=%s%s sock=%s\n",
    $pairs, $duration, $size, $batch, $mode,
    $mode === 'timer' ? " interval={$interval}ms" : '',
    $sockFile);

// ===== HUB =====
$hub = new Worker('unix://' . $sockFile);
$hub->count = 1;
$hub->name = 'bench_hub';
$hub->reloadable = false;
$hub->protocol = '\Workerman\Protocols\Frame';
$hub->channels = []; // binWorkerUniqid(6B) => connection

$hub->onWorkerStart = function (Worker $w) use ($duration) {
    //每秒打印 hub 自身 CPU/内存
    $lastRu   = getrusage();
    $lastWall = microtime(true);
    Timer::add(1, function () use ($w, &$lastRu, &$lastWall) {
        $ru   = getrusage();
        $wall = microtime(true);
        $dt   = max(1e-6, $wall - $lastWall);
        $du   = ($ru['ru_utime.tv_sec']  - $lastRu['ru_utime.tv_sec'])
              + ($ru['ru_utime.tv_usec'] - $lastRu['ru_utime.tv_usec']) / 1e6;
        $ds   = ($ru['ru_stime.tv_sec']  - $lastRu['ru_stime.tv_sec'])
              + ($ru['ru_stime.tv_usec'] - $lastRu['ru_stime.tv_usec']) / 1e6;
        $ucpu = $du / $dt * 100;
        $scpu = $ds / $dt * 100;
        $rss  = (int)(memory_get_usage(true) / 1024);
        fprintf(STDERR, "[hub] channels=%d rssKB=%d cpu=%.1f%% (u=%.1f%% s=%.1f%%)\n",
            count($w->channels), $rss, $ucpu + $scpu, $ucpu, $scpu);
        $lastRu   = $ru;
        $lastWall = $wall;
    });
    //到时间后终止所有 worker
    Timer::add($duration + 2, function () {
        Worker::stopAll();
    }, [], false);
};

$hub->onMessage = function (TcpConnection $conn, string $data) use ($hub) {
    if ($data === '' || !isset($data[0])) {
        return;
    }
    $cmd = ord($data[0]);
    if ($cmd === CH_TO) {
        //[1][8B fdBin][raw]
        if (strlen($data) < 9) {
            return;
        }
        $fdBin = substr($data, 1, 8);
        $raw   = substr($data, 9);
        // fdBin 布局 [wid:n=2][port:n=2][fdid:N=4] → uniqid [port:n=2][wid:N=4]
        $uniqidBin = substr($fdBin, 2, 2) . "\x00\x00" . substr($fdBin, 0, 2);
        if (isset($hub->channels[$uniqidBin])) {
            $hub->channels[$uniqidBin]->send(
                chr(CH_DELIVER) . substr($fdBin, 4, 4) . $raw
            );
        }
        return;
    }
    if ($cmd === CH_REG) {
        //[1][6B uniqidBin]
        if (strlen($data) !== 7) {
            return;
        }
        $uniqidBin = substr($data, 1, 6);
        $hub->channels[$uniqidBin] = $conn;
        $conn->chainUniqid = $uniqidBin;
        return;
    }
    if ($cmd === CH_ALL) {
        $out = chr(CH_BROAD) . substr($data, 1);
        foreach ($hub->channels as $c) {
            $c->send($out);
        }
    }
};

$hub->onClose = function (TcpConnection $conn) use ($hub) {
    if (!empty($conn->chainUniqid)) {
        if (isset($hub->channels[$conn->chainUniqid]) && $hub->channels[$conn->chainUniqid] === $conn) {
            unset($hub->channels[$conn->chainUniqid]);
        }
    }
};

// ===== 虚拟 worker 对 =====
// sender: 用 port 10000+i, worker_id 0 注册; 向 target (port=20000+i, wid=0, fdid=1) 猛发
// receiver: 用 port 20000+i, worker_id 0 注册; 接收并统计

for ($i = 0; $i < $pairs; $i++) {
    $sPort = 10000 + $i;
    $rPort = 20000 + $i;

    // --- sender ---
    $sender = new Worker();
    $sender->count = 1;
    $sender->name  = "sender_$i";
    $sender->reloadable = false;
    $sender->onWorkerStart = function () use ($sockFile, $sPort, $rPort, $size, $batch, $interval, $mode, $duration, $i) {
        $myUniqidBin  = pack('nN', $sPort, 0);
        $targetFdBin  = pack('nnN', 0, $rPort, 1); // wid=0, port=rPort, fdid=1
        $payload      = str_repeat('x', $size - 8);
        $sent         = 0;
        $startTs      = 0.0;
        $stopped      = false;
        $paused       = false;

        $conn = new AsyncTcpConnection('unix://' . $sockFile);
        $conn->protocol = '\Workerman\Protocols\Frame';
        $conn->maxSendBufferSize = 32 * 1024 * 1024;

        // onBufferDrain 驱动的 pump: 缓冲区空/刚排空时,批量灌入 $batch 条后yield
        // 相比 Timer 节拍, 不受 libevent HZ 限制, 可打满 hub 处理能力
        $pump = function () use (&$conn, &$sent, &$paused, &$stopped, $batch, $targetFdBin, $payload) {
            if ($stopped || $paused) {
                return;
            }
            for ($k = 0; $k < $batch; $k++) {
                $tsUs = (int)(microtime(true) * 1e6);
                $ts   = pack('J', $tsUs);
                $conn->send(chr(CH_TO) . $targetFdBin . $ts . $payload);
                $sent++;
                if ($paused) {
                    break;
                }
            }
        };

        $conn->onConnect = function (AsyncTcpConnection $c) use ($myUniqidBin, &$startTs, $pump, $mode) {
            $c->send(chr(CH_REG) . $myUniqidBin);
            $startTs = microtime(true);
            if ($mode === 'drain') {
                $pump();
            }
        };
        $conn->onBufferFull = function () use (&$paused) {
            $paused = true;
        };
        $conn->onBufferDrain = function () use (&$paused, $pump, $mode) {
            $paused = false;
            if ($mode === 'drain') {
                $pump();
            }
        };

        $conn->connect();

        if ($mode === 'timer') {
            //固定节拍注入,不依赖 drain; 适合小消息及主动过载测试
            Timer::add($interval / 1000, $pump);
        }

        // duration 秒后停发送
        Timer::add($duration, function () use (&$sent, $i, &$startTs, &$stopped) {
            $stopped = true;
            $elapsed = microtime(true) - $startTs;
            $rate = $elapsed > 0 ? $sent / $elapsed : 0;
            fprintf(STDERR, "[sender_$i] total=%d elapsed=%.2fs rate=%s msg/s\n",
                $sent, $elapsed, number_format($rate, 0));
        }, [], false);
    };

    // --- receiver ---
    $recv = new Worker();
    $recv->count = 1;
    $recv->name  = "recv_$i";
    $recv->reloadable = false;
    $recv->onWorkerStart = function () use ($sockFile, $rPort, $i, $duration) {
        $myUniqidBin = pack('nN', $rPort, 0);
        $count   = 0;
        $latSum  = 0;
        $latMax  = 0;
        $lats    = []; // 最近一秒采样,用于 p50/p99

        $conn = new AsyncTcpConnection('unix://' . $sockFile);
        $conn->protocol = '\Workerman\Protocols\Frame';
        $conn->onConnect = function (AsyncTcpConnection $c) use ($myUniqidBin) {
            $c->send(chr(CH_REG) . $myUniqidBin);
        };
        $conn->onMessage = function (AsyncTcpConnection $c, string $data) use (&$count, &$latSum, &$latMax, &$lats) {
            if (!isset($data[0]) || ord($data[0]) !== CH_DELIVER) {
                return;
            }
            //[0x10][4B fdId][8B tsUs][payload]
            if (strlen($data) < 13) {
                return;
            }
            $ts  = unpack('J', substr($data, 5, 8))[1];
            $lat = (int)(microtime(true) * 1e6) - $ts;
            $count++;
            $latSum += $lat;
            if ($lat > $latMax) {
                $latMax = $lat;
            }
            // 每秒最多采样 10000 条用于分位数
            if (count($lats) < 10000) {
                $lats[] = $lat;
            }
        };
        $conn->connect();

        Timer::add(1, function () use (&$count, &$latSum, &$latMax, &$lats, $i) {
            if ($count === 0) {
                return;
            }
            sort($lats);
            $n = count($lats);
            $p50 = $lats[(int)($n * 0.50)] ?? 0;
            $p99 = $lats[(int)($n * 0.99)] ?? 0;
            $avg = (int)($latSum / $count);
            fprintf(STDERR, "[recv_$i] rate=%s/s avgUs=%d p50Us=%d p99Us=%d maxUs=%d\n",
                number_format($count, 0), $avg, $p50, $p99, $latMax);
            $count  = 0;
            $latSum = 0;
            $latMax = 0;
            $lats   = [];
        });
    };
}

Worker::runAll();
