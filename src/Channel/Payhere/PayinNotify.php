<?php

namespace App\Channel\Payhere;

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
		
		//$allow_ips = [''];
		//if(!in_array($data['_ip'],$allow_ips))
		//{
			//$this->e('IP_DENY:'.$data['_ip']);
		//}
		
		//根据商户发来的订单号查找订单
		if(!array_key_exists('merchant_trx_id',$data))
		{
			$this->e('merchant_trx_id is missing');
		}
		
		$order = $this->entityManager->getRepository(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$data['merchant_trx_id']]);
		if(!$order)
		{
			$this->e('payin order:'.$data['merchant_trx_id'].' not found');
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
		$order_status = $data['status'];
		$const_status = $this->entityManager->getRepository(\App\Entity\ChannelStatusCode::class)->findOneBy(['cid'=>$order->getCid(),'bundle'=>'PAYIN_NOTIFY','channel_code'=>$order_status]);
		if($const_status)
		{
			$order_status = $const_status->getConstStatus();
		}
		
		$real_amount = $data['amount'];
		
		$return = ['code'=>0,'msg'=>'OK'];
		$return['plantform_order_no'] = $order->getPno();
		$return['order_status'] = $order_status;
		$return['real_amount'] = $real_amount;
		
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


