<?php
/**
 * 微信接口处理类
 * @author: chen
 * @version: 2013-12-20
 */
class WxAction extends BaseAction
{
    //类属性
    private $user_id;
    private $token;
    private $member_id;

    /**
     * api接口处理函数
     */
    public function wxapi()
    {
        $user = $this->_get('user');
        $userInfo = D('User')->where("name='".$user."'")->find();
        $this->user_id = $userInfo['id'];
        $this->token = $userInfo['token'];
		$this->valid();
		$this->responseMsg();
	}

    /**
     * 验证函数
     */
	private function valid()
    {
        $echoStr = $_GET["echostr"];
        //判断是否为验证数据
        if(!empty($echoStr)){
            if($this->checkSignature()){
            	echo $echoStr;
            	exit;
            }
        }
    }

    /**
     * 验证过程
     */
	private function checkSignature()
	{
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];
		$tmpArr = array($this->token, $timestamp, $nonce);
		sort($tmpArr);
		$tmpStr = implode( $tmpArr );
		$tmpStr = sha1( $tmpStr );
		if( $tmpStr == $signature ){
			return true;
		}else{
			return false;
		}
	}

    /**
     * 处理数据
     */
    private function responseMsg()
    {
        //获取post数据
		$postStr = $GLOBALS["HTTP_RAW_POST_DATA"];
        //如果post数据为空，则退出
        if(empty($postStr)){
            exit;
        }
        //将post数据解码为数组
        $arrPost = (array)simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
        //get the member id
        $this->member_id = D('Member')->getMemberIdByWechatId($this->user_id, $arrPost['FromUserName']);
        //将访问信息存入数据库
        D('MemberMsg')->addWechatMessage($arrPost, $this->member_id);
        //将用户发送的信息转换为关键字形式
        $keyword = $this->getKeyword($arrPost);
        //根据post数组获取返回数据内容
        $content = $this->getContent($keyword);
        //组装xml头部
        $content = D('Wx')->setHeader($arrPost, $content);
        echo $content;
    }

    /**
     * 把用户发送的原始信息转换为关键字形式
     * @return string $keyword 关键字
     * @param array $arrPost 用户POST提交的数据
     */
    private function getKeyword($arrPost)
    {
        //若用户发送的信息为text格式，则直接返回text内容
        if($arrPost['MsgType'] == 'text'){
            return $arrPost['Content'];
        //若用户发送的是关注事件，则返回“关注”
        }elseif($arrPost['Event'] == 'subscribe'){
            return '关注';
        //若用户返回的是取消关注事件，则返回“取消关注"
        }elseif($arrPost['Event'] == 'unsubscribe'){
            return '取消关注';
        //若用户发送的是菜单点击事件，则返回点击值
        }elseif($arrPost['EventKey']){
            return $arrPost['EventKey'];
        //否则，返回“无匹配”
        }else{
            return '默认';
        }
    }

    /**
     * 根据获取到的关键字搜索路由表进行匹配
     * @return xml $content 处理后的数据
     * @param string $keyword 关键字
     */
    private function getContent($keyword)
    {
        $routeObj = D('WechatRoute');
        $arrMap = array(
            'user_id' => $this->user_id,
            'keyword' => $keyword,
        );
        $routeInfo = $routeObj->where($arrMap)->find();
        //如果无匹配，则直接退出
        if(empty($routeInfo)){
            $noneMap = array(
                'keyword' => '默认',
                'user_id' => $this->user_id,
            );
            $routeInfo = $routeObj->where($noneMap)->find();
        }
        if(empty($routeInfo)){
            exit;
        }
        return $this->getPush($routeInfo['obj_type'], $routeInfo['obj_id'], $keyword);
    }

    /**
     * get the push data
     * @param string $obj_type
     * @param int $obj_id
     * @param varchar $keyword
     * @return varchar $pushInfo
     */
    private function getPush($obj_type, $obj_id, $keyword)
    {
        switch($obj_type){
        case 'text':
            $textInfo = D('WechatText')->where('id='.$obj_id)->getField('content');
            $pushInfo = D('Wx')->setText($textInfo);
            break;
        case 'tool':
            $function = D('WechatTool')->where('id='.$obj_id)->getField('function');
            $pushInfo = D('WechatTool')->$function($keyword, $this->member_id);
            break;
        case 'news':
            $newsList = D('WechatNewsMeta')->where('news_id='.$obj_id)->select();
            $count = count($newsList);
            $pushInfo = D('Wx')->setNews($newsList, $count, $this->member_id);
            break;
        }
        return $pushInfo;
    }

}
