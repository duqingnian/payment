<?php

namespace App\Channel\Coinpay;

class Payout extends Base
{
	private function _check()
	{
		if(!array_key_exists('account_name',$this->DATA))
		{
			$this->e('account_name is missing');
		}
		if(!array_key_exists('account_no',$this->DATA))
		{
			$this->e('account_no is missing');
		}
		if(!array_key_exists('ext_no',$this->DATA))
		{
			$this->e('ifsc column: ext_no  is missing');
		}
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'1','_fail'=>'2'];
		$notify_data = [
			'code'=>$status[$data['STATUS']],
			'mchId'=>"1859",
			'mchOrderNo'=>$data['PNO'],
			'message'=>"success",
			'orderAmount'=>$data['AMOUNT']*100,
			'payOrderId'=>'TC'.md5(($data['AMOUNT']*100).microtime()),
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$simulation_data = [
			'code'=>200,
			'msg'=>'success',
			'data'=>[
				'orderAmount'=>$this->DATA['amount'] * 100,
				'payOrderId'=>$this->DATA['TEST']['channel_order_no'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();

		$account_name = $this->DATA['account_name'];
		$account_no = $this->DATA['account_no'];
		$ext_no = $this->DATA['ext_no'];
		
		$api = "https://coin-pay.vip/api/pay/create_order";
		
		$amount = $this->DATA['amount'] * 100;
		$post_data = [
			'type'=>1,
			'mchId'=>'1859',
			
			'accountname'=>$account_name,
			'cardnumber'=>$account_no,
			'mode'=>'IMPS',
			'ifsc'=>$ext_no,
			'bankname'=>'wavepay',
			
			'mchOrderNo'=>$this->plantform_order_no,
			'productId'=>27,
			'orderAmount'=>$amount,
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
			'clientIp'=>'64.32.27.21',
			'device'=>'android',
			'uid'=>$this->DATA['merchant_id'],
			'customerName'=>'player'.$this->DATA['merchant_id'],
			'tel'=>rand(6828,9862).rand(1028,9674).rand(28,98),
			'email'=>'player'.$this->DATA['merchant_id'].'@baishipay.com',
			'returnType'=>'json',
		];
		$post_data['sign'] = $this->generate($post_data,$this->payin_secret);

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
		if(200 != strtoupper($ret_data['code']))
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
	
	public function get_sign_columns($_DATA)
	{
		return [];
	}

	//给商户的接口
	public function columns($only_key=false)
	{
		return [];
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
		return strtolower(md5($this->ascii_params($data).'&key='.$secret));
	}
}



