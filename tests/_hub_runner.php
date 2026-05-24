<?php
/**
 * 测试 hub:不依赖 Workerman 的纯 PHP stream_select loop.
 *
 * Windows 下 Workerman 用 proc_open fork worker,被 test_ipc.php 的 proc_open
 * 嵌套调用时 stderr 无法捕获,因此这里手写 accept/Frame 解码循环.
 *
 * 协议常量从 WorkerManSrv 复用,行为对齐 ipcWorker().
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../WorkerManSrv.php';

if (!isset($argv[1])) {
    fwrite(STDERR, "usage: _hub_runner.php <listenUri>\n");
    exit(2);
}

$listenUri = $argv[1];
$srv = stream_socket_server($listenUri, $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "listen fail: $errstr ($errno)\n");
    exit(2);
}
stream_set_blocking($srv, false);
$boundName = stream_socket_get_name($srv, false);
fwrite(STDERR, "[hub] listen=$boundName\n");
fwrite(STDERR, "[hub] ready\n");

// hub 状态
$channels  = []; // binWorkerUniqid(6B) => stream
$bindings  = []; // key => [binFd(8B) => 1]
$fdKeys    = []; // binFd => [key => 1]
$uniqidFds = []; // binWorkerUniqid => [binFd => 1]

// 每个客户端连接的状态
/** @var array<int, array{stream: resource, buf: string, uniqid: ?string}> */
$clients = [];
$nextId = 0;

$dropFd = function (string $fdBin, ?string $uniqidBin) use (&$bindings, &$fdKeys, &$uniqidFds): void {
    if (isset($fdKeys[$fdBin])) {
        foreach (array_keys($fdKeys[$fdBin]) as $key) {
            unset($bindings[$key][$fdBin]);
            if (empty($bindings[$key])) unset($bindings[$key]);
        }
        unset($fdKeys[$fdBin]);
    }
    if ($uniqidBin !== null && isset($uniqidFds[$uniqidBin][$fdBin])) {
        unset($uniqidFds[$uniqidBin][$fdBin]);
        if (empty($uniqidFds[$uniqidBin])) unset($uniqidFds[$uniqidBin]);
    }
};

$frameSend = function ($stream, string $payload) {
    $total = pack('N', strlen($payload) + 4) . $payload;
    return @fwrite($stream, $total);
};

$handleFrame = function ($cid, string $data) use (
    &$clients, &$channels, &$bindings, &$fdKeys, &$uniqidFds, $dropFd, $frameSend
) {
    if ($data === '' || !isset($data[0])) return;
    $cmd = ord($data[0]);
    $len = strlen($data);
    $conn = $clients[$cid]['stream'];
    switch ($cmd) {
        case WorkerManSrv::CH_REG:
            if ($len !== 7) return;
            $uniqidBin = substr($data, 1, 6);
            if (isset($channels[$uniqidBin]) && $channels[$uniqidBin] !== $conn) {
                $oldStream = $channels[$uniqidBin];
                foreach ($clients as $oid => &$oc) {
                    if ($oc['stream'] === $oldStream) {
                        $oc['uniqid'] = null; //防止 onClose 误删新注册
                        @fclose($oldStream);
                        unset($clients[$oid]);
                        break;
                    }
                }
                unset($oc);
            }
            $channels[$uniqidBin] = $conn;
            $clients[$cid]['uniqid'] = $uniqidBin;
            return;
        case WorkerManSrv::CH_TO:
            if ($len < 9) return;
            $fdBin = substr($data, 1, 8);
            $raw   = substr($data, 9);
            $uniqidBin = WorkerManSrv::fdToUniqid($fdBin);
            if (!isset($channels[$uniqidBin])) return;
            $frameSend($channels[$uniqidBin], chr(WorkerManSrv::CH_DELIVER) . substr($fdBin, 4, 4) . $raw);
            return;
        case WorkerManSrv::CH_CLOSE:
            if ($len !== 9) return;
            $fdBin = substr($data, 1, 8);
            $uniqidBin = WorkerManSrv::fdToUniqid($fdBin);
            if (!isset($channels[$uniqidBin])) return;
            $frameSend($channels[$uniqidBin], chr(WorkerManSrv::CH_DO_CLOSE) . substr($fdBin, 4, 4));
            $dropFd($fdBin, $uniqidBin);
            return;
        case WorkerManSrv::CH_ALL:
            $out = chr(WorkerManSrv::CH_BROAD) . substr($data, 1);
            foreach ($channels as $c) {
                $frameSend($c, $out);
            }
            return;
        case WorkerManSrv::CH_BIND:
            if ($len < 11) return;
            $keyLen = unpack('n', substr($data, 1, 2))[1];
            if ($len !== 3 + $keyLen + 8) return;
            $key   = substr($data, 3, $keyLen);
            $fdBin = substr($data, 3 + $keyLen, 8);
            $uniqidBin = WorkerManSrv::fdToUniqid($fdBin);
            if (!isset($channels[$uniqidBin])) {
                return;
            }
            $bindings[$key][$fdBin] = 1;
            $fdKeys[$fdBin][$key] = 1;
            $uniqidFds[$uniqidBin][$fdBin] = 1;
            return;
        case WorkerManSrv::CH_UNBIND:
            if ($len < 11) return;
            $keyLen = unpack('n', substr($data, 1, 2))[1];
            if ($len !== 3 + $keyLen + 8) return;
            $key   = substr($data, 3, $keyLen);
            $fdBin = substr($data, 3 + $keyLen, 8);
            unset($bindings[$key][$fdBin]);
            if (empty($bindings[$key])) unset($bindings[$key]);
            unset($fdKeys[$fdBin][$key]);
            if (empty($fdKeys[$fdBin])) unset($fdKeys[$fdBin]);
            return;
        case WorkerManSrv::CH_UNBIND_KEY:
            if ($len < 3) return;
            $keyLen = unpack('n', substr($data, 1, 2))[1];
            if ($len !== 3 + $keyLen) return;
            $key = substr($data, 3, $keyLen);
            if (isset($bindings[$key])) {
                foreach (array_keys($bindings[$key]) as $fdBin) {
                    unset($fdKeys[$fdBin][$key]);
                    if (empty($fdKeys[$fdBin])) unset($fdKeys[$fdBin]);
                }
                unset($bindings[$key]);
            }
            return;
        case WorkerManSrv::CH_UNBIND_FD:
            if ($len !== 9) return;
            $fdBin = substr($data, 1, 8);
            $dropFd($fdBin, WorkerManSrv::fdToUniqid($fdBin));
            return;
        case WorkerManSrv::CH_SEND_KEY:
            if ($len < 3) return;
            $keyLen = unpack('n', substr($data, 1, 2))[1];
            if ($len < 3 + $keyLen) return;
            $key = substr($data, 3, $keyLen);
            $raw = substr($data, 3 + $keyLen);
            if (empty($bindings[$key])) {
                return;
            }
            foreach (array_keys($bindings[$key]) as $fdBin) {
                $uniqidBin = WorkerManSrv::fdToUniqid($fdBin);
                if (!isset($channels[$uniqidBin])) continue;
                $frameSend($channels[$uniqidBin], chr(WorkerManSrv::CH_DELIVER) . substr($fdBin, 4, 4) . $raw);
            }
            return;
    }
};

// 主循环:30s 后自动退出
$deadline = microtime(true) + 30.0;
while (microtime(true) < $deadline) {
    $reads = [$srv];
    foreach ($clients as $c) {
        $reads[] = $c['stream'];
    }
    $writes = $excepts = null;
    @stream_select($reads, $writes, $excepts, 0, 50000); // 50ms

    foreach ($reads as $r) {
        if ($r === $srv) {
            $cli = @stream_socket_accept($srv, 0);
            if (!$cli) continue;
            stream_set_blocking($cli, false);
            $cid = ++$nextId;
            $clients[$cid] = ['stream' => $cli, 'buf' => '', 'uniqid' => null];
            continue;
        }
        // 找到对应的 cid
        $cid = null;
        foreach ($clients as $k => $c) {
            if ($c['stream'] === $r) { $cid = $k; break; }
        }
        if ($cid === null) continue;

        $chunk = @fread($r, 65536);
        if ($chunk === '' || $chunk === false) {
            // 连接关闭
            $uniqidBin = $clients[$cid]['uniqid'];
            if ($uniqidBin !== null && isset($channels[$uniqidBin]) && $channels[$uniqidBin] === $r) {
                unset($channels[$uniqidBin]);
                if (isset($uniqidFds[$uniqidBin])) {
                    foreach (array_keys($uniqidFds[$uniqidBin]) as $fdBin) {
                        $dropFd($fdBin, null);
                    }
                    unset($uniqidFds[$uniqidBin]);
                }
            }
            @fclose($r);
            unset($clients[$cid]);
            continue;
        }
        $clients[$cid]['buf'] .= $chunk;
        // 拆 frame: 4B 大端 totalLen
        while (strlen($clients[$cid]['buf']) >= 4) {
            $totalLen = unpack('N', substr($clients[$cid]['buf'], 0, 4))[1];
            if (strlen($clients[$cid]['buf']) < $totalLen) break;
            $payload = substr($clients[$cid]['buf'], 4, $totalLen - 4);
            $clients[$cid]['buf'] = substr($clients[$cid]['buf'], $totalLen);
            $handleFrame($cid, $payload);
        }
    }
}

fwrite(STDERR, "[hub] timeout exit\n");
foreach ($clients as $c) @fclose($c['stream']);
@fclose($srv);
