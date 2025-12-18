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

$cfg = require(__DIR__ . '/wokerman.conf.php'); #需要在此配置文件里配置myphp的目录路径
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

$cfg = require(__DIR__ . '/wokerman.conf.php');
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
 