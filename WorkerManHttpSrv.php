<?php
class WorkerManHttpSrv extends WorkerManSrv {
    //初始服务之前执行
    protected function beforeInit()
    {
        $this->config['type'] = self::TYPE_HTTP;
        self::$isHttp = true;
    }
}