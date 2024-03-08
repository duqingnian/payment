<?php

namespace App\Channel\Wowpay;

class Payin extends Base
{	
	private function _check()
	{
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		//
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>200,
			'channel_order_no'=>'',
			'pay_url'=>'https://pay.baishipay.com/paylink/'.$this->authcode('PNO:'.$this->plantform_order_no,'',$this->DATA['appsecret']),
		];
		return $clean_data;
	}
}



