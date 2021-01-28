<?php
//直接继承基类
class IndexAct extends Base{
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
		
        $headers = myphp::env('headers', getallheaders());
        $url = 'http://'.$domain.'.tun.guanliyuangong.com'.$url;
        $header = $headers['cookie']??'';

		$timeout = Q('get.timeout%d{1,60}', 35);
        if(Helper::isPost()){
            $data = Q('post.:null');
            $ret = Http::doPost($url, $data, $timeout, '', ['cookie'=>$header,'res'=>true]);
        }else{
            $ret = Http::doGet($url, $timeout, '', ['cookie'=>$header,'res'=>true]);
        }
        if($ret===false){
            return self::json(self::fail('请求失败'));
        }

        $contentType = '';
        $headers = explode("\r\n", $ret['res_header']);
        foreach ($headers as $v){
            if(strpos($v,': ')){
                list($name, $val) = explode(': ', $v, 2);
                if($name=='Set-Cookie' || $name=='Content-Type'){
                    myphp::setHeader($name, $val);
                    //break;
                }
            }
        }
        
        return $ret['res_body'];
    }
}