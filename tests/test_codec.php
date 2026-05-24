<?php
/**
 * 协议编解码静态函数单测.
 *
 * 用法: php tests/test_codec.php
 * 退出码: 0 全部通过 / 1 有失败.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// --- clientToUniqId / uniqIdToClient 对称性 ---
section('clientToUniqId <-> uniqIdToClient');

$cases = [
    [0, 0, 0],
    [1, 7900, 1],
    [3, 7901, 1234567],
    [0xFFFF, 0xFFFF, 0xFFFFFFFF], // uint16 max + uint32 max
];
foreach ($cases as [$wid, $port, $fdid]) {
    $hex = WorkerManSrv::clientToUniqId($wid, $port, $fdid);
    eq(strlen($hex), 16, "hex length=16 (wid=$wid port=$port fd=$fdid)");
    $back = WorkerManSrv::uniqIdToClient($hex);
    eq($back['worker_id'], $wid, "round-trip worker_id (wid=$wid)");
    eq($back['work_port'], $port, "round-trip work_port (port=$port)");
    eq($back['fd_id'], $fdid, "round-trip fd_id (fd=$fdid)");
}

// --- uniqIdToClient 异常输入 ---
section('uniqIdToClient invalid input');

eq(WorkerManSrv::uniqIdToClient(''), [], 'empty string -> []');
eq(WorkerManSrv::uniqIdToClient('abc'), [], 'short string -> []');
eq(WorkerManSrv::uniqIdToClient(str_repeat('0', 15)), [], '15 chars -> []');
eq(WorkerManSrv::uniqIdToClient(str_repeat('0', 17)), [], '17 chars -> []');

// --- workerToInnerId / innerIdToWorker 对称性 ---
section('workerToInnerId <-> innerIdToWorker');

$cases = [
    [0, 0],
    [7900, 0],
    [7900, 5],
    [0xFFFF, 0xFFFFFFFF],
];
foreach ($cases as [$port, $wid]) {
    $hex = WorkerManSrv::workerToInnerId($port, $wid);
    eq(strlen($hex), 12, "innerId hex length=12 (port=$port wid=$wid)");
    $back = WorkerManSrv::innerIdToWorker($hex);
    eq($back['worker_port'], $port, "round-trip worker_port (port=$port)");
    eq($back['worker_id'], $wid, "round-trip worker_id (wid=$wid)");
}

eq(WorkerManSrv::innerIdToWorker(''), [], 'empty -> []');
eq(WorkerManSrv::innerIdToWorker(str_repeat('0', 11)), [], '11 chars -> []');

// --- fdToUniqid 字节布局正确 ---
section('fdToUniqid layout');

// fdBin = pack('nnN', wid, port, fdid) => [wid:2][port:2][fdid:4]
// uniqidBin = [port:2]\x00\x00[wid:2]
$fdBin = pack('nnN', 0x0001, 0x1F5C, 0x12345678); // wid=1, port=8028, fd
$uniqidBin = WorkerManSrv::fdToUniqid($fdBin);
eq(strlen($uniqidBin), 6, 'uniqidBin length=6');
eq(substr($uniqidBin, 0, 2), pack('n', 0x1F5C), 'first 2B = port');
eq(substr($uniqidBin, 2, 2), "\x00\x00", 'mid 2B = zero padding');
eq(substr($uniqidBin, 4, 2), pack('n', 0x0001), 'last 2B = wid');

// 与 workerToInnerId 输出一致(同一个 worker,fd 不同 → uniqid 应相同)
$fd1 = pack('nnN', 7, 9000, 100);
$fd2 = pack('nnN', 7, 9000, 200);
eq(WorkerManSrv::fdToUniqid($fd1), WorkerManSrv::fdToUniqid($fd2), 'same worker,different fd → same uniqid');

$expectedHex = WorkerManSrv::workerToInnerId(9000, 7);
eq(bin2hex(WorkerManSrv::fdToUniqid($fd1)), $expectedHex, 'fdToUniqid matches workerToInnerId');

// --- getUniqId(connection) 是 clientToUniqId 的薄封装,要求真实 TcpConnection,
//     端到端测试 (test_ipc.php) 中会通过实际连接覆盖,此处不重复.

done();
