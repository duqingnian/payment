<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use App\Message\MainMsg;

class SimulationController extends BaseController
{
	#[Route('/simulation.payin', name: 'simulation_payin')]
    public function payin(Request $request): JsonResponse
    {
		$appid = $request->request->get('appid','');
		$sign  = $request->request->get('sign','');
		$time  = $request->request->get('time','');
		$channel_id  = $request->request->get('channel_id','');
		$plantform_order_no  = $request->request->get('plantform_order_no','');
		$amount  = $request->request->get('amount','');
		$notify_url  = $request->request->get('notify_url','');
		$order_no  = $request->request->get('order_no','');
		$country_name  = $request->request->get('country_name','');
		$country_currency_name  = $request->request->get('country_currency_name','');
		$plantform_notify_url  = $request->request->get('plantform_notify_url','');
		
		if('' == $appid || strlen($appid) < 2)
		{
			return new JsonResponse(['code' => -7003,'msg'=>'INVALIDATE_APPID']);
		}
		if('' == $sign || strlen($sign) < 10)
		{
			return new JsonResponse(['code' => -7004,'msg'=>'INVALIDATE_SIGN']);
		}
		if('' == $plantform_order_no || strlen($plantform_order_no) < 10)
		{
			return new JsonResponse(['code' => -7005,'msg'=>'plantform_order_no is missing']);
		}
		
		$my_sign = md5($time.$plantform_order_no.$channel_id);
		if($my_sign != $sign)
		{
			return new JsonResponse(['code' => -7006,'msg'=>'SIGN_ERR']);
		}
		
		//查找订单
		$order = $this->entityManager->getConnection()->executeQuery('select id from order_payin where pno="'.$plantform_order_no.'"')->fetchAssociative();
		if($order)
		{
			return new JsonResponse(['code' => -7007,'msg'=>'order exist']);
		}
		
		//生成订单信息
		$channel_order_no = 'TCNO'.time().'T';
		$parameters = [
			'plantform_order_no'=>$plantform_order_no,
			'amount'=>$amount,
			'notify_url'=>$notify_url,
			'order_no'=>$order_no,
			'channel_id'=>$channel_id,
			'country'=>$country_name.$country_currency_name,
			'plantform_notify_url'=>$plantform_notify_url,
		];
		$pay_url = $this->generateUrl('simulation_html',[],UrlGeneratorInterface::ABSOLUTE_URL);
		$pay_url = str_replace('http:','https:',$pay_url);
		$pay_url = $pay_url.'?parameters='.base64_encode(json_encode($parameters));
		
		$res = ['code'=>0,'msg'=>'OK','channel_order_no'=>$channel_order_no,'pay_url'=>$pay_url];
		return new JsonResponse($res);
	}
	
	#[Route('/simulation.payout', name: 'simulation_payout')]
    public function payout(Request $request): JsonResponse
	{
		$appid = $request->request->get('appid','');
		$time  = $request->request->get('time','');
		$plantform_order_no  = $request->request->get('plantform_order_no','');
		$sign  = $request->request->get('sign','');
		$plantform_notify_url  = $request->request->get('plantform_notify_url','');
		$amount  = $request->request->get('amount','');
		$account_no  = $request->request->get('account_no','');
		$account_name  = $request->request->get('account_name','');
		
		if('' == $appid || strlen($appid) < 2)
		{
			return new JsonResponse(['code' => -7003,'msg'=>'INVALIDATE_APPID']);
		}
		if('' == $sign || strlen($sign) < 10)
		{
			return new JsonResponse(['code' => -7004,'msg'=>'INVALIDATE_SIGN']);
		}
		if('' == $plantform_order_no || strlen($plantform_order_no) < 10)
		{
			return new JsonResponse(['code' => -7005,'msg'=>'plantform_order_no is missing']);
		}
		
		$my_sign = md5($time.$plantform_order_no);
		if($my_sign != $sign)
		{
			return new JsonResponse(['code' => -7006,'msg'=>'SIGN_ERR']);
		}
		
		//查找订单
		$order = $this->entityManager->getConnection()->executeQuery('select id from order_payout where pno="'.$plantform_order_no.'"')->fetchAssociative();
		if($order)
		{
			return new JsonResponse(['code' => -7007,'msg'=>'order exist']);
		}
		
		//生成订单信息
		$channel_order_no = 'TCNO'.time().'T';

		$res = ['code'=>0,'msg'=>'OK','channel_order_no'=>$channel_order_no];
		return new JsonResponse($res);
	}
	
	#[Route('/simulation/html/', name: 'simulation_html')]
    public function html(Request $request)
	{
		$token = $request->query->get('token','');
		if(strlen($token) < 10)
		{
			$this->e('simulation_html is missing token');
		}
		
		$data = [];
		
		$data['plantform_order_no'] = $this->authcode($token,'DECODE');
		$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$data['plantform_order_no']]);
		
		$data['ajax_url'] = $this->generateUrl('simulation_ajax',[],UrlGeneratorInterface::ABSOLUTE_URL);
		$data['ajax_url'] = str_replace('http:','https:',$data['ajax_url']);
		
		$data['token'] = $this->authcode('ID|'.$order->getId());
		$data['amount'] = $order->getAmount();
		$data['country'] = $order->getCountry().$order->getCurrency();
		$data['order_no'] = $order->getMno();
		$data['channel_id'] = $order->getCid();
		$data['notify_url'] = $order->getMerchantNotifyUrl();
		
		return $this->render('simulation/pay.html.twig',$data);
	}
	
	#[Route('/simulation.ajax', name: 'simulation_ajax')]
    public function ajax_notify(Request $request)
	{
		$token = $request->request->get('token','');
		$status = $request->request->get('status','');
		$bundle = $request->request->get('bundle','PAYIN');
		
		if(strlen($token) < 10) $this->e('token is missing');
		
		$data = $this->authcode($token,'DECODE');
		
		if('ID|' == substr($data,0,3))
		{
			$orderid = substr($data,3);
			
			$nd = $this->GetNd($bundle,$orderid,$status);
			
			$ret = $this->post_json($nd['plantform_notify_url'],$nd['notify_data']);
			echo json_encode(['code'=> 0,'msg'=>'操作完成']);
		}
		else
		{
			$this->e('token参数错误:'.$data);
		}
		die();
	}
	
	#[Route('/simulation.notify_data.get', name: 'simulation_notify_data_get')]
    public function get_notify_data(Request $request)
	{
		$bundle = $request->request->get('bundle','PAYIN');
		$status = $request->request->get('status','');
		$orderid = $request->request->get('orderid','0');
		$time = $request->request->get('time',0);
		$token = $request->request->get('token','');
		$my_token = md5('XIALI623745$-'.$orderid.'-'.$time);
		
		if($token != $my_token)
		{
			$this->e('[simulation.notify_data.get]token err');
		}
		
		$nd = $this->GetNd($bundle,$orderid,$status);
		
		echo json_encode($nd);
		die();
	}
	
	private function GetNd($bundle,$orderid,$status)
	{
		$IO = 'I';
		if('PAYOUT' == strtoupper($bundle))
		{
			$IO = 'O';
			$order = $this->db(\App\Entity\OrderPayout::class)->find($orderid);
		}
		else
		{
			$order = $this->db(\App\Entity\OrderPayin::class)->find($orderid);
		}
		
		if(!$order)
		{
			$this->e('order not exist!');
		}
		$channel_id = $order->getCid();
		$channel = $this->db(\App\Entity\Channel::class)->find($channel_id);
		if(!$channel)
		{
			$this->e('channel not exist!');
		}
		if(1 == $channel->isHasMethod())
		{
			//查找默认的
			$ChannelPayMethod = $this->db(\App\Entity\ChannelPayMethod::class)->findOneBy(['cid'=>$channel_id],['id'=>'asc']);
			$channel = $this->db(\App\Entity\Channel::class)->find($ChannelPayMethod->getTargetCid());
		}
		
		$channel_slug = $channel->getSlug();
		$channel_handler = new ('App\\Channel\\'.ucfirst($channel_slug).'\\'.ucfirst(strtolower($bundle)))();
		
		$pre_notify_data = [
			'STATUS'=>$status,
			'CNO'=>'SCN_'.strtoupper(substr(md5($order->getId().$order->getPno().microtime()),0,8)),
			'PNO'=>$order->getPno(),
			'AMOUNT'=>$order->getAmount(),
			'SIGN'=>md5($order->getId()),
		];
		$notify_data = $channel_handler->_get_simulation_notify_data($pre_notify_data);
		
		$plantform_notify_url = $this->generateUrl('api_notify',['io'=>$IO,'channel_slug'=>strtolower($channel->getSlug())],UrlGeneratorInterface::ABSOLUTE_URL);
		$plantform_notify_url = str_replace('http:','https:',$plantform_notify_url);
		
		return [
			'plantform_notify_url'=>$plantform_notify_url,
			'notify_data'=>$notify_data,
		];
	}

	#[Route('/simulation.notify', name: 'simulation_notify_url')]
    public function notify(Request $request)
	{
		echo 'success';
		die();
	}
}


