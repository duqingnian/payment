<?php

namespace App\Channel\Bobopay;

class Payout extends Base
{
	private function _check()
	{
		if(!array_key_exists('bank_code',$this->DATA))
		{
			$this->e('bank_code is missing');
		}
		if(!array_key_exists('account_no',$this->DATA))
		{
			$this->e('account_no is missing');
		}
		if(!array_key_exists('account_name',$this->DATA))
		{
			$this->e('account_name is missing');
		}
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'SUCCESS','_fail'=>'FAILED'];
		$notify_data = [
			'customerOrderNo'=>$data['PNO'],
			'description'=>'',
			'orderAmount'=>$data['AMOUNT'],
			'orderStatus'=>$status[$data['STATUS']],
			'platOrderNo'=>$data['CNO'],
			'signKey'=>$data['SIGN'],
			'token'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'status'=>'200',
			'message'=>'success',
			'data'=>[
				'orderAmount'=>$amount,
				"orderStatus"=>"PENDING",
				'customerOrderNo'=>$this->plantform_order_no,
				'platOrderNo'=>$this->DATA['TEST']['channel_order_no'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$bank_code = $this->DATA['bank_code'];
		$account_no = $this->DATA['account_no'];
		$account_name = $this->DATA['account_name'];
		$ext_no = $this->DATA['ext_no'];

		$amount = number_format($this->DATA['amount'],2);
		$amount = str_replace(',','',$amount);
		
		$timestamp = round(microtime(true) * 1000);
		
		$api = "https://api.bobopay.in/api/payout";
		$post_data = [
			'amount'=>$amount,
			'merchantId'=>$this->payout_appid,
			'orderId'=>$this->plantform_order_no,
			'timestamp'=>$timestamp,
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
			'accountHolder'=>$this->DATA['account_name'],
			'accountNumber'=>$this->DATA['account_no'],
		];
		if('UPI' == $bank_code)
		{
			$post_data['upi'] = $ext_no;
		}
		else
		{
			$post_data['ifsc'] = $ext_no;
		}
		$post_data['sign'] = md5($amount.$this->payout_appid.$this->plantform_order_no.$timestamp.$this->payout_secret);

		//将提交给接口的数据保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
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
		if(NULL == $ret)
		{
			$this->e('[-1]Exception:RET_NULL');
		}
		if(!is_array($ret))
		{
			$this->e('[-1]Exception:INVALIDATE_RET:['.$ret.']');
		}
		if(2 != count($ret))
		{
			$this->e('[-1]Exception:INVALIDATE_RET_COUNT:['.json_encode($ret).']');
		}
		$http_code = $ret[0];
		
		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('C_RTPF_D'); //channel return to plantform data
		$process->setData($ret[1]);
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		$ret_data = json_decode($ret[1],true);
		if(!array_key_exists('status',$ret_data))
		{
			return ['status'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_status'];
		}
		if(200 != strtoupper($ret_data['status']))
		{
			return ['code'=>-1,'msg'=>'['.$this->plantform_order_no.']RET:'.$ret[1]];
		}
		if(!array_key_exists('data',$ret_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_data'];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
		];
		$clean_data['channel_order_no'] = $ret_data['data']['payOrderId'];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('C_RTPF_CD');
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



