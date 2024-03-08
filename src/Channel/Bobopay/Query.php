<?php

namespace App\Channel\Bobopay;

class Query extends Base
{
	public function handle($order)
	{
		if(!$order)
		{
			return;
		}
		$pno = $order->getPno();
		if(strlen($pno) < 4)
		{
			return;
		}
		
		$timestamp = round(microtime(true) * 1000);
		if('PI' == substr($pno,0,2))
		{
			$api_url = 'https://api.bobopay.in/api/payin/query';
		}
		else if('PO' == substr($pno,0,2))
		{
			$api_url = 'https://api.bobopay.in/api/payout/query';
		}
		else
		{
			return;
		}
		$post_data = [
			'merchantId'=>$this->payin_appid,
			'orderId'=>$pno,
			"timestamp"=>$timestamp,
		];
		$post_data['sign'] = md5($this->payin_appid.$pno.$timestamp.$this->payin_secret);
		$ret = $this->post_json($api_url,$post_data);
		
		if(is_array($ret) && 200 == $ret[0])
		{
			$ret_data  = json_decode($ret[1],true);
			if(array_key_exists('status',$ret_data) && "200" == $ret_data['status'])
			{
				if(array_key_exists('data',$ret_data))
				{
					return [
						'STATUS'=>$ret_data['data']['status'],
						'DATA'=>$ret_data['data'],
					];
				}
			}
		}
		return '';
	}
}



