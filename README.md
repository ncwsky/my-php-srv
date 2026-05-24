##使用
```
composer require myphps/my-php-srv
```
##示例1 通过 composer的autolad
```php
#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app'); #myphp运行时指定的项目目录

$cfg = require(__DIR__ . '/app.conf.php'); #需要在此配置文件里配置myphp的目录路径
require __DIR__ . '/vendor/autoload.php';
/*
#使用wokerman时也可以直接加载
require __DIR__ . '/vendor/workerman/workerman/Autoloader.php';
require __DIR__ . '/vendor/myphps/my-php-srv/Load.php';
*/

$srv = new WorkerManHttpSrv($cfg);
$srv->run($argv);
```
##示例2 或直接通过自带Load.php载入
```php
#!/usr/bin/env php
<?php
$_SERVER['SCRIPT_FILENAME'] = __FILE__; //重置运行
define('APP_PATH', __DIR__ .'/app');

$cfg = require(__DIR__ . '/app.conf.php');
require __DIR__ . '/../Load.php'; #使用workerman时需要把workerman的目录与Load.php同级或直接引用workerman/Autoloader.php

$srv = new WorkerManHttpSrv($cfg);
$srv->run($argv);
```
>conf.php是myphp的配置文件

**Workerman Event**  
onWorkerStart|onWorkerReload(Workerman\Worker $worker)  
`udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调`  
onConnect|onClose|onBufferFull|onBufferDrain(Workerman\Connection\TcpConnection $connection)  
`ws协议握手、当客户端通过连接发来数据时(Workerman收到数据时)触发的回调函数`  
onWebSocketConnect|onWebSocketConnected|onMessage(Workerman\Connection\TcpConnection $connection, string|Workerman\Protocols\Http\Request $data)  
`当客户端的连接上发生错误时触发`  
onError(Workerman\Connection\TcpConnection $connection, $code, $msg)
> $fd = $connection->id;

**Swoole Event**  
`在 Worker 进程 / Task 进程 启动时发生，这里创建的对象可以在进程生命周期内使用。多个监听时只有主服务器的事件有效`
onWorkerStart|onWorkerStop(Swoole\Server $server, int $workerId)  
`当 Worker/Task 进程发生异常后会在 Manager 进程内回调此函数，主要用于报警和监控`  
onWorkerError(Swoole\Server $server, int $worker_id, int $worker_pid, int $exit_code, int $signal)  
`有新的连接进入时，在worker进程中回调 $fd 是连接的文件描述符`  
`客户端连接关闭事件 TCP客户端连接关闭后，在worker进程中回调此函数 $reactorId当服务器主动关闭连接时，底层会设置此参数为-1`  
onConnect|onClose(Swoole\Server $server, int $fd, int $reactorId)
`接收到数据时回调此函数，发生在worker进程中`  
tcp onReceive(Swoole\Server $server, int $fd, int $reactorId, string $data)  
`接收到UDP数据包时回调此函数，发生在worker进程中`  
udp onPacket(Swoole\Server $server, string $data, array $clientInfo)  
`websocket收到来自客户端的数据帧时会回调此函数`  
ws onMessage(Swoole\WebSocket\Server $server, Swoole\WebSocket\Frame $frame)  
`HTTP请求回调`  
http onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response)  
`异步任务 在task_worker进程内被调用`  
onTask(Swoole\Server $server, int $task_id, int $src_worker_id, mixed $data)  
`task 进程的 onTask 事件中没有调用 finish 方法或者 return 结果，worker 进程不会触发 onFinish`  
onFinish(Swoole\Server $server, int $task_id, mixed $data)  

## 跨进程 send / 业务键绑定 (IPC)

**仅 Workerman 适用**。Swoole 的 `$fd` 是 Server 进程内全局唯一的整数，任意 worker 都可直接 `$server->send($fd, $data)` 投递到对端连接，不需要额外的 IPC 通道。

Workerman 没有这套全局 fd 语义：每个 worker 进程独立持有自己的 `connections[$fd]` 表，跨进程发送必须显式通过框架内置的 IPC hub 转发。`WorkerManSrv` 因此提供以下能力，**同名 API 在 SwooleSrv 上不存在或行为完全不同**：

| API | Workerman 行为 | Swoole 等价做法 |
|---|---|---|
| `getUniqId(TcpConnection)` | 生成 16 字符 hex 全局 fd（worker_id+port+fd） | 直接用 `$fd` 整数 |
| `send($globalHexFd, $data)` | 本进程命中直发,否则走 hub 转 CH_TO 给目标 worker | 不需要,`send($fd, $data)` 即可 |
| `send(-1, $data)` | hub 启用时广播；未启用时退化为本进程 connections 遍历 | 自行 `foreach ($server->connections as $fd) $server->send($fd, ...)` |
| `close($globalHexFd)` | 走 hub 发 CH_CLOSE | `$server->close($fd)` 即可 |
| `bind($key, TcpConnection)` | hub 维护 key→fd 映射,跨 worker 可达 | 自行用 redis/共享内存维护 user_id → $fd |
| `unbind / unbindFd / sendByKey` | 同上 | 同上 |

### 启用条件

是否启动 IPC hub 由 `WorkerManSrv::needChain()` 按以下顺序决定:

1. Windows 平台直接关闭 (无 unix domain socket 支持)。
2. 配置了 `task_worker_num > 0` → 强制开启 (task 进程通信依赖 hub)。
3. `type === 'http'` 且 `setting.http_chain_enable` 不为真值 → 关闭。HTTP 是无状态短连接,跨 worker 推送通常无意义,即便 `count > 1` 也不会启用 IPC,避免无谓的 hub 进程开销。
4. 通用推断: `setting.count > 1` 或非空 `listen` 多端口监听 → 开启。
5. 都不满足 → 关闭。

> HTTP 服务确实需要跨进程能力时(例如 SSE 推送、HTTP 入口 `bind` 用户/由 task 进程通过 `sendByKey` 反推消息),需要显式 `setting.http_chain_enable = true`,之后再走第 4 条通用推断。
> `http_chain_enable` 顾名思义仅作用于 HTTP 类型的"绕过开关",对其他长连接型不生效;长连接型由 `count` 或 `listen` 自然驱动。

### 业务侧使用约束

1. **首次 bind 后不要直接覆盖 `$connection->onClose`**：框架在 bind 时会挂自动清理钩子；用户后续赋值会覆盖钩子，导致连接断开后 hub 上残留野绑定。需追加业务清理时，在 onClose 函数体里手动调 `$srv->unbindFd($conn)`。
2. **单 worker bind 数量建议 < 30k**：重连成功后框架一次性把本地 bindings 全部 BIND 重放给 hub，超过 Workerman 默认 1MB 发送缓冲会触发 onBufferFull。预期超量时在配置里调高 `setting.maxSendBufferSize`（如 10485760）。
3. **key 长度 ≤ 65500 字节**：协议 keyLen 字段 2 字节，超长会被框架拒绝。

### 相关配置

```php
'setting' => [
    'http_chain_enable'    => true,     // HTTP 服务的 IPC 绕过开关,truthy 时让 HTTP 也按 count/listen 推断;非 HTTP 不需配置
    'chain_pending_max'    => 10000,    // chain 断线期间业务消息缓冲条数,超限丢最早
    'chain_stat_interval'  => 60,       // hub 状态周期输出秒数,0 关闭
    'maxSendBufferSize'    => 10485760, // 防 bind 重放风暴,可选
],
```

### hub 状态输出（线上排障）

`chain_stat_interval > 0` 时，hub 进程会按周期输出到 stdout：

```
[chain stat] channels=N keys=K fds=F totalBindings=T maxKeyFds=M maxKey='xxx' rssKB=R
```

- `channels` 注册的 worker 数；与预期不符 = 有 worker 没接入。
- `fds` 持续 > 物理在线连接数 = 野绑定累积。
- `maxKeyFds` 异常飙升 = 某个 key 被循环 bind。
- `rssKB` 长期上扬 = 内存泄漏信号。

## 升级提示

1. 旧示例中的配置文件名 `wokerman.conf.php` 已更名为 `app.conf.php`；老项目可保持原文件名，仅是示例约定。
2. 以下 API 已重命名/重构,如有外部引用需同步：

| 旧 | 新 |
|---|---|
| `WorkerManSrv::workerToUniqId()` | `workerToInnerId()` |
| `WorkerManSrv::uniqIdToWorker()` | `innerIdToWorker()` |
| `WorkerManSrv::chainTo()` | `ipcTo()` |
| `WorkerManSrv::clientToUniqId($port, $fd)` (12 hex) | `clientToUniqId($wid, $port, $fd)` (16 hex) |
| `relog()` / `initMyPhp()` / `hasInitMyPhp` | `reLog()` / `initLoadPhp()` / `hasInitLoadPhp` |
| `WorkerManSrv::$workers` / `$fdConnection` / `$remoteConnection` / `$chainSocketFile` / `$chainWorker` | 已删除,见 `$ipcConnection` / `$ipcSocketFile` / `$ipcWorker` |