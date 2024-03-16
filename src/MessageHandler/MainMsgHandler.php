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
	
	//Create payout order
	private function __PAYOUT_CREATED($data)
	{
		$order_id = $data['order_id'];
		$DATA     = $data['DATA'];

		$order = $this->entityManager->getRepository(\App\Entity\OrderPayout::class)->find($order_id);
		if($order)
		{
			$merchant = $this->entityManager->getRepository(\App\Entity\Merchant::class)->find($order->getMid());

			//Check merchant order number is exist
			$merchant_order_no_checker = $this->entityManager->getConnection()->executeQuery('select count(id) as t from order_payout where mno="'.$DATA['order_no'].'"')->fetchOne();
			if($merchant_order_no_checker > 1)
			{
				echo "[".$DATA['order_no']."]merchant_order_no is exist!\n";

				//send error to merchant
				$sync_handler = new \App\Util\SyncMsg();
				$sync_handler->err([
					'IO'=>'O',
					'merchant'=>$merchant,
					'order'=>$order,
					'msg'=>'order number is exist',
					'entityManager'=>$this->entityManager,
				]);
			}
			else
			{
				//Check merchant's balance
				$balance = 1 == $order->isIsTest() ? $merchant->getTestAmount() : $merchant->getAmount();
				$fee = $order->getMfee();
				if($balance - $order->getAmount() - $fee < 0)
				{
					$sync_handler = new \App\Util\SyncMsg();
					$sync_handler->err([
						'IO'=>'O',
						'merchant'=>$merchant,
						'order'=>$order,
						'msg'=>'INSUFFICIENT_BALANCE',
						'entityManager'=>$this->entityManager,
					]);
					echo '['.$order_id."]INSUFFICIENT_BALANCE\n";
				}
				else
				{
					//Get channel hanle && post data
					$channel = $this->entityManager->getRepository(\App\Entity\Channel::class)->find($order->getCid());
					$cls = 'App\\Channel\\'.ucfirst(trim($channel->getSlug())).'\\Payout';
					if(!class_exists($cls))
					{
						echo '['.$order_id."]channel payout handler not exist\n";
					}
					$channel_handler = new ($cls)();
					$channel_handler->set_data([
						'entityManager'=>$this->entityManager,
						'DATA'=>$DATA,
						'plantform_order_no'=>$order->getPno(),
					]);
					
					$ret = $channel_handler->handle($order->isIsTest());
					if(!is_array($ret))
					{
						$ret = json_decode($ret, true);
					}
					if(0 == $ret['code'])
					{
						///////////////////////////////////////////////////
						$this->entityManager->beginTransaction(); // 开始事务

						try {
							if(array_key_exists('channel_order_no',$ret))
							{
								$order->setCno($ret['channel_order_no']);
							}
							
							if(1 == $order->isIsTest())
							{
								$sql = 'UPDATE merchant SET test_amount = test_amount - :A,test_df_pool = test_df_pool + :P  WHERE id = :id';
								$this->entityManager->getConnection()->executeStatement($sql,['id' => $merchant->getId(), 'A' => $order->getAmount() + $order->getMfee(), 'P'=>$order->getAmount()]);
								
								$old_amount = $merchant->getTestAmount();
								$new_amount = $merchant->getTestAmount() - $order->getAmount() - $order->getMfee();
								
								$old_df = $merchant->getTestDfPool();
								$new_df = $merchant->getTestDfPool() + $order->getAmount();
							}
							else
							{
								$sql = 'UPDATE merchant SET amount = amount - :A,df_pool = df_pool + :P  WHERE id = :id';
								$this->entityManager->getConnection()->executeStatement($sql,['id' => $merchant->getId(), 'A' => $order->getAmount() + $order->getMfee(), 'P'=>$order->getAmount()]);
								
								$old_amount = $merchant->getAmount();
								$new_amount = $merchant->getAmount() - $order->getAmount() - $order->getMfee();
								
								$old_df = $merchant->getDfPool();
								$new_df = $merchant->getDfPool() + $order->getAmount();
							}
							
							$alog = new \App\Entity\Log();
							$alog->setBundle('SUB_BALANCE');
							$alog->setCreatedAt(time());
							$alog->setOrderid($order->getId());
							$alog->setPno($order->getPno());
							$alog->setUid(0);
							$alog->setCid($order->getCid());
							$alog->setMid($order->getMid());
							$alog->setIp($DATA['_ip']);
							$alog->setSummary('PO_CREATED');
							$alog->setIsTest($order->isIsTest());
							$alog->setMoneyBefore($old_amount);
							$alog->setMoney($order->getAmount());
							$alog->setMoneyAfter($new_amount);
							$alog->setData(json_encode(['mfee'=>$order->getMfee()]));
							$this->entityManager->persist($alog);
							
							$df_log = new \App\Entity\Log();
							$df_log->setBundle('ADD_DF');
							$df_log->setCreatedAt(time());
							$df_log->setOrderid($order->getId());
							$df_log->setPno($order->getPno());
							$df_log->setUid(0);
							$df_log->setCid($order->getCid());
							$df_log->setMid($order->getMid());
							$df_log->setIp($DATA['_ip']);
							$df_log->setSummary('PO_CREATED');
							$df_log->setIsTest($order->isIsTest());
							$df_log->setMoneyBefore($old_df);
							$df_log->setMoney($order->getAmount());
							$df_log->setMoneyAfter($new_df);
							$df_log->setData(json_encode(['mfee'=>$order->getMfee()]));
							$this->entityManager->persist($df_log);

							$this->entityManager->flush(); // 将以上更改应用到数据库
							$this->entityManager->commit(); // 提交事务

							echo "[".$order_id."]payout create transaction succ!\n";
						} catch (\Exception $e) {
							$this->entityManager->rollback(); // 如果出现异常，回滚事务
							
							$sync_handler = new \App\Util\SyncMsg();
							$sync_handler->err([
								'IO'=>'O',
								'merchant'=>$merchant,
								'order'=>$order,
								'msg'=>'TransactionFail:'.substr($e->getMessage(),0,50),
								'entityManager'=>$this->entityManager,
							]);

							echo 'Transaction with custom SQL failed: ' . $e->getMessage();
						}
						///////////////////////////////////////////////////
					}
					else
					{
						//fail
						$sync_handler = new \App\Util\SyncMsg();
						$sync_handler->err([
							'IO'=>'O',
							'merchant'=>$merchant,
							'order'=>$order,
							'msg'=>$ret['msg'],
							'entityManager'=>$this->entityManager,
						]);
						echo 'channel ret fail:'.$ret['msg']."\n";
					}
				}
			}
		}
		else
		{
			echo "[".$order_id."]order not exist\n";
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

