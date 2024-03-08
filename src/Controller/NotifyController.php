<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\MainMsg;

class NotifyController extends BaseController
{
	#[Route('/api/notify/{io}/{channel_slug}', name: 'api_notify')]
    public function worker(Request $request, MessageBusInterface $bus, String $io, String $channel_slug): JsonResponse
    {
		if(strlen(trim($channel_slug)) < 2)
		{
			return new JsonResponse(['code' => -1,'msg'=>'ARG_SLUG_IS_MISSING']);
		}
		
		if(!in_array(strtoupper($io),['I','O']))
		{
			return new JsonResponse(['code' => -1,'msg'=>'io is missing']);
		}
		
		$bundle = ('I' == strtoupper($io)) ? 'PAYIN' : 'PAYOUT';
		
		//查找通道
		$channel = $this->db(\App\Entity\Channel::class)->findOneBy(['slug'=>trim($channel_slug)]);
		if(!$channel)
		{
			return new JsonResponse(['code' => -1,'msg'=>'NOTIFY_EXCEPTION:CHANNEL_NOT_EXISTS']);
		}

		$cls = 'App\\Channel\\'.ucfirst($channel->getSlug()).'\\'.ucfirst(strtolower($bundle)).'Notify';
		if(!class_exists($cls))
		{
			return new JsonResponse(['code' => -1,'msg'=>'NOTIFY_HANDLE_CLS_NOT_EXIST']);
		}
		
		$channel_handler = new $cls();
		$channel_handler->set_data(['entityManager'=>$this->entityManager,]);

		$message = $channel_handler->get_message();
		//print_r($message);die();
		if(0 == $message['code'])
		{
			$message['action'] = strtoupper($bundle).'_NOTIFY';
			$message['channel_id'] = $channel->getId();
			$message['channel_slug'] = strtoupper(strtolower($channel->getSlug()));
			$bus->dispatch(new MainMsg(json_encode(['action'=>strtoupper($bundle.'_Notify'),'message'=>$message,'plantform_order_no'=>$message['plantform_order_no']])));
		}
		else
		{
			return new JsonResponse($msg);
		}
		
		$channel_handler->complete();
		die();
	}
}
