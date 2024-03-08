<?php

namespace App\Channel\Cashpay;

class PayoutNotify extends \App\Channel\BasePayoutNotify
{
	public function get_message()
	{
		if(!array_key_exists('HTTP_CONTENT_TYPE',$_SERVER))
		{
			$this->e('HTTP_CONTENT_TYPE IS MISSING');
		}
		$content_type = $_SERVER['HTTP_CONTENT_TYPE'];
		if(false !== strstr($content_type,'json'))
		{
			$data = json_decode(file_get_contents('php://input'),true);
		}
		else
		{
			$data = $_POST;
		}
		$data['_ip'] = $this->GetIp();
		$data['_content_type'] = $content_type;
		if(!array_key_exists('merchantOrderId',$data))
		{
			$this->e('merchantOrderId IS MISSING');
		}

		$order = $this->entityManager->getRepository(\App\Entity\OrderPayout::class)->findOneBy(['pno'=>$data['merchantOrderId']]);
		if(!$order)
		{
			$this->e('order not found!');
		}
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('C_NTPF_D'); //channel notify to plantform data
		$process->setData(json_encode($data));
		$process->setPno($order->getPno());
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		//订单状态信息
		$order_status = $data['status'];
		$const_status = $this->entityManager->getRepository(\App\Entity\ChannelStatusCode::class)->findOneBy(['cid'=>$order->getCid(),'bundle'=>'PAYOUT_NOTIFY','channel_code'=>$order_status]);
		if($const_status)
		{
			$order_status = $const_status->getConstStatus();
		}
		
		$return = ['code'=>0,'msg'=>'OK'];
		$return['channel_order_no'] = $data['orderId'];
		$return['plantform_order_no'] = $order->getPno();
		$return['order_status'] = $order_status;
		$return['real_amount'] = $data['realPayAmount']/100;
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('C_NTPF_CD'); //channel notify to plantform clean data
		$process->setData(json_encode($return));
		$process->setPno($order->getPno());
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		return $return;
	}
	
	public function complete()
	{
		echo 'success';
		die();
	}
	
}


