<?php
/**
 * 极简断言工具 + WorkerManSrv 自动加载.
 *
 * 与 bench_ipc.php 同风格:无 require-dev 依赖,纯 CLI 脚本运行.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../WorkerManSrv.php';

class TestStat
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $section = '';
}

function section(string $name): void
{
    TestStat::$section = $name;
    fwrite(STDOUT, "\n== $name ==\n");
}

function ok(bool $cond, string $msg): void
{
    if ($cond) {
        TestStat::$pass++;
        fwrite(STDOUT, "  [PASS] $msg\n");
    } else {
        TestStat::$fail++;
        TestStat::$failures[] = '['.TestStat::$section.'] '.$msg;
        fwrite(STDOUT, "  [FAIL] $msg\n");
    }
}

function eq($actual, $expect, string $msg): void
{
    if ($actual === $expect) {
        ok(true, $msg);
        return;
    }
    $a = is_scalar($actual) || $actual === null ? var_export($actual, true) : '<'.gettype($actual).'>';
    $e = is_scalar($expect) || $expect === null ? var_export($expect, true) : '<'.gettype($expect).'>';
    ok(false, "$msg (actual=$a, expect=$e)");
}

function fail(string $msg): void
{
    ok(false, $msg);
}

function done(): void
{
    $total = TestStat::$pass + TestStat::$fail;
    fwrite(STDOUT, "\n--- summary ---\n");
    fwrite(STDOUT, "total: $total, pass: ".TestStat::$pass.", fail: ".TestStat::$fail."\n");
    if (TestStat::$failures) {
        fwrite(STDOUT, "failures:\n");
        foreach (TestStat::$failures as $f) {
            fwrite(STDOUT, "  - $f\n");
        }
    }
    exit(TestStat::$fail === 0 ? 0 : 1);
}
