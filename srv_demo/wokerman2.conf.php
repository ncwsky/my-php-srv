<?php
return [
    'name' => 'SrvDemo', //服务名
    'ver' => '1.0.0',
    'ip' => '0.0.0.0', //监听地址
    'port' => 6001, //监听地址
    'type' => 'http', //类型[http tcp websocket udp] 可通过修改createServer方法自定义服务创建
    'timer_dir' => __DIR__ . '/timer', //定时运行目录
    'myphp_dir' => __DIR__ .'/vendor/myphps/myphp', //myphp目录
    'conf_file'=> __DIR__ .'/conf.php', //app配置文件
    'context'=>[ //$context_option 资源流上下文配置
	/*  'ssl' => [ //启用ssl
            'local_cert' => '', // 也可以是crt文件
            'local_pk'   => '',
        ]*/
    ],
    'setting' => [
        'count' => 10,    // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
        'stdoutFile'=> __DIR__ . '/stdout.log', //终端输出
        'pidFile' => __DIR__ . '/server.pid',
        'logFile' => __DIR__ . '/server.log', //日志文件

        //'reusePort'=> true, //设置当前worker是否开启监听端口复用(socket的SO_REUSEPORT选项)
        //'reloadable' => true, //设置当前Worker实例是否可以reload 默认为true
        'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
    ]
];