<?php
namespace app\control;

class IndexAct extends \myphp\Control{
    public function _init(){
        header('Access-Control-Allow-Origin: *'); //跨域测试
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept'); //跨域测试
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE'); //跨域测试
    }
    public function index(){
        $url = Q('get.url:273'); #validate_url: 273
        if(!$url){
            return self::json(self::fail('error url'));
        }

        $cookie = \myphp::req()->header('Cookie', '');
        $timeout = Q('get.timeout%d{1,60}', 35);
        if(\myphp\Helper::isPost()){
            $data = Q('post.:null');
            $ret = \Http::doPost($url, $data, $timeout, '', ['cookie'=>$cookie,'res'=>true,'redirect'=>3]);
        }else{
            $ret = \Http::doGet($url, $timeout, '', ['cookie'=>$cookie,'res'=>true,'redirect'=>3]);
        }
        if($ret===false){
            return self::json(self::fail('请求失败'));
        }

        $headers = explode("\r\n", $ret['res_header']);
        foreach ($headers as $v){
            if(strpos($v,': ')){
                list($name, $val) = explode(': ', $v, 2);
                if($name=='Set-Cookie' || $name=='Content-Type'){
                    \myphp::setHeader($name, $val);
                    //break;
                }
            }
        }

        return $ret['res_body'];
    }
    //下载文件
    public function down()
    {
        $file = trim($this->request->get('file', ''));
        if ($file==='' || strpos($file, '..')) {
            return self::fail('文件路径无效');
        }
        $root = 'F:\\'; //指定下载目录
        $file = ltrim($file, '/');
        $_file = $root . $file;
        if (!file_exists($_file)) {
            return self::fail('文件不存在:'.$_file);
        }
        $this->response->steamLimit = 100; //每秒100kB
        return $this->response->sendFile($_file);
    }
}