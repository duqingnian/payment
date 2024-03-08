<?php

namespace App\Channel\Payhere;

class Balance extends Base
{
	public function query()
	{
		$api = "https://api.payhere.id/v1/balance";
		$header = ['Authorization:Bearer '.$this->payin_secret];
		$ret = $this->post_json($api,'',$header,'GET');
		
		if(is_array($ret) && 200 == $ret[0])
		{
			$ret_data  = json_decode($ret[1],true);
			if(array_key_exists('status',$ret_data) && 200 == $ret_data['status'])
			{
				if(array_key_exists('data',$ret_data))
				{
					$data = [];
					$ret = $ret_data['data'];
					if(array_key_exists('balance',$ret))
					{
						$data[] = ['text'=>'余额','amount'=>$ret['balance']];
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



