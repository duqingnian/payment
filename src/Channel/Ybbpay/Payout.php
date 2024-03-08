<?php

namespace App\Channel\Ybbpay;

class Payout extends Base
{
	private function _check()
	{
		if(!array_key_exists('bank_code',$this->DATA))
		{
			$this->e('bank_code is missing');
		}
		if(!array_key_exists('account_name',$this->DATA))
		{
			$this->e('account_name is missing');
		}
		else
		{}
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'SUCCESS','_fail'=>'FAIL'];
		$notify_data = [
			'amount'=>$data['AMOUNT'],
			'tradeId'=>$data['CNO'],
			'orderId'=>$data['PNO'],
			'orderStatus'=>$status[$data['STATUS']],
			'resCode'=>1000,
			'resMsg'=>'success',
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
				'amount'=>$amount,
				'orderId'=>$this->plantform_order_no,
				'tradeId'=>$this->DATA['TEST']['channel_order_no'],
				'orderStatus'=>'1',
				'sign'=>md5($this->plantform_order_no),
				'model'=>'simulation',
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();

		$amount = number_format($this->DATA['amount'],2);
		$amount = str_replace(',','',$amount);
		
		$api = "https://api.ybbpay.net/payout/create";
		
		$account_type = 'bank_account';
		if('UPI' == $this->DATA['bank_code'])
		{
			$account_type = 'vpa';
		}
		
		$post_data = [
			'pay_memberid'=>$this->payout_appid,
			'pay_orderid'=>$this->plantform_order_no,
			'model'=>'IMPS',
			'pay_amount'=>$amount,
			'pay_applytime'=>time(),
			'pay_notifyurl'=>$this->DATA['plantform_notify_url'],
			'account_type'=>$account_type,
			'pay_name'=>$this->DATA['account_name'],
			'account_number'=>$this->DATA['account_no'],
			'pay_mobile'=>$this->DATA['phone'],
			'ifsc'=>'',
			'vpa'=>'',
		];
		
		if('bank_account' == $account_type)
		{
			$post_data['ifsc'] = $this->DATA['ext_no'];
		}
		else if('vpa' == $this->DATA['bank_code'])
		{
			$post_data['vpa'] = $this->DATA['ext_no'];
		}
		else
		{}

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
			$ret = $this->post_form($api,$post_data);
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
		if(!array_key_exists('data',$ret_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_data'];
		}
		if(1000 != strtoupper($ret_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$ret[1]];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
		];
		$clean_data['channel_order_no'] = $ret_data['data']['tradeId'];
		$clean_data['plantform_order_no'] = $ret_data['data']['orderId'];

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
		if(!array_key_exists('bank_code',$_DATA))
		{
			$this->e('[bank_code] is missing');
		}
		if(!in_array($_DATA['bank_code'],['bank_account','vpa']))
		{
			$this->e('[bank_code] must be "bank_account" or "vpa" string');
		}
		$bank_code = $_DATA['bank_code'];
		if('bank_account' == $bank_code)
		{
			return ['bank_code','account_name','account_phone','account_no','ifsc'];
		}
		else
		{
			return ['bank_code','account_name','account_phone','vpa'];
		}
	}

	//给商户的接口
	public function columns($only_key=false)
	{
		$columns = [
			['type'=>'enum','key'=>'bank_code','text'=>'交易账户类型','is_require'=>1,'summary'=>'交易账户类型只能为:bank_account或者vpa','options'=>['bank_account','vpa']],
			['type'=>'input','key'=>'account_name','text'=>'姓名','is_require'=>1,'summary'=>'首尾不要有空格'],
			['type'=>'input','key'=>'account_phone','text'=>'手机号','is_require'=>1,'summary'=>'8-12位数字，前面不能带 + 符号'],
			['type'=>'input','key'=>'account_no','text'=>'卡号','is_require'=>0,'summary'=>'account_type 为 bank_account 时提交'],
			['type'=>'input','key'=>'ifsc','text'=>'ifsc','is_require'=>0,'summary'=>'account_type 为 bank_account 时提交'],
			['type'=>'input','key'=>'vpa','text'=>'vpa','is_require'=>0,'summary'=>'account_type 为 vpa 时提交'],
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
		$data = $this->ascii_params($data);
		$sign = md5($data.'&key='.$secret);
		return strtoupper($sign);
	}
	private function ascii_params($params = array())
	{
		if (!empty($params)) {$p = ksort($params);if ($p) {$str = '';foreach ($params as $k => $val) {$str .= $k . '=' . $val . '&';}{$strs = rtrim($str, '&');}return $strs;}}
		return '';
	}
}



