<?php

namespace App\Channel\Shivapay;

class Payin extends Base
{	
	private function _check()
	{
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'ARRIVED','_fail'=>'FAILED'];
		$notify_data = [
			'actualPayAmount'=>$data['AMOUNT'],
			'customerOrderNo'=>$data['PNO'],
			'orderStatus'=>$status[$data['STATUS']],
			'platOrderNo'=>$data['CNO'],
			'signKey'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'status'=>200,
			'message'=>'success',
			'data'=>[
				'orderAmount'=>$amount,
				'checkoutCounterLink'=>$this->DATA['TEST']['pay_url'],
				'customerOrderNo'=>$this->plantform_order_no,
				'platOrderNo'=>$this->DATA['TEST']['channel_order_no'],
				'token'=>md5($this->plantform_order_no),
				'orderStatus'=>'CREATED',
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://api.shivapay.in/v1/externalApi/recharge/create";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		
		$tel_arr = array('183846','183847','183856','183990','183991','183992','183993','183995','183996');
		$phone = mt_rand(6,9).$tel_arr[array_rand($tel_arr)].mt_rand(132,976);
		
		$post_data = [
			'callbackUrl'=>$this->DATA['plantform_notify_url'],
			'customerOrderNo'=>$this->plantform_order_no,
			'orderAmount'=>$amount,
			'paySuccessUrl'=>'https://pay.baishipay.com/pay_result',
			'paymentType'=>"UPI",
			'token'=>$this->payin_appid,
			'rechargeEmail'=>substr(md5('CAT'.microtime().date('YmdHis').rand(111,999)),0,8).'@autogen.com',
			'rechargeName'=>substr(md5('CAT'.microtime().date('YmdHis').rand(111,999)),0,8),
			'rechargePhone'=>$phone,
		];
		$post_data['signKey'] = $this->generate($post_data,$this->payin_secret);

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
			return ['code'=>-1,'msg'=>'HTTP_NOT_200:'.$ret[1]];
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
		if(!array_key_exists('status',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_status:'.$ret[1]];
		}
		if(200 != strtoupper($api_return_data['status']))
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
			'channel_order_no'=>$ret_data['data']['platOrderNo'],
			'pay_url'=>$ret_data['data']['checkoutCounterLink'],
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
	public function generate($data, $secret, $key_name='key',$ext=[])
	{
        $sign = $this->createLinkString($data, $secret);
        return md5($sign);
	}
	
	public function createLinkString($map, $signKey) 
	{
        //对集合的key按ASCII码字典序升序排序
        $keys = array_keys($map);
        sort($keys);
        $prestr = "";

        //将排序后非空的数据集合的value取出拼接成字符串
        foreach ($keys as $key) 
		{
            $value = $map[$key];
            if ($value === null || $value === "" || strtolower($key) === "signkey") 
			{
                continue;
            }
            $prestr .= $value;
        }
        $prestr .= $signKey;
        return $prestr;
    }
}



