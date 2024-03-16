<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\MainMsg;
use App\Util\PlantformGenerat;

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
		$DATA = file_get_contents('php://input');

		if(strlen($DATA) < 10)
		{
			$this->e('JSON_DATA_TOO_SHORT');
		}
		$json_parse_err = $this->json_validate($DATA);
		if('' != $json_parse_err)
		{
			$this->e('post data not validate json string:'.$json_parse_err);
		}
		$DATA = json_decode($DATA,true);
		if(!is_array($DATA) || count($DATA) < 5)
		{
			$this->e('PARAMETER_SIZE_NOT_ENOUGH');
		}
		
		if(!array_key_exists('appid',$DATA)) $this->e('APPID_IS_MISSING',7003);
		if(!array_key_exists('order_no',$DATA)) $this->e('ORDERNO_IS_MISSING',7004);
		if(!array_key_exists('amount',$DATA)) $this->e('AMOUNT_IS_MISSING',7005);
		if(!array_key_exists('notify_url',$DATA)) $this->e('NOTIFYURL_IS_MISSING',7006);
		if(!array_key_exists('timestamp',$DATA)) $this->e('TIMESTAMP_IS_MISSING',7007);
		if(!array_key_exists('version',$DATA)) $this->e('VERSION_IS_MISSING',7008);
		if(!array_key_exists('sign',$DATA)) $this->e('SIGN_IS_MISSING',7009);
		
		$channel_id          = $request->request->get('channel_id',''); //商户专属通道id，没有则为默认
		$appid               = trim($DATA['appid']);
		$merchant_order_no   = trim($DATA['order_no']);
		$amount              = trim($DATA['amount']);
		$merchant_notify_url = trim($DATA['notify_url']);
		$merchant_timestamp  = trim($DATA['timestamp']);
		$version             = trim($DATA['version']);
		$sign                = trim($DATA['sign']);
		$note                = '';
		$simulation = 0;
		if(array_key_exists('simulation',$DATA) && 1 == $DATA['simulation'])
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
		if(array_key_exists('note',$DATA)){$note = substr($DATA['note'],0,50);}
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
		$selfips = explode(',',$this->getParameter('selfips'));
		if(!in_array($request_ip,$selfips))
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

		//Check ip again
		if(!in_array($request_ip,$selfips) && $ip_table->getMid() != $merchant->getId())
		{
			$ip_table = $this->db(\App\Entity\IpTable::class)->findOneBy(['ip'=>$request_ip,'mid'=>$merchant->getId()]);
			if(!$ip_table)
			{
				$this->e('ACCESS_DENY:'.$request_ip);
			}
			if(!$ip_table->isIsActive())
			{
				$this->e('DISACTIVE:'.$request_ip);
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

		//Get merchant's channel
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
		
		//Check merchant's channel's min and max
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
		
		//Check merchant's balance
		$balance = 1 == $merchant->isIsTest() ? $merchant->getTestAmount() : $merchant->getAmount();
		$fee = ((float)$amount * ($merchant_channel->getPct()/100)) + (float)$merchant_channel->getSf();
		if($balance - $amount - $fee < 0)
		{
			$this->e('INSUFFICIENT_BALANCE:AMOUNT:'.$amount.':BALANCE:'.$balance.':FEE:'.$fee.':NEED_MIN:'.($amount + $fee));
		}

		//Find country and currency
		$country = ['name'=>'','slug'=>'','currency'=>'','currency_name'=>''];
		if('' != $channel->getCountry())
		{
			$channel_country = $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$channel->getCountry()]);
			if($channel_country)
			{
				$country = ['name'=>$channel_country->getName(),'slug'=>$channel_country->getSlug(),'currency'=>$channel_country->getCurrency(),'currency_name'=>$channel_country->getCurrencyName()];
			}
		}

		//Get channel's handler and check
		$cls = 'App\\Channel\\'.ucfirst(trim($channel->getSlug())).'\\Payout';
		if(!class_exists($cls))
		{
			$this->e('['.$channel->getId().']channel payout handler not exist');
		}
		$channel_handler = new ($cls)();
		$channel_handler->check($DATA);
		
		//Generate the plantform order number
		$generator = new PlantformGenerat();
		$plantform_order_no = $generator->GetPno([
			'prefix'=>$this->getParameter('po_start'),
			'end'=>$this->getParameter('po_end'),
			'merchant'=>$merchant
		]);

		//Create an empty order template
		$order = new \App\Entity\OrderPayout();
		$order->setMid($merchant->getId());
		$order->setCid($channel->getId());
		$order->setAmount($amount);
		$order->setCno('');
		$order->setMno($merchant_order_no);
		$order->setPno($plantform_order_no);
		$order->setStatus('GENERATED');
		$order->setCpct($channel->getPayinPct());
		$order->setCsf($channel->getPayinSf());
		$order->setCfee($amount * ($channel->getPayoutPct() / 100) + (float)$channel->getPayoutSf());
		$order->setMpct($merchant_channel->getPct());
		$order->setMsf($merchant_channel->getSf());
		$order->setMfee($fee);
		$order->setRamount('');
		$order->setNote('');
		$order->setIsTest($merchant->isIsTest());
		$order->setMerchantNotifyUrl($merchant_notify_url);
		$order->setCountry($country['slug']);
		$order->setCurrency($country['currency']);
		$order->setOriginalStatus("");
		$order->setCreatedAt(time());
		$order->setRetry(0);
		$this->save($order);

		if(is_numeric($order->getId()) && $order->getId() > 0)
		{
			//do nothing
		}
		else
		{
			$this->e('order create fail, traceid:'.$plantform_order_no);
		}
		//save the merchant's post data
		$DATA['_ip'] = $request_ip;
		$process = new \App\Entity\PayProcessData();
		$process->setIo('O');
		$process->setBundle('M_RTPF_D');
		$process->setData(json_encode($DATA));
		$process->setPno($plantform_order_no);
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$process->setCreatedAt(time());
		$this->save($process);

		//Response to the merchant
		$response_data = [
			'code'=>0,
			'msg'=>'OK',
			'order_status'=>$order->getStatus(),
			'plantform_order_no'=>$order->getPno(),
			'shanghu_order_no'=>$order->getMno(),
			'amount'=>$order->getAmount(),
			'created_at'=>$order->getCreatedAt(),
			'currency'=>$country['currency'],
			'updated_at'=>time(),
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
		$process->setBundle('PF_RTM_D');
		$process->setData(json_encode($response_data));
		$process->setPno($plantform_order_no);
		$process->setCid($channel->getId());
		$process->setMid($merchant->getId());
		$process->setCreatedAt(time());
		$this->save($process);

		//Generate notify url
		$plantform_notify_url = $this->generateUrl('api_notify',['io'=>'O','channel_slug'=>strtolower($channel->getSlug())],UrlGeneratorInterface::ABSOLUTE_URL);
		$plantform_notify_url = str_replace('http:','https:',$plantform_notify_url);
		
		if($merchant->isIsTest() || 1 == $simulation)
		{
			$DATA['TEST'] = [
				'channel_order_no'=>'SCO_'.strtoupper(substr(md5($merchant->getId().$plantform_order_no.microtime()),0,10)),
			];
		}

		//Prepare MSG struct to message queue
		$DATA['plantform_notify_url'] = $plantform_notify_url;
		$DATA['channel_id'] = $order->getCid();
		$DATA['merchant_id'] = $order->getMid();
		$MSG = [
			'action'=>'PAYOUT_CREATED',
			'order_id'=>$order->getId(),
			'DATA'=>$DATA,
		];
		
		//Add message queue
		$bus->dispatch(new MainMsg(json_encode($MSG)));

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
		$danger_symbos = ['dir','php','ini','exec','syst','delete','select','file','where','?','"',"'"];
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


