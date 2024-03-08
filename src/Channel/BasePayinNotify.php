<?php

namespace App\Channel;

class BasePayinNotify extends \App\Channel\BaseChannel
{
	public function handle()
	{
		//更新订单信息
		$plantform_order_no = $this->DATA['plantform_order_no'];
		$order_status = $this->DATA['order_status'];

		$order = $this->entityManager->getRepository(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$plantform_order_no]);
		if($order)
		{
			//如果已经是当前状态了，不做任何处理
			if($order->getOriginalStatus() == $order_status)
			{
				echo 'SAME_STATUS:'.$plantform_order_no.'('.$order->getOriginalStatus().'='.$order_status.')';
				return;
			}
			
			if('SUCCESS' == $order->getStatus() || 'FAIL' == $order->getStatus())
			{
				echo 'SUCC_OR_FAIL';
				return;
			}
			
			$order->setOriginalStatus($order_status);
			
			//是不是更新通道号
			$cno = '';
			if(array_key_exists('channel_order_no',$this->DATA))
			{
				$cno = $this->DATA['channel_order_no'];
				if('' != $cno && '' == $order->getCno())
				{
					$order->setCno($cno);
				}
			}
			
			//有真实金额字段
			if(array_key_exists('real_amount',$this->DATA))
			{
				$order->setRamount($this->DATA['real_amount']);
			}
			else
			{
				$order->setRamount($order->getAmount());
			}
			
			$order->setStatus($order_status);
			$this->entityManager->flush();

			if('SUCCESS' == $order_status)
			{
				//如果代收回调成功 增加商户余额
				$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());
				if($merchant)
				{
					$alog = new \App\Entity\Log(); //日志
					$alog->setBundle('ADD_BALANCE');
					$alog->setCreatedAt(time());
					$alog->setOrderid($order->getId());
					$alog->setPno($order->getPno());
					$alog->setUid(0);
					$alog->setCid($order->getCid());
					$alog->setMid($order->getMid());
					$alog->setIp('');
					$alog->setSummary('PI_SUCC');
					$alog->setIsTest($merchant->isIsTest());
					$data = ['real_amount'=>-1,'limit_current_amount'=>-1,'limit_amount'=>-1];
					
					if($merchant->isIsTest())
					{
						$old_amount = $merchant->getTestAmount();
					}
					else
					{
						$old_amount = $merchant->getAmount();
					}
					
					$alog->setMoneyBefore($old_amount);
					
					if(array_key_exists('real_amount',$this->DATA))
					{
						$real_amount = $this->DATA['real_amount'];
						$data['real_amount'] = $real_amount;
						$merchant_channel = $this->entityManager->getRepository(\App\Entity\MerchantChannel::class)->findOneBy(['mid'=>$merchant->getId(),'cid'=>$order->getCid(),'bundle'=>'PAYIN']);
						if(!$merchant_channel)
						{
							//没有分配通道，这种情况很难出现
						}
						else
						{
							$pct = $merchant_channel->getPct();
							$sf  = $merchant_channel->getSf();
							$new_amount = $old_amount + ($real_amount - $real_amount*($pct/100) - $sf);
							
							$order->setCfee($real_amount * ($order->getCpct()/100) + $order->getCsf());
							$order->setMfee($real_amount * ($order->getMpct()/100) + $order->getMsf());
						}
						
						$alog->setMoney($real_amount);
					}
					else
					{
						$new_amount = $old_amount + ($order->getAmount() - $order->getMfee());
						
						$order->setCfee($order->getAmount() * ($order->getCpct()/100) + $order->getCsf());
						$order->setMfee($order->getAmount() * ($order->getMpct()/100) + $order->getMsf());
						
						$alog->setMoney($order->getAmount());
					}
					
					//设置新余额
					if($order->isIsTest())
					{
						$merchant->setTestAmount($new_amount);
					}
					else
					{
						$merchant->setAmount($new_amount);
					}
					$alog->setMoneyAfter($new_amount);
					
					//更新限额
					$merchant_payin_limit = $this->entityManager->getRepository(\App\Entity\MerchantPayinLimit::class)->findOneBy(['mid'=>$merchant->getId(),'cid'=>$order->getCid()]);
					if($merchant_payin_limit && $merchant_payin_limit->getLimitAmount() > 1)
					{
						$current_amount = $merchant_payin_limit->getCurrentAmount();
						
						$data['limit_amount'] = $merchant_payin_limit->getLimitAmount();
						$data['limit_current_amount'] = $current_amount;
						
						if(0 == $merchant_payin_limit->getStartAt())
						{
							$merchant_payin_limit->setStartAt(time());
						}
						if(0 == $merchant_payin_limit->getFirstOrderId())
						{
							$merchant_payin_limit->setFirstOrderId($order->getId());
						}
						$merchant_payin_limit->setCurrentAmount($current_amount + $order->getRamount());
						
						//是否关闭
						if($current_amount + $order->getRamount() >= $merchant_payin_limit->getLimitAmount() )
						{
							$merchant_payin_limit->setLastOrderId($order->getId());
							$merchant_payin_limit->setClosedAt(time());
							$merchant_payin_limit->setIsClosed(1);
						}
					}
					$data['cfee'] = $order->getCfee();
					$data['mfee'] = $order->getMfee();
					$alog->setData(json_encode($data));
					$this->entityManager->persist($alog);
					$this->entityManager->flush();
				}
			}

			//回调给商户
			$merchant_notify_url = $order->getMerchantNotifyUrl();
			
			$time = time();
			$merchant_notify_data = [
				'amount'=>$order->getAmount(),
				'fee'=>$order->getMfee(),
				'order_status'=>$order->getStatus(),
				'plantform_order_no'=>$order->getPno(),
				'real_amount'=>$order->getRamount(),
				'shanghu_order_no'=>$order->getMno(),
				'time'=>$time,
			];
			$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());
			$payin_secret = $merchant->getPayinSecret();
			
			$merchant_notify_data['sign'] = md5($this->stand_ascii_params($merchant_notify_data).'&key='.$payin_secret);
			
			$json_data = json_encode($merchant_notify_data);
			$ret = $this->post_json($merchant_notify_url,$json_data);
			
			//保存下来
			$notify_log = new \App\Entity\MerchantNotifyLog();
			$notify_log->setBundle('PAYIN');
			$notify_log->setDelay('0');
			$notify_log->setTargetTime(time());
			$notify_log->setOrderId($order->getId());
			$notify_log->setData($json_data);
			$notify_log->setMerchantNotifyUrl($merchant_notify_url);
			$notify_log->setRetHttpCode($ret[0]);
			$notify_log->setRet(substr($ret[1],0,200));
			$notify_log->setCreatedAt(time());
			$this->entityManager->persist($notify_log);
			$this->entityManager->flush();
		}
		
		echo '[PAYIN]'.$order->getPno()." OK\r";
	}
	
}

