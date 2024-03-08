<?php

namespace App\Channel\Paynow10005;

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
		$status = ['_success'=>2,'_fail'=>0];
		$notify_data = [
			'amount'=>$data['AMOUNT'],
			'payAmount'=>$data['AMOUNT'],
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
		$simulation_data = [
			'code'=>0,
			'msg'=>'success',
			'data'=>true,
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
		
		//转换一下银行代码  
		$bank = $this->entityManager->getRepository(\App\Entity\BankCode::class)->findOneBy(['cid'=>$this->DATA['channel_id'],'clean_code'=>$bank_code]);
		if(!$bank)
		{
			$this->e('bank code not found:'.$bank_code);
		}
		
		$amount = number_format($this->DATA['amount'],2);
		$amount = str_replace(',','',$amount);
		
		$api = "https://gateway.paynow.network/open/v1/payouts/create";
		$post_data = [
			'merchantNo'=>$this->payout_appid,
			'timestamp'=>time(),
			'signType'=>'MD5',
			'channelCode'=>'NGN_PAYOUT',
			'currencyCode'=>'NGN',
			'bankCode'=>$bank->getChannelCode(),
			'accountName'=>$this->DATA['account_name'],
			'accountNo'=>$this->DATA['account_no'],
			'remarks'=>date('Y-m-d H:i:s'),
			'merchantOrderNo'=>$this->plantform_order_no,
			'amount'=>$amount,
		];
		$post_data['sign'] = $this->generate_payout_sign($post_data,$this->payout_secret);

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
		
		$api_return_data = json_decode($ret[1],true);
		if(!array_key_exists('code',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if('0' != strtoupper($api_return_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$ret[1]];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
		];
		//$clean_data['order_status'] = $ret_data['status'];
		//$clean_data['channel_order_no'] = $ret_data['orderNo'];
		//$clean_data['plantform_order_no'] = $ret_data['merchantOrderNo'];
		//$clean_data['real_amount'] = $ret_data['payAmount'];

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
	
	public function get_sign_columns($_DATA)
	{
		//return ['bank_code','account_no','account_name'];
		return $this->columns(true);
	}

	//给商户的接口
	public function columns($only_key=false)
	{
		$columns = [
			['type'=>'input','key'=>'bank_code','text'=>'银行代码','is_require'=>1,'summary'=>''],
			['type'=>'input','key'=>'account_no','text'=>'卡号','is_require'=>1,'summary'=>''],
			['type'=>'input','key'=>'account_name','text'=>'持卡人姓名','is_require'=>1,'summary'=>''],
		];
		if($only_key)
		{
			$arr = [];
			foreach($columns as $column)
			{
				$arr[] = $column['key'];
			}
			return $arr;
		}
		return $columns;
	}
	
	/////////////////////////////////
	// 生成签名
	/////////////////////////////////
	public function generate_payout_sign($data,$secret)
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



