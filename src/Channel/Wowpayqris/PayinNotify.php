<?php

namespace App\Channel\Wowpayqris;

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
		if(!array_key_exists('referenceId',$data))
		{
			$this->e('referenceId is missing');
		}
		
		$order = $this->entityManager->getRepository(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$data['referenceId']]);
		if(!$order)
		{
			$this->e('payin order:'.$data['referenceId'].' not found');
		}
		
		$status = '';
		$cno = '';
		$method = 'qris';
		$cid = $order->getCid();
		
		if(array_key_exists('orders',$data))
		{
			if(array_key_exists('id',$data['orders'][0]) && $data['orders'][0]['id'] != '')
			{
				$cno = $data['orders'][0]['id'];
			}
			if(array_key_exists('method',$data['orders'][0]) && strtoupper($data['orders'][0]['method']) == 'QRIS')
			{
				$method = 'qris';
			}
			if(array_key_exists('status',$data['orders'][0]) && $data['orders'][0]['status'] != '')
			{
				$status = $data['orders'][0]['status'];
			}
		}
		
		//查找支付方式设置信息，更新订单的商户费率和单笔费用
		$channel_pay_method_setting = $this->entityManager->getRepository(\App\Entity\ChannelPaymentMethodSetting::class)->findOneBy(['mid'=>$order->getMid(),'cid'=>$order->getCid(),'method'=>$method]);
		if($channel_pay_method_setting)
		{
			//查找支付方式关联通道，更新通道费率和金额
			$channel_pay_method = $this->entityManager->getRepository(\App\Entity\ChannelPayMethod::class)->findOneBy(['cid'=>$order->getCid(),'method'=>$method]);
			if($channel_pay_method)
			{
				if(is_numeric($channel_pay_method->getTargetCid()) && $channel_pay_method->getTargetCid() > 0)
				{
					$target_channel = $this->entityManager->getRepository(\App\Entity\Channel::class)->find($channel_pay_method->getTargetCid());
					if($target_channel)
					{
						$cid = $target_channel->getId();
						$order->setCpct($target_channel->getPayinPct());
						$order->setCsf($target_channel->getPayinSf());
						$order->setCfee($order->getAmount() * ($target_channel->getPayinPct() / 100) + $target_channel->getPayinSf());
					}
				}
			}
			
			//更新商户费率和金额
			$order->setCno($cno);
			$order->setMpct($channel_pay_method_setting->getPct());
			$order->setMsf($channel_pay_method_setting->getSf());
			$order->setMfee($order->getAmount() * ($channel_pay_method_setting->getPct() / 100) + $channel_pay_method_setting->getSf());
			$this->entityManager->flush();
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
		$order_status = $status;
		$const_status = $this->entityManager->getRepository(\App\Entity\ChannelStatusCode::class)->findOneBy(['cid'=>$cid,'bundle'=>'PAYIN_NOTIFY','channel_code'=>$order_status]);
		
		if($const_status)
		{
			$order_status = $const_status->getConstStatus();
		}

		$return = ['code'=>0,'msg'=>'OK'];
		$return['channel_order_no'] = $cno;
		$return['plantform_order_no'] = $order->getPno();
		$return['order_status'] = $order_status;
		
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
		echo '{"success": true}';
		die();
	}
	
}


