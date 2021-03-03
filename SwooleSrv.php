<?php
/**
 * Class SwooleSrv
 * @method onManagerStart(swoole_server $server) //管理进程回调 有配置才会运行
 * @method onManagerStop(swoole_server $server) //结束管理进程回调 有配置才会运行
 */
class SwooleSrv extends SrvBase {
    protected $mode; //运行模式 单线程模式（SWOOLE_BASE）| 进程模式（SWOOLE_PROCESS）[默认]
    /**
     * SwooleSrv constructor.
     * @param array $config
     */
	public function __construct($config)
    {
        parent::__construct($config);
        $this->mode = SWOOLE_PROCESS;
    }
    //此事件在Server正常结束时发生
    public function onShutdown(swoole_server $server){
        echo $this->serverName().' shutdown '.date("Y-m-d H:i:s"). PHP_EOL;
    }
    //管理进程 这里载入了php会造成与worker进程里代码冲突
    public function _onManagerStart(swoole_server $server){
        $this->setProcessTitle($this->serverName() . '-manager');

        echo $this->serverName().' ver'.$this->getConfig('ver','1.0.0') .', swoole'. SWOOLE_VERSION .' start '.date("Y-m-d H:i:s"). PHP_EOL;
        echo $this->address,PHP_EOL;
        echo 'master pid:' . $server->master_pid . PHP_EOL;
        echo 'manager pid:' . $server->manager_pid . PHP_EOL;
        echo 'run dir:'. $this->runDir . PHP_EOL;

        if(method_exists($this, 'onManagerStart')){
            $this->onManagerStart($server);
        }
    }
    //当管理进程结束时调用它
    public function _onManagerStop(swoole_server $server){
        echo 'manager pid:' . $server->manager_pid . ' end' . PHP_EOL;

        if(method_exists($this, 'onManagerStop')){
            $this->onManagerStop($server);
        }
        if(method_exists($this, 'onStop')){
            !$this->hasInitMyPhp && $this->initMyPhp();
            $this->onStop();
        }
        SrvTimer::destroy();
    }
    /** 此事件在Worker进程/Task进程启动时发生 这里创建的对象可以在进程生命周期内使用 如mysql/redis...
     * @param swoole_server $server
     * @param int $worker_id [0-$worker_num)区间内的数字
     * @return bool
     */
    final public function _onWorkerStart(swoole_server $server, int $worker_id){
        $this->initMyPhp();
        if (!$server->taskworker) { //worker进程
            #Config::set('APP_ROOT', dirname($_SERVER['SCRIPT_NAME'])); //重置app_root目录
            #echo "init myphp:".$worker_id, PHP_EOL;
            if($worker_id==0 && self::$isConsole) Log::write($_SERVER, 'server');
            myphp::Run(function($code, $data, $header) use($worker_id){
                #echo "init myphp:".$worker_id, PHP_EOL;
            }, false);
            if($this->getConfig('timer_file')){
                //定时载入
                $timer = new SwooleTimer();
                $timer->start($worker_id);
            }
        } else { //task进程
            //echo 'task worker:'.$worker_id.PHP_EOL;
        }

        if ($worker_id >= $server->setting['worker_num']) {
            swoole_set_process_name($this->serverName()."-{$worker_id}-Task");
        } else {
            $this->setProcessTitle($this->serverName()."-{$worker_id}-Worker");
        }

        $this->onWorkerStart($server, $worker_id);
    }
    //此事件在Worker进程终止时发生 在此函数中可以回收Worker进程申请的各类资源
    final public function _onWorkerStop(swoole_server $server, int $worker_id){
        if (!$server->taskworker) { //worker进程  异常结束后执行的逻辑
            echo 'Worker Stop clear' . PHP_EOL;
            $timer = new SwooleTimer();
            $timer->stop($worker_id);
        }
        $this->onWorkerStop($server, $worker_id);
    }
    //仅在开启reload_async特性后有效。异步重启特性，会先创建新的Worker进程处理新请求，旧的Worker进程自行退出。
    //https://wiki.swoole.com/wiki/page/808.html
    public function onWorkerExit(swoole_server $server, int $worker_id){
        //todo
        /*if($timers = (new SwooleTimer())->timer()) { //直接读取配置文件
            foreach ($timers as $item){ #清除当前工作进程内的所有定时器
                if($item['worker_id']==$worker_id) swoole_timer_clear($item['timerid']);
            }
        }*/
    }
    //此函数主要用于报警和监控，一旦发现Worker进程异常退出，那么很有可能是遇到了致命错误或者进程CoreDump。通过记录日志或者发送报警的信息来提示开发者进行相应的处理
    /** 当Worker/Task进程发生异常后会在Manager进程内回调此函数
     * @param swoole_server $server
     * @param int $worker_id 是异常进程的编号
     * @param int $worker_pid 是异常进程的ID
     * @param int $exit_code 退出的状态码，范围是 0～255
     * @param int $signal 进程退出的信号
     */
    final public function _onWorkerError(swoole_server $server, int $worker_id, int $worker_pid, int $exit_code, int $signal){
        $err = '异常进程的编号:'.$worker_id.', 异常进程的ID:'.$worker_pid.', 退出的状态码:'.$exit_code.', 进程退出信号:'.$signal;
        echo $err,PHP_EOL;
        //todo 记录日志或者发送报警的信息来提示开发者进行相应的处理
        self::err($err);
        $this->onWorkerError($server, $worker_id, $err);
    }
    //初始服务
    final public function init(){
        $this->config['setting']['daemonize'] = self::$isConsole ? 0 : 1; //守护进程化;
        $sockType = SWOOLE_SOCK_TCP; //todo ipv6待测试后加入
        $isSSL = isset($this->config['setting']['ssl_cert_file']); //是否使用的证书
        if($isSSL){
            $sockType =  SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }
        //监听1024以下的端口需要root权限
        switch ($this->getConfig('type')){
            case self::TYPE_HTTP:
                $this->server = new swoole_http_server($this->ip, $this->port, $this->mode, $sockType);
                $this->address = self::TYPE_HTTP;
                break;
            case self::TYPE_WEB_SOCKET:
                $this->server = new swoole_websocket_server($this->ip, $this->port, $this->mode, $sockType);
                $this->address = self::TYPE_WEB_SOCKET;
                break;
            case self::TYPE_UDP:
                $this->server = new swoole_server($this->ip, $this->port, $this->mode, $isSSL ? SWOOLE_SOCK_UDP | SWOOLE_SSL : SWOOLE_SOCK_UDP);
                $this->address = self::TYPE_UDP;
                break;
            default:
                $this->server = new swoole_server($this->ip, $this->port, $this->mode, $sockType);
                $this->address = self::TYPE_TCP;
        }
        $this->address .= '://'.$this->ip.':'.$this->port;
        //开启多个监听处理
        $listen = $this->getConfig('listen', []);
        if(is_array($listen) && $listen){
            #未调用set方法，设置协议处理选项的监听端口，默认继承主服务器的设置
            #未调用on方法，设置回调函数的监听端口，默认使用主服务器的回调函数
            //取多协议端口复合监听协议名
            $getTypeName = function($type){
                $ret = [
                    SWOOLE_SOCK_TCP=>self::TYPE_TCP,
                    SWOOLE_SOCK_UDP=>self::TYPE_UDP,
                    SWOOLE_SOCK_TCP6 =>self::TYPE_TCP.'6',
                    SWOOLE_SOCK_UDP6=>self::TYPE_UDP.'6',
                ];
                return isset($ret[$type]) ? $ret[$type] : 'null';
            };
            $port = (int)$this->port;
            foreach ($listen as $k=>$item){
                if(!isset($item['ip'])){ //未设置使用主服务器的
                    $item['ip'] = $this->ip;
                }
                if(!isset($item['port'])){ //未设置使用主服务器的 port+10
                    $item['port'] = ++$port;
                }
                if(!isset($item['type'])) $item['type'] = SWOOLE_SOCK_TCP; // Socket 类型
                if (is_string($item['type'])) $item['type'] = $item['type'] == self::TYPE_UDP ? SWOOLE_SOCK_UDP : SWOOLE_SOCK_TCP;
                //有配置证书
                if(isset($item['setting']['ssl_cert_file'])){
                    $item['type'] =  $item['type'] | SWOOLE_SSL;
                }
                //创建其他监听服务
                $this->childSrv[$k] = $this->server->listen($item['ip'], $item['port'], $item['type']);
                if(isset($item['setting'])){
                    $this->childSrv[$k]->set($item['setting']);
                }
                if(isset($item['event'])){ //有自定义事件
                    foreach ($item['event'] as $event=>$fun){
                        $this->childSrv[$k]->on($event, $fun);
                    }
                }
                $this->address .= '; '.$getTypeName($item['type']).'://'.$item['ip'].':'.$item['port'];
            }
        }
        $server = $this->server;
        //设置服务配置
        $server->set($this->getConfig('setting'));

        //初始事件绑定
        //BASE模式无start事件
        if($this->mode==SWOOLE_PROCESS){
            $server->on('Start', function(swoole_server $server){//回调有错误时 可能不会有主进程
                $this->setProcessTitle($this->serverName().'-master');
                if(method_exists($this, 'onStart')){
                    $this->initMyPhp();
                    $this->onStart();
                }
            });
        }
        $server->on('Shutdown', [$this, 'onShutdown']);
        $server->on('ManagerStart', [$this, '_onManagerStart']);
        $server->on('ManagerStop', [$this, '_onManagerStop']);

        $server->on('WorkerStart', [$this, '_onWorkerStart']);
        $server->on('WorkerStop', [$this, '_onWorkerStop']);
        $server->on('WorkerError', [$this, '_onWorkerError']);
        if($this->getConfig('setting.reload_async', false)) { //异步安全重启特性
            $server->on('WorkerExit', [$this, 'onWorkerExit']);
        }
        //事件
        $server->on('Connect', function (swoole_server $server, int $fd, int $reactorId) {
            return SwooleEvent::OnConnect($server, $fd, $reactorId);
        });
        $server->on('Receive', function (swoole_server $srv, $fd, $reactor_id, $data) {
            return SwooleEvent::OnReceive($srv, $fd, $reactor_id, $data);
        });
        $server->on('Close', function (swoole_server $srv, $fd, $reactorId) {
            return SwooleEvent::OnClose($srv, $fd, $reactorId);
        });

        if ($this->getConfig('setting.task_worker_num', 0)) { //启用了
            $server->on('Task', function (swoole_server $server, int $task_id, int $src_worker_id, $data){
                SwooleEvent::OnTask($server, $task_id, $src_worker_id, $data);
            });
            $server->on('Finish', function (swoole_server $server, int $task_id, string $data){
                SwooleEvent::OnFinish($server, $task_id, $data);
            });
        }
        if($this->getConfig('type')==self::TYPE_HTTP){
            $server->on('Request', function ($request, $response){
                SwooleEvent::OnRequest($request, $response);
            });
        }

        #设置了task_ipc_mode = 3将无法使用sendMessage向特定的task进程发送消息
        $server->on('PipeMessage', function (swoole_server $srv, $task_id, $data) {
            SwooleEvent::OnPipeMessage($srv, $task_id, $data);
        });
        /**
         * 用户进程实现了广播功能，循环接收管道消息，并发给服务器的所有连接
         * https://wiki.swoole.com/wiki/page/390.html 参见示例
         */
        /*
        $server = $this->server;
        $process = new swoole_process(function($process) use ($server) {
            while (true) {
                //todo
            }
        });
        $this->server->addProcess($process);
        */
    }
    public function workerId(){
        return $this->server->worker_id;
    }
    public function task($data){
        return $this->server->task($data);
    }
    public function send($fd, $data){
        if(!$this->server->send($fd, $data)){
            $code = $this->server->getLastError();
            $errCode = [
                1001=>'连接已经被 Server 端关闭了，出现这个错误一般是代码中已经执行了 $server->close() 关闭了某个连接，但仍然调用 $server->send() 向这个连接发送数据',
                1002=>'连接已被 Client 端关闭了，Socket 已关闭无法发送数据到对端',
                1003=>'正在执行 close，onClose 回调函数中不得使用 $server->send()',
                1004=>'连接已关闭',
                1005=>'连接不存在，传入 $fd 可能是错误的',
                1007=>'接收到了超时的数据，TCP 关闭连接后，可能会有部分数据残留在 unixSocket 缓存区内，这部分数据会被丢弃',
                1008=>'发送缓存区已满无法执行 send 操作，出现这个错误表示这个连接的对端无法及时收数据导致发送缓存区已塞满',
                1202=>'发送的数据超过了 server->buffer_output_size 设置',
                9007=>'仅在使用 dispatch_mode=3 时出现，表示当前没有可用的进程，可以调大 worker_num 进程数量',
            ];
            self::err(isset($errCode[$code])?$errCode[$code]:'未知错误码', $code); //错误码 参见https://wiki.swoole.com/wiki/page/554.html
            return false;
        }
        return true;
    }
    public function close($fd){
        return $this->server->close($fd);
    }
    public function clientInfo($fd){
        return $this->server->getClientInfo($fd);
    }
    final public function exec(){
        $this->server->start();
    }
    final public function relog(){
        $logFile = $this->getConfig('setting.log_file', $this->runDir .'/server.log');
        if($logFile) file_put_contents($logFile,'', LOCK_EX);
        if($pid=self::pid()){
            posix_kill($pid, SIGRTMIN); //34
        }
        echo '['.$logFile.'] relog ok!',PHP_EOL;
        return true;
    }
    public function run(&$argv){
        $action = isset($argv[1]) ? $argv[1] : 'start';
        self::$isConsole = array_search('--console', $argv);
        if($action=='--console') $action = 'start';
        switch($action){
            case 'relog':
                $this->relog();
                break;
            case 'reloadTask':
                $this->server->reload(true); //仅重启Task进程
                break;
            case 'reload':
                $this->reload();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'restart':
                $this->stop();
                echo "Start ".$this->serverName(),PHP_EOL;
                $this->start();
                break;
            case 'status':
                $this->status();
                break;
            case 'start':
                if($this->pid()){
                    echo $this->pidFile." exists, ".$this->serverName()." is already running or crashed.",PHP_EOL;
                    exit();
                }else{
                    echo "Start ".$this->serverName(),PHP_EOL;
                }
                $this->start();
                break;
            default:
                echo 'Usage: '. $this->runFile .' {([--console]|start[--console])|stop|restart[--console]|reload|relog|status}',PHP_EOL;
        }
    }
}