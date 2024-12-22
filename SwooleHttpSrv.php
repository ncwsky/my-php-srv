<?php

declare(strict_types=1);
class SwooleHttpSrv extends SwooleSrv
{
    //初始服务之前执行
    protected function beforeInit()
    {
        //BASE模式下Manager进程是可选的，当设置了worker_num=1，并且没有使用Task和MaxRequest特性时，底层将直接创建一个单独的Worker进程，不创建Manager进程
        $this->config['type'] = self::TYPE_HTTP;
        $this->mode = SWOOLE_BASE; //单线程模式 异步非阻塞Server同nginx https://wiki.swoole.com/wiki/page/353.html
    }
}
