<?php
return [
    'name' => 'SrvDemo', //服务名
    'ip' => '0.0.0.0', //监听地址
    'port' => 6001, //监听地址
    'type' => 'http', //类型[http websocket tcp udp] swoole:websocket同时支持http请求
    'setting' => [
        'static_path' => __DIR__ . '/web', //workerman 静态文件目录
        'count' => cpu_num() * 4, // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
        //'reusePort'=> true, //设置当前worker是否开启监听端口复用(socket的SO_REUSEPORT选项)
        //'reloadable' => true, //设置当前Worker实例是否可以reload 默认为true
        //'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
        //swoole
        'open_http2_protocol' => true,
        'package_max_length' => 10 * 1024 * 1024 //包大小
    ]
];