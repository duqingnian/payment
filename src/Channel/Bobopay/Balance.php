<?php

namespace App\Channel\Bobopay;

class Balance extends Base
{
	public function query()
	{
		$timestamp = round(microtime(true) * 1000);
		$post_data = [
			"merchantId"=>"Z0004",
			"timestamp"=>$timestamp,
		];
		$post_data['sign'] = md5('Z0004'.$timestamp.'yg8SzR72y3');
		$ret = $this->post_json('https://api.bobopay.in/api/balance',$post_data);
		
		if(is_array($ret) && 200 == $ret[0])
		{
			$ret_data  = json_decode($ret[1],true);
			if(array_key_exists('status',$ret_data) && "200" == $ret_data['status'])
			{
				if(array_key_exists('data',$ret_data))
				{
					$data = [];
					$ret = $ret_data['data'];
					
					if(array_key_exists('availableAmount',$ret))
					{
						$data[] = ['text'=>'可用','amount'=>$ret['availableAmount']];
					}
					if(array_key_exists('freezeAmount',$ret))
					{
						$data[] = ['text'=>'冻结','amount'=>$ret['freezeAmount']];
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
}



