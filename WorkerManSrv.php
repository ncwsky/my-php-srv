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
    public array $uniqidFds;

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
    public static $taskWorker = null;
    public static $taskAddr = '';
    public static $ipcWorker = null;
    public static $ipcSocketFile = '';

    // chain 二进制协议: worker → ipc
    const CH_REG        = 0x01; // [1][6B uniqidBin]
    const CH_TO         = 0x02; // [1][8B globalFdBin][raw]
    const CH_ALL        = 0x03; // [1][raw]
    const CH_BIND       = 0x04; // [1][2B keyLen][key][8B globalFdBin]
    const CH_UNBIND     = 0x05; // [1][2B keyLen][key][8B globalFdBin]
    const CH_UNBIND_KEY = 0x06; // [1][2B keyLen][key]
    const CH_UNBIND_FD  = 0x07; // [1][8B globalFdBin]
    const CH_SEND_KEY   = 0x08; // [1][2B keyLen][key][raw]
    // chain 二进制协议: ipc → worker
    const CH_DELIVER    = 0x10; // [1][4B fdId][raw]
    const CH_BROAD      = 0x11; // [1][raw]

    /** 断线期间暂存的待发原始 payload */
    public static array $chainPending = [];
    /** remoteConnection 是否已连通 */
    public static bool $chainConnected = false;
    /** 断线时 pending 队列容量上限,超限丢弃最早条目 */
    public static int $chainPendingMax = 10000;

    /**
     * @var Worker2
     */
    public Worker2 $server; //服务实例

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
        $this->ipcToConnection($worker);

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

    public static $ipcConnection = null;
    //连接到内部通信服务
    protected function ipcToConnection(Worker2 $worker)
    {
        if (!$this->needChain() || !self::$ipcSocketFile) {
            return;
        }
        //chain 服务自身进程无需再作为客户端接入
        if (self::$ipcWorker && $worker === self::$ipcWorker) {
            return;
        }
        //task 进程不接入 chain(其连接为 task 提交而非用户连接,避免被误广播)
        if (!empty($worker->isTask)) {
            return;
        }

        //本进程 worker 登记(按 port+worker_id 唯一)
        $innerIdHex = self::workerToInnerId($worker->port, $worker->id);
        $innerIdBin = hex2bin($innerIdHex);

        $conn = new \Workerman\Connection\AsyncTcpConnection('unix://' . self::$ipcSocketFile);
        $conn->protocol = '\Workerman\Protocols\Frame';
        $conn->onConnect = function ($connection) use ($innerIdBin) {
            self::$chainConnected = true;
            //REG 必须是连上后的第一条
            $connection->send(chr(self::CH_REG) . $innerIdBin);
            //断线期间暂存的消息补发
            if (self::$chainPending) {
                foreach (self::$chainPending as $p) {
                    $connection->send($p);
                }
                self::$chainPending = [];
            }
        };
        $conn->onMessage = function ($connection, $data) use ($worker) {
            if ($data === '' || !isset($data[0])) {
                return;
            }
            $cmd = ord($data[0]);
            if ($cmd === self::CH_DELIVER) {
                //[1][4B fdId][raw]
                if (strlen($data) < 5) {
                    return;
                }
                $fdId = unpack('N', substr($data, 1, 4))[1];
                $raw  = substr($data, 5);
                if (isset($worker->connections[$fdId])) {
                    $worker->connections[$fdId]->send($raw);
                }
            } elseif ($cmd === self::CH_BROAD) {
                //[1][raw]
                $raw = substr($data, 1);
                foreach ($worker->connections as $c) {
                    $c->send($raw);
                }
            }
        };
        $conn->onError = function ($connection, $code, $msg) {
            self::$chainConnected = false;
            Worker::safeEcho('chain err code='.$code.' msg='.$msg.PHP_EOL);
        };
        $conn->onClose = function (\Workerman\Connection\AsyncTcpConnection $connection) {
            self::$chainConnected = false;
            //chain 断开 1 秒后重连,重连成功会自动重新 REG 并 flush pending
            $connection->reConnect(1);
        };
        self::$ipcConnection = $conn;
        $conn->connect();
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
        $this->ipcToConnection($worker);

        $this->initLoadPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER
        #Worker::safeEcho("childWorker init myphp:".$worker->id.PHP_EOL);
    }

    //全局 fd 布局: [wid:n=2][port:n=2][fdid:N=4]  workerUniqid 布局: [port:n=2][wid:N=4]
    public static function fdToUniqid(string $fdBin): string
    {
        //拼接: port(2) + wid 前缀补零(2) + wid(2) = port(2) + 4字节N(wid 放低位)
        return substr($fdBin, 2, 2) . "\x00\x00" . substr($fdBin, 0, 2);
    }

    /**
     * ipc内部通信服务
     * 各个worker进程之间通过 ipc 通信
     * @return void
     */
    public function ipcWorker()
    {
        self::$ipcSocketFile = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . '_ipc.sock';
        if (file_exists(self::$ipcSocketFile) && file_exists($this->runLock) && @file_get_contents($this->runLock) === '0') {
            @unlink(self::$ipcSocketFile);
        }

        $ipcWorker = new Worker2('unix://'.self::$ipcSocketFile);
        $ipcWorker->user = $this->getConfig('setting.user', '');
        $ipcWorker->name = $this->serverName().'_chain';
        $ipcWorker->count = 1;
        $ipcWorker->reloadable = false;
        $ipcWorker->protocol = '\Workerman\Protocols\Frame';
        //全部键使用 raw binary,避免每条消息反复 hex 转换
        $ipcWorker->channels  = []; //binWorkerUniqid(6B) => connection
        $ipcWorker->bindings  = []; //key => [binGlobalFd(8B) => 1]
        $ipcWorker->fdKeys    = []; //binGlobalFd => [key => 1]
        $ipcWorker->uniqidFds = []; //binWorkerUniqid => [binGlobalFd => 1] 反向索引,供断连 O(1) 清理

        //删除一个 fd 的全部绑定(并同步 uniqidFds)
        $dropFd = function (string $fdBin, ?string $uniqidBin) use ($ipcWorker) {
            if (isset($ipcWorker->fdKeys[$fdBin])) {
                foreach (array_keys($ipcWorker->fdKeys[$fdBin]) as $key) {
                    if (isset($ipcWorker->bindings[$key][$fdBin])) {
                        unset($ipcWorker->bindings[$key][$fdBin]);
                        if (empty($ipcWorker->bindings[$key])) {
                            unset($ipcWorker->bindings[$key]);
                        }
                    }
                }
                unset($ipcWorker->fdKeys[$fdBin]);
            }
            if ($uniqidBin !== null && isset($ipcWorker->uniqidFds[$uniqidBin][$fdBin])) {
                unset($ipcWorker->uniqidFds[$uniqidBin][$fdBin]);
                if (empty($ipcWorker->uniqidFds[$uniqidBin])) {
                    unset($ipcWorker->uniqidFds[$uniqidBin]);
                }
            }
        };
        //从单个 key 中移除某个 fd
        $dropFdFromKey = function (string $key, string $fdBin) use ($ipcWorker) {
            if (isset($ipcWorker->bindings[$key][$fdBin])) {
                unset($ipcWorker->bindings[$key][$fdBin]);
                if (empty($ipcWorker->bindings[$key])) {
                    unset($ipcWorker->bindings[$key]);
                }
            }
            if (isset($ipcWorker->fdKeys[$fdBin][$key])) {
                unset($ipcWorker->fdKeys[$fdBin][$key]);
                if (empty($ipcWorker->fdKeys[$fdBin])) {
                    unset($ipcWorker->fdKeys[$fdBin]);
                }
            }
        };

        $ipcWorker->onMessage = function ($connection, $data) use ($ipcWorker, $dropFd, $dropFdFromKey) {
            if ($data === '' || !isset($data[0])) {
                return;
            }
            $cmd = ord($data[0]);
            $len = strlen($data);
            switch ($cmd) {
                case self::CH_REG: // [1][6B uniqidBin]
                    if ($len !== 7) {
                        return;
                    }
                    $uniqidBin = substr($data, 1, 6);
                    //旧 channel 残留清理: 同 uniqid 在老连接未彻底 onClose 前又注册新连接
                    if (isset($ipcWorker->channels[$uniqidBin]) && $ipcWorker->channels[$uniqidBin] !== $connection) {
                        $old = $ipcWorker->channels[$uniqidBin];
                        unset($old->chainUniqid); //防止老连接 onClose 误删新注册
                        try {
                            $old->close();
                        } catch (\Throwable $e) {
                        }
                    }
                    $ipcWorker->channels[$uniqidBin] = $connection;
                    $connection->chainUniqid = $uniqidBin;
                    return;

                case self::CH_TO: // [1][8B fdBin][raw]
                    if ($len < 9) {
                        return;
                    }
                    $fdBin = substr($data, 1, 8);
                    $raw   = substr($data, 9);
                    $uniqidBin = self::fdToUniqid($fdBin);
                    if (!isset($ipcWorker->channels[$uniqidBin])) {
                        return;
                    }
                    //向目标 worker 发送 DELIVER: [0x10][4B fdId][raw]
                    $ipcWorker->channels[$uniqidBin]->send(
                        chr(self::CH_DELIVER) . substr($fdBin, 4, 4) . $raw
                    );
                    return;

                case self::CH_ALL: // [1][raw]
                    $out = chr(self::CH_BROAD) . substr($data, 1);
                    foreach ($ipcWorker->channels as $conn) {
                        $conn->send($out);
                    }
                    return;

                case self::CH_BIND: // [1][2B keyLen][key][8B fdBin]
                    if ($len < 11) {
                        return;
                    }
                    $keyLen = unpack('n', substr($data, 1, 2))[1];
                    if ($len !== 3 + $keyLen + 8) {
                        return;
                    }
                    $key   = substr($data, 3, $keyLen);
                    $fdBin = substr($data, 3 + $keyLen, 8);
                    $uniqidBin = self::fdToUniqid($fdBin);
                    //目标 worker 未注册时丢弃
                    if (!isset($ipcWorker->channels[$uniqidBin])) {
                        return;
                    }
                    $ipcWorker->bindings[$key][$fdBin] = 1;
                    $ipcWorker->fdKeys[$fdBin][$key] = 1;
                    $ipcWorker->uniqidFds[$uniqidBin][$fdBin] = 1;
                    return;

                case self::CH_UNBIND: // [1][2B keyLen][key][8B fdBin]
                    if ($len < 11) {
                        return;
                    }
                    $keyLen = unpack('n', substr($data, 1, 2))[1];
                    if ($len !== 3 + $keyLen + 8) {
                        return;
                    }
                    $key   = substr($data, 3, $keyLen);
                    $fdBin = substr($data, 3 + $keyLen, 8);
                    $dropFdFromKey($key, $fdBin);
                    return;

                case self::CH_UNBIND_KEY: // [1][2B keyLen][key]
                    if ($len < 3) {
                        return;
                    }
                    $keyLen = unpack('n', substr($data, 1, 2))[1];
                    if ($len !== 3 + $keyLen) {
                        return;
                    }
                    $key = substr($data, 3, $keyLen);
                    if (isset($ipcWorker->bindings[$key])) {
                        foreach (array_keys($ipcWorker->bindings[$key]) as $fdBin) {
                            if (isset($ipcWorker->fdKeys[$fdBin][$key])) {
                                unset($ipcWorker->fdKeys[$fdBin][$key]);
                                if (empty($ipcWorker->fdKeys[$fdBin])) {
                                    unset($ipcWorker->fdKeys[$fdBin]);
                                }
                            }
                        }
                        unset($ipcWorker->bindings[$key]);
                    }
                    return;

                case self::CH_UNBIND_FD: // [1][8B fdBin]
                    if ($len !== 9) {
                        return;
                    }
                    $fdBin = substr($data, 1, 8);
                    $dropFd($fdBin, self::fdToUniqid($fdBin));
                    return;

                case self::CH_SEND_KEY: // [1][2B keyLen][key][raw]
                    if ($len < 3) {
                        return;
                    }
                    $keyLen = unpack('n', substr($data, 1, 2))[1];
                    if ($len < 3 + $keyLen) {
                        return;
                    }
                    $key = substr($data, 3, $keyLen);
                    $raw = substr($data, 3 + $keyLen);
                    if (empty($ipcWorker->bindings[$key])) {
                        return;
                    }
                    foreach (array_keys($ipcWorker->bindings[$key]) as $fdBin) {
                        $uniqidBin = self::fdToUniqid($fdBin);
                        if (!isset($ipcWorker->channels[$uniqidBin])) {
                            continue;
                        }
                        $ipcWorker->channels[$uniqidBin]->send(
                            chr(self::CH_DELIVER) . substr($fdBin, 4, 4) . $raw
                        );
                    }
                    return;
            }
        };
        $ipcWorker->onClose = function ($connection) use ($ipcWorker, $dropFd) {
            if (empty($connection->chainUniqid)) {
                return;
            }
            $uniqidBin = $connection->chainUniqid;
            //仅当该 connection 仍是当前 channel 才清理(reg 覆盖后的老连接关闭不影响新注册)
            if (isset($ipcWorker->channels[$uniqidBin]) && $ipcWorker->channels[$uniqidBin] === $connection) {
                unset($ipcWorker->channels[$uniqidBin]);
            }
            //反向索引 O(1) 清理该 worker 所有 fd 绑定
            if (isset($ipcWorker->uniqidFds[$uniqidBin])) {
                foreach (array_keys($ipcWorker->uniqidFds[$uniqidBin]) as $fdBin) {
                    $dropFd($fdBin, null);
                }
                unset($ipcWorker->uniqidFds[$uniqidBin]);
            }
        };
        self::$ipcWorker = $ipcWorker;
    }
    /** 通过 chain 通道把数据转发到目标 worker(或群发)
     * @param Worker2 $worker 当前 worker(保留参数用于将来扩展/日志)
     * @param string $fd -1 表示群发,否则为 16 字符全局 hex fd
     * @param mixed $data 原始业务数据
     */
    public static function ipcTo(Worker2 $worker, $fd, $data): bool
    {
        if ($fd === '-1') {
            return self::ipcRawSend(chr(self::CH_ALL) . $data);
        }
        if (!is_string($fd) || strlen($fd) !== 16) {
            Worker::safeEcho('ipcTo invalid fd='.var_export($fd, true).PHP_EOL);
            return false;
        }
        return self::ipcRawSend(chr(self::CH_TO) . hex2bin($fd) . $data);
    }

    /** 把原始二进制 payload 发到 chain; 连接未就绪时进入 pending,连上后 flush */
    protected static function ipcRawSend(string $payload): bool
    {
        if (!self::$ipcConnection) {
            Worker::safeEcho('chain not init, drop '.strlen($payload).'B'.PHP_EOL);
            return false;
        }
        if (!self::$chainConnected) {
            if (count(self::$chainPending) >= self::$chainPendingMax) {
                array_shift(self::$chainPending);
            }
            self::$chainPending[] = $payload;
            return true;
        }
        return false !== self::$ipcConnection->send($payload);
    }

    /** 绑定业务键到连接(跨进程缓存于 chain 服务端内存)
     * 典型用法:登录成功后 $serv->bind('user_'.$uid, $connection)
     * 同一 key 可绑定多个连接(多端登录),sendByKey 将群发到所有绑定
     */
    public function bind(string $key, TcpConnection $connection): bool
    {
        $fdHex = self::getUniqId($connection);
        return self::ipcRawSend(
            chr(self::CH_BIND) . pack('n', strlen($key)) . $key . hex2bin($fdHex)
        );
    }

    /** 解绑业务键
     * @param string $key
     * @param TcpConnection|null $connection 为 null 时解绑该 key 的全部连接
     * @return bool
     */
    public function unbind(string $key, ?TcpConnection $connection = null): bool
    {
        if ($connection) {
            $fdHex = self::getUniqId($connection);
            return self::ipcRawSend(
                chr(self::CH_UNBIND) . pack('n', strlen($key)) . $key . hex2bin($fdHex)
            );
        }
        return self::ipcRawSend(chr(self::CH_UNBIND_KEY) . pack('n', strlen($key)) . $key);
    }

    /** 一次性清理某个连接的全部绑定(在用户 onClose 中调用) */
    public function unbindFd(TcpConnection $connection): bool
    {
        $fdHex = self::getUniqId($connection);
        return self::ipcRawSend(chr(self::CH_UNBIND_FD) . hex2bin($fdHex));
    }

    /** 按业务键跨进程投递数据 */
    public function sendByKey(string $key, $data): bool
    {
        return self::ipcRawSend(
            chr(self::CH_SEND_KEY) . pack('n', strlen($key)) . $key . (string)$data
        );
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
            return [];
        }
        return unpack('nworker_id/nwork_port/Nfd_id', pack('H*', $uniqid));
    }
    /**
     * worker转换内部通信id
     *
     * @param int $worker_port
     * @param int $worker_id
     * @return string
     */
    public static function workerToInnerId(int $worker_port, int $worker_id): string
    {
        return bin2hex(pack('nN', $worker_port, $worker_id));
    }

    /**
     * 内部通信id转换worker
     *
     * @param string $uniqid
     * @return array
     * @throws Exception
     */
    public static function innerIdToWorker(string $uniqid): array
    {
        if (strlen($uniqid) !== 12) {
            return [];
        }
        return unpack('nworker_port/Nworker_id', pack('H*', $uniqid));
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
        //本进程命中直发
        if (is_int($fd)) { //当前进程fd
            $connection = $this->server->connections[$fd] ?? null;
        }
        if ($connection) {
            return false !== $connection->send($data);
        }
        //全局 hex fd 本进程未命中 → chain 转发到持有该连接的 worker
        if (is_string($fd) && strlen($fd) === 16) {
            return self::ipcTo($this->server, $fd, $data);
        }
        return false;
    }
    public function close($fd)
    {
        //本进程命中直发
        if (is_int($fd)) {
            $connection = $this->server->connections[$fd] ?? null;
            if ($connection) {
                return $connection->close();
            }
        } else { //内部通信转发
            //todo 
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
                'server_port' => $connection->worker->port ?? $this->port,
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
    /** 按 fd 取本进程内的 TcpConnection;
     * @param int $fd
     * @return TcpConnection|null
     */
    final public function getConnection(int $fd): ?TcpConnection
    {
        return $this->server->connections[$fd] ?? null;
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
