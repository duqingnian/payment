<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class DispatchController extends BaseController
{
    #[Route('/api.dispatch.all', name: 'api_dispatch_all')]
    public function all(Request $request): JsonResponse
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
		
		$timestamp = $request->request->get('timestamp','');
		$date      = $request->request->get('date','');
		$sign      = $request->request->get('sign','');
		
		$_sign = hash('sha256',$date.'.'.$timestamp);
		if($sign != $_sign)
		{
			return new JsonResponse(['code' => 2001,'msg'=>'SIGN_ERR']);
		}
		
		$data = ['code'=>0,'msg'=>'OK','slug_list'=>[]];
		$channels = $this->db(\App\Entity\Channel::class)->findBy(['note'=>'SYNC_BALANCE']);
		foreach($channels as $channel)
		{
			$data['slug_list'][] = $channel->getSlug();
		}
		
		echo json_encode($data);
		die();
    }

	#[Route('/api.dispatch.sync', name: 'api_dispatch_sync')]
    public function sync(Request $request): JsonResponse
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
		
		$timestamp = $request->request->get('timestamp','');
		$date      = $request->request->get('date','');
		$slug      = $request->request->get('slug','');
		$sign      = $request->request->get('sign','');
		
		$_sign = hash('sha256',$slug.'.'.$date.'.'.$timestamp);
		if($sign != $_sign)
		{
			return new JsonResponse(['code' => 2001,'msg'=>'SIGN_ERR']);
		}
		
		$channel = $this->db(\App\Entity\Channel::class)->findOneBy(['slug'=>$slug]);
		if($channel)
		{
			$cls = 'App\\Channel\\'.ucfirst(strtolower($channel->getSlug())).'\\Balance';
			if(class_exists($cls))
			{
				$channel_handler = new ($cls)();
				$amount = $channel_handler->query();
				$amount = substr($amount,0,280);
				if('-' != $amount)
				{
					$model = new \App\Entity\ChannelBalanceLog();
					$model->setCid($channel->getId());
					$model->setBalanceSnapshot($channel->getAmount());
					$model->setBalance($amount);
					$model->setCreatedAt(time());
					
					$channel->setAmount($amount);
					$this->save($model);
					
					echo '['.date('Y-m-d H:i:s').']'.$channel->getName().', Updated';
				}
			}
			die();
		}
		else
		{
			echo 'slug:'.$slug.' not exist!';
			die();
		}
	}

}
