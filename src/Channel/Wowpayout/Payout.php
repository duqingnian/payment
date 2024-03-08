<?php

namespace App\Channel\Wowpayout;

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
		$status = ['_success'=>'SUCCEED','_fail'=>'FAILED'];
		$notify_data = [
			'id'=>$data['CNO'],
			'referenceId'=>$data['PNO'],
			'orderAmount'=>$data['AMOUNT'],
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
			'code'=>'SUCCESS',
			'message'=>'',
			'data'=>[
				"status"=>"SUCCEED",
				'referenceId'=>$this->plantform_order_no,
				'id'=>$this->DATA['TEST']['channel_order_no'],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$amount = $this->DATA['amount'];
		
		$bank_code = $this->DATA['bank_code'];
		$account_no = $this->DATA['account_no'];
		$account_name = $this->DATA['account_name'];
		
		//转换一下银行代码  
		$bank = $this->entityManager->getRepository(\App\Entity\BankCode::class)->findOneBy(['cid'=>$this->DATA['channel_id'],'clean_code'=>$bank_code]);
		if(!$bank)
		{
			$this->e('bank code not found:'.$this->DATA['channel_id'].':'.$bank_code);
		}
		
		//转换通道代码bank_code  wowpayout $this->DATA['channel_id']
		$_bank_code = $this->entityManager->getRepository(\App\Entity\BankCode::class)->findOneBy(['cid'=>$this->DATA['channel_id'],'clean_code'=>$this->DATA['bank_code']]);
		if(!$_bank_code)
		{
			$this->e('invalidate bank_code:'.$this->DATA['bank_code']);
		}
		
		//$api = "https://dev.wowpayidr.com/rest/cash-out/disbursement";
		$api = "https://igwhTB.wowpayidr.com/rest/cash-out/disbursement";
		$post_data = [
			'referenceId'=>$this->plantform_order_no,
			'customerName'=>$this->DATA['account_name'],
			'bankCode'=>$_bank_code->getChannelCode(),
			'cardNo'=>$this->DATA['account_no'],
			'amount'=>$amount,
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
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
			$headers[] = 'X-SN: '.$this->payin_appid;
			$headers[] = 'X-SECRET: '.$this->payin_secret;
			$ret = $this->_post_json($api,$post_data,$headers);
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
		if(200 != $ret[0])
		{
			return ['code'=>-1,'msg'=>'HTTP_NOT_200:'.$ret[1]];
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
		if(!is_array($api_return_data))
		{
			$this->e('INVALIDATE_CHANNEL_RESULT_DATA');
		}
		if(!array_key_exists('code',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		if('SUCCESS' != $api_return_data['code'])
		{
			return ['code'=>-1,'msg'=>'RET_CODE_NOT_SUCCESS:'.$api_return_data['code']];
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
		];
		$clean_data['channel_order_no'] = $api_return_data['data']['id'];
		$clean_data['plantform_order_no'] = $api_return_data['data']['referenceId'];

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
	
	function _post_json($url, $data = null,$header=[],$method='POST') {
        $curl = curl_init ();
        curl_setopt ( $curl, CURLOPT_URL, $url );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYHOST, FALSE );
        if (! empty ( $data )) 
		{
            curl_setopt ( $curl, CURLOPT_POST, 1 );
			$header[] = 'Content-Type: application/json;charset=utf-8';
			curl_setopt ( $curl, CURLOPT_POSTFIELDS, json_encode($data) );
        }
        curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $curl, CURLOPT_HTTPHEADER, $header);
        $response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return array($httpCode, $response);
    }
}



