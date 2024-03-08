<?php

namespace App\Channel\Wowpayqris;

class Payin extends Base
{
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'SUCCEED','_fail'=>'FAILED'];
		$notify_data = [
			'referenceId'=>$data['PNO'],
			'orders'=>[
				[
					'id'=>$data['CNO'],
					'method'=>"QRIS",
					'amount'=>$data['AMOUNT'],
					'status'=>$status[$data['STATUS']],
				],
			],
			'signKey'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	//只能从paylink这边提交请求
	public function handle($simulation = 0)
	{
		if(!array_key_exists('token',$this->DATA))
		{
			return ['code'=>-1,'msg'=>'token is missing!'];
		}
		if(!array_key_exists('original',$this->DATA))
		{
			return ['code'=>-1,'msg'=>'PAYLINK REQUEST NOT ALLOWED!'];
		}
		if('link' != $this->DATA['original'])
		{
			return ['code'=>-1,'msg'=>'invalidate payment original'];
		}
		
		$order = $this->DATA['order'];
		
		//$api = "https://dev.wowpayidr.com/rest/cash-in/payment-checkout";
		$api = "https://igwhTB.wowpayidr.com/rest/cash-in/payment-checkout";
		$post_data = [
			'referenceId'=>$order->getPno(),
			'amount'=>$order->getAmount(),
			'customerName'=>'m'.$order->getMid(),
			//'supportMethods '=>[$this->DATA['item']],
			'supportMethods'=>["QRIS"],
			'notifyUrl'=>'https://pay.baishipay.com/api/notify/I/wowpayqris',
			'redirectUrl'=>'https://pay.baishipay.com/paylink.finesh/'.$this->DATA['token'],
			/*'merchant_trx_id'=>$order->getPno(),
			'amount'=>$order->getAmount(),
			'title'=>'m'.$order->getMid(),
			'expired_time'=>date('c',time()+86400),
			'return_url'=>'https://pay.baishipay.com/paylink.finesh/'.$this->DATA['token'],
			'callback_url'=>'https://pay.baishipay.com/api/notify/I/payhereva',
			'payment_method'=>'virtual_account',
			'payment_channel'=>$this->DATA['item'],*/
		];
		
		//将提交给接口的数据保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('PF_RTC_D'); //plantform request to channel data
		$process->setData(json_encode($post_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		//开始提交
		$headers = [];
		$headers[] = 'X-SN: '.$this->payin_appid;
		$headers[] = 'X-SECRET: '.$this->payin_secret;
		$ret = $this->_post_json($api,$post_data,$headers);
		//print_r($ret);die();
		if(!is_array($ret))
		{
			return ['code'=>-1,'msg'=>'NETWORK_ERROR_WHILE_POST_DATA'];
		}
		if(200 != $ret[0])
		{
			$file = '/mnt/v2/payment/src/Channel/Wowpayqris/debug/NOT200_'.time().'.txt';
			file_put_contents($file,json_encode($ret));
			
			return ['code'=>-1,'msg'=>'HTTP_NOT_200:'.$ret[1]];
		}
		
		//通道接口的返回值
		if(strlen(trim($ret[1])) < 1)
		{
			$file = '/mnt/v2/payment/src/Channel/Wowpayqris/debug/'.time().'.txt';
			file_put_contents($file,json_encode($ret));
			return ['code'=>-1,'msg'=>'API_RESULT_NULL'];
		}
		$http_code = $ret[0];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_RTPF_D'); //channel return to plantform data
		$process->setData($ret[1]);
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();

		$api_return_data = json_decode($ret[1],true);
		if(!is_array($api_return_data))
		{
			$this->e('['.$order->getId().']INVALIDATE_CHANNEL_RESULT_DATA');
		}
		if(!array_key_exists('code',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if('SUCCESS' != $api_return_data['code'])
		{
			return ['code'=>-1,'msg'=>'RET_CODE_NOT_SUCCESS:'.$api_return_data['code']];
		}
		if(!array_key_exists('data',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_data'];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
			'channel_order_no'=>$api_return_data['data']['referenceId'],
			'pay_url'=>$api_return_data['data']['url'],
		];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_RTPF_CD'); //channel return to plantform clean data
		$process->setData(json_encode($clean_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		return $clean_data;
	}
	
	function _post_json($url, $data = null,$header=[],$method='POST') {
        $curl = curl_init ();
        curl_setopt ( $curl, CURLOPT_URL, $url );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYHOST, FALSE );
        if (! empty ( $data )) 
		{
            curl_setopt ( $curl, CURLOPT_POST, 1 );
			$header[] = 'Content-Type: application/json;charset=utf-8';
			curl_setopt ( $curl, CURLOPT_POSTFIELDS, json_encode($data) );
        }
        curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $curl, CURLOPT_HTTPHEADER, $header);
        $response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return array($httpCode, $response);
    }
}



