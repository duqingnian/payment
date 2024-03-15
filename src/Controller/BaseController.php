<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BaseController extends AbstractController
{
	const METHOD = 'aes-256-ctr';
	protected EntityManagerInterface $entityManager;
	protected UserPasswordHasherInterface $passwordHasher;
	
	//基类的初始化操作
    public function __construct(EntityManagerInterface $entityManager,UserPasswordHasherInterface $passwordHasher)
    {
		$this->_initialization($entityManager,$passwordHasher);
    }
	
	//初始化
	private function _initialization(EntityManagerInterface $_entityManager,UserPasswordHasherInterface $_passwordHasher)
	{
		$this->entityManager = $_entityManager;
		$this->passwordHasher = $_passwordHasher;
	}
	
	//Doctrine句柄
	protected function db($table)
	{
		return $this->entityManager->getRepository($table);
	}
	
	//保存数据
	protected function save($object)
	{
		$this->entityManager->persist($object);
		$this->entityManager->flush();
	}
	
	//更新
	protected function update()
	{
		$this->entityManager->flush();
	}
	
	//根据 action 调用具体的接口
	protected function dispatch($request)
    {
		if(!$request->isMethod('post'))
		{
			$this->e('not a post request');
		}
		
        $action = $request->request->get("action",'');
        $csrf = $request->request->get("csrf",'');
		
		if('' == $action)
		{
			$this->e('action is missing');
		}
		if('' == $csrf)
		{
			//$this->e('csrf is missing');
		}
		
		if (!$this->isCsrfTokenValid('api_request', $csrf)) 
		{
			//$this->e('invalidate api_request csrf');
		}

		$method = '_'.$action;
        if(!method_exists($this,$method))
        {
            $this->e('method:'.$action.' not exist!');
        }
		$this->{$method}($request);
    }
	
	//报错
	protected function e(String $msg, int $code=-1)
    {
        echo json_encode((['code'=>$code,'msg'=>$msg]));
		exit();
    }
	
	//成功
	protected function succ(String $msg)
    {
        echo json_encode((['code'=>0,'msg'=>$msg]));
		exit();
    }
	
	//打印数据
	protected function console(Array $data,Array $ext=[])
    {
		$ret = ['code'=>0,'msg'=>'OK','data'=>$data];
		$ret = array_merge($ret,$ext);
        echo json_encode($ret);
		exit();
    }
	
	protected function authcode($string, $operation = 'ENCODE') 
	{
		$key = $this->getParameter('appsecret');
		if('DECODE' != $operation)
		{
			return self::encrypt($string, $key, true);
		}
		else
		{
			return self::decrypt($string, $key, true);
		}
    }

	/**
	ref: https://www.doctrine-project.org/projects/doctrine-dbal/en/3.7/reference/data-retrieval-and-manipulation.html
	1.fetchNumeric() - Retrieves the next row from the statement or false if there are none. The row is fetched as an array with numeric keys where the columns appear in the same order as they were specified in the executed SELECT query. Moves the pointer forward one row, so that consecutive calls will always return the next row.
	2.fetchAssociative() - Retrieves the next row from the statement or false if there are none. The row is fetched as an associative array where the keys represent the column names as specified in the executed SELECT query. Moves the pointer forward one row, so that consecutive calls will always return the next row.
	3.fetchOne() - Retrieves the value of the first column of the next row from the statement or false if there are none. Moves the pointer forward one row, so that consecutive calls will always return the next row.
	4.fetchAllNumeric() - Retrieves all rows from the statement as arrays with numeric keys.
	5.fetchAllAssociative() - Retrieves all rows from the statement as associative arrays.
	6.fetchFirstColumn() - Retrieves the value of the first column of all rows.
	*/
	protected function pager($request,$columns,$table,$where='',$orderBy=' order by id desc')
	{
		if('' == $where)
		{
			$where = ' where id > 0';
		}
		
		$page = $request->request->get('page','1');
		$per = $request->request->get('per','15');
		
		$total = $this->entityManager->getConnection()->executeQuery('select count(id) as t from '.$table.$where)->fetchOne();
		$pages = 0;
		if($total > $per)
		{
			$pages = ceil($total/$per);
		}
		
		$poffset = 3;
		$min = $page - $poffset > 1 ? $page - $poffset : 1;
        $max = $page + $poffset < $pages ? $page + $poffset : $pages;
        if($max < $min)
		{
            $max = $min;
		}
        $prev = $page - 1 > 0 ? $page - 1 : 1;
        $next = $page + 1 < $pages ? $page + 1 : $pages;
        $limit = ($page -1)*$per;
		
		$range = [];
        for($i=$min;$i<=$max;$i++)
        {
            $range[] = $i;
        }
		
		$sql = 'select '.$columns.' from '.$table.$where.$orderBy.' limit '.$limit.','.$per;
		$rows = $this->entityManager->getConnection()->executeQuery($sql)->fetchAllAssociative();
		
		return [
			'pager'=>[
				'page'=>$page,
				'pages'=>$pages,
				'per'=>$per,
				'total'=>$total,
				'range'=>$range
			],
			'rows'=>$rows,
		];
	}
	
	protected function GetId(String $string)
	{
		if('' == $string)
		{
			$this->e('request_token is null');
		}
		$decode_string = $this->authcode($string,'DECODE');
		if(strlen($decode_string) < 4)
		{
			$this->e('invalidate request_token length');
		}
		if('ID:' != substr($decode_string,0,3))
		{
			$this->e('invalidate request_token');
		}
		$id = substr($decode_string,3);
		if(is_numeric($id) && $id > 0)
		{
			return $id;
		}
		else
		{
			$this->e('request_token not a number');
		}
	}
	
	protected function findOneById($table,$id)
	{
		return $this->entityManager->getConnection()->executeQuery('select * from '.strtolower($table).' where id='.$id)->fetchAssociative();
	}
	
	//获取boolean
	protected function GetBool($is)
	{
		$b = 0;
		if(1 == $is || true == $is || 'true' == $is)
		{
			$b = 1;
		}
		if(0 == $is || false == $is || 'false' == $is)
		{
			$b = 0;
		}
		
		return $b;
	}

	//获取全部国家
	protected function getAllCounties()
	{
		$data = ['countries'=>[]];
		
		$countries = $this->db(\App\Entity\Country::class)->findAll();
		foreach($countries as $country)
		{
			$data['countries'][] = ['key'=>$country->getSlug(),'text'=>$country->getName()];
		}
		
		return $data;
	}
	
	//根据别名查找国家
	protected function getCountryBySlug($slug)
	{
		return $this->db(\App\Entity\Country::class)->findOneBy(['slug'=>$slug]);
	}
	
	//返回国家别名、名称
	protected function GetCountryMap()
	{
		$map = [];
		
		$countries = $this->db(\App\Entity\Country::class)->findAll();
		foreach($countries as $country)
		{
			$map[$country->getSlug()] = $country->getName();
		}
		
		return $map;
	}
	
	function post_json($url, $jsonStr,$_header=[],$method='POST')
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
		if(count($_header) > 0)
		{
			$header = array_merge($header,$_header);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($httpCode, $response);
	}
	
    function post_form($url, $data = null,$method='POST',$header=[]) 
	{
        $curl = curl_init ();
        curl_setopt ( $curl, CURLOPT_URL, $url );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $curl, CURLOPT_SSL_VERIFYHOST, FALSE );
        if (! empty ( $data )) 
		{
			$header[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
			curl_setopt ( $curl, CURLOPT_HTTPHEADER, $header);  
            curl_setopt ( $curl, CURLOPT_POST, 1 );
			curl_setopt ( $curl, CURLOPT_POSTFIELDS, http_build_query($data) );
        }
        curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
        $response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		return array($httpCode, $response);
    }
	
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
	
	public static function encrypt($message, $key, $encode = true)
    {
        $nonceSize = openssl_cipher_iv_length(self::METHOD);
        $nonce = openssl_random_pseudo_bytes($nonceSize);
        
        $ciphertext = openssl_encrypt(
            $message,
            self::METHOD,
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

        $nonceSize = openssl_cipher_iv_length(self::METHOD);
        $nonce = mb_substr($message, 0, $nonceSize, '8bit');
        $ciphertext = mb_substr($message, $nonceSize, null, '8bit');
        
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $nonce
        );
        
        return $plaintext;
    }
}
