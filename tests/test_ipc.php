<?php
/**
 * IPC 端到端测试.
 *
 * 主进程用阻塞 socket 自己实现 Workerman Frame 协议作为虚拟 worker 客户端,
 * 子进程跑独立 hub (与 WorkerManSrv::ipcWorker 协议对齐).
 *
 * 用法: php tests/test_ipc.php
 * 退出码: 0 全部通过 / 1 有失败.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// ===== Workerman Frame 协议封包/解包(阻塞模式) =====

/** 发送一帧.payload 业务数据,Frame 头是 4B 大端总长(含头) */
function frameSend($sock, string $payload): void
{
    $total = pack('N', strlen($payload) + 4) . $payload;
    $n = fwrite($sock, $total);
    if ($n === false || $n !== strlen($total)) {
        throw new RuntimeException('frameSend short write');
    }
}

/** 读一帧;超时返回 null;EOF/错误抛异常 */
function frameRecv($sock, float $timeoutSec = 1.0): ?string
{
    stream_set_timeout($sock, (int)$timeoutSec, (int)(($timeoutSec - (int)$timeoutSec) * 1e6));
    $head = '';
    while (strlen($head) < 4) {
        $chunk = fread($sock, 4 - strlen($head));
        if ($chunk === '' || $chunk === false) {
            $info = stream_get_meta_data($sock);
            if (!empty($info['timed_out'])) return null;
            throw new RuntimeException('frameRecv eof at head');
        }
        $head .= $chunk;
    }
    $total = unpack('N', $head)[1];
    $bodyLen = $total - 4;
    $body = '';
    while (strlen($body) < $bodyLen) {
        $chunk = fread($sock, $bodyLen - strlen($body));
        if ($chunk === '' || $chunk === false) {
            throw new RuntimeException('frameRecv eof at body');
        }
        $body .= $chunk;
    }
    return $body;
}

/** 排空 socket 中已到达的所有帧,直到超时 */
function frameDrain($sock, float $timeoutSec = 0.2): array
{
    $msgs = [];
    while (true) {
        $msg = frameRecv($sock, $timeoutSec);
        if ($msg === null) break;
        $msgs[] = $msg;
    }
    return $msgs;
}

/** 用 worker 身份(port+wid)连 hub 并 REG;返回前自带 syncHub 让 hub 完成注册 */
function makeWorker(string $hubHost, int $hubPort, int $port, int $wid)
{
    $client = stream_socket_client("tcp://{$hubHost}:{$hubPort}", $errno, $errstr, 2);
    if (!$client) {
        throw new RuntimeException("connect hub fail: $errstr ($errno)");
    }
    stream_set_blocking($client, true);
    $uniqidBin = pack('nN', $port, $wid);
    frameSend($client, chr(WorkerManSrv::CH_REG) . $uniqidBin);
    syncHub();
    return $client;
}

/**
 * 给 hub 时间处理已发送的命令.
 * 跨连接消息无 TCP 序保证: A 先 BIND 再让 B SEND_KEY 时,如果不让 hub 把 BIND 消化掉,
 * SEND_KEY 可能先被处理.30ms 在 localhost 足够覆盖单次 select 间隔.
 */
function syncHub(): void
{
    usleep(120000); // ≥ 2x hub select 周期(50ms),保证 hub 至少跑完一轮事件处理
}

// ===== hub 子进程管理 =====

// 让 hub 自己绑 0 端口,从 stderr 解析实际地址 (避免 Windows SO_EXCLUSIVEADDRUSE 抢端口)
$proc = proc_open(
    [PHP_BINARY, __DIR__ . '/_hub_runner.php', 'tcp://127.0.0.1:0'],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    __DIR__ . '/..'
);
if (!is_resource($proc)) {
    fwrite(STDERR, "failed to start hub\n");
    exit(2);
}
register_shutdown_function(function () use ($proc, $pipes) {
    $err = @stream_get_contents($pipes[2]) ?: '';
    foreach ($pipes as $p) { @fclose($p); }
    @proc_terminate($proc, 9);
    @proc_close($proc);
    if (TestStat::$fail > 0) {
        fwrite(STDOUT, "\n--- hub stderr (only on failure) ---\n$err\n");
    }
});
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// 等 hub stderr 出现 [hub] listen=ADDR
$ready = false;
$stderrBuf = '';
$hubHost = '127.0.0.1';
$hubPort = 0;
for ($i = 0; $i < 100; $i++) {
    usleep(100000);
    $stderrBuf .= @stream_get_contents($pipes[2]) ?: '';
    if (preg_match('/\[hub\] listen=([0-9.]+):(\d+)/', $stderrBuf, $m)) {
        $hubHost = $m[1];
        $hubPort = (int)$m[2];
        $ready = true;
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, "hub not ready in 10s\n");
    fwrite(STDERR, "stderr:\n$stderrBuf\n");
    exit(2);
}

// ===== 场景 1: bind + sendByKey 单点命中 =====
section('bind + sendByKey single hit');
{
    $w1 = makeWorker($hubHost, $hubPort, 9001, 0);
    $fdHex = WorkerManSrv::clientToUniqId(0, 9001, 100);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 6) . 'user_1' . hex2bin($fdHex));
    syncHub();

    // 另一个 worker 发 sendByKey
    $w2 = makeWorker($hubHost, $hubPort, 9002, 0);
    frameSend($w2, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 6) . 'user_1' . 'hello');

    $msgs = frameDrain($w1, 0.3);
    eq(count($msgs), 1, 'w1 received exactly 1 message');
    if (count($msgs) === 1) {
        $m = $msgs[0];
        eq(ord($m[0]), WorkerManSrv::CH_DELIVER, 'cmd is CH_DELIVER');
        $fdId = unpack('N', substr($m, 1, 4))[1];
        eq($fdId, 100, 'fdId=100');
        eq(substr($m, 5), 'hello', 'payload=hello');
    }
    eq(count(frameDrain($w2, 0.1)), 0, 'w2 received nothing');
    fclose($w1);
    fclose($w2);
}

// ===== 场景 2: 多端登录,sendByKey 群发到同 key 多 fd =====
section('sendByKey to multiple fds bound to same key');
{
    $w1 = makeWorker($hubHost, $hubPort, 9011, 0);
    $w2 = makeWorker($hubHost, $hubPort, 9012, 0);

    $fd1 = WorkerManSrv::clientToUniqId(0, 9011, 1);
    $fd2 = WorkerManSrv::clientToUniqId(0, 9012, 2);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'multi' . hex2bin($fd1));
    frameSend($w2, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'multi' . hex2bin($fd2));
    syncHub();

    $sender = makeWorker($hubHost, $hubPort, 9013, 0);
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'multi' . 'ping');

    $r1 = frameDrain($w1, 0.3);
    $r2 = frameDrain($w2, 0.3);
    eq(count($r1), 1, 'w1 got 1');
    eq(count($r2), 1, 'w2 got 1');
    if ($r1) eq(substr($r1[0], 5), 'ping', 'w1 payload');
    if ($r2) eq(substr($r2[0], 5), 'ping', 'w2 payload');
    fclose($w1);
    fclose($w2);
    fclose($sender);
}

// ===== 场景 3: unbind 精确移除 =====
section('unbind(key, fd) removes only that fd');
{
    $w1 = makeWorker($hubHost, $hubPort, 9021, 0);
    $w2 = makeWorker($hubHost, $hubPort, 9022, 0);
    $fd1 = WorkerManSrv::clientToUniqId(0, 9021, 1);
    $fd2 = WorkerManSrv::clientToUniqId(0, 9022, 2);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 4) . 'team' . hex2bin($fd1));
    frameSend($w2, chr(WorkerManSrv::CH_BIND) . pack('n', 4) . 'team' . hex2bin($fd2));
    // 解绑 w1 的 fd1
    frameSend($w1, chr(WorkerManSrv::CH_UNBIND) . pack('n', 4) . 'team' . hex2bin($fd1));
    syncHub();

    $sender = makeWorker($hubHost, $hubPort, 9023, 0);
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 4) . 'team' . 'hi');

    eq(count(frameDrain($w1, 0.3)), 0, 'w1 got nothing (unbound)');
    $r2 = frameDrain($w2, 0.3);
    eq(count($r2), 1, 'w2 still got 1');
    fclose($w1);
    fclose($w2);
    fclose($sender);
}

// ===== 场景 4: unbindFd 一次清掉某 fd 在所有 key 下的绑定 =====
section('unbindFd clears all keys');
{
    $w1 = makeWorker($hubHost, $hubPort, 9031, 0);
    $fd = WorkerManSrv::clientToUniqId(0, 9031, 1);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 1) . 'a' . hex2bin($fd));
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 1) . 'b' . hex2bin($fd));
    frameSend($w1, chr(WorkerManSrv::CH_UNBIND_FD) . hex2bin($fd));
    syncHub();

    $sender = makeWorker($hubHost, $hubPort, 9032, 0);
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 1) . 'a' . 'x');
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 1) . 'b' . 'y');

    eq(count(frameDrain($w1, 0.3)), 0, 'w1 got nothing on a or b');
    fclose($w1);
    fclose($sender);
}

// ===== 场景 5: CH_ALL 广播 =====
section('CH_ALL broadcast');
{
    $w1 = makeWorker($hubHost, $hubPort, 9041, 0);
    $w2 = makeWorker($hubHost, $hubPort, 9042, 0);
    $w3 = makeWorker($hubHost, $hubPort, 9043, 0);

    frameSend($w1, chr(WorkerManSrv::CH_ALL) . 'broadcast-payload');
    foreach (['w1' => $w1, 'w2' => $w2, 'w3' => $w3] as $name => $w) {
        $msgs = frameDrain($w, 0.3);
        eq(count($msgs), 1, "$name got 1");
        if ($msgs) {
            eq(ord($msgs[0][0]), WorkerManSrv::CH_BROAD, "$name cmd=CH_BROAD");
            eq(substr($msgs[0], 1), 'broadcast-payload', "$name payload");
        }
    }
    fclose($w1);
    fclose($w2);
    fclose($w3);
}

// ===== 场景 6: CH_CLOSE 跨进程关连接 =====
section('CH_CLOSE forwards to target worker');
{
    $w1 = makeWorker($hubHost, $hubPort, 9051, 0);
    $fd = WorkerManSrv::clientToUniqId(0, 9051, 7);
    syncHub();
    // 另一进程发 CH_CLOSE
    $other = makeWorker($hubHost, $hubPort, 9052, 0);
    frameSend($other, chr(WorkerManSrv::CH_CLOSE) . hex2bin($fd));

    $msgs = frameDrain($w1, 0.3);
    eq(count($msgs), 1, 'target worker received DO_CLOSE');
    if ($msgs) {
        eq(ord($msgs[0][0]), WorkerManSrv::CH_DO_CLOSE, 'cmd=CH_DO_CLOSE');
        $fdId = unpack('N', substr($msgs[0], 1, 4))[1];
        eq($fdId, 7, 'fdId=7');
    }
    fclose($w1);
    fclose($other);
}

// ===== 场景 7: CH_CLOSE 同步清理 hub 上的 bindings =====
section('CH_CLOSE drops bindings on hub');
{
    $w1 = makeWorker($hubHost, $hubPort, 9061, 0);
    $fd = WorkerManSrv::clientToUniqId(0, 9061, 1);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'gone1' . hex2bin($fd));
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'gone2' . hex2bin($fd));
    syncHub();

    // 另一进程触发 CLOSE
    $other = makeWorker($hubHost, $hubPort, 9062, 0);
    frameSend($other, chr(WorkerManSrv::CH_CLOSE) . hex2bin($fd));
    syncHub();
    // 排空 w1 收到的 DO_CLOSE
    frameDrain($w1, 0.2);

    // 现在 sendByKey 到 gone1/gone2 应该都不到 w1
    frameSend($other, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'gone1' . 'x');
    frameSend($other, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'gone2' . 'y');
    eq(count(frameDrain($w1, 0.3)), 0, 'no DELIVER after CH_CLOSE');
    fclose($w1);
    fclose($other);
}

// ===== 场景 8: 重连重放 bindings (本轮 §B 核心回归) =====
section('reconnect-replay protocol contract');
{
    $w1 = makeWorker($hubHost, $hubPort, 9071, 0);
    $fd = WorkerManSrv::clientToUniqId(0, 9071, 1);
    frameSend($w1, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'reco1' . hex2bin($fd));
    syncHub();

    // 第一次 sendByKey 应命中
    $sender = makeWorker($hubHost, $hubPort, 9072, 0);
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'reco1' . 'before');
    eq(count(frameDrain($w1, 0.3)), 1, 'before disconnect: hit');

    // w1 主动断
    fclose($w1);
    usleep(150000); // 等 hub 处理 onClose

    // 不重发 BIND 直接 sendByKey,新 w1' 不会收到 (hub 已清掉)
    $w1New = makeWorker($hubHost, $hubPort, 9071, 0);
    syncHub();
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'reco1' . 'lost');
    eq(count(frameDrain($w1New, 0.3)), 0, 'after reconnect without replay: miss (proves hub cleared bindings)');

    // 模拟 localBindings 重放
    frameSend($w1New, chr(WorkerManSrv::CH_BIND) . pack('n', 5) . 'reco1' . hex2bin($fd));
    syncHub();
    frameSend($sender, chr(WorkerManSrv::CH_SEND_KEY) . pack('n', 5) . 'reco1' . 'after');
    eq(count(frameDrain($w1New, 0.3)), 1, 'after replay: hit');
    fclose($w1New);
    fclose($sender);
}

done();
