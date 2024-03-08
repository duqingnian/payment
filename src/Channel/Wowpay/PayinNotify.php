<?php

namespace App\Channel\Wowpay;

class PayinNotify extends \App\Channel\BasePayinNotify
{
	public function get_message()
	{
		//获取通道发来的回调数据
		if(!array_key_exists('HTTP_CONTENT_TYPE',$_SERVER))
		{
			$this->e('HTTP_CONTENT_TYPE IS MISSING');
		}
		$content_type = $_SERVER['HTTP_CONTENT_TYPE'];
		
		if(false !== strstr($content_type,'json'))
		{
			$data = json_decode(file_get_contents('php://input'),true);
		}
		else
		{
			$data = $_POST;
		}
		
		$data['_ip'] = $this->GetIp();
		$data['_content_type'] = $content_type;
		file_put_contents('/mnt/v2/payment/src/Channel/Wowpayva/debug/main.'.time().'.txt', json_encode($data));

		echo '{"success": true}';
		die();
	}
	
	public function complete()
	{
		echo '{"success": true}';
		die();
	}
	
}


