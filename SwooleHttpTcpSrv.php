<?php

declare(strict_types=1);
//tcp+http  示例
class SwooleHttpTcpSrv extends SwooleSrv
{
    public const LEN_TYPE = 'L'; //长度类型 无符号长整形 L主机序 N网络序
    public const LEN_SIZE = 4; //包体定字节位
    public function __construct($config)
    {
        parent::__construct($config);
        $this->config['type'] = self::TYPE_HTTP;
        if (empty($this->config['listen'])) {
            $this->config['listen'] =  [ //监听其他地址
                'tcp' => [
                    'type' => SWOOLE_SOCK_TCP, //不设置默认tcp  SWOOLE_SOCK_TCP , SWOOLE_SOCK_UDP
                    'setting' => [
                        'open_length_check' => true,
                        'package_max_length' => 65536, //64K 最大数据包尺寸 单位为字节
                        'package_length_type' => self::LEN_TYPE, //无符号长整形
                        'package_length_offset' => 0, //长度定字节位
                        'package_body_offset' => self::LEN_SIZE, //包体定字节位
                    ]
                ],
            ];
        }
    }
    //组装发送TCP数据 长度+内容
    public static function pack_data($send_data)
    {
        return pack(self::LEN_TYPE, strlen($send_data)) . $send_data;
    }
}
