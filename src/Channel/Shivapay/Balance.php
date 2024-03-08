<?php

namespace App\Channel\Shivapay;

class Balance extends Base
{
	public function query()
	{
		$api = "https://api.shivapay.in/v1/externalApi/balance";
		
		$timestamp = round(microtime(true) * 1000);
		
		$post_data = [
			'timestamp'=>$timestamp,
			'token'=>$this->payin_appid,
		];
		$post_data['signKey'] = $this->generate($post_data,$this->payin_secret);
		
		$ret = $this->post_json($api,$post_data);
		
		if(is_array($ret) && 200 == $ret[0])
		{
			$ret_data  = json_decode($ret[1],true);
			if(array_key_exists('status',$ret_data) && 200 == $ret_data['status'])
			{
				if(array_key_exists('data',$ret_data))
				{
					$data = [];
					$ret = $ret_data['data'];
					if(array_key_exists('availableAmount',$ret))
					{
						$data[] = ['text'=>'可用余额','amount'=>$ret['availableAmount']];
					}
					if(array_key_exists('processingAmount',$ret))
					{
						$data[] = ['text'=>'处理中金额','amount'=>$ret['processingAmount']];
					}
					if(array_key_exists('totalAmount',$ret))
					{
						$data[] = ['text'=>'商户总余额','amount'=>$ret['totalAmount']];
					}
					$data[] = ['text'=>'T','T'=>time()];
					return json_encode($data);
				}
				else
				{
					return '-';
				}
			}
			else
			{
				return '-';
			}
		}
		else
		{
			return '-';
		}
	}
	
	public function generate($data, $secret, $key_name='key',$ext=[])
	{
        $sign = $this->createLinkString($data, $secret);
        return md5($sign);
	}
	
	public function createLinkString($map, $signKey) 
	{
        //对集合的key按ASCII码字典序升序排序
        $keys = array_keys($map);
        sort($keys);
        $prestr = "";

        //将排序后非空的数据集合的value取出拼接成字符串
        foreach ($keys as $key) 
		{
            $value = $map[$key];
            if ($value === null || $value === "" || strtolower($key) === "signkey") 
			{
                continue;
            }
            $prestr .= $value;
        }
        $prestr .= $signKey;
        return $prestr;
    }
}



