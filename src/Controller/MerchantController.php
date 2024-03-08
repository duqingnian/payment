<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MerchantController extends BaseController
{
	#[Route('/api/merchant/balance', name: 'api_merchant_balance')]
    public function index(Request $request): JsonResponse
    {
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		file_put_contents('/home/web/wolong.v2/payment/public/tmp_data/'.time().'.txt',file_get_contents('php://input'));
		
		$appid = $request->request->get('appid','');
		$timestamp = $request->request->get('timestamp','');
		$sign = $request->request->get('sign','');
		
		if(false !== strstr(file_get_contents('php://input'),'{'))
		{
			$this->e('do not post json data,please post form data');
		}
		
		if('' == $appid){ $this->e('appid is missing:'.date('Y-m-d H:i:s')); }
		if('' == $timestamp){ $this->e('timestamp is missing:'.date('Y-m-d H:i:s')); }
		if('' == $sign){ $this->e('sign is missing:'.date('Y-m-d H:i:s')); }
		
		$appid = trim($appid);

		$type = 'PAYIN';
		$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payin_appid'=>$appid]);
		if(!$merchant)
		{
			$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payout_appid'=>$appid]);
			if($merchant)
			{
				$type = 'PAYOUT';
			}
		}
		if(!$merchant)
		{
			echo json_encode(['code' => -6011,'msg'=>'APPID_NOT_MATCH:'.$appid]);
			die();
		}
		
		//验证签名
		$arr = [
			'appid'=>$appid,
			'timestamp'=>$timestamp,
		];
		if('PAYOUT' == $type)
		{
			$my_sign = md5($this->_ascii_params($arr).'&key='.$merchant->getPayoutSecret());
		}
		else
		{
			$my_sign = md5($this->_ascii_params($arr).'&key='.$merchant->getPayinSecret());
		}
		if($my_sign != $sign)
		{
			$this->e('SIGN_NOT_MATCH',6015);
		}

		$country_slug = $merchant->getCountry();
		$country = $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$country_slug]);
		$response = [
			'code'=>0,
			'msg'=>'OK',
			'currency'=>$country->getCurrency(),
			'payin_balance'=>$merchant->getAmount(),
			'payout_balance'=>$merchant->getDfPool(),
			'test_payin_balance'=>$merchant->getTestAmount(),
			'test_payout_balance'=>$merchant->getTestDfPool(),
			'disable_balance'=>$merchant->getFreezePool(),
			'timestamp'=>time(),
		];
		if('PAYOUT' == $type)
		{
			$response['sign'] = md5($this->_ascii_params($response).'&key='.$merchant->getPayoutSecret());
		}
		else
		{
			$response['sign'] = md5($this->_ascii_params($response).'&key='.$merchant->getPayinSecret());
		}
			
		echo json_encode($response);
		exit();
	}
}

