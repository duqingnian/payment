<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ChannelController extends BaseController
{
	#[Route('/api/channel', name: 'api_channel')]
    public function index(Request $request): Response
    {
		return $this->dispatch($request);
	}
	
	public function _fetch($request): Response
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
			echo json_encode(['code' => 6001,'msg'=>'CONTENT_TYPE_IS_MISSING']);die();
		}
		if($allow_content_type !== substr($client_content_type,0,strlen($allow_content_type)))
		{
			echo json_encode(['code' => 6002,'msg'=>'CONTENT_TYPE_MUST_BE_START_WITH:application/x-www-form-urlencoded,CURRENT_CONTENT_TYPE_IS:'.$client_content_type]);die();
		}
		
		$appid = $request->request->get('appid','');
		$bundle = $request->request->get('bundle','');
		$time = $request->request->get('time','');
		$sign = $request->request->get('sign','');
		
		if('' == $appid || strlen($appid) < 5)
		{
			echo json_encode(['code' => 6003,'msg'=>'INVALIDATE_APPID']);die();
		}
		if('' == $time || strlen($time) < 10)
		{
			echo json_encode(['code' => 6004,'msg'=>'INVALIDATE_TIME']);die();
		}
		if('' == $bundle || strlen($bundle) < 3)
		{
			echo json_encode(['code' => 6005,'msg'=>'INVALIDATE_BUNDLE']);die();
		}
		if('' == $sign || strlen($sign) < 10)
		{
			echo json_encode(['code' => 6005,'msg'=>'INVALIDATE_SIGN']);die();
		}
		
		if(!in_array($bundle,['PAYIN','PAYOUT']))
		{
			echo json_encode(['code' => 6005,'msg'=>'INVALIDATE_BUNDLE:'.substr($bundle,0,10)]);die();
		}
		
		//根据appid查找商户
		$secret = '';
		if('PAYIN' == $bundle)
		{
			$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payin_appid'=>$appid]);
		}
		if('PAYOUT' == $bundle)
		{
			$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payout_appid'=>$appid]);
		}
		if(!$merchant)
		{
			echo json_encode(['code' => -6011,'msg'=>'APPID_NOT_MATCH:'.$appid]);die();
		}
		if(0 == $merchant->isIsActive())
		{
			echo json_encode(['code' => -6012,'msg'=>'MERCHANT_NOT_ACTIVE:'.$appid]);die();
		}
		
		if('PAYIN' == $bundle)
		{
			$secret = $merchant->getPayinSecret();
		}
		if('PAYOUT' == $bundle)
		{
			$secret = $merchant->getPayoutSecret();
		}
		$client_post_data = ['action'=>'fetch','appid'=>$appid,'time'=>$time,'bundle'=>$bundle];
		$my_sign = $this->_hash_hmac($client_post_data,$secret);
		if($my_sign != $sign)
		{
			echo json_encode(['code' => -6013,'msg'=>'INVALIDATE_SIGN: '.$my_sign]);
			die();
		}
		
		$merchant_channel = $this->db(\App\Entity\MerchantChannel::class)->findOneBy(['bundle'=>'PAYOUT','mid'=>$merchant->getId(),'is_default'=>1]);
		if(!$merchant_channel)
		{
			$this->e('暂无默认通道');
		}
		if(!$merchant_channel->isIsActive())
		{
			$this->e('默认通道未激活');
		}
		
		$cls = 'App\\Country\\'.ucfirst($merchant->getCountry()).'\\Column';
		if(!class_exists($cls))
		{
			$this->e('国家句柄不存在:'.$merchant->getId().':'.$merchant_channel->getCid().':'.$merchant->getCountry());
		}
		$country_column_handler = new ($cls)();
		$columns = $country_column_handler->render();
		
		//返回该商户下的通道
		$time = time();
		$info = [
			'code'=>0,
			'msg'=>'OK',
			'bundle'=>strtoupper($bundle),
			'time'=>$time,
			'columns'=>$columns,
			'channel_id'=>$merchant_channel->getCid(),
		];
		$info['sign'] = $this->_hash_hmac(['appid'=>$appid,'time'=>$time],$merchant->getPayinSecret());
		
		echo json_encode($info);
		die();
	}
	
	
}


