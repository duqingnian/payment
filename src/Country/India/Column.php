<?php

namespace App\Country\India;

class Column
{
	public function render()
	{
		return [
			[
				'type'=>'enum',
				'key'=>'bank_code',
				'text'=>'支付类型',
				'summary'=>'IMPS,NEFT,RTGS,UPI,PAYTM',
				'options'=>[
					['key'=>'IMPS','text'=>'IMPS','checked'=>0],
					['key'=>'NEFT','text'=>'NEFT','checked'=>0],
					['key'=>'RTGS','text'=>'RTGS','checked'=>0],
					['key'=>'UPI','text'=>'UPI','checked'=>0],
					['key'=>'PAYTM','text'=>'PAYTM','checked'=>0],
				],
			],
			['type'=>'input','key'=>'account_name','text'=>'姓名','is_require'=>1,'summary'=>'','default'=>''],
			['type'=>'input','key'=>'account_no','text'=>'账号','is_require'=>1,'summary'=>'卡号','default'=>''],
			['type'=>'input','key'=>'ext_no','text'=>'扩展号码','is_require'=>1,'summary'=>'ifsc号码或者vpa号码等扩展号码','default'=>''],
			['type'=>'input','key'=>'phone','text'=>'手机号码','is_require'=>0,'summary'=>'','default'=>''],
			['type'=>'input','key'=>'email','text'=>'邮箱','is_require'=>0,'summary'=>'','default'=>''],
		];
	}
}
