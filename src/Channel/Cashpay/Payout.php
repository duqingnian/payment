<?php

namespace App\Channel\Cashpay;

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
	}
	
		//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'01','_fail'=>'02'];
		$notify_data = [
			'status'=>$status[$data['STATUS']],
			'orderId'=>$data['CNO'], //平台单号
			'merchantOrderId'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'realPayAmount'=>$data['AMOUNT'],
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
			'amount'=>$amount,
			'merchantOrderId'=>$this->plantform_order_no,
			'orderId'=>$this->DATA['TEST']['channel_order_no'],
			'status'=>'90',
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
		
		$amount = $this->DATA['amount']*100;
		
		$api = "https://pix.cashpag.com/open-api/pay/transfer_fast";
		$post_data = [
			'amount'=>$amount,
			'merchantOrderId'=>$this->plantform_order_no,
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
			'customerName'=>$this->DATA['account_name'],
			'customerCert'=>$this->DATA['account_no'],
			'accountType'=>$this->DATA['bank_code'],
			'accountNum'=>$this->DATA['account_no'],
			'merchantUserId'=>rand(4215,9786).rand(4215,9786),
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
		$header[] = 'Authorization:Basic '.base64_encode($this->payout_appid.':'.$this->payout_secret);
		
		if(0 == $simulation)
		{
			$ret = $this->post_json($api,$post_data,$header);
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
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
		];
		$clean_data['channel_order_no'] = $ret_data['orderId'];
		$clean_data['plantform_order_no'] = $ret_data['merchantOrderId'];

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
		return '';
	}

	public static function create($map,$appSecret) {
        $signStr = self::createSignStr($appSecret, $map);
        return hash('sha256', $signStr);
    }

    public static function createSignStr($appSecret, $map) {
        $signStr = self::joinMap($map);
        $signStr .= '&'. 'key' . '=' . $appSecret;

        return $signStr;
    }

    private static function prepareMap($map) {
        if (!is_array($map)) {
            return array();
        }

        if (array_key_exists('sign', $map)) {
            unset($map['sign']);
        }
        ksort($map);
        reset($map);

        return $map;
    }

    private static function joinMap($map) {
        if (!is_array($map)) {
            return '';
        }

        $map = self::prepareMap($map);
        $pair = array();
        foreach($map as $key => $value) {
            if (self::isIgnoredItem($key, $value)) {
                continue;
            }

            $tmp = $key . '=';
            if(0 === strcmp('extra', $key)) {
                 $tmp .= self::joinMap($value);
            } else {
                $tmp .= $value;
            }

            $pair[] = $tmp;
        }

        if (empty($pair)) {
            return '';
        }

        return join('&', $pair);
    }

    private static function isIgnoredItem($key, $value) {
        if (empty($key) || empty($value)) {
            return true;
        }

        if (0 === strcmp('sign', $key)) {
            return true;
        }

        if (0 === strcmp('extra', $key)) {
            return false;
        }

        if (is_string($value)) {
            return false;
        }
        
        if (is_numeric($value)) {
            return false;
        }

        if (is_bool($value)) {
            return false;
        }
         
        return true;
    }
}



