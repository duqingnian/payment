<?php

namespace App\Channel\Shivapay;

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
			//$this->e('account_name is missing');
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
		$account_name = substr(md5('XIAZHAOLI'.microtime()),0,8);
		if(array_key_exists('account_name',$this->DATA))
		{
			$account_name = $this->DATA['account_name'];
		}
		$ifsc_code = $this->DATA['bank_code'];
		if(array_key_exists('ifsc_code',$this->DATA))
		{
			$ifsc_code = $this->DATA['ifsc_code'];
		}
		if(array_key_exists('ext_no',$this->DATA))
		{
			$ifsc_code = $this->DATA['ext_no'];
		}
		
		$account_phone = '87'.rand(1241,9854).rand(2432,9746);
		if(array_key_exists('account_phone',$this->DATA))
		{
			$account_phone = $this->DATA['account_phone'];
		}

		$amount = number_format($this->DATA['amount'],2);
		$amount = str_replace(',','',$amount);
		
		$api = "https://api.shivapay.in/v1/externalApi/withdraw/create";
		$post_data = [
			'token'=>$this->payout_appid,
			'bankAccountName'=>$this->DATA['account_name'],
			'bankAccountNumber'=>$this->DATA['account_no'],
			'callBackUrl'=>$this->DATA['plantform_notify_url'],
			'customerOrderNo'=>$this->plantform_order_no,
			'description'=>'',
			'orderAmount'=>$amount,
			'payEmail'=>$this->DATA['account_name'].'@auto.gen',
			'payIfsc'=>$ifsc_code,
			'payPhone'=>$account_phone,
		];
		$post_data['signKey'] = $this->sign($post_data,$this->payout_secret);

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
		$clean_data['order_status'] = $ret_data['data']['orderStatus'];
		$clean_data['channel_order_no'] = $ret_data['data']['platOrderNo'];
		$clean_data['plantform_order_no'] = $ret_data['data']['customerOrderNo'];

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
		return $this->columns(true);
	}

	//给商户的接口
	public function columns($only_key=false)
	{
		$columns = [
			['type'=>'enum','key'=>'bank_code','text'=>'IFSC编号','is_require'=>1,'summary'=>'前四位IFSC编号，第5位是0，后6位银行编码'],
			['type'=>'input','key'=>'account_no','text'=>'卡号','is_require'=>1,'summary'=>''],
			['type'=>'input','key'=>'account_name','text'=>'姓名','is_require'=>1,'summary'=>''],
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
	
	///////////////////
	//以下是签名相关方法
	///////////////////
	 public function sign($map, $signKey) {
        //拼接加密前原串
        $sign = $this->createLinkString($map, $signKey);
        //将拼接好的字符串进行MD5加密
        return md5($sign);
    }

    public function createLinkString($map, $signKey) {
        //对集合的key按ASCII码字典序升序排序
        $keys = array_keys($map);
        sort($keys);
        $prestr = "";

        //将排序后非空的数据集合的value取出拼接成字符串
        foreach ($keys as $key) {
            $value = $map[$key];
            if ($value === null || $value === "" || strtolower($key) === "signkey") {
                continue;
            }
            $prestr .= $value;
        }
        $prestr .= $signKey;
        return $prestr;
    }
}



