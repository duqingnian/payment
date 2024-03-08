<?php

namespace App\Channel\Stabpay;

class Payout extends Base
{
	private function _check()
	{
		if(!array_key_exists('bank_code',$this->DATA))
		{
			$this->e('bank_code is missing');
		}
		if(!in_array($this->DATA['bank_code'],['CPF', 'CNPJ', 'PHONE', 'EMAIL']))
		{
			$this->e('bank_code must be [CPF, CNPJ, PHONE, EMAIL]');
		}
		if(!array_key_exists('account_no',$this->DATA))
		{
			$this->e('account_no is missing');
		}
		if(!array_key_exists('account_name',$this->DATA))
		{
			$this->e('account_name is missing');
		}
		if(!array_key_exists('ext_no',$this->DATA))
		{
			//$this->e('ext_no is missing');
		}
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>2,'_fail'=>3];
		$notify_data = [
			'amount'=>$data['AMOUNT'],
			'amountReal'=>$data['AMOUNT'],
			'channel'=>'SIMULATION',
			'orderNo'=>$data['PNO'],
			'payNo'=>$data['CNO'],
			'status'=>$status[$data['STATUS']],
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
			'data'=>[
				'amount'=>$amount,
				'channel'=>'SIMULATION',
				'currency'=>'BRL',
				'fee'=>'0.00',
				'orderNo'=>$this->plantform_order_no,
				'payNo'=>$this->DATA['TEST']['channel_order_no'],
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
		
		$api = "https://api.stabpay.com/payout/createOrder";
		$post_data = [
			'merchantId'=>$this->payout_appid,
			'country'=>2,
			'orderNo'=>$this->plantform_order_no,
			'amount'=>$amount,
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
			'pixType'=>$this->DATA['bank_code'],
			'accountNum'=>$this->DATA['account_no'],
			'name'=>$this->DATA['account_name'],
			'cert'=>$this->DATA['account_no'],
		];
		$post_data['sign'] = $this->generate($post_data,$this->payout_secret);

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
		if('200' != strtoupper($ret_data['code']))
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
		$clean_data['channel_order_no'] = $ret_data['data']['payNo'];
		$clean_data['plantform_order_no'] = $ret_data['data']['orderNo'];

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
			['type'=>'enum','key'=>'bank_code','text'=>'支付类型','is_require'=>1,'summary'=>'PIX账号类型[CPF, CNPJ, PHONE, EMAIL]','options'=>['CPF', 'CNPJ', 'PHONE', 'EMAIL']],
			['type'=>'input','key'=>'account_no','text'=>'PIX账号','is_require'=>1,'summary'=>''],
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
	function ascii_params($params = array())
	{
		if (!empty($params)) {
			$p = ksort($params);
			if ($p) {
				$str = '';
				foreach ($params as $k => $val) {
					$str .= $k . '=' . $val . '&';
				}
				$strs = rtrim($str, '&');
				return $strs;
			}
		}
		return '参数错误';
	}

	function generate($data, $key, $key_name='key',$ext=[])
	{
		$str = $this->ascii_params($data);
		$signature = "";
		if (function_exists('hash_hmac')) {
			$signature = base64_encode(hash_hmac("sha1", $str, $key, true));
		} else {
			$blocksize = 64;
			$hashfunc = 'sha1';
			if (strlen($key) > $blocksize) {
				$key = pack('H*', $hashfunc($key));
			}
			$key = str_pad($key, $blocksize, chr(0x00));
			$ipad = str_repeat(chr(0x36), $blocksize);
			$opad = str_repeat(chr(0x5c), $blocksize);
			$hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $str))));
			$signature = base64_encode($hmac);
		}
		return $signature;
	}
}



