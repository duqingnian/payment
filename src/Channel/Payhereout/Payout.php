<?php

namespace App\Channel\Payhereout;

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
		$status = ['_success'=>'PAID','_fail'=>'FAILED'];
		$notify_data = [
			'uuid'=>$data['CNO'],
			'merchant_trx_id'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'status'=>$status[$data['STATUS']],
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
			'status'=>'201',
			'success'=>true,
			'data'=>[
				'amount'=>$amount,
				"status"=>"PAID",
				'merchant_trx_id'=>$this->plantform_order_no,
				'uuid'=>$this->DATA['TEST']['channel_order_no'],
			],
		];
		
		return [201,json_encode($simulation_data)];
	}
	
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$amount = $this->DATA['amount'];
		
		$bank_code = $this->DATA['bank_code'];
		$account_no = $this->DATA['account_no'];
		$account_name = $this->DATA['account_name'];
		$bank = $this->entityManager->getRepository(\App\Entity\BankCode::class)->findOneBy(['cid'=>$this->DATA['channel_id'],'clean_code'=>$bank_code]);
		if(!$bank)
		{
			$this->e('bank code not found:'.$bank_code);
		}
		
		$api = "https://api.payhere.id/v1/disbursement";
		$post_data = [
			'merchant_trx_id'=>$this->plantform_order_no,
			'recipient_name'=>$this->DATA['account_name'],
			'recipient_bank_code'=>$bank->getChannelCode(),
			'recipient_account_number'=>$this->DATA['account_no'],
			'amount'=>$amount,
			'callback_url'=>$this->DATA['plantform_notify_url'],
		];

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
			$headers = [];
			$headers[] = 'Authorization:Bearer '.$this->payin_secret;
			$ret = $this->_post_form($api,$post_data,$headers);
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
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if('201' != $ret_data['status'])
		{
			return ['code'=>-1,'msg'=>'RET:'.$ret[1]];
		}
		if(!array_key_exists('data',$ret_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_data:'.$ret[1]];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
		];
		$clean_data['channel_order_no'] = $ret_data['data']['uuid'];
		$clean_data['plantform_order_no'] = $ret_data['data']['merchant_trx_id'];

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
	
	function _post_form($url, $data = null,$header=[],$method='POST') {
        $curl = curl_init ();
        curl_setopt ( $curl, CURLOPT_URL, $url );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYHOST, FALSE );
        if (! empty ( $data )) 
		{
            curl_setopt ( $curl, CURLOPT_POST, 1 );
			$header[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
			curl_setopt ( $curl, CURLOPT_POSTFIELDS, http_build_query($data) );
        }
        curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $curl, CURLOPT_HTTPHEADER, $header);
        $response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return array($httpCode, $response);
    }
}



