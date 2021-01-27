<?php
use Workerman\Lib\Timer;
//定时处理
class WorkerManTimer extends SrvTimer {
    protected function after($timer, $interval){
        $timerId = Timer::add(round($interval/1000,3), function () use ($timer, $interval) {
            if($timers = $this->timer()) { //检测是否执行了清除定时器 $interval  的 cleartimer 操作
                $status = $timers[$interval]['status']; //获取旧的$interval定时器状态
                unset($timers[$interval]);
                $this->timer($timers); //此定时仅执行一次 需要清除记录

                if(!$status){ //收到 cleartimer 的操作 不继续执行
                    echo 'Timer-after'.$interval.' is clear'.PHP_EOL;
                    return;
                }
            }
            $timer->run(); //执行定时
        }, [], false);
        return [WorkerManSrv::$instance->server->id, $timerId];
    }
    protected function tick($timer, $interval){
        $timerId = Timer::add(round($interval/1000,3), function () use ($timer, $interval) {
            if($timers = $this->timer()) { //直接读取配置文件
                if(!$timers[$interval]['status']){ //收到 cleartimer 的操作 清除定时及记录
                    Timer::del($timers[$interval]['timerid']);
                    unset($timers[$interval]);
                    $this->timer($timers);
                    echo 'Timer-tick'.$interval.' is clear'.PHP_EOL;
                    return;
                }
            }
            $timer->run(); //执行定时
        });
        return [WorkerManSrv::$instance->server->id, $timerId];
    }
    public function clear($timerWorkerId, $timerId){
        if(WorkerManSrv::$instance->server->id==$timerWorkerId){ //是在当前进程 直接结束
            Timer::del($timerId);
        }
        return WorkerManSrv::$instance->server->id;
    }
}