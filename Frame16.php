<?php
#frame16的协议，协议格式为 总包长+包体，其中包长为2字节网络字节序的整数，包体可以是普通文本或者二进制数据。
class Frame16
{
    public static function input($buffer, \Workerman\Connection\ConnectionInterface $connection)
    {
        if(strlen($buffer)<2)
        {
            return 0;
        }
        $unpack_data = unpack('ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    public static function decode($buffer)
    {
        return substr($buffer, 2);
    }

    public static function encode($buffer)
    {
        $total_length = 2 + strlen($buffer);
        return pack('n',$total_length) . $buffer;
    }
}