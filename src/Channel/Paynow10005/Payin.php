<?php

namespace App\Channel\Paynow10005;

class Payin extends Base
{	
	private function _check()
	{
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>2,'_fail'=>3];
		$notify_data = [
			'amount'=>$data['AMOUNT'],
			'payAmount'=>$data['AMOUNT'],
			'channelCode'=>"NGN_TRANSFER",
			'merchantOrderNo'=>$data['PNO'],
			'orderNo'=>$data['CNO'],
			'status'=>$status[$data['STATUS']],
			'sign'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'code'=>0,
			'msg'=>'success',
			'data'=>[
				'amount'=>$amount,
				'merchantOrderNo'=>$this->plantform_order_no,
				'orderNo'=>$this->DATA['TEST']['channel_order_no'],
				'sign'=>md5($this->plantform_order_no),
				'link'=>$this->DATA['TEST']['pay_url'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://gateway.paynow.network/open/v1/payins/create";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$post_data = [
			'merchantNo'=>$this->payin_appid,
			'timestamp'=>time(),
			'signType'=>'MD5',
			'channelCode'=>'NGN_TRANSFER',
			'merchantOrderNo'=>$this->plantform_order_no,
			'amount'=>$amount,
		];
		$post_data['sign'] = $this->generate($post_data,$this->payin_secret);

		//将提交给接口的数据保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('PF_RTC_D'); //plantform request to channel data
		$process->setData(json_encode($post_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		//开始提交
		if(0 == $simulation)
		{
			$ret = $this->post_json($api,$post_data);
		}
		else
		{
			$ret = $this->_get_simulation_data();
		}
		
		if(!is_array($ret))
		{
			return ['code'=>-1,'msg'=>'NETWORK_ERROR_WHILE_POST_DATA'];
		}
		if(200 != $ret[0])
		{
			return ['code'=>-1,'msg'=>'HTTP_NOT_200_EXCEPT:200:'.$ret[0]];
		}
		
		//通道接口的返回值
		if(strlen(trim($ret[1])) < 1)
		{
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
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		$ret_data  = json_decode($ret[1],true);
		if(!is_array($ret_data))
		{
			$this->e('['.$process->getId().']INVALIDATE_CHANNEL_RESULT_DATA');
		}

		$api_return_data = json_decode($ret[1],true);
		if(!array_key_exists('code',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if('0' != strtoupper($api_return_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$api_return_data['code'].':'.$api_return_data['msg']];
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
			'channel_order_no'=>$ret_data['data']['orderNo'],
			'pay_url'=>$ret_data['data']['link'],
		];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_RTPF_CD'); //channel return to plantform clean data
		$process->setData(json_encode($clean_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		return $clean_data;
	}
	
	/////////////////////////////////
	// 生成签名
	/////////////////////////////////
	public function generate($data, $secret)
	{
		$merchant_no = $data['merchantNo'];
		$timestamp = $data['timestamp'];
		$sign_type = $data['signType'];
		
		unset($data['merchantNo']);
		unset($data['timestamp']);
		unset($data['signType']);
		unset($data['sign']);
		
		ksort($data);
		
		return md5($merchant_no.json_encode($data).$sign_type.$timestamp.$secret);
	}
}



