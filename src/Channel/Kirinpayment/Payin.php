<?php

namespace App\Channel\Kirinpayment;

class Payin extends Base
{	
	private function _check()
	{
		//测试通道不需要检测任何东西
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'CODE_SUCCESS','_fail'=>'CODE_FAILURE'];
		$notify_data = [
			'callbacks'=>$status[$data['STATUS']],
			'out_trade_no'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'amount_true'=>$data['AMOUNT'],
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
			'msg'=>'success',
			'url'=>$this->DATA['TEST']['pay_url'],
			'data'=>[
				'order_no'=>$this->DATA['TEST']['channel_order_no'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://gw.kirinpayment.net/pay/unifiedorder?format=json";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$post_data = [
			'appid'=>$this->payin_appid,
			'pay_type'=>"upi",
			'trade_type'=>"account",
			'return_type'=>"PC",
			'amount'=>$amount,
			'out_trade_no'=>$this->plantform_order_no,
			'callback_url'=>$this->DATA['plantform_notify_url'],
			'version'=>'v2.0',
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
			'channel_order_no'=>$ret_data['data']['order_no'],
			'pay_url'=>$ret_data['url'],
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
	private function ascii_params($params = array())
	{
		if (!empty($params)) {
			$p = ksort($params);
			if ($p) {
				$str = '';
				foreach ($params as $k => $val) {$str .= $k . '=' . $val . '&';}
				$strs = rtrim($str, '&');
				return $strs;
			}
		}
		return '参数错误';
	}

	public function generate($data, $secret)
	{
		return strtoupper(md5($this->ascii_params($data).'&key='.$secret));
	}
}



