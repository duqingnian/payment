<?php

namespace App\Channel\Testco;

class MethodItem extends Base
{	
	public function GetItems()
	{
		return [
			'virtual_account'=>[
				'text'=>'Bank Transfer',
				'items'=>[
					['key'=>'PERMATA','text'=>'Permata Bank','logo'=>'/channels/payhere/va_permata.png'],
					['key'=>'BRI','text'=>'BRI','logo'=>'/channels/payhere/va_bri.png'],
					['key'=>'BCA','text'=>'BCA','logo'=>'/channels/payhere/va_bca.png'],
					['key'=>'CIMB','text'=>'CIMB','logo'=>'/channels/payhere/va_cimb.png'],
					['key'=>'BNI','text'=>'BNI','logo'=>'/channels/payhere/va_bni.png']
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
					['key'=>'QRIS','text'=>'QRIS','logo'=>'/channels/payhere/qris.png']
				],
			],
		];
	}
	
}