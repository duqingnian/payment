<?php

namespace App\Channel\Spay;

class Payin extends Base
{	
	private function _check()
	{
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'success','_fail'=>'fail'];
		$notify_data = [
			'amount'=>$data['AMOUNT'],
			'merchant_orderno'=>$data['PNO'],
			'status'=>$status[$data['STATUS']],
			'orderno'=>$data['CNO'],
			'sign'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'code'=>200,
			'errmsg'=>'OK',
			'data'=>[
				'orderno'=>$this->DATA['TEST']['channel_order_no'],
				'payurl'=>$this->DATA['TEST']['pay_url'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://api.ssspays.in/mcapi/prepaidorder/";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$tel_arr = array('183846','183847','183856','183990','183991','183992','183993','183995','183996');
		$phone = mt_rand(6,9).$tel_arr[array_rand($tel_arr)].mt_rand(132,976);
		$timestamp = round(microtime(true) * 1000);
		
		$callback_url = 'https://pay.baishipay.com/pay_result';
		if(array_key_exists('jump_url',$this->DATA) && strlen($this->DATA['jump_url']) > 0)
		{
			$callback_url = $this->DATA['jump_url'];
		}
		
		$post_data = [
			'merchant_id'=>$this->payin_appid,
			'merchant_orderno'=>$this->plantform_order_no,
			'amount'=>$amount,
			'notify_url'=>$this->DATA['plantform_notify_url'],
			'callback_url'=>$callback_url,
			'passage_code'=>'100001',
		];
		$post_data['sign'] = md5($this->stand_ascii_params($post_data).'&key='.$this->payin_secret);

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
			$ret = $this->post_form($api,$post_data);
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
			return ['code'=>-1,'msg'=>'HTTP'.$ret[0].'_NOT_200:'.$ret[1]];
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
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code:'.$ret[1]];
		}
		if(200 != strtoupper($api_return_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$ret[1]];
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
			'channel_order_no'=>$ret_data['data']['orderno'],
			'pay_url'=>$ret_data['data']['payurl'],
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
}



