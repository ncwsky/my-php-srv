<?php
defined('SIGTERM') || define('SIGTERM', 15); //中止服务
defined('SIGUSR1') || define('SIGUSR1', 10); //柔性重启
defined('SIGRTMIN') || define('SIGRTMIN', 34); //SIGRTMIN信号重新打开日志文件

/**
 * Class SrvBase
 * @method onStart //主进程回调 有配置才会运行 仅用于回收数据
 * @method onStop //结束回调 有配置才会运行 仅用于回收数据
 */
abstract class SrvBase{
    use SrvMsg;
    //全局变量存放 仅当前的工作进程有效[参见进程隔离]
    public static $isHttp = false;
    public $isWorkerMan = false;
	public static $isConsole = false;
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
    public static $types = [
        self::TYPE_HTTP,
        self::TYPE_TCP,
        self::TYPE_UDP,
        self::TYPE_WEB_SOCKET
    ];
    /**
     * SrvBase constructor.
     * @param array $config
     */
	public function __construct($config)
    {
        self::$instance = $this;
        $this->runFile = $_SERVER['SCRIPT_FILENAME'];
        $this->runDir = dirname($this->runFile);
        $this->config = $config;
        $this->pidFile = $this->getConfig('setting.pid_file', $this->runDir .'/server.pid');
        $this->ip = $this->getConfig('ip', '0.0.0.0');
        $this->port = $this->getConfig('port', 7900);
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
            myphp::Analysis(false);
        }
    }
    final protected function setProcessTitle($title){
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            setproctitle($title);
        }
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
    public function stop(){
        if($pid=self::pid()){
            echo "Stopping...",PHP_EOL;
            if(posix_kill($pid, SIGTERM)){ //15 可安全关闭(等待任务处理结束)服务器
                sleep(1);
                while(self::pid()){
                    echo "Waiting for ". $this->serverName() ." to shutdown...",PHP_EOL;
                    sleep(1);
                }
                file_exists($this->pidFile) && @unlink($this->pidFile);
                echo $this->serverName()." stopped!",PHP_EOL;
            }else{
                echo 'PID:'.$pid.' stop fail!',PHP_EOL;
                return false;
            }
        }else{
            echo 'PID invalid! Process is not running.',PHP_EOL;
        }
        return true;
    }
    public function reload(){
        if($pid=self::pid()){
            $ret = posix_kill($pid, SIGUSR1); //10
            if($ret){
                echo 'reload ok!',PHP_EOL;
                return true;
            }else{
                echo 'reload fail!',PHP_EOL;
            }
        }else{
            echo 'PID invalid! Process is not running.',PHP_EOL;
        }
        return false;
    }
	public function status(){
		if($pid=self::pid()){
			echo $this->serverName().' (pid '.$pid.') is running...',PHP_EOL;
			return true;
		}else{
			echo $this->serverName()." is stopped",PHP_EOL;
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
}