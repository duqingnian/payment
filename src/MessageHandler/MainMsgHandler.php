<?php
namespace App\MessageHandler;

use App\Message\MainMsg;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

#[AsMessageHandler]
class MainMsgHandler implements MessageHandlerInterface
{
	public EntityManagerInterface $entityManager;
	
	public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
	
    public function __invoke(MainMsg $message)
    {
		$content = $message->getContent();
		$data = json_decode($content, true);
		$data['action'] = strtoupper($data['action']);
		if('PAYIN_NOTIFY' == $data['action']) //代收回调
		{
			$this->__PAYIN_NOTIFY($data);
		}
		else if('PAYOUT_CREATED' == $data['action']) //创建代付后商户的积分变更
		{
			$this->__PAYOUT_CREATED($data);
		}
		else if('PAYOUT_NOTIFY' == $data['action']) //代付回调
		{
			$this->__PAYOUT_NOTIFY($data);
		}
		else
		{
			//
		}
    }
	
	//代收回调
	private function __PAYIN_NOTIFY($data)
	{
		$message = $data['message'];
		
		$channel_slug = trim($message['channel_slug']);
		$cls = 'App\\Channel\\'.ucfirst(strtolower($channel_slug)).'\\PayinNotify';
		if(class_exists($cls))
		{
			$channel_handler = new $cls();
			$channel_handler->set_data([
				'entityManager'=>$this->entityManager,
				'DATA'=>$message,
				'plantform_order_no'=>$data['plantform_order_no'],
			]);
			$channel_handler->handle();
		}
	}
	
	//创建了代收订单 变更商户的余额和代付金额
	private function __PAYOUT_CREATED($data)
	{
		$order_id = $data['order_id'];
		$order = $this->entityManager->getRepository(\App\Entity\OrderPayout::class)->find($order_id);
		if($order)
		{
			$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());
			
			$alog = new \App\Entity\Log();
			$alog->setBundle('SUB_BALANCE');
			$alog->setCreatedAt(time());
			$alog->setOrderid($order->getId());
			$alog->setPno($order->getPno());
			$alog->setUid(0);
			$alog->setCid($order->getCid());
			$alog->setMid($order->getMid());
			$alog->setIp('');
			$alog->setSummary('PO_CREATED');
			$alog->setIsTest($merchant->isIsTest());
			
			$df_log = new \App\Entity\Log();
			$df_log->setBundle('ADD_DF');
			$df_log->setCreatedAt(time());
			$df_log->setOrderid($order->getId());
			$df_log->setPno($order->getPno());
			$df_log->setUid(0);
			$df_log->setCid($order->getCid());
			$df_log->setMid($order->getMid());
			$df_log->setIp('');
			$df_log->setSummary('PO_CREATED');
			$df_log->setIsTest($merchant->isIsTest());
			
			if(1 == $merchant->isIsTest())
			{
				//旧余额 - 新余额
				$old_amount = $merchant->getTestAmount();
				$new_amount = $old_amount - $order->getAmount() - $order->getMfee();
				
				//旧代付 - 新代付
				$old_df = $merchant->getTestDfPool();
				$new_df = $old_df + $order->getAmount();
				
				//余额日志
				$alog->setMoneyBefore($old_amount);
				$alog->setMoney($order->getAmount());
				$alog->setMoneyAfter($new_amount);
				
				//代付池日志
				$df_log->setMoneyBefore($old_df);
				$df_log->setMoney($order->getAmount());
				$df_log->setMoneyAfter($new_df);
				
				$merchant->setTestAmount($new_amount);
				$merchant->setTestDfPool($new_df);
			}
			else
			{
				//旧余额 - 新余额
				$old_amount = $merchant->getAmount();
				$new_amount = $old_amount - $order->getAmount() - $order->getMfee();
				
				//旧代付 - 新代付
				$old_df = $merchant->getDfPool();
				$new_df = $old_df + $order->getAmount();
				
				//余额日志
				$alog->setMoneyBefore($old_amount);
				$alog->setMoney($order->getAmount());
				$alog->setMoneyAfter($new_amount);
				
				//代付池日志
				$df_log->setMoneyBefore($old_df);
				$df_log->setMoney($order->getAmount());
				$df_log->setMoneyAfter($new_df);
				
				$merchant->setAmount($new_amount);
				$merchant->setDfPool($new_df);
			}
			$this->entityManager->flush();
			
			$alog->setData(json_encode(['mfee'=>$order->getMfee()]));
			$this->entityManager->persist($alog);
			$this->entityManager->flush();
			
			$df_log->setData(json_encode(['mfee'=>$order->getMfee()]));
			$this->entityManager->persist($df_log);
			$this->entityManager->flush();
			echo "[".$order_id."]PAYOUT_CREATED\n";
		}
	}
	
	//代付回调
	private function __PAYOUT_NOTIFY($data)
	{
		$message = $data['message'];
		
		$channel_slug = trim($message['channel_slug']);
		$cls = 'App\\Channel\\'.ucfirst(strtolower($channel_slug)).'\\PayoutNotify';
		if(class_exists($cls))
		{
			$channel_handler = new $cls();
			$channel_handler->set_data([
				'entityManager'=>$this->entityManager,
				'DATA'=>$message,
				'plantform_order_no'=>$data['plantform_order_no'],
			]);
			$channel_handler->handle();
		}
		echo "[".$data['plantform_order_no']."]PAYOUT_NOTIFY\n";
	}
}

