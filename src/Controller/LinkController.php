<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LinkController extends BaseController
{
	#[Route('/paylink/{token}', name: 'payment_link')]
    public function paylink(Request $request, $token): Response
    {
		if('' == $token)
		{
			$this->e('token is missing');
		}
		$_token = $this->authcode($token,'DECODE');
		if(strlen($_token) < 10)
		{
			$this->e('invalidate token');
		}
		if('PNO:' != substr($_token,0,4))
		{
			$this->e('invalidate error');
		}
		$pno = substr($_token, 4);
		
		$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$pno]);
		if(!$order)
		{
			$this->e('order not exists!');
		}
		
		//channel
		$channel = $this->db(\App\Entity\Channel::class)->find($order->getCid());
		
		//merchant
		$logo = '';
		$merchant = $this->db(\App\Entity\Merchant::class)->find($order->getMid());
		if('' != $merchant->getLogo())
		{
			$logo = $this->getParameter("mstatic_url").$merchant->getLogo();
		}
		
		$methods = $this->db(\App\Entity\ChannelPaymentMethodSetting::class)->findBy(['cid'=>$order->getCid(),'mid'=>$order->getMid(),'is_active'=>1]);
		
		$cls = 'App\\Channel\\'.ucfirst($channel->getSlug()).'\\MethodItem';
		if(!class_exists($cls))
		{
			$this->e('channel:'.$channel_slug.' MethodItem handle not exist!');
		}
		
		$icon = ['qris'=>'si-grid','virtual_account'=>'si-cursor','ewallet'=>'si-wallet'];
		
		$channel_handler = new ($cls)();
		
		$pm_items = $channel_handler->GetItems();
		
		$forbidden_pay_array = [];
		$forbidden_pay_items = $this->db(\App\Entity\ForbiddenPayItem::class)->findBy(['cid'=>$order->getCid(),'mid'=>$order->getMid()]);
		foreach($forbidden_pay_items as $forbidden)
		{
			$forbidden_pay_array[] = $forbidden->getBundle().'_'.$forbidden->getItemKey();
		}
		
		$clean_pm_items = [];
		foreach($pm_items as $bundle=>$pi)
		{
			$clean_pm_items[$bundle] = ['text'=>$pi['text'],'items'=>[]];
			foreach($pi['items'] as $_item)
			{
				if(!in_array($bundle.'_'.$_item['key'], $forbidden_pay_array))
				{
					$clean_pm_items[$bundle]['items'][] = $_item;
				}
			}
		}
		
		return $this->render("paylink.html.twig",[
			'token'=>$token,
			'order'=>$order,
			'icon'=>$icon,
			'methods'=>$methods,
			'pm_items'=>$clean_pm_items,
			'logo'=>$logo,
		]);
	}
	
	#[Route('/paylink.r/{token}/{method}/{item}', name: 'payment_link_redirect')]
    public function payment_link_redirect(Request $request, $token, $method, $item): Response
	{
		if('' == $token)
		{
			$this->e('token is missing');
		}
		if('' == $method)
		{
			$this->e('method is missing');
		}
		if('' == $item)
		{
			$this->e('item is missing');
		}
		$_token = $this->authcode($token,'DECODE');
		if(strlen($_token) < 10)
		{
			$this->e('invalidate token');
		}
		if('PNO:' != substr($_token,0,4))
		{
			$this->e('invalidate error');
		}
		$pno = substr($_token, 4);
		
		//查找订单信息
		$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$pno]);
		if(!$order)
		{
			$this->e('order not exists!');
		}
		
		if('GENERATED' != $order->getStatus())
		{
			$this->e('order not generated status, trade abort!');
		}
		
		$channel = $this->db(\App\Entity\Channel::class)->find($order->getCid());
		
		/**
		 * 回调地址 使用关联通道的地址
		 */
		
		//更新订单的支付方式和节点
		$order->setMethod($method);
		$order->setMethodItem($item);
		$this->update();
		
		//查找商户
		if(is_numeric($order->getMid()) && $order->getMid() > 0)
		{
			//do nothing...
		}
		else
		{
			$this->e('order merchant id is not a number.trade abort!');
		}
		$merchant = $this->db(\App\Entity\Merchant::class)->find($order->getMid());
		if(!$merchant)
		{
			$this->e('merchant not found:'.$order->getMid());
		}
		if(0 == $merchant->isIsActive())
		{
			$this->e('merchant not active:'.$order->getMid());
		}
		
		//获取关联通道的句柄
		$channel_pay_method = $this->db(\App\Entity\ChannelPayMethod::class)->findOneBy(['cid'=>$channel->getId(),'method'=>$method]);
		if(!$channel_pay_method)
		{
			$this->e('channel_pay_method not found!');
		}
		if(is_numeric($channel_pay_method->getTargetCid()) && $channel_pay_method->getTargetCid() > 0)
		{
			//do nothing
		}
		else
		{
			$this->e('channel_pay_method target channel not setting:'. $channel_pay_method->getTargetCid().':'.$method);
		}
		
		$target_channel = $this->db(\App\Entity\Channel::class)->find( $channel_pay_method->getTargetCid());
		if(!$target_channel)
		{
			$this->e('channel_pay_method target channel not exist:'. $channel_pay_method->getTargetCid().':'.$method);
		}
		
		//获取支付方式 更新订单的费率 单笔费用 商户费用
		$channel_pay_method_setting = $this->db(\App\Entity\ChannelPaymentMethodSetting::class)->findOneBy(['mid'=>$merchant->getId(),'cid'=>$channel->getId(),'method'=>$method]);
		if(!$channel_pay_method_setting)
		{
			$this->e('channel_pay_method_setting not found!');
		}
		
		//获取关联通道句柄
		$cls = 'App\\Channel\\'.ucfirst($target_channel->getSlug()).'\\Payin';
		if(!class_exists($cls))
		{
			$this->e('channel:'.$target_channel->getSlug().' handle not exist!');
		}
		$target_channel_handler = new ($cls)();
		$target_channel_handler->set_data([
			'entityManager'=>$this->entityManager,
			'DATA'=>['original'=>'link','method'=>$method,'item'=>$item,'order'=>$order,'token'=>$token],
			'plantform_order_no'=>$order->getPno(),
		]);
		//发起请求，获取真实支付地址
		$ret = $target_channel_handler->handle($merchant->isIsTest());
		if(0 != $ret['code'])
		{
			$this->e($ret['msg']);
		}
		else
		{
			if(array_key_exists('channel_order_no',$ret) && '' != $ret['channel_order_no'])
			{
				$order->setCno($ret['channel_order_no']);
				$order->setMpct($channel_pay_method_setting->getPct());
				$order->setMsf($channel_pay_method_setting->getSf());
				$order->setMfee($order->getAmount() * ($channel_pay_method_setting->getPct() / 100) + $channel_pay_method_setting->getSf());
				$this->update();
			}
			echo json_encode([
				'code'=>0,
				'msg'=>'OK',
				'pay_url'=>$ret['pay_url'],
			]);
			die();
		}
		//不应该出现的页面
		$this->e('Exception occoured while generate payment link:'.$order->getId());
	}
	
	private function _err($order,$err_message)
	{
		return $this->render('paylink/err.html.twig',['order'=>$order,'err_message'=>$err_message,'traceid'=>$this->authcode('ID:'.$order->getId())]);
	}
	
	#[Route('/paylink.finesh/{token}', name: 'payment_link_finesh')]
    public function payment_link_finesh(Request $request, $token): Response
	{
		if('' == $token)
		{
			$this->e('token is missing');
		}
		$_token = $this->authcode($token,'DECODE');
		if(strlen($_token) < 10)
		{
			$this->e('invalidate token');
		}
		if('PNO:' != substr($_token,0,4))
		{
			$this->e('invalidate error');
		}
		$pno = substr($_token, 4);
		
		//查找订单信息
		$order = $this->db(\App\Entity\OrderPayin::class)->findOneBy(['pno'=>$pno]);
		if(!$order)
		{
			$this->e('order not exists!');
		}
		$err_message = 'Order completed!';
		return $this->render('paylink/finesh.html.twig',['order'=>$order,'err_message'=>$err_message,'traceid'=>$this->authcode('ID:'.$order->getId())]);
	}
}
