<?php
use Swoole\Server;
/**
 * Class SwooleSrv
 * @method onManagerStart(Server $server) //管理进程回调 有配置才会运行
 * @method onManagerStop(Server $server) //结束管理进程回调 有配置才会运行
 */
class SwooleSrv extends SrvBase {
    protected $mode; //运行模式 单线程模式（SWOOLE_BASE）| 进程模式（SWOOLE_PROCESS）[默认]
    /**
     * SwooleSrv constructor.
     * @param array $config
     */
	public function __construct($config, $mode=SWOOLE_PROCESS)
    {
        parent::__construct($config);
        $this->mode = $mode;
        $config = &$this->config;
        //兼容处理
        if (isset($config['setting']['pidFile'])) {
            $config['setting']['pid_file'] = $config['setting']['pidFile'];
            unset($config['setting']['pidFile']);
        }
        if (isset($config['setting']['logFile'])) {
            $config['setting']['log_file'] = $config['setting']['logFile'];
            unset($config['setting']['logFile']);
        }
        if (isset($config['setting']['count'])) {
            $config['setting']['worker_num'] = $config['setting']['count'];
            unset($config['setting']['count']);
        }
        unset($config['setting']['stdoutFile'], $config['setting']['protocol']);
        $this->pidFile = $this->getConfig('setting.pid_file', $this->runDir . '/server.pid');
    }
    //此事件在Server正常结束时发生
    public function onShutdown(Server $server){
        static::safeEcho($this->serverName().' shutdown '.date("Y-m-d H:i:s"). PHP_EOL);
    }
    //管理进程 这里载入了php会造成与worker进程里代码冲突
    public function _onManagerStart(Server $server){
        $this->setProcessTitle($this->serverName() . '-manager');
        static::safeEcho($this->serverName().', swoole'. SWOOLE_VERSION .' start '.date("Y-m-d H:i:s"). PHP_EOL);
        static::safeEcho($this->address.PHP_EOL);
        static::safeEcho('master pid:' . $server->master_pid . PHP_EOL);
        static::safeEcho('manager pid:' . $server->manager_pid . PHP_EOL);
        static::safeEcho('run dir:'. $this->runDir . PHP_EOL);

        if(method_exists($this, 'onManagerStart')){
            $this->onManagerStart($server);
        }
    }
    //当管理进程结束时调用它
    public function _onManagerStop(Server $server){
        static::safeEcho('manager pid:' . $server->manager_pid . ' end' . PHP_EOL);

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
     * @param \Swoole\Server $server
     * @param int $worker_id [0-$worker_num)区间内的数字
     * @return bool
     */
    final public function _onWorkerStart(Server $server, $worker_id){
        $this->initMyPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER

        if (!$server->taskworker) { //worker进程
            if($worker_id==0) {
                self::$isConsole && static::safeEcho("run dir:".$this->runDir.PHP_EOL);
                //if(self::$isConsole) static::safeEcho(json_encode($_SERVER).PHP_EOL);
            }
            if($this->getConfig('timer_file')){
                //定时载入
                $timer = new SwooleTimer();
                $timer->start($worker_id);
            }
        } else { //task进程

        }

        if ($worker_id >= $server->setting['worker_num']) {
            cli_set_process_title($this->serverName()."-{$worker_id}-Task");
        } else {
            $this->setProcessTitle($this->serverName()."-{$worker_id}-Worker");
        }

        $this->onWorkerStart($server, $worker_id);
    }
    //此事件在Worker进程终止时发生 在此函数中可以回收Worker进程申请的各类资源

    /**
     * @param Server $server
     * @param int $worker_id
     */
    final public function _onWorkerStop(Server $server, $worker_id){
        if (!$server->taskworker) { //worker进程  异常结束后执行的逻辑
            static::safeEcho('Worker Stop clear' . PHP_EOL);
            $timer = new SwooleTimer();
            $timer->stop($worker_id);
        }
        $this->onWorkerStop($server, $worker_id);
    }
    //仅在开启reload_async特性后有效。异步重启特性，会先创建新的Worker进程处理新请求，旧的Worker进程自行退出。
    //https://wiki.swoole.com/wiki/page/808.html
    /**
     * @param Server $server
     * @param int $worker_id
     */
    public function onWorkerExit(Server $server, $worker_id){
        //todo
        /*if($timers = (new SwooleTimer())->timer()) { //直接读取配置文件
            foreach ($timers as $item){ #清除当前工作进程内的所有定时器
                if($item['worker_id']==$worker_id) swoole_timer_clear($item['timerid']);
            }
        }*/
    }
    //此函数主要用于报警和监控，一旦发现Worker进程异常退出，那么很有可能是遇到了致命错误或者进程CoreDump。通过记录日志或者发送报警的信息来提示开发者进行相应的处理
    /** 当Worker/Task进程发生异常后会在Manager进程内回调此函数
     * @param Server $server
     * @param int $worker_id 是异常进程的编号
     * @param int $worker_pid 是异常进程的ID
     * @param int $exit_code 退出的状态码，范围是 0～255
     * @param int $signal 进程退出的信号
     */
    final public function _onWorkerError(Server $server, $worker_id, $worker_pid, $exit_code, $signal){
        $err = date('Y-m-d H:i:s ') . '异常进程的编号:'.$worker_id.', 异常进程的ID:'.$worker_pid.', 退出的状态码:'.$exit_code.', 进程退出信号:'.$signal;
        static::safeEcho($err.PHP_EOL);
        //todo 记录日志或者发送报警的信息来提示开发者进行相应的处理
        self::err($err);
        $this->onWorkerError($server, $worker_id, $err);
    }
    //初始服务
    final public function init(){
        $this->config['setting']['daemonize'] = self::$isConsole ? 0 : 1; //守护进程化;
        //if(!isset($this->config['setting']['max_wait_time'])) $this->config['setting']['max_wait_time'] = 10; #进程收到停止服务通知后最大等待时间
        $sockType = SWOOLE_SOCK_TCP; //todo ipv6待测试后加入
        $isSSL = isset($this->config['setting']['ssl_cert_file']); //是否使用的证书
        if($isSSL){
            $sockType =  SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }
        $type = $this->getConfig('type');
        //监听1024以下的端口需要root权限
        switch ($type){
            case self::TYPE_HTTP:
                $this->server = new \Swoole\Http\Server($this->ip, $this->port, $this->mode, $sockType);
                $this->server->type = self::TYPE_HTTP;
                self::$isHttp = true;
                break;
            case self::TYPE_WEB_SOCKET:
                $this->server = new \Swoole\WebSocket\Server($this->ip, $this->port, $this->mode, $sockType);
                $this->server->type = self::TYPE_WEB_SOCKET;
                break;
            case self::TYPE_UDP:
                $this->server = new Server($this->ip, $this->port, $this->mode, $isSSL ? SWOOLE_SOCK_UDP | SWOOLE_SSL : SWOOLE_SOCK_UDP);
                $this->server->type = self::TYPE_UDP;
                break;
            case self::TYPE_UNIX:
                $this->ip = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . $this->ip;
                $this->server = new Server($this->ip, 0, $this->mode, SWOOLE_UNIX_STREAM);
                $this->server->type = self::TYPE_UNIX;
                break;
            default:
                $this->server = new Server($this->ip, $this->port, $this->mode, $sockType);
                $this->server->type = self::TYPE_TCP;
        }
        $this->address = $this->server->type.'://'.$this->ip.':'.$this->port;
        //开启多个监听处理
        $listen = $this->getConfig('listen', []);
        if(is_array($listen) && $listen){
            #未调用set方法，设置协议处理选项的监听端口，默认继承主服务器的设置
            #未调用on方法，设置回调函数的监听端口，默认使用主服务器的回调函数
            //取多协议端口复合监听协议名
            $getTypeName = function($type){
                $ret = [
                    SWOOLE_SOCK_TCP => self::TYPE_TCP,
                    SWOOLE_SOCK_UDP => self::TYPE_UDP,
                    SWOOLE_SOCK_TCP6 => self::TYPE_TCP . '6',
                    SWOOLE_SOCK_UDP6 => self::TYPE_UDP . '6',
                    SWOOLE_UNIX_STREAM => self::TYPE_UNIX,
                ];
                return isset($ret[$type]) ? $ret[$type] : 'null';
            };
            $port = (int)$this->port;
            foreach ($listen as $k=>$item){
                if(!isset($item['type'])) $item['type'] = SWOOLE_SOCK_TCP; // Socket 类型
                if (is_string($item['type'])) {
                    if ($item['type'] == self::TYPE_UDP) {
                        $item['type'] = SWOOLE_SOCK_UDP;
                    } elseif ($item['type'] == self::TYPE_UNIX) {
                        $item['type'] = SWOOLE_UNIX_STREAM;
                        $item['port'] = 0;
                        if(!isset($item['ip'])){
                            $item['ip'] = $k;
                        }
                        $item['ip'] = (is_dir('/dev/shm') ? '/dev/shm' : $this->runDir) . '/' . $this->serverName() . $item['ip'];
                    } else {
                        $item['type'] = SWOOLE_SOCK_TCP;
                    }
                }

                if(!isset($item['ip'])){ //未设置使用主服务器的
                    $item['ip'] = $this->ip;
                }
                if(!isset($item['port'])){ //未设置使用主服务器的 port+10
                    $item['port'] = ++$port;
                }

                //有配置证书
                if(isset($item['setting']['ssl_cert_file'])){
                    $item['type'] =  $item['type'] | SWOOLE_SSL;
                }

                //兼容处理
                if (isset($item['setting']['count'])) {
                    $item['setting']['worker_num'] = $item['setting']['count'];
                    unset($item['setting']['count']);
                }
                unset($item['setting']['protocol']);

                //创建其他监听服务
                /**
                 * @var \Swoole\Server[];
                 */
                $this->childSrv[$k] = $this->server->listen($item['ip'], $item['port'], $item['type']);
                if(isset($item['setting'])){
                    $this->childSrv[$k]->set($item['setting']);
                }
                if(isset($item['event'])){ //有自定义事件
                    foreach ($item['event'] as $event=>$fun){ //onWorkerStart onWorkerStop 设置无效只能继承主服务器的
                        if (strpos($event, 'on') === 0) $event = substr($event, 2);
                        if ($event == 'WorkerStart' || $event == 'WorkerStop') continue;
                        $this->childSrv[$k]->on($event, $fun);
                    }
                }
                $this->address .= '; '.$getTypeName($item['type']).'://'.$item['ip'].':'.$item['port'];
            }
        }
        $server = $this->server;
        //设置服务配置
        $server->set($this->getConfig('setting'));

        // 获取配置的事件
        $event = $this->getConfig('event', []);

        //初始事件绑定
        //BASE模式无start事件
        if ($this->mode == SWOOLE_PROCESS) {
            $server->on('Start', function ($server) {//回调有错误时 可能不会有主进程
                $this->setProcessTitle($this->serverName() . '-master');
                if (method_exists($this, 'onStart')) {
                    $this->initMyPhp();
                    $this->onStart();
                }
            });
        }
        $server->on('Shutdown', [$this, 'onShutdown']);
        $server->on('ManagerStart', function ($server) use ($event) {
            $this->_onManagerStart($server);
            isset($event['onManagerStart']) && call_user_func($event['onManagerStart'], $server);
        });
        $server->on('ManagerStop', function ($server) use ($event) {
            $this->_onManagerStop($server);
            isset($event['onManagerStop']) && call_user_func($event['onManagerStop'], $server);
        });

        $server->on('WorkerStart', function ($server, $worker_id) use ($event) {
            $this->_onWorkerStart($server, $worker_id);
            isset($event['onWorkerStart']) && call_user_func($event['onWorkerStart'], $server, $worker_id);
        });

        $server->on('WorkerStop', function ($server, $worker_id) use ($event) {
            $this->_onWorkerStop($server, $worker_id);
            isset($event['onWorkerStop']) && call_user_func($event['onWorkerStop'], $server, $worker_id);
        });

        $server->on('WorkerError', function ($server, $worker_id, $worker_pid, $exit_code, $signal) use ($event) {
            $this->_onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
            isset($event['onWorkerError']) && call_user_func($event['onWorkerError'], $server, $worker_id, self::err());
        });

        if ($this->getConfig('setting.reload_async', false)) { //异步安全重启特性
            $server->on('WorkerExit', function ($server, $worker_id) use ($event) {
                if(isset($event['onWorkerExit'])){
                    call_user_func($event['onWorkerExit'], $server, $worker_id);
                }else{
                    $this->onWorkerExit($server, $worker_id);
                }
            });
        }
        //事件
        if (isset($event['onConnect'])) {
            $server->on('Connect', $event['onConnect']); // args: $server, $fd, $reactorId
        }
        if (isset($event['onClose'])) {
            $server->on('Close', $event['onClose']); // args: $server, $fd, $reactorId
        }
        if (!isset($event['onReceive'])) { //使用非tcp服务时会有这个提示 require onReceive callback 所以兼容下
            $event['onReceive'] = function($server, $fd, $reactor_id, $data){};
        }
        $server->on('Receive', $event['onReceive']); // args: $server, $fd, $reactor_id, $data

        if($type==self::TYPE_WEB_SOCKET){
            if (isset($event['onMessage'])) {
                $server->on('Message', $event['onMessage']); // args: $server, $frame
            }
        }elseif($type==self::TYPE_UDP){
            if (isset($event['onPacket'])) {
                $server->on('Packet', $event['onPacket']); // args: $server, $data, $client_info
            }
        }

        if ($this->task_worker_num) { //启用了
            $server->on('Task', function ($server, $task_id, $src_worker_id, $data) use ($event) {
                if (isset($event['onTask'])) {
                    call_user_func($event['onTask'], $server, $task_id, $src_worker_id, $data);
                } else {
                    SwooleEvent::onTask($server, $task_id, $src_worker_id, $data);
                }
            });
            $server->on('Finish', function ($server, $task_id, $data) use ($event) {
                if (isset($event['onFinish'])) {
                    call_user_func($event['onFinish'], $server, $task_id, $data);
                } else {
                    SwooleEvent::onFinish($server, $task_id, $data);
                }
            });
        }
        if ($this->getConfig('type') == self::TYPE_HTTP || isset($event['onRequest'])) {
            $server->on('Request', function ($request, $response) use ($event) {
                if (isset($event['onRequest'])) {
                    call_user_func($event['onRequest'], $request, $response);
                } else {
                    SwooleEvent::onRequest($request, $response);
                }
            });
        }
    }
    public function workerId(){
        return $this->server->worker_id;
    }
    public function task($data){
        return $this->server->task($data);
    }
    public function send($fd, $data){
        if ($this->server->type === self::TYPE_UDP) {
            return $this->server->sendto($fd['address'], $fd['port'], $data);
        }

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
            $err = date('Y-m-d H:i:s ') . (isset($errCode[$code])?$errCode[$code]:'未知错误码');
            self::err($err, $code); //错误码 参见https://wiki.swoole.com/wiki/page/554.html
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
    public function getHeader($req){
        return is_array($req) ? $req['header'] : $req->header;
    }
    public function getRawBody($req){
        return is_array($req) ? $req['rawbody'] : $req->rawContent();
    }
    /**
     * @param \Swoole\Http\Response $response
     * @param $code
     * @param $header
     * @param $content
     */
    public function httpSend($response, $code, &$header, &$content){
        // 发送状态码
        $response->status($code);
        // 发送头部信息
        foreach ($header as $name => $val) {
            $response->header($name, $val);
        }
        // 发送内容
        if (is_string($content)) {
            $content !== '' && $response->write($content);
        } else {
            $response->write(self::toJson($content));
        }
        $response->end();
    }
    final public function exec(){
        $this->server->start();
    }
    final public function relog(){
        $logFile = $this->getConfig('setting.log_file', $this->runDir .'/server.log');
        if($logFile) file_put_contents($logFile,'', LOCK_EX);
        /*if($pid=self::pid()){
            posix_kill($pid, SIGRTMIN); //34  运行时日志不存在可重新打开日志文件
        }*/
        static::safeEcho('['.$logFile.'] relog ok!'.PHP_EOL);
        return true;
    }
    public function run(&$argv){
        $action = ''; //$action = isset($argv[1]) ? $argv[1] : 'start';
        $allow_action = ['relog', 'reloadTask', 'reload', 'stop', 'restart', 'status', 'start'];
        foreach ($argv as $value) {
            if (in_array($value, $allow_action)) {
                $action = $value;
                break;
            }
        }
        self::$isConsole = array_search('--console', $argv);
        if($action=='' || $action=='--console') $action = 'start';
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
                static::safeEcho("Start ".$this->serverName().PHP_EOL);
                $this->start();
                break;
            case 'status':
                $this->status();
                break;
            case 'start':
                if($this->pid()){
                    static::safeEcho($this->pidFile." exists, ".$this->serverName()." is already running or crashed.".PHP_EOL);
                    exit();
                }else{
                    static::safeEcho("Start ".$this->serverName().PHP_EOL);
                }
                $this->start();
                break;
            default:
                static::safeEcho('Usage: '. $this->runFile .' {([--console]|start[--console])|stop|restart[--console]|reload|relog|status}'.PHP_EOL);
        }
    }
}