<?php

namespace App\Channel\Shivapay;

class PayinNotify extends \App\Channel\BasePayinNotify
{
	public function get_message()
	{
		//获取通道发来的回调数据
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
		
		//根据商户发来的订单号查找订单
		if(!array_key_exists('customerOrderNo',$data))
		{
			$this->e('customerOrderNo is missing');
		}
		
		$order = $this->entityManager->getRepository(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$data['customerOrderNo']]);
		if(!$order)
		{
			$this->e('order:'.$data['customerOrderNo'].' not found');
		}
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_NTPF_D'); //channel notify to plantform data
		$process->setData(json_encode($data));
		$process->setPno($order->getPno());
		$process->setCreatedAt(time());
		$process->setCid($order->getCid());
		$process->setMid($order->getMid());
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		//订单状态信息
		$order_status = $data['orderStatus'];
		$const_status = $this->entityManager->getRepository(\App\Entity\ChannelStatusCode::class)->findOneBy(['cid'=>$order->getCid(),'bundle'=>'PAYIN_NOTIFY','channel_code'=>$order_status]);
		if($const_status)
		{
			$order_status = $const_status->getConstStatus();
		}
		
		$return = ['code'=>0,'msg'=>'OK'];
		$return['channel_order_no'] = $data['platOrderNo'];
		$return['plantform_order_no'] = $order->getPno();
		$return['order_status'] = $order_status;
		$return['real_amount'] = $data['actualPayAmount'];
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_NTPF_CD'); //channel notify to plantform clean data
		$process->setData(json_encode($return));
		$process->setPno($return['plantform_order_no']);
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


