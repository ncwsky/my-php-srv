<?php
class SwooleEvent{
    //有新的连接进入时，在worker进程中回调 $fd 是连接的文件描述符
    public static function onConnect(swoole_server $server, int $fd, int $reactorId){

    }
    //接收到数据时回调此函数，发生在worker进程中
    public static function onReceive(swoole_server $server, int $fd, int $reactor_id, string $data){

    }
    //接收到UDP数据包时回调此函数，发生在worker进程中
    public static function onPacket(swoole_server $server, string $data, array $client_info){

    }
    //客户端连接关闭事件 TCP客户端连接关闭后，在worker进程中回调此函数 $reactorId当服务器主动关闭连接时，底层会设置此参数为-1
    public static function onClose(swoole_server $server, int $fd, int $reactorId){

    }
    //异步任务 在task_worker进程内被调用
    public static function onTask(swoole_server $server, int $task_id, int $src_worker_id, $data){
        //重置
        $_SERVER = $data['_SERVER'];
        $_REQUEST = $data['_REQUEST'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        myphp::Run(function($code, $data, $header) use($task_id){
            //is_string($data) ? $data : toJson($data)
            if(SwooleSrv::$isConsole) echo "AsyncTask Finish:Connect.task_id=" . $task_id . (is_string($data) ? $data : toJson($data)). PHP_EOL;
        }, false);
        unset($_SERVER, $_REQUEST, $_GET, $_POST);
        //return 等同$server->finish($response); 这里没有return不会触发finish事件
    }
    //异步任务完成 当worker进程投递的任务在task_worker中完成时，task进程会通过swoole_server->finish()方法将任务处理的结果发送给worker进程
    //return void;
    public static function onFinish(swoole_server $server, int $task_id, string $data){
        //todo
        //echo "AsyncTask Finish:Connect.task_id=" . $task_id .", " . $data.PHP_EOL;
    }
    //当工作进程收到由 sendMessage 发送的管道消息时会触发onPipeMessage事件
    //参见 https://wiki.swoole.com/wiki/page/363.html
    //return void;
    public static function onPipeMessage(swoole_server $server, int $src_worker_id, $message){

    }

    public static function onRequest(swoole_http_request $request, swoole_http_response $response){
        SrvBase::$isHttp = true;
        $_SERVER = array_change_key_case($request->server,CASE_UPPER);
        foreach ($request->header as $k=>$v){
            $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
            $_SERVER[$k] = $v;
        }
        //客户端的真实IP
        if(isset($request->header['x-real-ip']) || isset($request->header['x-forwarded-for'])) { // HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
            Helper::$isProxy = true;
        }

        myphp::setEnv('headers', $request->header);
        myphp::setEnv('rawBody', $request->rawContent()); //file_get_contents("php://input")
        $_COOKIE = $_FILES = $_REQUEST = $_POST = $_GET = [];
        if($request->get) $_GET = &$request->get;
        if($request->post) $_POST = &$request->post;
        if($request->cookie) $_COOKIE = &$request->cookie;
        if($request->files) $_FILES = &$request->files;
        $_REQUEST = array_merge($_GET, $_POST);
        if(!isset($_GET['c']) && isset($_POST['c'])) $_GET['c'] = $_POST['c'];
        if(!isset($_GET['a']) && isset($_POST['a'])) $_GET['a'] = $_POST['a'];

        Log::trace('[http]'.toJson($_REQUEST));
        if (Q('async%d')==1) { //异步任务
            $task_id = SwooleSrv::$instance->task([
                '_SERVER'=>$_SERVER,
                '_REQUEST'=>$_REQUEST,
                '_GET'=>$_GET,
                '_POST'=>$_POST
            ]);
            if($task_id===false){
                $response->write(Helper::toJson(Control::fail('异步任务调用失败:'.SrvBase::err())));
            }else{
                $response->write(Helper::toJson(Control::ok(['task_id'=>$task_id])));
            }
        } else {
            myphp::Run(function($code, $data, $header) use($response){
                if($header) {
                    foreach ($header as $name => $val) {
                        $response->header($name, $val);
                    }
                }
                $response->status($code);
                if (is_string($data)) {
                    $data !== '' && $response->write($data);
                } else {
                    $response->write(toJson($data));
                }
            }, false);
            //清除本次请求的数据
            myphp::setEnv('headers');
            myphp::setEnv('rawBody');
        }
        $response->end();
    }
}