<?php
class WorkerManHttpSrv extends WorkerManSrv {
    protected function beforeInit()
    {
        $this->config['type'] = self::TYPE_HTTP;
        WorkerManEvent::$onlyHttp = true;
    }
}