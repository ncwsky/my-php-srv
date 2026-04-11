<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use Workerman\Timer;

/**
 * Class Worker2
 * 增加定时处理的tick、after方法
 * @method void reload() 用于提示
 */
class Worker2 extends Worker
{
    public string $ip;
    public int $port;
    public string $type = 'tcp';
    public int $worker_id = 0;
    public bool $isTask = false; #是否task进程
    /**
     * @var ?callable
     */
    public $onTask = null; #task进程回调
    public array $channels;
    public array $bindings;
    public array $fdKeys;

    /** 自定义间隔时钟
     * @param int $msec 毫秒
     * @param callable $callback
     * @param array|null $args
     * @return int
     */
    public function tick(int $msec, callable $callback, ?array $args = []): int
    {
        return Timer::add(round($msec / 1000, 3), $callback, $args);
    }

    /** 自定义指定时间执行时钟
     * @param int $msec 毫秒
     * @param callable $callback
     * @param array|null $args
     * @return int
     */
    public function after(int $msec, callable $callback, ?array $args = []): int
    {
        return Timer::add(round($msec / 1000, 3), $callback, $args, false);
    }

    /**清除定时器
     * @param int $timer_id
     * @return bool
     */
    public function clearTimer(int $timer_id): bool
    {
        return Timer::del($timer_id);
    }

    /**
     * 清除所有定时器
     */
    public function clearAll()
    {
        Timer::delAll();
    }
}

class WorkerManSrv extends SrvBase
{
    public bool $isWorkerMan = true;
    public $max_request = 0;
    public static $workers = []; //记录本进程的 worker(按 uniqid 索引)
    public static $taskWorker = null;
    public static $taskAddr = '';
    public static $ipcWorker = null;
    public static $ipcSocketFile = '';
    public static $fdConnection = null;

    /**
     * @var Worker2
     */
    public \Swoole\Server|Worker2 $server; //服务实例

    private $runLock = ''; #用于判定重载onStart处理
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->pidFile = $this->getConfig('setting.pidFile', $this->runDir .'/server.pid');
        $this->runLock = $this->runDir.'/runLock';
        $this->max_request = $this->getConfig('setting.max_request', 0);

        if (isset($this->config['setting']['statusFile'])) {
            Worker::$statusFile = $this->config['setting']['statusFile'];
            unset($this->config['setting']['statusFile']);
        }
        if (isset($this->config['setting']['stdoutFile'])) {
            Worker::$stdoutFile = $this->config['setting']['stdoutFile'];
            unset($this->config['setting']['stdoutFile']);
        }
        if (isset($this->config['setting']['pidFile'])) {
            Worker::$pidFile = $this->config['setting']['pidFile'];
            unset($this->config['setting']['pidFile']);
        } elseif (isset($this->config['setting']['pid_file'])) { //兼容处理
            Worker::$pidFile = $this->config['setting']['pid_file'];
            unset($this->config['setting']['pid_file']);
        }
        if (isset($this->config['setting']['logFile'])) {
            Worker::$logFile = $this->config['setting']['logFile'];
            unset($this->config['setting']['logFile']);
        } elseif (isset($this->config['setting']['log_file'])) { //兼容处理
            Worker::$logFile = $this->config['setting']['log_file'];
            unset($this->config['setting']['log_file']);
        }
    }
    /** 此事件在Worker进程启动时发生 这里创建的对象可以在进程生命周期内使用 如mysql/redis...
     * @param Worker2 $worker
     #* @param int $worker_id [0-$worker_num)区间内的数字
     */
    final public function _onWorkerStart(Worker2 $worker)
    {
        $worker_id = $worker->id;
        $this->server->worker_id = $worker_id;
        $this->initLoadPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER

        if ($worker_id == 0) {
            Worker::safeEcho("run dir:".$this->runDir.PHP_EOL);
            //self::$isConsole && Worker::safeEcho(json_encode($_SERVER).PHP_EOL);
        }
        if ($this->getConfig('timer_file')) {
            //定时载入
            $timer = new WorkerManTimer();
            $timer->start($worker_id);
        }
        //主进程回调处理
        if ($worker_id == 0 && file_exists($this->runLock) && @file_get_contents($this->runLock) === '0') {
            if (method_exists($this, 'onStart')) {
                $this->onStart();
            }
        }

        //连接到内部通信服务
        $this->chainConnection($worker);

        $this->onWorkerStart($worker, $worker_id);
    }
    //此事件在Worker进程终止时发生 在此函数中可以回收Worker进程申请的各类资源
    final public function _onWorkerStop(Worker2 $worker)
    {
        $worker_id = $worker->id;
        if (!$worker->isTask) { //worker进程  异常结束后执行的逻辑
            Worker::safeEcho('Worker Stop clear' . PHP_EOL);
            $timer = new WorkerManTimer();
            $timer->stop($worker_id);
        }
        $this->onWorkerStop($worker, $worker_id);
    }
    //当客户端的连接上发生错误时触发 参见 http://doc.workerman.net/worker/on-error.html
    final public function _onWorkerError(TcpConnection $connection, $code, $msg)
    {
        $err = date('Y-m-d H:i:s ') . '异常进程的ID:'.$connection->worker->id.', 异常连接的ID:'.$connection->id.', code:'.$code.', msg:'.$msg;
        Worker::safeEcho($err.PHP_EOL);
        //todo 记录日志或者发送报警的信息来提示开发者进行相应的处理
        self::err($err);
        $this->onWorkerError(self::$instance->server, $connection->worker->id, $err);
    }

    //reloadable为false时 可以此重载回调重新载入配置等操作
    public function onWorkerReload(Worker2 $worker)
    {
        //todo
    }
    //初始服务
    final public function init()
    {
        $context = $this->getConfig('context', []); //资源上下文
        if ($this->needChain()) {
            //创建进程通信服务(跨 worker 消息转发)
            $this->ipcWorker();
        }
        $unixFiles = [];
        //监听1024以下的端口需要root权限
        switch ($this->getConfig('type')) {
            case self::TYPE_HTTP:
                $this->server = new Worker2(self::TYPE_HTTP.'://'.$this->ip.':'.$this->port, $context);
                $this->server->type = self::TYPE_HTTP;
                self::$isHttp = true;
                break;
            case self::TYPE_WEB_SOCKET:
                $this->server = new Worker2(self::TYPE_WEB_SOCKET.'://'.$this->ip.':'.$this->port, $context);
                $this->server->type = self::TYPE_WEB_SOCKET;
                break;
            case self::TYPE_UDP:
                $this->server = new Worker2(self::TYPE_UDP.'://'.$this->ip.':'.$this->port, $context);
                $this->server->type = self::TYPE_UDP;
                break;
            case self::TYPE_UNIX:
                $this->ip = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . $this->ip;

                $this->server = new Worker2(self::TYPE_UNIX.'://'.$this->ip, $context);
                $this->server->type = self::TYPE_UNIX;
                $unixFiles[] = $this->ip;
                break;
            default:
                $this->server = new Worker2(self::TYPE_TCP.'://'.$this->ip.':'.$this->port, $context);
                $this->server->type = self::TYPE_TCP;
        }
        $this->address = $this->server->type.'://'.$this->ip.':'.$this->port;

        $server = $this->server;
        $server->ip = $this->ip;
        $server->port = $this->port;
        //设置服务配置
        foreach ($this->config['setting'] as $k => $v) {
            $server->$k = $v;
        }
        if ($server->name == 'none') {
            $server->name = $this->serverName();
        }
        if (!empty($context['ssl'])) { // 设置transport开启ssl
            $server->transport = 'ssl';
        }
        // 获取配置的事件
        $event = $this->getConfig('event', []);

        #protocol: 子服务不会继承主服务的协议方式
        //初始进程事件绑定
        $server->onWorkerStart = function (Worker2 $worker) use ($event) {
            $this->_onWorkerStart($worker);
            isset($event['onWorkerStart']) && call_user_func($event['onWorkerStart'], $worker, $worker->id);
        };

        if (!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
            //如重置载入配置
            $server->onWorkerReload = function ($worker) use ($event) {
                $this->onWorkerReload($worker);
                isset($event['onWorkerReload']) && call_user_func($event['onWorkerReload'], $worker);
            };
        }
        $server->onWorkerStop = function (Worker2 $worker) use ($event) {
            $this->_onWorkerStop($worker);
            isset($event['onWorkerStop']) && call_user_func($event['onWorkerStop'], $worker, $worker->id);
        };
        //当客户端的连接上发生错误时触发
        $server->onError = function (TcpConnection $connection, $code, $msg) use ($event) {
            $this->_onWorkerError($connection, $code, $msg);
            isset($event['onWorkerError']) && call_user_func($event['onWorkerError'], self::$instance->server, $connection->worker->id, $msg);
        };
        //绑定事件
        if (isset($event['onConnect'])) {
            $server->onConnect = $event['onConnect']; // args: $connection
        }
        if ($this->getConfig('type') == self::TYPE_WEB_SOCKET) {
            if (isset($event['onWebSocketConnect'])) { //before websocket handshake
                $server->onWebSocketConnect = $event['onWebSocketConnect']; // args: $connection, $data
            }
            if (isset($event['onWebSocketConnected'])) { //after websocket handshake
                $server->onWebSocketConnected = $event['onWebSocketConnected']; // args: $connection, $data
            }
        }

        if (isset($event['onMessage'])) {
            $server->onMessage = $event['onMessage']; // args: $connection, $data
        } else {
            $server->onMessage = ['WorkerManEvent', 'onMessage'];
        }
        if (isset($event['onClose'])) {
            $server->onClose = $event['onClose']; // args: $connection
        }

        $server->onBufferFull = function ($connection) use ($event) {
            if (isset($event['onBufferFull'])) {
                call_user_func($event['onBufferFull'], $connection);
            } else {
                WorkerManEvent::onBufferFull($connection);
            }
        };
        $server->onBufferDrain = function ($connection) use ($event) {
            if (isset($event['onBufferDrain'])) {
                call_user_func($event['onBufferDrain'], $connection);
            } else {
                WorkerManEvent::onBufferDrain($connection);
            }
        };

        //开启多个监听处理
        $listen = $this->getConfig('listen', []);
        if (is_array($listen) && $listen) {
            $port = $this->port;
            foreach ($listen as $k => $item) {
                if (!isset($item['type']) || !in_array($item['type'], SrvBase::$types)) {
                    $item['type'] = self::TYPE_TCP;
                } // Socket 类型
                if ($item['type'] == self::TYPE_UNIX) {
                    if (!isset($item['ip'])) {
                        $item['ip'] = $k;
                    }
                    $item['ip'] = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . $item['ip'];

                    if (file_exists($item['ip']) && file_exists($this->runLock) && @file_get_contents($this->runLock) === '0') {
                        @unlink($item['ip']);
                    }
                    $unixFiles[] = $item['ip'];
                }

                if (!isset($item['ip'])) { //未设置使用主服务器的
                    $item['ip'] = $this->ip;
                }
                if (!isset($item['port'])) { //未设置使用主服务器的 port+10
                    $item['port'] = ++$port;
                }

                //创建其他监听服务
                $this->childSrv[$k] = new Worker2($item['type'] . '://' . $item['ip'] . ($item['type'] == self::TYPE_UNIX ? '' : ':' . $item['port']), empty($item['context']) ? [] : $item['context']);
                /**
                 * @var Worker2 $childSrv;
                 */
                $childSrv = $this->childSrv[$k];
                $childSrv->type = $item['type'];
                $childSrv->ip = $item['ip'];
                $childSrv->port = $item['port'];
                //有配置证书
                if (!empty($item['context']['ssl'])) {
                    $childSrv->transport = 'ssl';
                }
                if (isset($item['setting'])) {
                    foreach ($item['setting'] as $name => $val) {
                        $childSrv->$name = $val;
                    }
                }
                if ($childSrv->name == 'none') {
                    $childSrv->name = $this->serverName().'_'.$k;
                }
                if ($childSrv->user == '') {
                    $childSrv->user = $this->getConfig('setting.user', '');
                }
                //初始进程事件绑定
                $childSrv->onWorkerStart = [$this, 'childWorkerStart'];
                if (!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
                    //如重置载入配置
                    $childSrv->onWorkerReload = [$this, 'onWorkerReload'];
                }
                //当客户端的连接上发生错误时触发
                $childSrv->onError = [$this, '_onWorkerError'];
                #未设置【onConnect,onMessage,onClose】回调函数，默认使用主服务器的回调函数
                $childSrv->onBufferFull = ['WorkerManEvent', 'onBufferFull'];
                $childSrv->onBufferDrain = ['WorkerManEvent', 'onBufferDrain'];
                if (isset($item['event'])) { //有自定义事件
                    foreach ($item['event'] as $event => $fun) {
                        if ($event == 'onWorkerStart') {
                            $childSrv->onWorkerStart = function (Worker2 $worker) use ($fun) {
                                $this->childWorkerStart($worker);
                                call_user_func($fun, $worker, $worker->id);
                            };
                        } elseif ($event == 'onWorkerStop') {
                            $childSrv->onWorkerStop = function (Worker2 $worker) use ($fun) {
                                call_user_func($fun, $worker, $worker->id);
                            };
                        } else {
                            $childSrv->$event = $fun;
                        }
                    }
                }

                $this->address .= '; '.$item['type'].'://'.$item['ip'].':'.$item['port'];
            }
        }
        $server->onTask = null;
        if ($this->task_worker_num && !self::$taskWorker) { //启用了
            $server->onTask = function ($task_id, $src_worker_id, $data) use ($event) {
                if (isset($event['onTask'])) {
                    call_user_func($event['onTask'], $task_id, $src_worker_id, $data);
                } else {
                    WorkerManEvent::onTask($task_id, $src_worker_id, $data);
                }
            };

            $taskPort = $this->getConfig('setting.task_port', $this->port + 100);
            self::$taskAddr = "127.0.0.1:".$taskPort;
            //创建异步任务进程
            $taskWorker = new Worker2('frame://'.self::$taskAddr);
            $taskWorker->ip = '127.0.0.1';
            $taskWorker->port = $taskPort;
            $taskWorker->isTask = true;
            $taskWorker->user = $this->getConfig('setting.user', '');
            $taskWorker->name = $server->name.'_task';
            $taskWorker->count = $this->task_worker_num; #unix://不支持多worker进程
            //初始进程事件绑定
            $taskWorker->onWorkerStart = [$this, 'childWorkerStart'];
            if (!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
                //如重置载入配置
                $taskWorker->onWorkerReload = [$this, 'onWorkerReload'];
            }
            //当客户端的连接上发生错误时触发
            $taskWorker->onError = [$this, '_onWorkerError'];
            $taskWorker->onConnect = function (TcpConnection $connection) use ($taskWorker) {
                $connection->send($taskWorker->id); //返回进程id
            };
            $taskWorker->onMessage = function ($connection, $data) use ($taskWorker) {
                static $request_count = 0;
                if ($this->server->onTask) {
                    $src_worker_id = unpack('n', $data)[1];
                    $data = unserialize(substr($data, 2));
                    call_user_func($this->server->onTask, $taskWorker->id, $src_worker_id, $data);
                    // 请求数达到xxx后退出当前进程，主进程会自动重启一个新的进程
                    if ($this->max_request > 0 && ++$request_count > $this->max_request) {
                        Worker::stopAll();
                    }
                }
            };
            //$taskWorker->listen();
            self::$taskWorker = $taskWorker;
        }

        Worker::$onMasterReload = function () use ($unixFiles) {
            //清除unix-sock文件
            foreach ($unixFiles as $sockFile) {
                file_exists($sockFile) && @unlink($sockFile);
            }

            self::$ipcSocketFile && file_exists(self::$ipcSocketFile) && @unlink(self::$ipcSocketFile);
        };
        #结束时销毁处理
        Worker::$onMasterStop = function () use ($unixFiles) {
            if (method_exists($this, 'onStop')) {
                !$this->hasInitLoadPhp && $this->initLoadPhp();
                $this->onStop();
            }

            //清除unix-sock文件
            foreach ($unixFiles as $sockFile) {
                file_exists($sockFile) && @unlink($sockFile);
            }

            file_exists($this->runLock) && @unlink($this->runLock);
            self::$ipcSocketFile && file_exists(self::$ipcSocketFile) && @unlink(self::$ipcSocketFile);
            SrvTimer::destroy();
        };
    }
    public static $remoteConnection = null;
    //连接到内部通信服务
    protected function chainConnection(Worker2 $worker)
    {
        if (!$this->needChain() || !self::$ipcSocketFile) {
            return;
        }
        //chain 服务自身进程无需再作为客户端接入
        if (self::$ipcWorker && $worker === self::$ipcWorker) {
            return;
        }

        //本进程 worker 登记(按 port+worker_id 唯一)
        $uniqid = self::workerToUniqId((int)$worker->port, (int)$worker->id);
        self::$workers[$uniqid] = $worker;

        self::$remoteConnection = new \Workerman\Connection\AsyncTcpConnection('unix://' . self::$ipcSocketFile);
        self::$remoteConnection->protocol = '\Workerman\Protocols\Frame';
        self::$remoteConnection->onConnect = function ($connection) use ($uniqid) {
            $connection->send(serialize(['a' => 'reg', 'uniqid' => $uniqid]));
        };
        self::$remoteConnection->onMessage = function ($connection, $data) use ($worker) {
            $pos = strpos($data, '|');
            if ($pos === false) {
                return;
            }
            $fd  = (int)substr($data, 0, $pos);
            $raw = substr($data, $pos + 1);
            if ($fd === -1) { //群发
                foreach ($worker->connections as $conn) {
                    $conn->send($raw);
                }
                return;
            }
            if (isset($worker->connections[$fd])) {
                $worker->connections[$fd]->send($raw);
            }
        };
        self::$remoteConnection->onClose = function (\Workerman\Connection\AsyncTcpConnection $connection) {
            // chain 断开 1 秒后重连,重连成功会自动重新 reg
            $connection->reConnect(1);
        };
        self::$remoteConnection->connect();
    }

    /** 是否需要启用内部通信通道 */
    protected function needChain(): bool
    {
        if (DIRECTORY_SEPARATOR !== '/') { //for windows
            return false;
        }
        if ($this->task_worker_num > 0) {
            return true;
        }
        if ((int)$this->getConfig('setting.count', 1) > 1) {
            return true;
        }
        $listen = $this->getConfig('listen', []);
        return is_array($listen) && !empty($listen);
    }
    //
    public function childWorkerStart(Worker2 $worker)
    {
        $this->chainConnection($worker);

        $this->initLoadPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER
        #Worker::safeEcho("childWorker init myphp:".$worker->id.PHP_EOL);
    }
    //创建进程通信服务
    public function ipcWorker()
    {
        self::$ipcSocketFile = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . '_ipc.sock';
        if (file_exists(self::$ipcSocketFile) && file_exists($this->runLock) && @file_get_contents($this->runLock) === '0') {
            @unlink(self::$ipcSocketFile);
        }

        $ipcWorker = new Worker2('unix://'.self::$ipcSocketFile);
        $ipcWorker->user = $this->getConfig('setting.user', '');
        $ipcWorker->name = $this->serverName().'_chain';
        $ipcWorker->count = 1; //chain 服务端独占单进程
        $ipcWorker->reloadable = false;
        $ipcWorker->protocol = '\Workerman\Protocols\Frame';
        $ipcWorker->channels = []; //uniqid(worker) => connection
        $ipcWorker->bindings = []; //key => [fd, fd, ...]   业务键 → 全局连接 fd
        $ipcWorker->fdKeys   = []; //fd  => [key => 1]      反向索引,加速清理
        //内部辅助闭包:把某个 fd 从 bindings / fdKeys 中全部移除
        $dropFd = function ($fd) use ($ipcWorker) {
            if (!isset($ipcWorker->fdKeys[$fd])) {
                return;
            }
            foreach ($ipcWorker->fdKeys[$fd] as $key => $_) {
                if (!isset($ipcWorker->bindings[$key])) {
                    continue;
                }
                $ipcWorker->bindings[$key] = array_values(array_filter(
                    $ipcWorker->bindings[$key],
                    static fn($v) => $v !== $fd
                ));
                if (empty($ipcWorker->bindings[$key])) {
                    unset($ipcWorker->bindings[$key]);
                }
            }
            unset($ipcWorker->fdKeys[$fd]);
        };
        $ipcWorker->onMessage = function ($connection, $data) use ($ipcWorker, $dropFd) {
            $data = @unserialize($data);
            if (!is_array($data) || empty($data['a'])) {
                return;
            }
            switch ($data['a']) {
                case 'to': //按全局 fd 指定转发
                    $client = self::uniqIdToClient($data['fd'] ?? '');
                    if (!$client) {
                        Worker::safeEcho('chain to invalid fd:'.($data['fd'] ?? '').PHP_EOL);
                        return;
                    }
                    $uniqid = self::workerToUniqId($client['work_port'], $client['worker_id']);
                    if (isset($ipcWorker->channels[$uniqid])) {
                        $ipcWorker->channels[$uniqid]->send($client['fd_id'].'|'.$data['raw']);
                    }
                    break;
                case 'all': //群发:发给所有已登记 worker
                    foreach ($ipcWorker->channels as $conn) {
                        $conn->send('-1|'.$data['raw']);
                    }
                    break;
                case 'reg': //worker 登记
                    if (empty($data['uniqid'])) {
                        return;
                    }
                    $ipcWorker->channels[$data['uniqid']] = $connection;
                    $connection->chainUniqid = $data['uniqid'];
                    $chainData = self::uniqIdToWorker($data['uniqid']);
                    if ($chainData) {
                        Worker::safeEcho('ipc reg work_port:'.$chainData['work_port'].' worker_id:'.$chainData['fd_id'].PHP_EOL);
                    }
                    break;
                case 'bind': //业务键绑定连接 {key, fd}
                    $key = (string)($data['key'] ?? '');
                    $fd  = (string)($data['fd']  ?? '');
                    if ($key === '' || !self::uniqIdToClient($fd)) {
                        return;
                    }
                    if (!isset($ipcWorker->bindings[$key])) {
                        $ipcWorker->bindings[$key] = [];
                    }
                    if (!in_array($fd, $ipcWorker->bindings[$key], true)) {
                        $ipcWorker->bindings[$key][] = $fd;
                    }
                    $ipcWorker->fdKeys[$fd][$key] = 1;
                    break;
                case 'unbind': //解绑 {key, fd?}; 不带 fd 则移除整个 key
                    $key = (string)($data['key'] ?? '');
                    if ($key === '' || !isset($ipcWorker->bindings[$key])) {
                        return;
                    }
                    $fd = (string)($data['fd'] ?? '');
                    if ($fd !== '') {
                        $ipcWorker->bindings[$key] = array_values(array_filter(
                            $ipcWorker->bindings[$key],
                            static fn($v) => $v !== $fd
                        ));
                        if (empty($ipcWorker->bindings[$key])) {
                            unset($ipcWorker->bindings[$key]);
                        }
                        if (isset($ipcWorker->fdKeys[$fd][$key])) {
                            unset($ipcWorker->fdKeys[$fd][$key]);
                            if (empty($ipcWorker->fdKeys[$fd])) {
                                unset($ipcWorker->fdKeys[$fd]);
                            }
                        }
                    } else {
                        foreach ($ipcWorker->bindings[$key] as $fdv) {
                            if (isset($ipcWorker->fdKeys[$fdv][$key])) {
                                unset($ipcWorker->fdKeys[$fdv][$key]);
                                if (empty($ipcWorker->fdKeys[$fdv])) {
                                    unset($ipcWorker->fdKeys[$fdv]);
                                }
                            }
                        }
                        unset($ipcWorker->bindings[$key]);
                    }
                    break;
                case 'unbindFd': //客户端断开时一次性清理该 fd 的所有绑定 {fd}
                    $fd = (string)($data['fd'] ?? '');
                    if ($fd !== '') {
                        $dropFd($fd);
                    }
                    break;
                case 'sendKey': //按业务键投递 {key, raw}
                    $key = (string)($data['key'] ?? '');
                    if ($key === '' || empty($ipcWorker->bindings[$key])) {
                        return;
                    }
                    foreach ($ipcWorker->bindings[$key] as $fd) {
                        $client = self::uniqIdToClient($fd);
                        if (!$client) {
                            continue;
                        }
                        $uniqid = self::workerToUniqId($client['work_port'], $client['worker_id']);
                        if (isset($ipcWorker->channels[$uniqid])) {
                            $ipcWorker->channels[$uniqid]->send($client['fd_id'].'|'.$data['raw']);
                        }
                    }
                    break;
            }
        };
        $ipcWorker->onClose = function ($connection) use ($ipcWorker, $dropFd) {
            if (empty($connection->chainUniqid)) {
                return;
            }
            $workerUniqid = $connection->chainUniqid;
            unset($ipcWorker->channels[$workerUniqid]);
            //按 fdKeys 反向索引,清理所有属于该崩溃 worker 的绑定
            foreach (array_keys($ipcWorker->fdKeys) as $fd) {
                $client = self::uniqIdToClient($fd);
                if (!$client) {
                    $dropFd($fd);
                    continue;
                }
                if (self::workerToUniqId($client['work_port'], $client['worker_id']) === $workerUniqid) {
                    $dropFd($fd);
                }
            }
        };
        self::$ipcWorker = $ipcWorker;
    }
    /** 通过 chain 通道把数据转发到目标 worker(或群发)
     * @param Worker2 $worker 当前 worker(保留参数用于将来扩展/日志)
     * @param int|string $fd -1 表示群发,否则为 16 字符全局 hex fd
     * @param mixed $data 原始业务数据
     */
    public static function chainTo(Worker2 $worker, $fd, $data): bool
    {
        if (!self::$remoteConnection) {
            Worker::safeEcho('chain not ready, drop send fd='.var_export($fd, true).PHP_EOL);
            return false;
        }
        $payload = [
            'a'   => ($fd === -1 || $fd === '-1') ? 'all' : 'to',
            'fd'  => $fd,
            'raw' => $data,
        ];
        return false !== self::$remoteConnection->send(serialize($payload));
    }

    /** 向 chain 服务端发送一条指令(bind/unbind/unbindFd/sendKey 等) */
    protected static function chainCmd(array $payload): bool
    {
        if (!self::$remoteConnection) {
            Worker::safeEcho('chain not ready, drop cmd='.($payload['a'] ?? '').PHP_EOL);
            return false;
        }
        return false !== self::$remoteConnection->send(serialize($payload));
    }

    /** 绑定业务键到连接(跨进程缓存于 chain 服务端内存)
     * 典型用法:登录成功后 $serv->bind('user_'.$uid, $connection)
     * 同一 key 可绑定多个连接(多端登录),sendByKey 将群发到所有绑定
     */
    public function bind(string $key, TcpConnection $connection): bool
    {
        return self::chainCmd([
            'a'   => 'bind',
            'key' => $key,
            'fd'  => self::getUniqId($connection),
        ]);
    }

    /** 解绑业务键
     * @param string $key
     * @param TcpConnection|null $connection 为 null 时解绑该 key 的全部连接
     */
    public function unbind(string $key, ?TcpConnection $connection = null): bool
    {
        $payload = ['a' => 'unbind', 'key' => $key];
        if ($connection) {
            $payload['fd'] = self::getUniqId($connection);
        }
        return self::chainCmd($payload);
    }

    /** 一次性清理某个连接的全部绑定(在用户 onClose 中调用) */
    public function unbindFd(TcpConnection $connection): bool
    {
        return self::chainCmd([
            'a'  => 'unbindFd',
            'fd' => self::getUniqId($connection),
        ]);
    }

    /** 按业务键跨进程投递数据 */
    public function sendByKey(string $key, $data): bool
    {
        return self::chainCmd([
            'a'   => 'sendKey',
            'key' => $key,
            'raw' => $data,
        ]);
    }
    /**
     * 通讯地址到 uniqid 的转换
     *
     * @param int $worker_id
     * @param int $work_port
     * @param int $fd_id
     * @return string
     */
    public static function clientToUniqId(int $worker_id, int $work_port, int $fd_id): string
    {
        return bin2hex(pack('nnN', $worker_id, $work_port, $fd_id));
    }

    /** 为一个连接生成跨进程唯一的全局 fd(16 字符 hex)
     * 用户代码在 onConnect/onMessage 里调用,把返回值作为稳定 id 存到 redis/内存,
     * 之后在任意 worker 进程都能用它 send(),实现 Swoole 风格的跨进程发送。
     */
    public static function getUniqId(TcpConnection $connection): string
    {
        $worker = $connection->worker;
        return self::clientToUniqId($worker->id ?? 0, $worker->port ?? 0, $connection->id);
    }

    /**
     * uniqid 到通讯地址的转换
     *
     * @param string $uniqid
     * @return array
     * @throws Exception
     */
    public static function uniqIdToClient(string $uniqid): array
    {
        if (strlen($uniqid) !== 16) {
            echo new Exception("uniqid $uniqid is invalid");
            return [];
        }
        return unpack('nworker_id/nwork_port/Nfd_id', pack('H*', $uniqid));
    }
    /**
     * worker到 uniqid 的转换
     *
     * @param int $work_port
     * @param int $fd_id
     * @return string
     */
    public static function workerToUniqId($work_port, int $fd_id): string
    {
        return bin2hex(pack('nN', $work_port, $fd_id));
    }

    /**
     * uniqid 到worker的转换
     *
     * @param string $uniqid
     * @return array
     * @throws Exception
     */
    public static function uniqIdToWorker(string $uniqid): array
    {
        if (strlen($uniqid) !== 12) {
            echo new Exception("uniqid $uniqid is invalid");
            return [];
        }
        return unpack('nwork_port/Nfd_id', pack('H*', $uniqid));
    }
    public function workerId()
    {
        return $this->server->id;
    }
    public function task($data)
    {
        $fp = stream_socket_client("tcp://" . self::$taskAddr, $errno, $err_msg, 1);
        if (!$fp) {
            self::err(date('Y-m-d H:i:s ') . $err_msg . ' (' . $errno . ')');
            return false;
        }
        //stream_set_blocking($fp, false); //非阻塞模式
        $worker_id = $this->server->id;
        $taskId = (int)substr(fread($fp, 10), 4);
        $send_data = serialize($data);
        $len = 4 + 2 + strlen($send_data);
        $send_data = pack('N', $len) . pack('n', $worker_id) . $send_data;
        if (!fwrite($fp, $send_data, $len)) {
            $taskId = false;
        }
        fclose($fp);
        return $taskId;
    }
    /** 向指定连接发送数据
     * @param int|string $fd
     *   - 16 字符全局 hex fd: 可跨进程,由 getUniqId() 生成,send 本地命中直发,否则走 chain 转发
     *   - -1: 广播到所有进程的所有连接(需要 chain 通道)
     *   - int: 当前进程本地连接 id(不具备跨进程能力,仅作后向兼容)
     */
    public function send($fd, $data)
    {
        //广播
        if ($fd === -1 || $fd === '-1') {
            if (self::$remoteConnection) {
                return self::chainTo($this->server, -1, $data);
            }
            //chain 未启用:降级为当前进程广播
            foreach ($this->server->connections as $conn) {
                $conn->send($data);
            }
            return true;
        }
        //全局 hex fd
        if (is_string($fd) && strlen($fd) === 16 && ctype_xdigit($fd)) {
            $connection = $this->getConnection($fd);
            if ($connection) {
                return false !== $connection->send($data);
            }
            return self::chainTo($this->server, $fd, $data);
        }
        //本进程整数 fd
        $fdInt = (int)$fd;
        $connection = $this->server->connections[$fdInt] ?? null;
        if (!$connection && !empty($this->childSrv)) {
            foreach ($this->childSrv as $csrv) {
                if (isset($csrv->connections[$fdInt])) {
                    $connection = $csrv->connections[$fdInt];
                    break;
                }
            }
        }
        return $connection && false !== $connection->send($data);
    }
    public function close($fd)
    {
        $connection = $this->getConnection($fd);
        if ($connection) {
            return $connection->close();
        }
        return false;
    }

    /** 获取客户端信息
     * @param int $fd
     * @return array|null
     */
    public function clientInfo(int $fd): ?array
    {
        $connection = $this->server->connections[$fd] ?? null;
        if ($connection) {
            return [
                'remote_ip' => $connection->getRemoteIp(),
                'remote_port' => $connection->getRemotePort(),
                'server_port' => $connection->worker->port,
            ];
        }
        return null;
    }

    public function getHeader($req)
    {
        return is_array($req) ? $req['header'] : $req->header();
    }
    public function getRawBody($req)
    {
        return is_array($req) ? $req['rawbody'] : $req->rawBody();
    }

    final public function exec()
    {
        foreach ($this->childSrv as $childSrv) { //继承主服务
            if (!$childSrv->onConnect) {
                $childSrv->onConnect = $this->server->onConnect;
            }
            if (!$childSrv->onMessage) {
                $childSrv->onMessage = $this->server->onMessage;
            }
            if (!$childSrv->onClose) {
                $childSrv->onClose = $this->server->onClose;
            }
        }

        Worker::runAll();
    }
    final public function getConnection($fd) //仅读取主要服务用于发送消息
    {
        if (self::$fdConnection) {
            return self::$fdConnection;
        }
        $uniqid = '';
        $client = self::uniqIdToClient($fd);
        if ($client) {
            $uniqid = self::workerToUniqId($client['work_port'], $client['worker_id']);
            $fd = $client['fd_id'];
        }
        return isset(self::$workers[$uniqid]) ? self::$workers[$uniqid]->connections[$fd] : null;
    }
    //reload -g会等所有客户端连接断开后重启 stop -g会等所有客户端连接断开后关闭

    final public function reLog()
    {
        Worker::$logFile && file_put_contents(Worker::$logFile, '', LOCK_EX);

        self::safeEcho('[' . Worker::$logFile . '] relog ok!' . PHP_EOL);
    }

    public function run(&$argv)
    {
        $action = ''; //$action = isset($argv[1]) ? $argv[1] : 'start';
        $allow_action = ['relog', 'reload', 'stop', 'restart', 'status', 'start'];
        foreach ($argv as $value) {
            if (in_array($value, $allow_action)) {
                $action = $value;
                break;
            }
        }
        self::$isConsole = (bool)array_search('--console', $argv);
        Worker::$daemonize = !self::$isConsole; //守护进程化;
        if ($action == '' || $action == '--console') {
            $action = 'start';
            $argv[1] = $action; //置启动参数
        }
        if ($action == 'reload') {
            file_put_contents($this->runLock, 1); #用于判定重载onStart处理
        } elseif ($action == 'start' || $action == 'restart') {
            file_put_contents($this->runLock, 0);
        }
        switch ($action) {
            case 'relog':
                $this->reLog();
                break;
            default:
                $this->start();
        }
    }
}
