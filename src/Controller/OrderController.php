<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class OrderController extends BaseController
{
    #[Route('/api/payment/order/query', name: 'payment_order_query_api')]
    public function payment_query(Request $request): Response
    {
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		$headers = $request->headers->all();
		$content_type = "";
		if(array_key_exists("content-type",$headers))
		{
			if(is_array($headers["content-type"])){$content_type = $headers["content-type"][0];}
			else{$content_type = $headers["content-type"];}
		}
		if('' == $content_type || (false === strstr($content_type,'x-www-form'))){$this->e('content_type: ['.$content_type.'] not allowed!');}
		
		$json_post = file_get_contents('php://input');
		if(false !== strstr('{', $json_post))
		{
			$this->e('json data not allowed');
		}
		
		$appid = $request->request->get('appid','');
		$order_no = $request->request->get('order_no','');
		$timestamp = $request->request->get('timestamp','');
		$sign = $request->request->get('sign','');
		
		if('' == $appid){ $this->e('appid is missing'); }
		if('' == $order_no){ $this->e('order_no is missing'); }
		if('' == $timestamp){ $this->e('timestamp is missing'); }
		if('' == $sign){ $this->e('sign is missing'); }
		
		$order_no = trim($order_no);
		$appid = trim($appid);
		
		//查询代收appid是不是存在
		//根据appid查找商户
		$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payin_appid'=>$appid]);
		if(!$merchant)
		{
			echo json_encode(['code' => -6011,'msg'=>'APPID_NOT_MATCH:'.$appid]);
			die();
		}
		
		//验证签名
		$arr = [
			'appid'=>$appid,
			'order_no'=>$order_no,
			'timestamp'=>$timestamp,
		];
		$my_sign = md5($this->_ascii_params($arr).'&key='.$merchant->getPayinSecret());
		if($my_sign != $sign)
		{
			$this->e('SIGN_NOT_MATCH',6015);
		}
		
		//查询订单
		$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['mno'=>$order_no]);
		if(!$order)
		{
			$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$order_no]);
		}
		if(!$order)
		{
			$this->e('order not exist:['.$order_no.']');
		}
		
		if($order->getMid() != $merchant->getId())
		{
			$this->e('order not allowed to query:'.$order->getId());
		}
		
		$country_slug = $merchant->getCountry();
		$country = $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$country_slug]);
		$response = [
			'code'=>0,
			'msg'=>'OK',
			'order_status'=>$order->getStatus(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'currency'=>$country->getCurrency(),
			'created_at'=>$order->getCreatedAt(),
			'updated_at'=>$order->getCreatedAt(),
			'ext_message'=>'',
			'payer_name'=>'',
			'payer_pay_type'=>'',
			'payer_account_no'=>'',
		];
		$response['sign'] = md5($this->_ascii_params($response).'&key='.$merchant->getPayinSecret());
			
		echo json_encode($response);
		exit();
    }
	
	
	#[Route('/api/payout/order/query', name: 'payout_order_query_api')]
    public function payout_query(Request $request): Response
    {
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		$headers = $request->headers->all();
		$content_type = "";
		if(array_key_exists("content-type",$headers))
		{
			if(is_array($headers["content-type"])){$content_type = $headers["content-type"][0];}
			else{$content_type = $headers["content-type"];}
		}
		if('' == $content_type || (false === strstr($content_type,'x-www-form'))){$this->e('content_type: ['.$content_type.'] not allowed!');}
		
		$json_post = file_get_contents('php://input');
		if(false !== strstr('{', $json_post))
		{
			$this->e('json data not allowed');
		}
		
		$appid = $request->request->get('appid','');
		$order_no = $request->request->get('order_no','');
		$timestamp = $request->request->get('timestamp','');
		$sign = $request->request->get('sign','');
		
		if('' == $appid){ $this->e('appid is missing'); }
		if('' == $order_no){ $this->e('order_no is missing'); }
		if('' == $timestamp){ $this->e('timestamp is missing'); }
		if('' == $sign){ $this->e('sign is missing'); }
		
		$order_no = trim($order_no);
		$appid = trim($appid);
		
		//查询代收appid是不是存在
		//根据appid查找商户
		$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payout_appid'=>$appid]);
		if(!$merchant)
		{
			echo json_encode(['code' => -6011,'msg'=>'APPID_NOT_MATCH:'.$appid]);
			die();
		}
		
		//验证签名
		$arr = [
			'appid'=>$appid,
			'order_no'=>$order_no,
			'timestamp'=>$timestamp,
		];
		$my_sign = md5($this->_ascii_params($arr).'&key='.$merchant->getPayoutSecret());
		if($my_sign != $sign)
		{
			$this->e('SIGN_NOT_MATCH',6015);
		}
		
		//查询订单
		$order = $this->db(\App\Entity\OrderPayout::class)->findOneBy(['mno'=>$order_no]);
		if(!$order)
		{
			$order = $this->db(\App\Entity\OrderPayout::class)->findOneBy(['pno'=>$order_no]);
		}
		if(!$order)
		{
			$this->e('order not exist:['.$order_no.']');
		}
		
		if($order->getMid() != $merchant->getId())
		{
			$this->e('order not allowed to query:'.$order->getId());
		}
		
		$request_data_object = $this->db(\App\Entity\PayProcessData::class)->findOneBy(['bundle'=>'M_RTPF_D','pno'=>$order->getPno()]);
		
		$country_slug = $merchant->getCountry();
		$country = $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$country_slug]);
		$response = [
			'code'=>0,
			'msg'=>'OK',
			'order_status'=>$order->getStatus(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'currency'=>$country->getCurrency(),
			'created_at'=>$order->getCreatedAt(),
			'updated_at'=>$order->getCreatedAt(),
			'ext_message'=>'',
			'payer_name'=>'',
			'payer_pay_type'=>'',
			'payer_account_no'=>'',
		];
		if($request_data_object)
		{
			$request_data = json_decode($request_data_object->getData(),true);
			if(is_array($request_data))
			{
				if(array_key_exists('bank_code',$request_data))
				{
					$response['payer_pay_type'] = $request_data['bank_code'];
				}
				if(array_key_exists('account_name',$request_data))
				{
					$response['payer_name'] = $request_data['account_name'];
				}
				if(array_key_exists('account_no',$request_data))
				{
					$response['payer_account_no'] = $request_data['account_no'];
				}
			}
		}
		$response['sign'] = md5($this->_ascii_params($response).'&key='.$merchant->getPayoutSecret());

		echo json_encode($response);
		exit();
			
    }
}


