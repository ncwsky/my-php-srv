<?php
defined('SIGTERM') || define('SIGTERM', 15); //中止服务
defined('SIGUSR1') || define('SIGUSR1', 10); //柔性重启
defined('SIGRTMIN') || define('SIGRTMIN', 34); //SIGRTMIN信号重新打开日志文件
defined('ASYNC_NAME') || define('ASYNC_NAME', 'async');
if (!class_exists('Error')) { //兼容7.0
    class Error extends Exception{}
}
/**
 * Class SrvBase
 * @method onStart //主进程回调 有配置才会运行 仅用于回收数据
 * @method onStop //结束回调 有配置才会运行 仅用于回收数据
 * @property \Swoole\Server|Worker2 $server
 * @property \Swoole\Server[]|Worker2[] $childSrv
 */
abstract class SrvBase{
    use SrvMsg;
    //全局变量存放 仅当前的工作进程有效[参见进程隔离]
    public static $_SERVER;
    public static $isHttp = false;
    public $isWorkerMan = false;
    public $task_worker_num = 0;
	public static $isConsole = false;
	public static $logFile = '';
    public $server; //服务实例
    public $childSrv = []; //多个监听时的子服务
    protected $config;
    protected $runFile;
    public $runDir;
    protected $pidFile;
    protected $address;
    protected $hasInitMyPhp = false;
    public $port;
    protected $ip;
    public static $instance;
    const TYPE_HTTP = 'http';
    const TYPE_TCP = 'tcp';
    const TYPE_UDP = 'udp';
    const TYPE_WEB_SOCKET = 'websocket';
    const TYPE_UNIX = 'unix';
    public static $types = [
        self::TYPE_HTTP,
        self::TYPE_TCP,
        self::TYPE_UDP,
        self::TYPE_WEB_SOCKET,
        self::TYPE_UNIX,
    ];
    /**
     * SrvBase constructor.
     * @param array $config
     */
	public function __construct($config)
    {
        self::$instance = $this;
        $this->runFile = $_SERVER['SCRIPT_FILENAME'];
        $this->runDir = realpath(dirname($this->runFile));
        $this->config = $config;
        $this->pidFile = $this->getConfig('setting.pid_file', $this->runDir .'/server.pid');
        $this->ip = $this->getConfig('ip', '0.0.0.0');
        $this->port = $this->getConfig('port', 7900);
        $this->task_worker_num = (int)$this->getConfig('setting.task_worker_num', 0);

        if (isset($config['setting']['logFile'])) {
            static::$logFile = $config['setting']['logFile'];
        } elseif (isset($config['setting']['log_file'])) {
            static::$logFile = $config['setting']['log_file'];
        }

        set_error_handler(function($code, $msg, $file, $line){
            static::safeEcho("$msg in file $file on line $line\n");
        });
        register_shutdown_function(["SrvBase", 'checkErrors']);
    }

    public function getConfig($name, $def=''){
        //获取值
        if (false === ($pos = strpos($name, '.')))
            return isset($this->config[$name]) ? $this->config[$name] : $def;
        // 二维数组支持
        $name1 = substr($name, 0, $pos);
        $name2 = substr($name, $pos + 1);
        return isset($this->config[$name1][$name2]) ? $this->config[$name1][$name2] : $def;
    }
    public function serverName(){
        return $this->getConfig('name', basename($this->runFile,'.php'));
    }
    final protected function initMyPhp(){
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        $this->hasInitMyPhp = true;
        $worker_load = $this->getConfig('worker_load');
        if($worker_load){
            if(!is_array($worker_load)){
                $worker_load = [$worker_load];
            }
            //todo 循环load配置文件或匿名函数 ['file1','file2',...,function(){},...] || function(){};
            foreach ($worker_load as $load){
                if(is_string($load) && is_file($load)){
                    include $load;
                }else{
                    call_user_func($load);
                }
            }
        }else{
            include $this->getConfig('conf_file', $this->runDir. '/conf.php') ;
            include $this->getConfig('myphp_dir', $this->runDir.'/myphp').'/base.php';
            myphp::Analysis(false); //为myphp初始app目录自动载入
        }
    }
    final protected function setProcessTitle($title){
        set_error_handler(function(){});
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            setproctitle($title);
        }
        restore_error_handler();
    }
    //workerman环境检测
    public static function workermanCheck()
    {
        if(\DIRECTORY_SEPARATOR !== '\\'){ //非win
            if (!in_array("pcntl", get_loaded_extensions())) {
                self::err('Extension pcntl check fail');
                return false;
            }
            if (!in_array("posix", get_loaded_extensions())) {
                self::err('Extension posix check fail');
                return false;
            }
        }

        $check_func_map = array(
            "stream_socket_server",
            "stream_socket_accept",
            "stream_socket_client",
            "pcntl_signal_dispatch",
            "pcntl_signal",
            "pcntl_alarm",
            "pcntl_fork",
            "posix_getuid",
            "posix_getpwuid",
            "posix_kill",
            "posix_setsid",
            "posix_getpid",
            "posix_getpwnam",
            "posix_getgrnam",
            "posix_getgid",
            "posix_setgid",
            "posix_initgroups",
            "posix_setuid",
            "posix_isatty",
        );
        // 获取php.ini中设置的禁用函数
        if ($disable_func_string = ini_get("disable_functions")) {
            $disable_func_map = array_flip(explode(",", $disable_func_string));
        }
        // 遍历查看是否有禁用的函数
        foreach ($check_func_map as $func) {
            if (isset($disable_func_map[$func])) {
                self::err("Function $func may be disabled. Please check disable_functions in php.ini\nsee http://www.workerman.net/doc/workerman/faq/disable-function-check.html\n");
                return false;
            }
        }
        return true;
    }
    /**
     * Safe Echo.
     * @param string $msg
     * @return bool
     */
    public static function safeEcho($msg)
    {
        $stream = \STDOUT;

        $line = $white = $green = $end = '';
        //输出装饰
        $line = "\033[1A\n\033[K";
        $white = "\033[47;30m";
        $green = "\033[32;40m";
        $end = "\033[0m";

        $msg = \str_replace(array('<n>', '<w>', '<g>'), array($line, $white, $green), $msg);
        $msg = \str_replace(array('</n>', '</w>', '</g>'), $end, $msg);
        set_error_handler(function(){});
        \fwrite($stream, $msg);
        \fflush($stream);
        restore_error_handler();
        return true;
    }

    /****** 分隔线 ******/
    #woker进程回调
    protected function onWorkerStart($server, $worker_id){
        if($worker_id==0){ //
            //todo 清理上次服务的全局缓存数据 reload时也会被处理
        }
    }
    protected function onWorkerStop($server, $worker_id){
        //todo
    }
    protected function onWorkerError($server, $worker_id, $err){
        //todo
    }

    /** 返回当前进程的id
     * @return int
     */
    abstract public function workerId();

    /** 异步任务
     * @param $data
     * @return int|bool
     */
    abstract public function task($data);

    /** 发送数据
     * @param $fd
     * @param $data
     * @return bool
     */
    abstract public function send($fd, $data);

    /** 关闭连接
     * @param $fd
     * @return bool
     */
    abstract public function close($fd);

    /** 连接信息
     * @param $fd
     * @return array|null
     */
    abstract public function clientInfo($fd);
    //获取http时传入的header 及 rawBody
    abstract public function getHeader($req);
    abstract public function getRawBody($req);
    /**
     * 发送http数据
     * @param $response
     * @param $code
     * @param $header
     * @param $content
     * @return void
     */
    abstract public function httpSend($response, $code, &$header, &$content);
    //初始服务
    abstract protected function init();
    //运行
    abstract protected function exec();
    //初始服务之前执行
    protected function beforeInit(){
        //todo
    }
    //初始服务之后执行
    protected function afterInit(){
        //todo
    }
    public function start(){
        $this->beforeInit();
        //初始服务
        $this->init();
        $this->afterInit();
        //启动
        $this->exec();
    }
    abstract public function relog();
    public function stop($sig=SIGTERM){
        if($pid=self::pid()){
            static::safeEcho("Stopping...".PHP_EOL);
            if(posix_kill($pid, $sig)){ //15 可安全关闭(等待任务处理结束)服务器
                sleep(1);
                while(self::pid()){
                    static::safeEcho("Waiting for ". $this->serverName() ." to shutdown...".PHP_EOL);
                    sleep(1);
                }
                file_exists($this->pidFile) && @unlink($this->pidFile);
                static::safeEcho($this->serverName()." stopped!".PHP_EOL);
            }else{
                static::safeEcho('PID:'.$pid.' stop fail!'.PHP_EOL);
                return false;
            }
        }else{
            static::safeEcho('PID invalid! Process is not running.'.PHP_EOL);
        }
        return true;
    }
    public function reload($sig=SIGUSR1){
        if($pid=self::pid()){
            $ret = posix_kill($pid, $sig); //10
            if($ret){
                static::safeEcho('reload ok!'.PHP_EOL);
                return true;
            }else{
                static::safeEcho('reload fail!'.PHP_EOL);
            }
        }else{
            static::safeEcho('PID invalid! Process is not running.'.PHP_EOL);
        }
        return false;
    }
	public function status(){
		if($pid=self::pid()){
			static::safeEcho($this->serverName().' (pid '.$pid.') is running...'.PHP_EOL);
			return true;
		}else{
			static::safeEcho($this->serverName()." is stopped".PHP_EOL);
			return false;
		}
	}
	//检查进程pid是否存在
    public function pid(){
        if(file_exists($this->pidFile) && $pid = file_get_contents($this->pidFile)){
            if(posix_kill($pid, 0)) { //检测进程是否存在，不会发送信号
                return $pid;
            }
        }
        return false;
    }
    abstract public function run(&$argv);

    protected static $_errorType = array(
        E_ERROR             => 'E_ERROR',             // 1
        E_WARNING           => 'E_WARNING',           // 2
        E_PARSE             => 'E_PARSE',             // 4
        E_NOTICE            => 'E_NOTICE',            // 8
        E_CORE_ERROR        => 'E_CORE_ERROR',        // 16
        E_CORE_WARNING      => 'E_CORE_WARNING',      // 32
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',     // 64
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',   // 128
        E_USER_ERROR        => 'E_USER_ERROR',        // 256
        E_USER_WARNING      => 'E_USER_WARNING',      // 512
        E_USER_NOTICE       => 'E_USER_NOTICE',       // 1024
        E_STRICT            => 'E_STRICT',            // 2048
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        E_DEPRECATED        => 'E_DEPRECATED',        // 8192
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED'   // 16384
    );

    public static function checkErrors()
    {
        $errors    = error_get_last();
        if ($errors && ($errors['type'] === E_ERROR ||
                $errors['type'] === E_PARSE ||
                $errors['type'] === E_CORE_ERROR ||
                $errors['type'] === E_COMPILE_ERROR ||
                $errors['type'] === E_RECOVERABLE_ERROR)
        ) {
            $error_msg = DIRECTORY_SEPARATOR === '\\' ? 'Worker process terminated' : 'Worker['. posix_getpid() .'] process terminated';
            $error_msg .= ' with ERROR: ' . (isset(static::$_errorType[$errors['type']]) ? static::$_errorType[$errors['type']] : '') . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            static::log($error_msg);
        }
    }

    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (static::$isConsole) {
            static::safeEcho($msg);
        }
        if (static::$logFile==='') {
            static::$logFile = __DIR__ . '/../log.log';
        }
        file_put_contents(static::$logFile, \date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (DIRECTORY_SEPARATOR === '\\' ? 1 : posix_getpid()) . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $msg
     */
    public static function toClose($con, $fd=0, $msg=null){
        if (self::$instance->isWorkerMan) {
            $con->close($msg);
        } else {
            if ($msg) $con->send($fd, $msg);
            $con->close($fd);
        }
    }

    /**
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $msg
     * @return bool|null
     */
    public static function toSend($con, $fd, $msg){
        return self::$instance->isWorkerMan ? $con->send($msg) : $con->send($fd, $msg);
    }
}