<?php

declare(strict_types=1);
use myphp\Control;
use myphp\Helper;
use myphp\Log;
use Swoole\Server;

class SwooleEvent
{
    //有新的连接进入时，在worker进程中回调 $fd 是连接的文件描述符
    public static function onConnect(Server $server, int $fd, int $reactorId)
    {

    }
    //接收到数据时回调此函数，发生在worker进程中
    public static function onReceive(Server $server, int $fd, int $reactor_id, string $data)
    {

    }
    //接收到UDP数据包时回调此函数，发生在worker进程中
    public static function onPacket(Server $server, string $data, array $client_info)
    {

    }
    //客户端连接关闭事件 TCP客户端连接关闭后，在worker进程中回调此函数 $reactorId当服务器主动关闭连接时，底层会设置此参数为-1
    public static function onClose(Server $server, int $fd, int $reactorId)
    {

    }
    //当工作进程收到由 sendMessage 发送的管道消息时会触发onPipeMessage事件
    //参见 https://wiki.swoole.com/wiki/page/363.html
    //return void;
    public static function onPipeMessage(Server $server, int $src_worker_id, $message)
    {

    }

    public static function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        $_SERVER = array_change_key_case($request->server, CASE_UPPER);
        $_COOKIE = $_FILES = $_REQUEST = $_POST = $_GET = [];
        if ($request->cookie) {
            $_COOKIE = &$request->cookie;
        }
        if ($request->files) {
            $_FILES = &$request->files;
        }
        if ($request->get) {
            $_GET = &$request->get;
        }
        if ($request->post) {
            $_POST = &$request->post;
        }
        $_REQUEST = array_merge($_GET, $_POST);
        foreach ($request->header as $k => $v) {
            $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
            $_SERVER[$k] = $v;
        }
        //客户端的真实IP HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
        if (isset($request->header['x-real-ip']) || isset($request->header['x-forwarded-for'])) {
            Helper::$isProxy = true;
        }

        GetC('log_level') == 0 && Log::trace('[' . $_SERVER['REQUEST_METHOD'] . ']' . Helper::getIp() . ' ' . $_SERVER["REQUEST_URI"] . ($_SERVER['REQUEST_METHOD'] == 'POST' ? PHP_EOL . 'post:' . Helper::toJson($_POST) : ''));

        if (SrvBase::$instance->task_worker_num && isset($_REQUEST[ASYNC_NAME]) && $_REQUEST[ASYNC_NAME] == 1) { //异步任务
            $task_id = SrvBase::$instance->task([
                '_COOKIE' => $_COOKIE,
                '_FILES' => $_FILES,
                '_GET' => $_GET,
                '_POST' => $_POST,
                '_REQUEST' => $_REQUEST,
                '_SERVER' => $_SERVER,
                'header' => $request->header,
                'rawBody' => $request->rawContent()
            ]);
            if ($task_id === false) {
                $response->write(Helper::toJson(Control::fail('异步任务调用失败:'.SrvBase::err())));
            } else {
                $response->write(Helper::toJson(Control::ok(['task_id' => $task_id])));
            }
            $response->end();
        } else {
            //myphp::setEnv('headers', $request->header);
            myphp::req()->setHeaders($request->header);
            myphp::req()->setRawBody($request->rawContent()); //file_get_contents("php://input")
            myphp::Run(function ($code, $res, $header) use ($response) {
                //myphp::setEnv('headers');
                if ($header) {
                    foreach ($header as $name => $val) {
                        $response->header($name, $val);
                    }
                }
                $response->status($code);
                /**
                 * @var \myphp\Response $res
                 */
                if ($res->file) { //发送文件 [$file, $offset, $size]
                    $response->sendfile($res->file[0], $res->file[1], $res->file[2]);
                } else { // 发送内容
                    $data = is_scalar($res->body) ? $res->body : Helper::toJson($res->body);
                    $data !== '' && $response->write($data);
                    $response->end();
                }
            }, false);
        }
    }
    //异步任务 在task_worker进程内被调用
    public static function onTask(Server $server, int $task_id, int $src_worker_id, $data)
    {
        //重置
        $_COOKIE = $data['_COOKIE'];
        $_FILES = $data['_FILES'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        $_REQUEST = $data['_REQUEST'];
        $_SERVER = $data['_SERVER'];
        myphp::req()->setHeaders($data['header']);
        myphp::req()->setRawBody($data['rawBody']);
        myphp::Run(function ($code, $res, $header) use ($task_id, $src_worker_id) {
            /**
             * @var \myphp\Response $res
             */
            $data = is_scalar($res->body) ? $res->body : Helper::toJson($res->body);
            if (SrvBase::$isConsole) {
                SrvBase::safeEcho("AsyncTask Finish:Connect.task_id=" . $task_id . ',src_worker_id=' . $src_worker_id . ', ' . $data . PHP_EOL);
            }
        }, false);
        unset($_COOKIE, $_FILES, $_GET, $_POST, $_REQUEST, $_SERVER);
        //return 等同$server->finish($response); 这里没有return不会触发finish事件
    }
    //异步任务完成 当worker进程投递的任务在task_worker中完成时，task进程会通过Server->finish()方法将任务处理的结果发送给worker进程
    //return void;
    public static function onFinish(Server $server, int $task_id, string $data)
    {
        //todo
        //echo "AsyncTask Finish:Connect.task_id=" . $task_id .", " . $data.PHP_EOL;
    }
}
