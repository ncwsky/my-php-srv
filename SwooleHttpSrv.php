<?php
class SwooleHttpSrv extends SwooleSrv {
    public function __construct($config)
    {
        parent::__construct($config);
        $this->config['type'] = self::TYPE_HTTP;
        $this->mode = SWOOLE_BASE; //单线程模式 异步非阻塞Server同nginx https://wiki.swoole.com/wiki/page/353.html
    }
    //绑定事件
    protected function afterInit(){
        $server = $this->server;

        if ($this->getConfig('setting.task_worker_num', 0)) { //启用了
            $server->on('Task', function (swoole_server $server, int $task_id, int $src_worker_id, mixed $data){
                SwooleEvent::onTask($server, $task_id, $src_worker_id, $data);
            });
            $server->on('Finish', function (swoole_server $server, int $task_id, string $data){
                SwooleEvent::onFinish($server, $task_id, $data);
            });
        }
        #$server->on('Request', ['SwooleEvent','onRequest']);
        $server->on('Request', function ($request, $response){
            SwooleEvent::onRequest($request, $response);
        });
    }
}
