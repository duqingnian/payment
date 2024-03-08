<?php

namespace App\Channel\Betcatpay;

class Payin extends Base
{	
	private function _check()
	{
	}
	
	//获取模拟回调数据
	public function _get_simulation_notify_data($data)
	{
		$status = ['_success'=>'3','_fail'=>'-1'];
		$notify_data = [
			'orderStatus'=>$status[$data['STATUS']],
			'orderNo'=>$data['CNO'], //平台单号
			'merOrderNo'=>$data['PNO'],
			'amount'=>$data['AMOUNT'],
			'currency'=>'BRL',
			'createTime'=>time(),
			'updateTime'=>time(),
			'sign'=>$data['SIGN'],
		];
		return json_encode($notify_data);
	}
	
	//获取模拟同步返回数据
	public function _get_simulation_data()
	{
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$simulation_data = [
			'code'=>0,
			'msg'=>'success',
			'data'=>[
				'amount'=>$amount,
				'createTime'=>time(),
				'currency'=>'BRL',
				'merOrderNo'=>$this->plantform_order_no,
				'orderNo'=>$this->DATA['TEST']['channel_order_no'],
				'orderStatus'=>'1',
				'sign'=>md5($this->plantform_order_no),
				'updateTime'=>time(),
				'params'=>[
					'qrcode'=>'',
					'url'=>$this->DATA['TEST']['pay_url'],
				],
			],
		];
		
		return [200,json_encode($simulation_data)];
	}
	
	//正式账户 提交给接口
	public function handle($simulation = 0)
	{
		$this->_check();
		
		$api = "https://v1.a.betcatpay.com/api/v1/payment/order/create";
		$amount = sprintf("%.2f",$this->DATA['amount']);
		$post_data = [
			'appId'=>$this->payin_appid,
			'merOrderNo'=>$this->plantform_order_no,
			'amount'=>$amount,
			'currency'=>"BRL",
			'notifyUrl'=>$this->DATA['plantform_notify_url'],
		];
		$post_data['sign'] = self::create($post_data,$this->payin_secret);

		//将提交给接口的数据保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('PF_RTC_D'); //plantform request to channel data
		$process->setData(json_encode($post_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		if(0 == $simulation)
		{
			$ret = $this->post_json($api,$post_data);
		}
		else
		{
			$ret = $this->_get_simulation_data();
		}
		//print_r($ret);die();
		if(!is_array($ret))
		{
			return ['code'=>-1,'msg'=>'NETWORK_ERROR_WHILE_POST_DATA'];
		}
		if(200 != $ret[0])
		{
			return ['code'=>-1,'msg'=>'HTTP_NOT_200_EXCEPT:200:'.$ret[0]];
		}
		
		//通道接口的返回值
		if(strlen(trim($ret[1])) < 1)
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NULL'];
		}
		$http_code = $ret[0];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_RTPF_D'); //channel return to plantform data
		$process->setData($ret[1]);
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		$ret_data  = json_decode($ret[1],true);
		if(!is_array($ret_data))
		{
			$this->e('['.$process->getId().']INVALIDATE_CHANNEL_RESULT_DATA');
		}

		$api_return_data = json_decode($ret[1],true);
		if(!array_key_exists('code',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_code'];
		}
		
		//{"code":400,"error":"Merchant collection single minimum transaction amount: 20.00, single maximum transaction amount: 10000.00"}
		if('0' != strtoupper($api_return_data['code']))
		{
			return ['code'=>-1,'msg'=>'RET:'.$api_return_data['code'].':'.$api_return_data['error']];
		}
		if(!array_key_exists('data',$api_return_data))
		{
			return ['code'=>-1,'msg'=>'API_RESULT_NOT_CONTAINS_data'];
		}
		
		//清洗数据 返回
		$clean_data = [
			'code'=>0,
			'msg'=>'OK',
			'http_code'=>$http_code,
			'channel_order_no'=>$ret_data['data']['orderNo'],
			'pay_url'=>$ret_data['data']['params']['url'],
		];

		//保存到数据库
		$process = new \App\Entity\PayProcessData();
		$process->setIo('I');
		$process->setBundle('C_RTPF_CD'); //channel return to plantform clean data
		$process->setData(json_encode($clean_data));
		$process->setPno($this->plantform_order_no);
		$process->setCreatedAt(time());
		$process->setCid($this->DATA['channel_id']);
		$process->setMid($this->DATA['merchant_id']);
		$this->entityManager->persist($process);
		$this->entityManager->flush();
		
		return $clean_data;
	}
	
	/////////////////////////////////
	// 生成签名
	/////////////////////////////////
	private function ascii_params($params = array())
	{
		if (!empty($params)) {
			$p = ksort($params);
			if ($p) {
				$str = '';
				foreach ($params as $k => $val) {$str .= $k . '=' . $val . '&';}
				$strs = rtrim($str, '&');
				return $strs;
			}
		}
		return '';
	}

	public static function create($map,$appSecret) {
        $signStr = self::createSignStr($appSecret, $map);
        return hash('sha256', $signStr);
    }

    public static function createSignStr($appSecret, $map) {
        $signStr = self::joinMap($map);
        $signStr .= '&'. 'key' . '=' . $appSecret;

        return $signStr;
    }

    private static function prepareMap($map) {
        if (!is_array($map)) {
            return array();
        }

        if (array_key_exists('sign', $map)) {
            unset($map['sign']);
        }
        ksort($map);
        reset($map);

        return $map;
    }

    private static function joinMap($map) {
        if (!is_array($map)) {
            return '';
        }

        $map = self::prepareMap($map);
        $pair = array();
        foreach($map as $key => $value) {
            if (self::isIgnoredItem($key, $value)) {
                continue;
            }

            $tmp = $key . '=';
            if(0 === strcmp('extra', $key)) {
                 $tmp .= self::joinMap($value);
            } else {
                $tmp .= $value;
            }

            $pair[] = $tmp;
        }

        if (empty($pair)) {
            return '';
        }

        return join('&', $pair);
    }

    private static function isIgnoredItem($key, $value) {
        if (empty($key) || empty($value)) {
            return true;
        }

        if (0 === strcmp('sign', $key)) {
            return true;
        }

        if (0 === strcmp('extra', $key)) {
            return false;
        }

        if (is_string($value)) {
            return false;
        }
        
        if (is_numeric($value)) {
            return false;
        }

        if (is_bool($value)) {
            return false;
        }
         
        return true;
    }
}



