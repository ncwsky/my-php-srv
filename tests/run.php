<?php
/**
 * 测试总入口.
 * 顺序运行所有测试脚本,任一失败 → 整体失败.
 *
 * 用法: php tests/run.php
 */

declare(strict_types=1);

$scripts = [
    'codec'  => __DIR__ . '/test_codec.php',
    'ipc'    => __DIR__ . '/test_ipc.php',
];

$overallExit = 0;
$summaries = [];

// 两种模式:管道捕获(可解析 summary)和 inherit(避免嵌套 proc_open 卡 STDOUT)
$pipedExec = function (string $path) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = '';
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($proc)) return [1, ''];
    fclose($pipes[0]);
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk === false) break;
        fwrite(STDOUT, $chunk);
        $output .= $chunk;
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if ($stderr !== false && $stderr !== '') {
        fwrite(STDOUT, $stderr);
        $output .= $stderr;
    }
    return [proc_close($proc), $output];
};

// 直接捕获到临时文件,再回放并解析
$fileExec = function (string $path) {
    $tmp = tempnam(sys_get_temp_dir(), 'mphps_test_');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' > ' . escapeshellarg($tmp) . ' 2>&1';
    $rc = 0;
    system($cmd, $rc);
    $output = (string)@file_get_contents($tmp);
    @unlink($tmp);
    fwrite(STDOUT, $output);
    return [$rc, $output];
};

foreach ($scripts as $name => $path) {
    fwrite(STDOUT, str_repeat('=', 70) . "\n");
    fwrite(STDOUT, ">>> $name ($path)\n");
    fwrite(STDOUT, str_repeat('=', 70) . "\n");

    // ipc 测试自己嵌套 proc_open 起 hub,父进程再 proc_open 它会有 Windows STDOUT 缓冲卡死;
    // 改走临时文件路径稳定可靠.
    [$rc, $output] = $name === 'ipc' ? $fileExec($path) : $pipedExec($path);

    if ($rc !== 0) $overallExit = 1;
    if (preg_match('/total: (\d+), pass: (\d+), fail: (\d+)/', $output, $m)) {
        $summaries[$name] = ['total' => (int)$m[1], 'pass' => (int)$m[2], 'fail' => (int)$m[3]];
    } else {
        $summaries[$name] = ['total' => 0, 'pass' => 0, 'fail' => 0, 'note' => 'no summary line'];
    }
    fwrite(STDOUT, "\n");
}

fwrite(STDOUT, str_repeat('=', 70) . "\n");
$grandPass = 0;
$grandFail = 0;
foreach ($summaries as $name => $s) {
    $line = sprintf("%-10s pass=%d fail=%d", $name, $s['pass'], $s['fail']);
    if (!empty($s['note'])) $line .= "  ({$s['note']})";
    fwrite(STDOUT, $line . "\n");
    $grandPass += $s['pass'];
    $grandFail += $s['fail'];
}
fwrite(STDOUT, "OVERALL: pass=$grandPass fail=$grandFail exit=$overallExit\n");
exit($overallExit);
