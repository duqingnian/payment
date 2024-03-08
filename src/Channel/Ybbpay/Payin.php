<?php

namespace App\Channel\Ybbpay;

class Payin extends Base
{	
	private function _check()
	{
		//测试通道不需要检测任何东西
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'SUCCESS','_fail'=>'FAIL'];
		$notify_data = [
			'orderStatus'=>$status[$data['STATUS']],
			'transaction_id'=>$data['CNO'],
			'orderId'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'sign'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'code'=>1000,
			'msg'=>'success',
			'data'=>[
				'pay_amount'=>$amount,
				'transaction_id'=>$this->DATA['TEST']['channel_order_no'],
				'pay_orderid'=>$this->plantform_order_no,
				'pay_type'=>'simulation',
				'pay_url'=>$this->DATA['TEST']['pay_url'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://api.ybbpay.net/pay";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$post_data = [
			'pay_memberid'=>$this->payin_appid,
			'pay_orderid'=>$this->plantform_order_no,
			'pay_amount'=>$amount,
			'pay_type'=>'UPI',
			'pay_applytime'=>time(),
			'pay_notifyurl'=>$this->DATA['plantform_notify_url'],
			'pay_name'=>substr(md5('CAT'.microtime().date('YmdHis').rand(111,999)),0,8),
			'pay_mobile'=>$this->__randomNum(10),
		];
		$post_data['pay_sign'] = $this->generate($post_data,$this->payin_secret);

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
		if('1000' != strtoupper($api_return_data['code']))
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
			'channel_order_no'=>$ret_data['data']['transaction_id'],
			'pay_url'=>$ret_data['data']['pay_url'],
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
	function __randomNum($len=6)
	{
		$tel_arr = array('1883846','1883847','1883856','1883990','1883991','1883992','1883993','1883995','1883996');
		$num = $tel_arr[array_rand($tel_arr)].mt_rand(1342,9786);
		return substr($num,0,$len);
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

	public function generate($data, $secret, $key_name='key',$ext=[])
	{
		if('' == $key_name)
		{
			$key_name = 'key';
		}
		return strtoupper(md5($this->ascii_params($data).'&'.$key_name.'='.$secret));
	}
}



