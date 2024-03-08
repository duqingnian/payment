<?php

namespace App\Channel;

class BasePayoutNotify extends \App\Channel\BaseChannel
{
	public function handle()
	{
		//更新订单信息
		$plantform_order_no = $this->DATA['plantform_order_no'];
		$order_status = $this->DATA['order_status'];

		$order = $this->entityManager->getRepository(\App\Entity\OrderPayout::class)->findOneBy(['pno'=>$plantform_order_no]);
		if($order)
		{
			if('SUCCESS' == $order->getStatus() || 'FAIL' == $order->getStatus())
			{
				echo 'SUCC_OR_FAIL';
				return;
			}
			else
			{
				//如果已经是当前状态了，不做任何处理
				if($order->getOriginalStatus() == $order_status)
				{
					echo 'SAME_STATUS:'.$plantform_order_no."\n";
					return;
				}
				else
				{
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
					
					$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());
					if($merchant)
					{
						//减掉代付
						$df_log = new \App\Entity\Log();
						$df_log->setBundle('SUB_DF');
						$df_log->setCreatedAt(time());
						$df_log->setOrderid($order->getId());
						$df_log->setPno($order->getPno());
						$df_log->setUid(0);
						$df_log->setCid($order->getCid());
						$df_log->setMid($order->getMid());
						$df_log->setIp('');
						$df_log->setSummary('PO_NOTIFY');
						$df_log->setIsTest($order->isIsTest());
				
						if($order->isIsTest())
						{
							//旧代付 - 新代付
							$old_df = $merchant->getTestDfPool();
							$new_df = $old_df - $order->getAmount();
							
							$df_log->setMoneyBefore($old_df);
							$df_log->setMoney($order->getAmount());
							$df_log->setMoneyAfter($new_df);
					
							$merchant->setTestDfPool($new_df);
						}
						else
						{
							//旧代付 - 新代付
							$old_df = $merchant->getDfPool();
							$new_df = $old_df - $order->getAmount();
							
							$df_log->setMoneyBefore($old_df);
							$df_log->setMoney($order->getAmount());
							$df_log->setMoneyAfter($new_df);
					
							$merchant->setDfPool($new_df);
						}
						$df_log->setData(json_encode(['cfee'=>$order->getCfee(),'order_status'=>$order_status,'mfee'=>$order->getMfee()]));
						$this->entityManager->persist($df_log);
						$this->entityManager->flush();
						
						if('SUCCESS' == $order_status)
						{
							//do nothing
						}
						else
						{
							//失败 退金额
							$alog = new \App\Entity\Log();
							$alog->setBundle('ADD_BALANCE');
							$alog->setCreatedAt(time());
							$alog->setOrderid($order->getId());
							$alog->setPno($order->getPno());
							$alog->setUid(0);
							$alog->setCid($order->getCid());
							$alog->setMid($order->getMid());
							$alog->setIp('');
							$alog->setSummary('PO_FAIL_NOTIFY');
							$alog->setIsTest($order->isIsTest());

							if($order->isIsTest())
							{
								//旧余额 - 新余额
								$old_amount = $merchant->getTestAmount();
								$new_amount = $old_amount + $order->getAmount() + $order->getMfee();
					
								$alog->setMoneyBefore($old_amount);
								$alog->setMoney($order->getAmount());
								$alog->setMoneyAfter($new_amount);
					
								$merchant->setTestAmount($new_amount);
							}
							else
							{
								//旧余额 - 新余额
								$old_amount = $merchant->getAmount();
								$new_amount = $old_amount + $order->getAmount() + $order->getMfee();
								
								$alog->setMoneyBefore($old_amount);
								$alog->setMoney($order->getAmount());
								$alog->setMoneyAfter($new_amount);
					
								$merchant->setAmount($new_amount);
							}
							$alog->setData(json_encode(['cfee'=>$order->getCfee(),'order_status'=>$order_status,'mfee'=>$order->getMfee()]));
							$this->entityManager->persist($alog);
							$this->entityManager->flush();
						}
						$this->entityManager->flush();
					}
					else
					{
						echo '['.$order->getId()."]merchant not found!\r";
					}
					usleep(500000);
					
					//回调给商户
					$merchant_notify_url = $order->getMerchantNotifyUrl();
					$merchant_notify_data = [
						'amount'=>$order->getAmount(),
						'fee'=>$order->getMfee(),
						'order_status'=>$order->getStatus(),
						'plantform_order_no'=>$order->getPno(),
						'real_amount'=>$order->getRamount(),
						'shanghu_order_no'=>$order->getMno(),
						'time'=>time(),
					];
					
					$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());
					$payout_secret = $merchant->getPayoutSecret();
					
					$sign_str = $this->stand_ascii_params($merchant_notify_data).'&key='.$payout_secret;
					$merchant_notify_data['sign'] = md5($sign_str);
					
					$json_data = json_encode($merchant_notify_data);
					$ret = $this->post_json($merchant_notify_url,$json_data);

					//保存下来
					$notify_log = new \App\Entity\MerchantNotifyLog();
					$notify_log->setBundle('PAYOUT');
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
					
					echo '['.$order->getId()."]order payout complete!\r";
				}
				
			}
		}
		else
		{
			echo '['.$order->getId()."]order not exist\r";
		}
	}
	
}

