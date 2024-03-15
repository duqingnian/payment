<?php

namespace App\Util;

class SyncMsg
{
    public function err($DATA)
    {
        $IO       = $DATA['IO'];
        $merchant = $DATA['merchant'];
        $order    = $DATA['order'];
        $msg      = $DATA['msg'];
        $entityManager = $DATA['entityManager'];

        //update order status to FAIL
        if($order)
        {
            $order->setStatus('FAIL');
            $entityManager->flush();
        }

        //notify merchant error msg
        $merchant_notify_url = $order->getMerchantNotifyUrl();
		$merchant_notify_data = [
            'code'=>-1,
            'msg'=>$msg,
			'amount'=>$order->getAmount(),
			'fee'=>$order->getMfee(),
			'order_status'=>'FAIL',
			'plantform_order_no'=>$order->getPno(),
			'real_amount'=>$order->getAmount(),
			'shanghu_order_no'=>$order->getMno(),
			'time'=>time(),
		];
		$merchant_notify_data['sign'] = md5($this->stand_ascii_params($merchant_notify_data).'&key='.$merchant->getPayoutSecret());
		
		$ret = $this->post_json($merchant_notify_url,$merchant_notify_data);
        //////////////////////////////////

        //save it to log
        $notify_log = new \App\Entity\MerchantNotifyLog();
		$notify_log->setBundle('O' == $IO ? 'PAYOUT' : 'PAYIN');
		$notify_log->setDelay('0');
		$notify_log->setTargetTime(time());
		$notify_log->setOrderId($order->getId());
		$notify_log->setData($merchant_notify_data);
		$notify_log->setMerchantNotifyUrl($merchant_notify_url);
		$notify_log->setRetHttpCode($ret[0]);
		$notify_log->setRet(substr($ret[1],0,200));
		$notify_log->setCreatedAt(time());
		$entityManager->persist($notify_log);
		$entityManager->flush();
    }

    function post_json($url, $jsonStr,$header=[],$method='POST')
	{
		if(is_array($jsonStr))
		{
			$jsonStr = json_encode($jsonStr);
		}
		$ch = curl_init();
		if('GET' == $method)
		{
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		}
		else
		{
			curl_setopt($ch, CURLOPT_POST, 1);
		}
		$header[] = 'Content-Type: application/json;charset=utf-8';
		$header[] = 'Content-Length: ' . strlen($jsonStr);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($httpCode, $response);
	}
}

