<?php

namespace App\Channel\Kirinpayment;

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
		$status = ['_success'=>'CODE_FINISHED','_fail'=>'CODE_REFUSE'];
		$notify_data = [
			'code'=>$status[$data['STATUS']],
			'order_no'=>$data['CNO'],
			'out_trade_no'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'sign'=>$data['SIGN'],
			'err_msg'=>'',
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'code'=>1,
			'msg'=>'success',
			'data'=>[
				'amount'=>$amount,
				'order_no'=>$this->DATA['TEST']['channel_order_no'],
				'out_trade_no'=>$this->plantform_order_no,
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
		
		$amount = number_format($this->DATA['amount'],2);
		$amount = str_replace(',','',$amount);
		
		$api = "https://gw.kirinpayment.net/mch/withdrawin";
		$post_data = [
			'appid'=>$this->payout_appid,
			'money'=>$amount,
			'out_trade_no'=>$this->plantform_order_no,
			'type'=>$this->DATA['bank_code'],
			'name'=>$this->DATA['account_name'],
			'account'=>$this->DATA['account_no'],
			'callback'=>$this->DATA['plantform_notify_url'],
			'mobile'=>$this->DATA['phone'],
			'email'=>$this->DATA['email'],
			'ifsc_code'=>'',
		];
		if(in_array($this->DATA['bank_code'],['RTGS','NEFT','IMPS']))
		{
			$post_data['ifsc_code'] = $this->DATA['ext_no'];
		}
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
		
		$ret_data = json_decode($ret[1],true);
		if(!array_key_exists('code',$ret_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if(1 != strtoupper($ret_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$ret[1]];
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
		$clean_data['channel_order_no'] = $ret_data['data']['order_no'];
		$clean_data['plantform_order_no'] = $ret_data['data']['out_trade_no'];

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
		if(in_array($_DATA['bank_code'],['IMPS','NEFT','RTGS']))
		{
			return ['bank_code','account_name','account_no','ifsc'];
		}
		return ['bank_code','account_name','account_no'];
	}

	//给商户的接口
	public function columns($only_key=false)
	{
		$columns = [
			['type'=>'enum','key'=>'bank_code','text'=>'交易','is_require'=>1,'summary'=>'交易类型（IMPS、NEFT、RTGS、UPI、PAYTM）','options'=>['IMPS', 'NEFT', 'RTGS', 'UPI', 'PAYTM']],
			['type'=>'input','key'=>'account_name','text'=>'姓名','is_require'=>1,'summary'=>''],
			['type'=>'input','key'=>'account_no','text'=>'账号','is_require'=>1,'summary'=>'受益人帳戶。交易類型為IMPS、NEFT、RTGS時，此項內容為受益人帳戶號碼；交易類型為UPI時，此项内容为VPA/UPI ID；交易類型為PAYTM時，此項內容為Paytm的10位電話號碼'],
			['type'=>'input','key'=>'ext_no','text'=>'扩展号码','is_require'=>0,'summary'=>'交易类型为IMPS、NEFT、RTGS时，此为ifsc'],
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
	private function ascii_params($params = array())
	{
		if (!empty($params)) {$p = ksort($params);if ($p) {$str = '';foreach ($params as $k => $val) {$str .= $k . '=' . $val . '&';}{$strs = rtrim($str, '&');}return $strs;}}
		return '';
	}
	//生成通道签名 去掉merchant_no、timestamp、sign_type、sign
	public function generate_payout_sign($data,$secret)
	{
		$str = $this->ascii_params($data);
		return strtoupper(md5($str.'&key='.$secret));
	}
}



