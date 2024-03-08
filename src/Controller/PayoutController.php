<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\MainMsg;

class PayoutController extends BaseController
{
	#[Route('/api/payout/order/create', name: 'api_payout_create')]
    public function create(Request $request, MessageBusInterface $bus): JsonResponse
    {
		//不允许GET直接访问
		if(!$request->isMethod('post'))
		{
			header('HTTP/1.1 403 Forbidden');
			echo 'HTTP/1.1 403 FORBIDDEN';
			die();
		}
		
		//代付创建 必须是json
		$client_content_type = $request->headers->get('content-type');
		$allow_content_type = 'application/json';
		if(strlen(trim($client_content_type)) < 2)
		{
			$this->e('CONTENT_TYPE_IS_MISSING');
		}
		if($allow_content_type !== substr($client_content_type,0,strlen($allow_content_type)))
		{
			$this->e('CONTENT_TYPE_MUST_BE_START_WITH:application/json,CURRENT_CONTENT_TYPE_IS:'.$client_content_type);
		}
		
		//代付必须有的字段
		$client_post_data = file_get_contents('php://input');
		if(strlen($client_post_data) < 10)
		{
			$this->e('JSON_DATA_TOO_SHORT');
		}
		$json_parse_err = $this->json_validate($client_post_data);
		if('' != $json_parse_err)
		{
			$this->e('post data not validate json string:'.$json_parse_err);
		}
		$client_post_data = json_decode($client_post_data,true);
		if(!is_array($client_post_data) || count($client_post_data) < 5)
		{
			$this->e('PARAMETER_SIZE_NOT_ENOUGH');
		}
		
		if(!array_key_exists('appid',$client_post_data)) $this->e('APPID_IS_MISSING',7003);
		if(!array_key_exists('order_no',$client_post_data)) $this->e('ORDERNO_IS_MISSING',7004);
		if(!array_key_exists('amount',$client_post_data)) $this->e('AMOUNT_IS_MISSING',7005);
		if(!array_key_exists('notify_url',$client_post_data)) $this->e('NOTIFYURL_IS_MISSING',7006);
		if(!array_key_exists('timestamp',$client_post_data)) $this->e('TIMESTAMP_IS_MISSING',7007);
		if(!array_key_exists('version',$client_post_data)) $this->e('VERSION_IS_MISSING',7008);
		if(!array_key_exists('sign',$client_post_data)) $this->e('SIGN_IS_MISSING',7009);
		
		$channel_id          = $request->request->get('channel_id',''); //商户专属通道id，没有则为默认
		$appid               = trim($client_post_data['appid']);
		$merchant_order_no   = trim($client_post_data['order_no']);
		$amount              = trim($client_post_data['amount']);
		$merchant_notify_url = trim($client_post_data['notify_url']);
		$merchant_timestamp  = trim($client_post_data['timestamp']);
		$version             = trim($client_post_data['version']);
		$sign                = trim($client_post_data['sign']);
		$note                = '';
		$simulation = 0;
		if(array_key_exists('simulation',$client_post_data) && 1 == $client_post_data['simulation'])
		{
			$simulation = 1;
		}
		
		if('' != $channel_id)
		{
			if(is_numeric($channel_id) && $channel_id > 0)
			{
				//
			}
			else
			{
				return new JsonResponse(['code' => 7010,'msg'=>'INVALIDATE_CHANNEL_ID']);
			}
		}
		if(array_key_exists('note',$client_post_data)){$note = substr($client_post_data['note'],0,50);}
		if(strlen($appid) < 6){$this->e('INVALIDATE_APPID');}
		if(strlen($merchant_order_no) < 4){$this->e('merchant_order_no too short');}
		if(strlen($merchant_notify_url) < 10){$this->e('merchant_notify_url too short');}
		//if('https:' != substr($merchant_notify_url,0,6)){$this->e('merchant_notify_url must be start with `https`');}
		if(10 != strlen($merchant_timestamp)){$this->e('invalidate timestamp length');}
		if('2.0' != $version){$this->e('invalidate version:'.$version);}
		
		//IP白名单检测
		$request_ip = $this->GetIp();
		if('' == $request_ip)
		{
			$this->e('ip is missing');
		}
		if(!in_array($request_ip,['2610:150:c009:8:f816:3eff:febb:ef04','64.32.27.21']))
		{
			$ip_table = $this->db(\App\Entity\IpTable::class)->findOneBy(['ip'=>$request_ip]);
			if(!$ip_table){$this->e('IP_ACCESS_DENY:'.$request_ip);}
			if(!$ip_table->isIsActive()){$this->e('IP_DISABLED:'.$request_ip);}
		}

		//获取商户
		$merchant = $this->db(\App\Entity\Merchant::class)->findOneBy(['payout_appid'=>$appid]);
		if(!$merchant)
		{
			$this->e('MERCHANT_BY_APPID_404:'.$appid);
		}
		if(!in_array($request_ip,['2610:150:c009:8:f816:3eff:febb:ef04','64.32.27.21']) && $ip_table->getMid() != $merchant->getId())
		{
			$ip_table = $this->db(\App\Entity\IpTable::class)->findOneBy(['ip'=>$request_ip,'mid'=>$merchant->getId()]);
			if(!$ip_table)
			{
				$this->e('ACCESS_DENY_THIS_IP:'.$request_ip);
			}
			if(!$ip_table->isIsActive())
			{
				$this->e('IP_DISABLED_THIS_IP:'.$request_ip);
			}
		}
		
		if(0 == $merchant->isIsActive())
		{
			$this->e('MERCHANT_NOT_ACTIVED:'.$appid);
		}
		
		//验证签名
		$sign_data = ['appid'=>$appid,'order_no'=>$merchant_order_no,'amount'=>$amount,'timestamp'=>$merchant_timestamp,'version'=>'2.0'];
		$my_sign = md5($this->_ascii_params($sign_data).'&key='.$merchant->getPayoutSecret());
		if($my_sign != $sign)
		{
			$this->e('sign err,original sign:['.$sign.'],target sign:['.$my_sign.']');
		}
		//检测商户订单号是不是已经存在了
		$merchant_order_no_checker = $this->entityManager->getConnection()->executeQuery('select id from order_payout where mno="'.$merchant_order_no.'"')->fetchAssociative();
		if($merchant_order_no_checker)
		{
			$this->e('INVALIDATE_ORDER_NO,PLEASE_CHANGE');
		}
		
		//获取通道
		$merchant_channel = $this->_get_merchant_channel($merchant,$channel_id);
		if(NULL == $merchant_channel)
		{
			return new JsonResponse(['code' => 7015,'msg'=>'MERCHANT_VALIDATE_CHANNEL_IS_NULL：'.$merchant->getId()]);
		}
		
		$channel = $this->db(\App\Entity\Channel::class)->find($merchant_channel->getCid());
		if(!$channel)
		{
			return new JsonResponse(['code' => 7016,'msg'=>'CHANNEL_NOT_EXISTS:'.$merchant_channel->getCid()]);
		}
		if(1 != $channel->isIsActive())
		{
			return new JsonResponse(['code' => 7017,'msg'=>'CHANNEL_NOT_ACTIVED:'.$channel->getId()]);
		}
		$channel_slug = trim($channel->getSlug());
		if('' == $channel_slug)
		{
			return new JsonResponse(['code' => 7018,'msg'=>'CHANNEL_SLUG_NULL:'.$channel->getId()]);
		}
		
		//检查商户通道的支付上限和下限
		$merchant_channel_payout_min = $merchant_channel->getMin();
		$merchant_channel_payout_max = $merchant_channel->getMax();
		
		if(-1 != $merchant_channel_payout_min)
		{
			if($amount < $merchant_channel_payout_min)
			{
				return new JsonResponse(['code' => -1,'msg'=>'AMOUNT:'.$amount.'_LIMITED:'.$merchant_channel_payout_min]);
			}
		}
		if(-1 != $merchant_channel_payout_max)
		{
			if($amount > $merchant_channel_payout_max)
			{
				return new JsonResponse(['code' => -1,'msg'=>'AMOUNT:'.$amount.'_OUTOF:'.$merchant_channel_payout_max]);
			}
		}
		
		//检测余额是不是足以发起代付
		$balance = 1 == $merchant->isIsTest() ? $merchant->getTestAmount() : $merchant->getAmount();
		$fee = ((float)$amount * ($merchant_channel->getPct()/100)) + (float)$merchant_channel->getSf();
		if($balance - $amount - $fee < 0)
		{
			$this->e('INSUFFICIENT_BALANCE:AMOUNT:'.$amount.':BALANCE:'.$balance.':FEE:'.$fee.':NEED_MIN:'.($amount + $fee));
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
		
		//生成订单号
		$plantform_order_no = 'PO'.strtoupper(Uuid::v6()->toBase32());
		if($merchant->isIsTest())
		{
			$plantform_order_no = 'TESTPO'.strtoupper(Uuid::v6()->toBase32());
		}
		$plantform_order_no = substr($plantform_order_no,0,35).'L';
		
		//记录下商户发来的数据
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('M_RTPF_D'); //merchant request to plantform data
		$process->setData(json_encode($client_post_data));
		$process->setPno($plantform_order_no);
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$process->setCreatedAt(time());
		$this->save($process);
		
		//把data传递给通道  记录发给通道的数据 记录通道返回的数据
		$DATA = $client_post_data;
		$DATA['channel_id'] = $channel->getId();
		$DATA['merchant_id'] = $merchant->getId();
		$DATA['country'] = $country;
		
		//生成回调地址
		$DATA['plantform_notify_url'] = $this->generateUrl('api_notify',['io'=>'O','channel_slug'=>strtolower($channel->getSlug())],UrlGeneratorInterface::ABSOLUTE_URL);
		$DATA['plantform_notify_url'] = str_replace('http:','https:',$DATA['plantform_notify_url']);
		
		$_simulation = 0;
		if($merchant->isIsTest() || 1 == $simulation)
		{
			$_simulation = 1;
			$DATA['TEST'] = [
				'channel_order_no'=>'SCO_'.strtoupper(substr(md5($merchant->getId().$plantform_order_no.microtime()),0,10)),
			];
		}
		
		$cls = 'App\\Channel\\'.ucfirst($channel_slug).'\\Payout';
		if(!class_exists($cls))
		{
			$this->e('payout channel:'.$channel_slug.' handle not exist!');
		}
		$channel_handler = new ($cls)();
		$channel_handler->set_data([
			'entityManager'=>$this->entityManager,
			'DATA'=>$DATA,
			'plantform_order_no'=>$plantform_order_no,
		]);
		$ret = $channel_handler->handle($_simulation);
		
		if(!is_array($ret))
		{
			$this->e('An error occurred while creating payment order,traceid:'.$plantform_order_no);
		}
		
		//没问题，生成订单
		if(0 == $ret['code'])
		{
			//通道单号
			$channel_order_no = '';
			
			if(array_key_exists('channel_order_no',$ret))
			{
				$channel_order_no = $ret['channel_order_no'];
			}
			
			//生成订单
			$order = new \App\Entity\OrderPayout();
			$order->setMid($merchant->getId());
			$order->setCid($channel->getId());
			$order->setAmount($amount);
			$order->setCno($channel_order_no);
			$order->setMno($merchant_order_no);
			$order->setPno($plantform_order_no);
			$order->setStatus('GENERATED');
			$order->setCpct($channel->getPayinPct());
			$order->setCsf($channel->getPayinSf());
			$order->setCfee($amount * ($channel->getPayoutPct() / 100) + (float)$channel->getPayoutSf());
			$order->setMpct($merchant_channel->getPct());
			$order->setMsf($merchant_channel->getSf());
			$order->setMfee((float)$amount * ($merchant_channel->getPct() / 100) + (float)$merchant_channel->getSf());
			$order->setRamount('');
			$order->setNote($note);
			$order->setIsTest($merchant->isIsTest());
			$order->setMerchantNotifyUrl($merchant_notify_url);
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
			'version'=>'2.0',
		];
		
		$sign_data = [
			'appid'=>$merchant->getPayoutAppid(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'timestamp'=>$order->getCreatedAt(),
			'version'=>'2.0'
		];
		
		$response_data['sign'] = md5($this->_ascii_params($sign_data).'&key='.$merchant->getPayoutSecret());
		
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('PF_RTM_D'); //plantform return to merchant data
		$process->setData(json_encode($response_data));
		$process->setPno($plantform_order_no);
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$process->setCreatedAt(time());
		$this->save($process);
		
		//加入消息队列来变更余额和代付金额
		$bus->dispatch(new MainMsg(json_encode(['action'=>'PAYOUT_CREATED','order_id'=>$order->getId()])));

		//发送json数据给接口调用者
		return new JsonResponse($response_data);
	}
	
	private function _get_merchant_channel($merchant,$channel_id)
	{
		$merchant_channel = NULL;
		if(is_numeric($channel_id) && $channel_id > 0)
		{
			//传递了通道id就查询通道id
			$merchant_channel = $this->db(\App\Entity\MerchantChannel::class)->find($channel_id);
			if(!$merchant_channel)
			{
				echo json_encode(['code' => -6101,'msg'=>'INVALIDATE_PAYOUT_MERCHANT_CHANNEL:'.$channel_id]);die();
			}
			if('PAYOUT' != $merchant_channel->getBundle())
			{
				echo json_encode(['code' => -7102,'msg'=>'PAYOUT_MERCHANT_CHANNEL_NOT_MATCHED:'.$merchant_channel->getId()]);die();
			}
			if($merchant->getId() != $merchant_channel->getMid())
			{
				echo json_encode(['code' => -7107,'msg'=>'PAYOUT_MERCHANT_CHANNEL_OUT_OF_RANGE:'.$merchant_channel->getId()]);die();
			}
		}
		else
		{
			//没有传递id就查询默认的
			$merchant_channel = $this->db(\App\Entity\MerchantChannel::class)->findOneBy(['bundle'=>'PAYOUT','mid'=>$merchant->getId(),'is_default'=>1]);
			if(!$merchant_channel)
			{
				echo json_encode(['code' => -7103,'msg'=>'DEFAULT_PAYOUT_MERCHANT_CHANNEL_NOT_CONFIGED:'.$merchant->getId()]);die();
			}
		}
		if(!$merchant_channel->isIsActive())
		{
			echo json_encode(['code' => -7104,'msg'=>'PAYOUT_MERCHANT_CHANNEL_NOT_ACTIVED:'.$merchant_channel->getId()]);die();
		}
		return $merchant_channel;
	}
	
	private function json_validate($string)
	{
		$result =json_decode($string); 
		switch (json_last_error())
		{
			case JSON_ERROR_NONE:
				$error = '';
				break;
			case JSON_ERROR_DEPTH:
				$error = 'The maximum stack depth has been exceeded.'; break;
			case JSON_ERROR_STATE_MISMATCH:
				$error = 'Invalid or malformed JSON.'; break;
			case JSON_ERROR_CTRL_CHAR:
				$error = 'Control character error, possibly incorrectly encoded.'; break;
			case JSON_ERROR_SYNTAX:
				$error = 'syntax error, malformed JSON.'; break;
			case JSON_ERROR_UTF8:
				$error = 'Malformed UTF-8 characters, possibly incorrectly encoded.'; break;
			case JSON_ERROR_RECURSION:
				$error = 'One or more recursive references in the value to be encoded.'; break;
			case JSON_ERROR_INF_OR_NAN:
				$error = 'One or more NAN or INF values in the value to be encoded.'; break;
			case JSON_ERROR_UNSUPPORTED_TYPE:
				$error = 'A value of a type that cannot be encoded was given.'; break; 
			default:
				$error = 'Unknown JSON error occured.'; break;
		}
		return $error;
	}
	
	private function _filter_danger($str)
	{
		$danger_symbos = ['dir','php','ini','exec','syst','delete','select','file','where'];
		$str = trim($str);
		return str_replace($danger_symbos,'',$str);
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
}


