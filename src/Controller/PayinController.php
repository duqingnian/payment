<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class PayinController extends BaseController
{
	#[Route('/api/payment/order/create', name: 'api_payin_create')]
    public function create(Request $request): JsonResponse
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
			return new JsonResponse(['code' => 6001,'msg'=>'CONTENT_TYPE_IS_MISSING']);
		}
		if($allow_content_type !== substr($client_content_type,0,strlen($allow_content_type)))
		{
			return new JsonResponse(['code' => 6002,'msg'=>'CONTENT_TYPE_MUST_BE_START_WITH:application/x-www-form-urlencoded,CURRENT_CONTENT_TYPE_IS:'.$client_content_type]);
		}
		
		//检测参数
		$channel_id  = $request->request->get('channel_id',''); //商户专属通道id，没有则为默认
		$appid       = $request->request->get('appid','');      //商户代收appid
		$sign        = $request->request->get('sign','');       //签名
		$amount      = $request->request->get('amount','');     //金额
		$note        = $request->request->get('note','');       //备注
		$simulation  = $request->request->get('simulation',''); //是否为模拟单
		$merchant_order_no   = $request->request->get('order_no','');   //商户订单号
		$merchant_notify_url = $request->request->get('notify_url',''); //回调地址
		$merchant_jump_url   = $request->request->get('jump_url','');   //同步跳转地址
		$timestamp   = $request->request->get('timestamp','');
		$version   = $request->request->get('version','');
		
		
		if('' == $appid || strlen($appid) < 5)
		{
			return new JsonResponse(['code' => 6003,'msg'=>'INVALIDATE_APPID']);
		}
		if('' == $sign || strlen($sign) < 10)
		{
			return new JsonResponse(['code' => 6004,'msg'=>'INVALIDATE_SIGN']);
		}
		if('' == $amount)
		{
			return new JsonResponse(['code' => 6005,'msg'=>'AMOUNT_IS_MISSING']);
		}
		if('' == $merchant_order_no)
		{
			return new JsonResponse(['code' => 6006,'msg'=>'ORDER_NO_IS_MISSING']);
		}
		if('' == $merchant_notify_url)
		{
			return new JsonResponse(['code' => 6007,'msg'=>'NOTIFY_URL_IS_MISSING']);
		}
		if(strlen($merchant_notify_url) >= 200)
		{
			return new JsonResponse(['code' => 6008,'msg'=>'NOTIFY_URL_OUT_OF_LENGTH:199']);
		}
		if(strlen($merchant_jump_url) >= 200)
		{
			return new JsonResponse(['code' => 6009,'msg'=>'JUMP_URL_OUT_OF_LENGTH:199']);
		}
		if('' == $timestamp || strlen($timestamp) < 10)
		{
			return new JsonResponse(['code' => 6003,'msg'=>'INVALIDATE_timestamp']);
		}
		if('2.0' != $version)
		{
			return new JsonResponse(['code' => 6003,'msg'=>'INVALIDATE_VERSION']);
		}
		
		$appid               = $this->_filter_danger($appid);
		$merchant_order_no   = $this->_filter_danger($merchant_order_no);
		$channel_id          = $this->_filter_danger($channel_id);
		$note                = $this->_filter_danger($note);
		if('' == $appid || strlen($appid) < 5)
		{
			return new JsonResponse(['code' => -6010,'msg'=>'INVALIDATE_APPID']);
		}
		if('' == $merchant_order_no)
		{
			return new JsonResponse(['code' => -6011,'msg'=>'ORDER_NO_IS_MISSING']);
		}

		//检测签名
		$client_post_data = $_POST;
		if(strlen($note) > 0)
		{
			$note = substr($note,0,50);
			$client_post_data['note'] = $note;
		}
		if('' != $channel_id)
		{
			if(is_numeric($channel_id) && $channel_id > 0)
			{
				//
			}
			else
			{
				return new JsonResponse(['code' => 6010,'msg'=>'INVALIDATE_CHANNEL_ID']);
			}
		}
		
		//根据appid查找商户
		$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payin_appid'=>$appid]);
		if(!$merchant)
		{
			return new JsonResponse(['code' => -6011,'msg'=>'APPID_NOT_MATCH:'.$appid]);
		}
		if(0 == $merchant->isIsActive())
		{
			return new JsonResponse(['code' => -6012,'msg'=>'MERCHANT_NOT_ACTIVE:'.$appid]);
		}
		$sign_data = [
			'appid'=>$client_post_data['appid'],
			'order_no'=>$client_post_data['order_no'],
			'amount'=>"".$client_post_data['amount'],
			'timestamp'=>$client_post_data['timestamp'],
			'version'=>'2.0'
		];
		$my_sign = $this->_hash_hmac($sign_data,$merchant->getPayinSecret());
		if(trim($my_sign) != trim($sign))
		{
			return new JsonResponse(['code' => -6013,'msg'=>'INVALIDATE_SIGN']);
		}
		
		//检测商户订单号是不是已经存在了
		$merchant_order_no_checker = $this->entityManager->getConnection()->executeQuery('select id from order_payin where mno="'.$merchant_order_no.'"')->fetchAssociative();
		if($merchant_order_no_checker)
		{
			return new JsonResponse(['code' => 6014,'msg'=>'INVALIDATE_ORDER_NO,PLEASE_CHANGE']);
		}
		
		//通道
		$merchant_channel = $this->_get_merchant_channel($merchant,$channel_id);
		if(NULL == $merchant_channel)
		{
			return new JsonResponse(['code' => 6015,'msg'=>'MERCHANT_VALIDATE_CHANNEL_IS_NULL：'.$merchant->getId()]);
		}
		$channel = $this->db(\App\Entity\Channel::class)->find($merchant_channel->getCid());
		if(!$channel)
		{
			return new JsonResponse(['code' => 6016,'msg'=>'CHANNEL_NOT_EXISTS:'.$merchant_channel->getCid()]);
		}
		if(1 != $channel->isIsActive())
		{
			return new JsonResponse(['code' => 6017,'msg'=>'CHANNEL_NOT_ACTIVED:'.$channel->getId()]);
		}
		$channel_slug = trim($channel->getSlug());
		if('' == $channel_slug)
		{
			return new JsonResponse(['code' => 6018,'msg'=>'CHANNEL_SLUG_NULL:'.$channel->getId()]);
		}
		
		//查找国家和货币
		$country = ['name'=>'','slug'=>'','currency'=>'','currency_name'=>''];
		if('' != $channel->getCountry())
		{
			$channel_country = $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$channel->getCountry()]);
			if($channel_country)
			{
				$country = ['name'=>$channel_country->getName(),'slug'=>$channel_country->getSlug(),'currency'=>$channel_country->getCurrency(),'currency_name'=>$channel_country->getCurrencyName()];
			}
		}
		
		//检查商户通道的支付上限和下限
		$merchant_channel_payin_min = $merchant_channel->getMin();
		$merchant_channel_payin_max = $merchant_channel->getMax();
		
		if(-1 != $merchant_channel_payin_min)
		{
			if($amount < $merchant_channel_payin_min)
			{
				return new JsonResponse(['code' => -1,'msg'=>'AMOUNT:'.$amount.'_LIMITED:'.$merchant_channel_payin_min]);
			}
		}
		if(-1 != $merchant_channel_payin_max)
		{
			if($amount > $merchant_channel_payin_max)
			{
				return new JsonResponse(['code' => -1,'msg'=>'AMOUNT:'.$amount.'_OUTOF:'.$merchant_channel_payin_max]);
			}
		}
		
		//查询是不是限额
		$merchant_payin_limit = $this->db(\App\Entity\MerchantPayinLimit::class)->findOneBy(['mid'=>$merchant->getId(),'cid'=>$channel->getId()]);
		if($merchant_payin_limit)
		{
			if(1 == (int)$merchant_payin_limit->isIsClosed())
			{
				$this->e('TRADE_LIMITED:'.date('Y-m-d H:i:s'));
			}
			if($merchant_payin_limit->getLimitAmount() > 1)
			{
				if($merchant_payin_limit->getCurrentAmount() + $amount > $merchant_payin_limit->getLimitAmount() + $merchant_payin_limit->getLastOutLimitAmount())
				{
					$this->e('TRANSACTION_LIMITED:'.date('Y-m-d H:i:s'));
				}
			}
		}
		
		//生成订单号
		$pi_start = $this->getParameter('pi_start');
		$pi_end = $this->getParameter('pi_end');
		if('' == $pi_start){$pi_start = '0';}
		if('' == $pi_end){$pi_end = 'Z';}

		$plantform_order_no = $pi_start.date('md').strtoupper(Uuid::v6()->toBase32());
		if($merchant->isIsTest())
		{
			$plantform_order_no = 'TEST'.$pi_start.date('md').strtoupper(Uuid::v6()->toBase32());
		}
		$plantform_order_no = substr($plantform_order_no,0,31).$pi_end;
		
		//记录下商户发来的数据
		$client_data = json_encode($_POST);
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('M_RTPF_D'); //merchant request to plantform data
		$process->setData($client_data);
		$process->setPno($plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$this->save($process);
		
		//把data传递给通道  记录发给通道的数据 记录通道返回的数据
		$DATA = $_POST;
		$DATA['channel_id'] = $channel->getId();
		$DATA['merchant_id'] = $merchant->getId();
		$DATA['country'] = $country;
		
		//生成回调地址
		$DATA['plantform_notify_url'] = $this->generateUrl('api_notify',['io'=>'I','channel_slug'=>strtolower($channel->getSlug())],UrlGeneratorInterface::ABSOLUTE_URL);
		$DATA['plantform_notify_url'] = str_replace('http:','https:',$DATA['plantform_notify_url']);
		
		//如果是测试账号
		$_simulation = 0;
		if($merchant->isIsTest() || 1 == $simulation)
		{
			$_simulation = 1;
			
			$pay_url = $this->generateUrl('simulation_html',[],UrlGeneratorInterface::ABSOLUTE_URL);
			$pay_url = str_replace('http:','https:',$pay_url);
			$pay_url = $pay_url.'?token='.urlencode($this->authcode($plantform_order_no));

			$DATA['TEST'] = [
				'channel_order_no'=>'SC_'.strtoupper(substr(md5($merchant->getId().$plantform_order_no.microtime()),0,8)),
				'pay_url'=>$pay_url,
			];
		}
		$cls = 'App\\Channel\\'.ucfirst($channel_slug).'\\Payin';
		if(!class_exists($cls))
		{
			$this->e('channel:'.$channel_slug.' handle not exist!');
		}
		$DATA['appsecret'] = $this->getParameter('appsecret');
		$channel_handler = new ($cls)();
		$channel_handler->set_data([
			'entityManager'=>$this->entityManager,
			'DATA'=>$DATA,
			'plantform_order_no'=>$plantform_order_no,
		]);
		
		$ret = $channel_handler->handle($_simulation);
		
		//没问题，生成订单 真实支付金额  跳转地址
		if(0 == $ret['code'])
		{
			//通道单号
			$channel_order_no = '';
			
			if(array_key_exists('channel_order_no',$ret))
			{
				$channel_order_no = $ret['channel_order_no'];
			}
			
			//生成订单
			$order = new \App\Entity\OrderPayin();
			$order->setMid($merchant->getId());
			$order->setCid($channel->getId());
			$order->setAmount($amount);
			$order->setCno($channel_order_no);
			$order->setMno($merchant_order_no);
			$order->setPno($plantform_order_no);
			$order->setStatus('GENERATED');
			$order->setCpct($channel->getPayinPct());
			$order->setCsf($channel->getPayinSf());
			$order->setCfee($amount * ($channel->getPayinPct() / 100) + $channel->getPayinSf());
			$order->setMpct($merchant_channel->getPct());
			$order->setMsf($merchant_channel->getSf());
			$order->setMfee($amount * ($merchant_channel->getPct() / 100) + $merchant_channel->getSf());
			$order->setRamount('');
			$order->setNote($note);
			$order->setIsTest($merchant->isIsTest());
			$order->setMerchantNotifyUrl($merchant_notify_url);
			$order->setMerchantJumpUrl($merchant_jump_url);
			$order->setCountry($country['slug']);
			$order->setCurrency($country['currency']);
			$order->setOriginalStatus("");
			$order->setCreatedAt(time());

			$this->save($order);
		}
		else
		{
			return new JsonResponse($ret);
		}
		
		//记录发给商户的数据
		$pay_url = $ret['pay_url'];

		$response_data = [
			'code'=>0,
			'msg'=>'OK',
			'order_status'=>$order->getStatus(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'created_at'=>$order->getCreatedAt(),
			'currency'=>$country['currency'],
			'updated_at'=>time()+1,
			'pay_url'=>$pay_url,
			'qrcode'=>$pay_url,
			'version'=>'2.0',
		];
		
		$sign_data = [
			'appid'=>$merchant->getPayinAppid(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'timestamp'=>$order->getCreatedAt(),
			'version'=>'2.0'
		];
		$response_data['sign'] = $this->_hash_hmac($sign_data,$merchant->getPayinSecret());
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('PF_RTM_D'); //plantform return to merchant data
		$process->setData(json_encode($response_data));
		$process->setPno($plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$this->save($process);

		//发送json数据给接口调用者
		return new JsonResponse($response_data);
    }
	
	private function _get_merchant_channel($merchant,$channel_id)
	{
		if(is_numeric($channel_id) && $channel_id > 0)
		{
			//传递了通道id就查询通道id
			$merchant_channel = $this->db(\App\Entity\MerchantChannel::class)->find($channel_id);
			if(!$merchant_channel)
			{
				echo json_encode(['code' => -6101,'msg'=>'INVALIDATE_PAYIN_MERCHANT_CHANNEL:'.$channel_id]);die();
			}
			if('PAYIN' != $merchant_channel->getBundle())
			{
				echo json_encode(['code' => -6102,'msg'=>'PAYIN_MERCHANT_CHANNEL_NOT_MATCHED:'.$merchant_channel->getId()]);die();
			}
		}
		else
		{
			//没有传递id就查询默认的
			$merchant_channel = $this->db(\App\Entity\MerchantChannel::class)->findOneBy(['bundle'=>'PAYIN','mid'=>$merchant->getId(),'is_default'=>1]);
			if(!$merchant_channel)
			{
				echo json_encode(['code' => -6103,'msg'=>'DEFAULT_PAYIN_MERCHANT_CHANNEL_NOT_CONFIGED:'.$merchant->getId()]);die();
			}
		}
		if(!$merchant_channel->isIsActive())
		{
			echo json_encode(['code' => -6104,'msg'=>'PAYIN_MERCHANT_CHANNEL_NOT_ACTIVED:'.$merchant_channel->getId()]);die();
		}
		return $merchant_channel;
	}
	
	private function _filter_danger($str)
	{
		if('' != $str)
		{
			$danger_symbos = ['"',"'",'select','where','from','drop'];
			$str = trim($str);
			return str_replace($danger_symbos,'',$str);
		}
		return '';
	}
	
	function _ascii_params($params = array())
	{
		if (!empty($params)) 
		{
			$p = ksort($params);
			if ($p) 
			{
				$str = '';
				foreach ($params as $k => $val) 
				{
					$str .= $k . '=' . $val . '&';
				}
				$strs = rtrim($str, '&');
				return $strs;
			}
		}
		return '';
	}
	
	function _hash_hmac($data, $key)
	{
		$str = $this->_ascii_params($data);
		$signature = "";
		if (function_exists('hash_hmac')) 
		{
			$signature = base64_encode(hash_hmac("sha1", $str, $key, true));
		}
		else
		{
			$blocksize = 64;
			$hashfunc = 'sha1';
			if (strlen($key) > $blocksize) 
			{
				$key = pack('H*', $hashfunc($key));
			}
			$key = str_pad($key, $blocksize, chr(0x00));
			$ipad = str_repeat(chr(0x36), $blocksize);
			$opad = str_repeat(chr(0x5c), $blocksize);
			$hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $str))));
			$signature = base64_encode($hmac);
		}
		return $signature;
	}

}
