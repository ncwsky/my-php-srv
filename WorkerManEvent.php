<?php
use Workerman\Connection\ConnectionInterface;
use myphp\Control;
use myphp\Helper;

class WorkerManEvent{
    //有新的连接进入时， $fd 是连接的文件描述符
    public static function onConnect(ConnectionInterface $connection){
        $fd = $connection->id;
    }

    /**
     * 接收到数据时回调此函数
     * @param ConnectionInterface $connection
     * @param string|\Workerman\Protocols\Http\Request $data
     * @throws Exception
     */
    public static function onMessage(ConnectionInterface $connection, $data){
        static $request_count = 0;

        //如果有http请求需要判断处理
        if(SrvBase::$isHttp || (isset($connection->worker->type) && $connection->worker->type==SrvBase::TYPE_HTTP)){
            $_SESSION = null;
            //引入session
            \myphp\Session::on(function () use($data){
                return $data->session();
            });
            //重置
            $_SERVER = WorkerManSrv::$_SERVER; //使用初始的server
            $_COOKIE = $data->cookie();
            $_FILES = $data->file();
            $_GET = $data->get();
            $_POST = $data->post();
            $_REQUEST = array_merge($_GET, $_POST);
            $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
            $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
            $_SERVER['REQUEST_METHOD'] = $data->method();
            foreach ($data->header() as $k=>$v){
                $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
                $_SERVER[$k] = $v;
            }
            //客户端的真实IP HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
            if($data->header('x-real-ip') || $data->header('x-forwarded-for')) {
                Helper::$isProxy = true;
            }
            $_SERVER['HTTP_HOST'] = $data->host();
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['PHP_SELF'] = $data->path();
            $_SERVER["REQUEST_URI"] = $data->uri();
            $_SERVER['QUERY_STRING'] = $data->queryString();

            GetC('log_level')==0 && \myphp\Log::trace('[' . $_SERVER['REQUEST_METHOD'] . ']' . Helper::getIp() . ' ' . $_SERVER["REQUEST_URI"] . ($_SERVER['REQUEST_METHOD'] == 'POST' ? PHP_EOL . 'post:' . Helper::toJson($_POST) : ''));

            if (SrvBase::$instance->task_worker_num && isset($_REQUEST[ASYNC_NAME]) && $_REQUEST[ASYNC_NAME]==1) { //异步任务
                $task_id = SrvBase::$instance->task([
                    '_COOKIE'=>$_COOKIE,
                    '_FILES'=>$_FILES,
                    '_GET'=>$_GET,
                    '_POST'=>$_POST,
                    '_REQUEST'=>$_REQUEST,
                    '_SERVER'=>$_SERVER,
                    'header'=>$data->header(),
                    'rawBody'=>$data->rawBody()
                ]);
                $response = new \Workerman\Protocols\Http\Response(200, [
                    'Content-Type'=>'application/json; charset=utf-8'
                ]);
                if($task_id===false){
                    $response->withBody(Helper::toJson(Control::fail('异步任务调用失败:'.SrvBase::err())));
                }else{
                    $response->withBody(Helper::toJson(Control::ok(['task_id'=>$task_id])));
                }
                $connection->send($response);
            } else {
                //myphp::setEnv('headers', $data->header());
                myphp::req()->setHeaders($data->header());
                myphp::req()->setRawBody($data->rawBody()); //file_get_contents("php://input")
                myphp::Run(function($code, $res, $header) use($connection){
                    //myphp::setEnv('headers');
                    // 发送状态码
                    $response = new \Workerman\Protocols\Http\Response($code);
                    // 发送头部信息
                    $response->withHeaders($header);
                    /**
                     * @var \myphp\Response $res
                     */
                    if($res->file){ //发送文件 [$file, $offset, $size]
                        $response->withFile($res->file[0], $res->file[1], $res->file[2]);
                    } else { // 发送内容
                        $data = is_scalar($res->body) ? $res->body : Helper::toJson($res->body);
                        $data !== '' && $response->withBody($data);
                    }
                    $connection->send($response);
                }, false);
            }
        }else{
            $connection->send($data);
        }
        // 请求数达到xxx后退出当前进程，主进程会自动重启一个新的进程
        if (SrvBase::$instance->max_request > 0 && ++$request_count > SrvBase::$instance->max_request) {
            \Workerman\Worker::stopAll();
        }
    }
    //客户端连接关闭事件
    public static function onClose(ConnectionInterface $connection){
        if((isset($connection->worker->type) && $connection->worker->type==SrvBase::TYPE_HTTP)) return true;
        //todo
        return true;
    }
    //当连接的应用层发送缓冲区满时触发
    public static function onBufferFull(ConnectionInterface $connection){
        //echo "bufferFull and do not send again\n";
        $connection->pauseRecv(); //暂停接收
    }
    //当连接的应用层发送缓冲区数据全部发送完毕时触发
    public static function onBufferDrain(ConnectionInterface $connection){
        //echo "buffer drain and continue send\n";
        $connection->resumeRecv(); //恢复接收
    }
    //异步任务 在task_worker进程内被调用
    public static function onTask($task_id, $src_worker_id, $data){
        //重置
        $_COOKIE = $data['_COOKIE'];
        $_FILES = $data['_FILES'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        $_REQUEST = $data['_REQUEST'];
        $_SERVER = $data['_SERVER'];
        myphp::req()->setHeaders($data['header']);
        myphp::req()->setRawBody($data['rawBody']);
        myphp::Run(function($code, $res, $header) use($task_id, $src_worker_id){
            /**
             * @var \myphp\Response $res
             */
            $data = is_scalar($res->body) ? $res->body : Helper::toJson($res->body);
            if (SrvBase::$isConsole) SrvBase::safeEcho("AsyncTask Finish:Connect.task_id=" . $task_id . ',src_worker_id=' . $src_worker_id . ', ' . $data . PHP_EOL);
        }, false);
        unset($_COOKIE, $_FILES, $_GET, $_POST, $_REQUEST, $_SERVER);
        return true;
    }
}