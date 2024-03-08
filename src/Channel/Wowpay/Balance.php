<?php

namespace App\Channel\Wowpay;

class Balance extends Base
{
	public function query()
	{
		$headers = [];
		$headers[] = 'X-SN: igwhTB';
		$headers[] = 'X-SECRET: OVPMAQNMRYGG45GNVR3ZODXXQRH6KVWY';
		$ret = $this->post_json('https://igwhTB.wowpayidr.com/rest/account/balance-inquiry','',$headers,'GET');
		
		if(is_array($ret) && 200 == $ret[0])
		{
			$ret_data  = json_decode($ret[1],true);
			if(array_key_exists('code',$ret_data) && "SUCCESS" == $ret_data['code'])
			{
				if(array_key_exists('data',$ret_data))
				{
					$data = [];
					$ret = $ret_data['data'];
					
					if(array_key_exists('balance',$ret))
					{
						$data[] = ['text'=>'余额','amount'=>$ret['balance']];
					}
					if(array_key_exists('frozenBalance',$ret))
					{
						$data[] = ['text'=>'冻结','amount'=>$ret['frozenBalance']];
					}
					if(array_key_exists('pendingBalance',$ret))
					{
						$data[] = ['text'=>'代付中','amount'=>$ret['pendingBalance']];
					}
					if(array_key_exists('creditAmount',$ret))
					{
						$data[] = ['text'=>'授信金额','amount'=>$ret['creditAmount']];
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



