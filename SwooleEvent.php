<?php

declare(strict_types=1);
use myphp\Control;
use myphp\Helper;
use myphp\Log;
use Swoole\Server;

class SwooleEvent
{
    //当工作进程收到由 sendMessage 发送的管道消息时会触发onPipeMessage事件
    //参见 https://wiki.swoole.com/wiki/page/363.html
    //public static function onPipeMessage(Server $server, int $src_worker_id, $message);

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
        if ($_POST && $_GET) {
            $_REQUEST = array_merge($_GET, $_POST);
        } elseif ($_POST) {
            $_REQUEST = $_POST;
        } else {
            $_REQUEST = $_GET;
        }
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
                    $data = is_scalar($res->body) ? (string)$res->body : Helper::toJson($res->body);
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
}
