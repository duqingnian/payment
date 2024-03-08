<?php

namespace App\Channel;

class BaseChannel
{
	protected $entityManager      = NULL;
	protected $DATA               = [];
	protected $plantform_order_no = '';
	
	public function __construct() {}
	
	public function set_data($_data)
	{
		$this->entityManager = $_data['entityManager'];
		if(array_key_exists('DATA',$_data))
		{
			$this->DATA = $_data['DATA'];
		}
		if(array_key_exists('plantform_order_no',$_data))
		{
			$this->plantform_order_no = $_data['plantform_order_no'];
		}
	}

	function post_json($url, $jsonStr,$header=[],$method='POST')
	{
		if(is_array($jsonStr))
		{
			$jsonStr = json_encode($jsonStr);
		}
		$ch = curl_init();
		if('GET' == $method)
		{
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		}
		else
		{
			curl_setopt($ch, CURLOPT_POST, 1);
		}
		$header[] = 'Content-Type: application/json;charset=utf-8';
		$header[] = 'Content-Length: ' . strlen($jsonStr);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($httpCode, $response);
	}
	
    //POST请求
    function post_form($url, $data = null,$header=[],$method='POST') {
        $curl = curl_init ();
        curl_setopt ( $curl, CURLOPT_URL, $url );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYHOST, FALSE );
        if (! empty ( $data )) 
		{
            curl_setopt ( $curl, CURLOPT_POST, 1 );
			$header[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
			curl_setopt ( $curl, CURLOPT_POSTFIELDS, http_build_query($data) );
        }
        curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
        $response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return array($httpCode, $response);
    }
	
	//报错
	protected function e($msg,$code=-1)
    {
        echo json_encode(['code'=>$code,'msg'=>$msg]);
        exit();
    }
	
	//成功
	protected function succ($msg)
    {
        $this->e($msg,0);
    }
	
	//获取IP
	public function GetIp()
    {
        if (isset($_SERVER)) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $realip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $realip = $_SERVER['HTTP_CLIENT_IP'];
            } else {
                $realip = $_SERVER['REMOTE_ADDR'];
            }
        } else {
            if (getenv("HTTP_X_FORWARDED_FOR")) {
                $realip = getenv( "HTTP_X_FORWARDED_FOR");
            } elseif (getenv("HTTP_CLIENT_IP")) {
                $realip = getenv("HTTP_CLIENT_IP");
            } else {
                $realip = getenv("REMOTE_ADDR");
            }
        }
        return $realip;
    }
	
	function stand_ascii_params($params = array())
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
	
	function authcode($string, $operation = 'ENCODE', $key='') 
	{
		if('DECODE' != $operation)
		{
			return self::encrypt($string, $key, true);
		}
		else
		{
			return self::decrypt($string, $key, true);
		}
    }
	
	public static function encrypt($message, $key, $encode = true)
    {
        $nonceSize = openssl_cipher_iv_length('aes-256-ctr');
        $nonce = openssl_random_pseudo_bytes($nonceSize);
        
        $ciphertext = openssl_encrypt(
            $message,
            'aes-256-ctr',
            $key,
            OPENSSL_RAW_DATA,
            $nonce
        );
        
        if ($encode) {return str_replace('/','-',base64_encode($nonce.$ciphertext));}
        return str_replace('/','-',$nonce.$ciphertext);
    }
    
    public static function decrypt($message, $key, $encoded = true)
    {
		$message = str_replace('-','/',$message);
        if ($encoded)
		{
            $message = base64_decode($message, true);
            if ($message === false) {
                echo 'Encryption failure';die();
            }
        }

        $nonceSize = openssl_cipher_iv_length('aes-256-ctr');
        $nonce = mb_substr($message, 0, $nonceSize, '8bit');
        $ciphertext = mb_substr($message, $nonceSize, null, '8bit');
        
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-ctr',
            $key,
            OPENSSL_RAW_DATA,
            $nonce
        );
        
        return $plaintext;
    }
}
