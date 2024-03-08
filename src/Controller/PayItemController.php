<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayItemController extends BaseController
{
	#[Route('/api/pay.item.shtml', name: 'api_pay_item_shtml')]
    public function pay_item(Request $request): Response
    {
		$channel_id = $request->request->get('channel_id',0);
		$time = $request->request->get('time',0);
		$sign = $request->request->get('sign','');
		
		if(is_numeric($channel_id) && $channel_id > 0)
		{
			//do nothing
		}
		else
		{
			$this->e('invalidate cid');
		}
		
		if(time() - $time > 300)
		{
			$this->e('invalidate t arg');
		}
		
		$_sign = strtoupper(md5($channel_id.'$'.$time));
		if($_sign != $sign)
		{
			$this->e('invalidate sign');
		}
		
		$channel = $this->db(\App\Entity\Channel::class)->find($channel_id);
		$cls = 'App\\Channel\\'.ucfirst($channel->getSlug()).'\\MethodItem';
		if(!class_exists($cls))
		{
			$this->e('channel:'.$channel_slug.' MethodItem handle not exist!');
		}
		$channel_handler = new ($cls)();
		echo json_encode([
			'code'=>0,
			'msg'=>'OK',
			'items'=>$channel_handler->GetItems(),
		]);
		die();
	}
}


