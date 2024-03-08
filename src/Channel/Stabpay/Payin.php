<?php

namespace App\Channel\Stabpay;

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
			'amountReal'=>$data['AMOUNT'],
			'orderNo'=>$data['PNO'],
			'payNo'=>$data['CNO'], //平台单号
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
			'code'=>200,
			'msg'=>'success',
			'data'=>[
				'amount'=>$amount,
				'currency'=>'BRL',
				'extended'=>'',
				'fee'=>'0.00',
				'jumpUrl'=>$this->DATA['TEST']['pay_url'],
				'orderNo'=>$this->plantform_order_no,
				'payNo'=>$this->DATA['TEST']['channel_order_no'],
				'realAmount'=>$amount-0.01,
				'rqcode'=>'',
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://api.stabpay.com/payment/createOrder";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$post_data = [
			'merchantId'=>$this->payin_appid,
			'country'=>2,
			'orderNo'=>$this->plantform_order_no,
			'amount'=>$amount,
			'name'=>"baishipay",
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
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
		if('200' != strtoupper($api_return_data['code']))
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
			'channel_order_no'=>$ret_data['data']['payNo'],
			'pay_url'=>$ret_data['data']['jumpUrl'],
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

	function generate($data, $key)
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



