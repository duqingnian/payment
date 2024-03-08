<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

class DebugController extends BaseController
{
	const METHOD = 'aes-256-ctr';
	
	#[Route('/debug.payout', name: 'debug_payout')]
    public function payout(Request $request): JsonResponse
    {
		die('aaaaaaaa');
		/*for($i=0;$i<15;$i++)
		{
			$order_no = time().rand(1111,9999).rand(1111,9999);
			$api = 'https://pay.baishipay.com/api/payout/order/create';
			$s = $request->query->get('s',1);
			$data = [
				'appid'=>'b59b9b9c75a6423f89236d1a876e7df7',
				'notify_url'=>'https://test-channel.testpay.xyz/payout_notify.php',
				'sign'=>'aZ1lpo1d8A0A9cSVWfmkn3vxRjI=',
				'amount'=>'100',
				'order_no'=>$order_no,
				'account_no'=>'622848'.rand(12345,98987),
				'account_name'=>'比齐'.$i,
				'timestamp'=>time(),
				'bank_code'=>'CPF',
				'ext_no'=>'20'.$i,
				'version'=>'2.0',
			];
			
			$ret = $this->post_json($api,$data);
			
			$nd = '{"orderStatus":3,"orderNo":"SCN_'.rand(1111,9999).rand(1111,9999).'","merOrderNo":"'.$order_no.'","amount":"100","currency":"BRL","createTime":1704063866,"updateTime":1704063866,"sign":"c81e728d9d4c2f636f067f89cc14862c"}';
			$a = $this->post_json("https://pay.baishipay.com/api/notify/O/betcatpay",$nd);
			
			echo 'i='.$i;
			print_r($ret);
			print_r($a);
			echo "=======================<br />";
			
		}
		
		die();*/
	}

	
	#[Route('/openssl2', name: 'openssl2')]
    public function openssl2(Request $request): JsonResponse
	{	
	die('==================================');
		$string = 'qwertyuiopasdfghjklzxcvbnm1234567890';
		$str = $this->authcode($string,'ENCODE');
		echo '加密后:'.$str;
		echo "<br />";
		echo '解密后:'.$this->authcode($str,'DECODE');
		die();
	}
	
	
	#[Route('/openssl', name: 'openssl')]
    public function openssl(Request $request): JsonResponse
	{
		die('==================================');
		$message = '123456';
		$key = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
		
		$encrypted = self::encrypt($message, $key);
		$decrypted = self::decrypt($encrypted, $key);

		echo '加密后:'.$encrypted."<br />";
		echo '原文后:'.$decrypted."<br />";
		die();
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
        
        // Now let's pack the IV and the ciphertext together
        // Naively, we can just concatenate
        if ($encode) {
            return base64_encode($nonce.$ciphertext);
        }
        return $nonce.$ciphertext;
    }
    
    /**
     * Decrypts (but does not verify) a message
     * 
     * @param string $message - ciphertext message
     * @param string $key - encryption key (raw binary expected)
     * @param boolean $encoded - are we expecting an encoded string?
     * @return string
     */
    public static function decrypt($message, $key, $encoded = true)
    {
        if ($encoded) {
            $message = base64_decode($message, true);
            if ($message === false) {
                throw new Exception('Encryption failure');
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
	
	#[Route('/debug.payin', name: 'debug_payin')]
    public function payin(Request $request): JsonResponse
    {
		die('bbbbbbbbbbbbbbbbbbb');
		//$api = 'http://pay.heypay.org/api/payment/order/create';
		$api = 'https://payment.wolong.in/api/payment/order/create';
		
		$data = [
			'appid'=>'ADC39055BB1172',
			'notify_url'=>'https://test-channel.testpay.xyz/notify.php',
			'sign'=>'aZ1lpo1d8A0A9cSVWfmkn3vxRjI=',
			'amount'=>'100',
			'order_no'=>time().rand(1111,9999).rand(1111,9999),
			'note'=>$this->getChar(1),
			'jump_url'=>'https://test-channel.testpay.xyz/go.php?name='.$this->getChar(1).'&time='.time(),
		];
		
		$ret = $this->post_form($api,$data);
		
		if(1)
		{
			if(200 == $ret[0])
			{
				$data = json_decode($ret[1],true);
				
				$plantform_notify_url = $data['plantform_notify_url'];
				$plantform_order_no = $data['plantform_order_no'];
				$amount = $data['amount'];
				//echo $plantform_notify_url;die();
				$notify_data = [
					'channel_order_no'=>'TC'.time().rand(1111,9999).'K',
					'plantform_order_no'=>$plantform_order_no,
					'order_status'=>'SUCCESS',
					//'RealAmount'=>$amount/2,
				];
				$nret = $this->post_form($plantform_notify_url,$notify_data);
				echo 'notify url:'.$plantform_notify_url."<br />";
				echo 'httocode:'.$nret[0]."<br />";
				echo 'nret:'.$nret[1]."<br />";
				die();
			}
			else
			{
				echo 'HTTP NOT 200:'.$ret[0];print_r($ret);
			}
		}
		die();
	}
	
	function getChar($num)
	{
		$b = '';
		for ($i=0; $i<$num; $i++) 
		{
			$a = chr(mt_rand(0xB0,0xD0)).chr(mt_rand(0xA1, 0xF0));
			$b .= iconv('GB2312', 'UTF-8', $a);
		}
		return $b;
	}
}
