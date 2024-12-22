<?php

declare(strict_types=1);
//定时处理
abstract class SrvTimer
{
    use SrvMsg;
    public static $timers = []; //运行期间有效的定时器记录
    public $shmFile; // 使用/dev/shm[内存] 优化读取定时配置
    public $timerFile;
    public function __construct()
    {
        $this->timerFile = SrvBase::$instance->getConfig('timer_file'); //, SrvBase::$instance->runDir .'/timer.json' //无定时记录文件时 定时重启后失效
        $this->shmFile =  $this->timerFile ? '/dev/shm/'.str_replace(['/','\\'], '', SrvBase::$instance->serverName().'_'.$this->timerFile) : '';
        //定时处理载入
        $time_dir = SrvBase::$instance->getConfig('timer_dir', SrvBase::$instance->runDir . '/timer');
        is_dir($time_dir) && myphp::class_dir($time_dir);
    }
    //销毁定时内存缓存配置
    public static function destroy()
    {
        $timerFile = SrvBase::$instance->getConfig('timer_file');
        $shmFile =  $timerFile ? '/dev/shm/'.str_replace(['/','\\'], '', SrvBase::$instance->serverName().'_'.$timerFile) : '';
        file_exists($shmFile) && @unlink($shmFile);
    }

    /**
     * @param array|null $data  null时读取
     * @return array|true
     */
    public function timer(array $data = null)
    {
        if ($data === null) {
            if ($this->shmFile && file_exists($this->shmFile)) {
                $t = file_get_contents($this->shmFile);
            } elseif (is_file($this->timerFile)) {
                $t = file_get_contents($this->timerFile);
                if ($this->shmFile && !is_file($this->shmFile) && is_dir('/dev/shm')) {
                    file_put_contents($this->shmFile, $t, LOCK_EX); //生成初始的内存缓存文件
                    echo 'timer cache shm ok!', PHP_EOL;
                }
            } else {
                return self::$timers; //没有定时配置时 返回进程内临时定时器
            }
            return json_decode($t, true);
        }
        if ($this->timerFile) { //记录定时
            self::$timers = $data;
            $data = json_encode($data);
            file_put_contents($this->timerFile, $data, LOCK_EX); //记录定时记录
            if ($this->shmFile && is_dir('/dev/shm')) {
                file_put_contents($this->shmFile, $data, LOCK_EX); //写入到内存缓存文件
            }
        }
        return true;
    }

    /** 延时执行 仅一次 返回定时器id
     * @param $timer
     * @param $interval
     * @return array [workId, timerId]
     */
    abstract protected function after($timer, $interval);
    /** 循环执行 返回定时器id
     * @param $timer
     * @param $interval
     * @return array [workId, timerId]
     */
    abstract protected function tick($timer, $interval);
    /** 定时在当前进程需要调用销毁方法处理
     * @param $timerWorkerId
     * @param $timerId
     * @return int workId
     */
    abstract public function clear($timerWorkerId, $timerId);

    //添加定时器
    final public function addTimer($data, $force = false)
    {
        $interval = isset($data['interval']) ? (int)$data['interval'] : 0;
        $type = $data['type'] ?? 'tick';

        if ($interval <= 0 || $interval > 86400000) {
            self::err('定时时间无效');
            return false;
        }
        $timers = $this->timer(); //重新读取定时配置
        if (!$force && isset($timers[$interval])) {
            self::err('已存在 '.$interval.' 定时器');
            return false;
        }

        $className = 'Timer'.$interval;
        if (!class_exists($className)) {
            self::err($interval.' 定时器任务不存在'. $className);
            return false;
        }
        $timer = new $className($data);
        if (!method_exists($timer, 'run')) {
            self::err($interval.' 定时器任务未定义run()方法');
            return false;
        }

        [$workerId, $timerId] = $type == 'after' ? $this->after($timer, $interval) : $this->tick($timer, $interval);

        $timers[$interval] = ['timerid' => $timerId, 'type' => $type, 'interval' => $interval, 'worker_id' => $workerId, 'status' => 1];
        $this->timer($timers); //更新定时记录
        self::msg($interval.' 定时器添加成功');
        return $timers[$interval];
    }

    /** 清除定时器
     * @param int $interval 定时器标识 单位秒
     * @return bool
     */
    final public function clearTimer($interval)
    {
        $timers = $this->timer();
        if (isset($timers[$interval])) {
            $timers[$interval]['status'] = 0; //标记定时器失效
            $timerWorkerId = $timers[$interval]['worker_id'];

            $workId = $this->clear($timerWorkerId, $timers[$interval]['timerid']); //执行清除处理
            if ($workId == $timerWorkerId) {
                unset($timers[$interval]);
            }

            $this->timer($timers); //更新定时记录
            self::msg($interval.' 定时器清除成功['.$workId.']');
            return true;
        }
        self::err('不存在 '.$interval.' 定时器');
        return false;
    }

    /** 启动所有定时器
     * @param int $worker_id worker进程id
     * @param int $interval 可指定只启动某定时器
     */
    final public function start($worker_id, $interval = 0)
    {
        if ($timers = $this->timer()) { //定时器在对应进程重启
            foreach ($timers as $k => $data) {
                if ($data['worker_id'] == $worker_id && ($interval == 0 || $interval == $k)) {
                    $ret = $this->addTimer($data, true);
                    echo self::msg(), json_encode($ret), PHP_EOL;
                }
            }
        }
    }
    /** 停止定时器
     * @param int $worker_id worker进程id
     * @param int $interval 0停止所有定时器
     */
    final public function stop($worker_id, $interval = 0)
    {
        if ($timers = $this->timer()) {
            foreach ($timers as $k => $data) { //结束定时器
                if ($data['worker_id'] == $worker_id && ($interval == 0 || $interval == $k)) {
                    $this->clear($data['worker_id'], $data['timerid']);
                }
            }
        }
    }
}
