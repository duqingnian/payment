<?php

namespace App\Channel\Wowpay;

class MethodItem extends Base
{	
	public function GetItems()
	{
		return [
			'virtual_account'=>[
				'text'=>'Bank Transfer',
				'items'=>[
					['key'=>'BNI','text'=>'BNI','logo'=>'/channels/wowpay/va_bni.png'],
					['key'=>'PERMATA','text'=>'Permata Bank','logo'=>'/channels/wowpay/va_permata.png'],
					['key'=>'BRI','text'=>'BRI','logo'=>'/channels/wowpay/va_bri.png'],
					['key'=>'MANDIRI','text'=>'MANDIRI','logo'=>'/channels/wowpay/MANDIRI.png'],
					['key'=>'DANAMON','text'=>'DANAMON','logo'=>'/channels/wowpay/DANAMON.png'],
					['key'=>'BSI','text'=>'BSI','logo'=>'/channels/wowpay/BSI.jpg'],
					
					//['key'=>'BCA','text'=>'BCA','logo'=>'/channels/wowpay/va_bca.png'],
					//['key'=>'CIMB_NIAGA','text'=>'CIMB','logo'=>'/channels/wowpay/va_cimb.png'],
					//['key'=>'SAHABAT_SAMPOERNA','text'=>'SAHABAT_SAMPOERNA','logo'=>'/channels/wowpay/SAHABAT_SAMPOERNA.png'],
					//['key'=>'BTPN','text'=>'BTPN','logo'=>'/channels/wowpay/BTPN.png'],
				],
			],
			'ewallet'=>[
				'text'=>'Wallet',
				'items'=>[
					['key'=>'DANA','text'=>'DANA','logo'=>'/channels/payhere/ew_dana.png'],
					['key'=>'LINKAJA','text'=>'LINKAJA','logo'=>'/channels/payhere/ew_linkaja.png'],
					['key'=>'SHOPEEPAY','text'=>'SHOPEEPAY','logo'=>'/channels/payhere/ew_shopeepay.png']
				],
			],
			'qris'=>[
				'text'=>'QRIS',
				'items'=>[
					['key'=>'QRIS','text'=>'QRIS','logo'=>'/channels/wowpay/qris.png']
				],
			],
		];
	}
	
}