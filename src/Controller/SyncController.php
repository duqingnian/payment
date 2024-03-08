<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SyncController extends BaseController
{
    #[Route('/pno_list.sync.go', name: 'go_pno_list_sync')]
    public function go_pno_list_sync(Request $request): JsonResponse
    {
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		//检测客户端的content_type
		$client_content_type = $request->headers->get('content-type');
		$allow_content_type = 'application/x-www-form-urlencoded';
		if(strlen(trim($client_content_type)) < 2)
		{
			return new JsonResponse(['code' => 2001,'msg'=>'CONTENT_TYPE_IS_MISSING']);
		}
		if($allow_content_type !== substr($client_content_type,0,strlen($allow_content_type)))
		{
			return new JsonResponse(['code' => 2002,'msg'=>'CONTENT_TYPE_MUST_BE_START_WITH:application/x-www-form-urlencoded,CURRENT_CONTENT_TYPE_IS:'.$client_content_type]);
		}
		
		$bundle    = $request->request->get('bundle','');
		$timestamp = $request->request->get('timestamp','');
		$date      = $request->request->get('date','');
		$sign      = $request->request->get('sign','');
		
		if('PI' != $bundle && 'PO' != $bundle)
		{
			return new JsonResponse(['code' => 2003,'msg'=>'invalidate bundle']);
		}
		
		$_sign = hash('sha256',$date.'.'.$timestamp);
		if($sign != $_sign)
		{
			return new JsonResponse(['code' => 2004,'msg'=>'SIGN_ERR']);
		}
		
		//近4小时内1000条记录
		$table = 'PI' == $bundle ? 'order_payin' : 'order_payout';
		$orders = $this->entityManager->getConnection()->executeQuery("select `pno` from `".$table."` where `status` = 'GENERATED' and created_at > ".strtotime('-4 hours')." order by id asc limit 1")->fetchAllAssociative();
		$ret = ['code'=>0,'msg'=>'OK','pnos'=>[]];
		foreach($orders as $order)
		{
			$ret['pnos'][] = $order['pno'];
		}
		return new JsonResponse($ret);
		die();
    }

	#[Route('/order.sync.go', name: 'go_order_sync')]
    public function go_order_sync(Request $request): JsonResponse
	{
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		//检测客户端的content_type
		$client_content_type = $request->headers->get('content-type');
		$allow_content_type = 'application/x-www-form-urlencoded';
		if(strlen(trim($client_content_type)) < 2)
		{
			return new JsonResponse(['code' => 2001,'msg'=>'CONTENT_TYPE_IS_MISSING']);
		}
		if($allow_content_type !== substr($client_content_type,0,strlen($allow_content_type)))
		{
			return new JsonResponse(['code' => 2002,'msg'=>'CONTENT_TYPE_MUST_BE_START_WITH:application/x-www-form-urlencoded,CURRENT_CONTENT_TYPE_IS:'.$client_content_type]);
		}
		
		$bundle    = $request->request->get('bundle','');
		$timestamp = $request->request->get('timestamp','');
		$date      = $request->request->get('date','');
		$pno       = $request->request->get('pno','');
		$sign      = $request->request->get('sign','');
		
		if('PI' != $bundle && 'PO' != $bundle)
		{
			return new JsonResponse(['code' => 2003,'msg'=>'invalidate bundle']);
		}
		
		$_sign = hash('sha256',$pno.'.'.$date.'.'.$timestamp);
		if($sign != $_sign)
		{
			return new JsonResponse(['code' => 2001,'msg'=>'SIGN_ERR']);
		}
		
		if('PI' == $bundle)
		{
			$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$pno]);
		}
		else
		{
			$order = $this->db(\App\Entity\OrderPayout::class)->findOneBy(['pno'=>$pno]);
		}
		
		if($order)
		{
			if('SUCCESS' == $order->getStatus() || 'FAIL' == $order->getStatus())
			{
				return new JsonResponse(['code' => 2001,'msg'=>'pno:'.$pno.' status: '.$order->getStatus().'!']);
			}
			
			$channel = $this->db(\App\Entity\Channel::class)->find($order->getCid());
			if(!$channel)
			{
				return new JsonResponse(['code' => 2001,'msg'=>$order->getCid().' channel not exists!']);
			}
			
			$cls = 'App\\Channel\\'.ucfirst(strtolower($channel->getSlug())).'\\Query';
			if(class_exists($cls))
			{
				$channel_handler = new ($cls)();
				$DATA = $channel_handler->handle($order);

				if('' != $DATA && is_array($DATA))
				{
					$order_status = $DATA['STATUS'];
					$const_status = $this->entityManager->getRepository(\App\Entity\ChannelStatusCode::class)->findOneBy(['cid'=>$order->getCid(),'bundle'=>'PI' == $bundle ? 'PAYIN_NOTIFY' : 'PAYOUT_NOTIFY','channel_code'=>$order_status]);
					if($const_status)
					{
						$order_status = $const_status->getConstStatus();
						if('SUCCESS' == $order_status)
						{
							//
						}
						if('FAIL' == $order_status)
						{
							//
						}
					}
					//increase 1 for order retry
					//$order->setRetry($order->getRetry()+1);
					//$this->update();
					
					//return new JsonResponse(['code' => 2001,'msg'=>'pno:'.$pno.' retry increase:'.$order->getRetry()]);
				}
				return new JsonResponse(['code' => 2001,'msg'=>'pno:'.$pno.' Query data null!']);
			}
			return new JsonResponse(['code' => 2001,'msg'=>'pno:'.$pno.' Query handle not exist!']);
		}
		else
		{
			return new JsonResponse(['code' => 2001,'msg'=>'pno:'.$pno.' not exist!']);
		}
	}

}
