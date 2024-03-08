<?php

namespace App\Country\Brazil;

class Column
{
	public function render()
	{
		return [
			[
				'type'=>'enum',
				'key'=>'bank_code',
				'text'=>'支付类型',
				'summary'=>'EMAIL,PHONE,CPF,CNPJ,RANDOM',
				'options'=>[
					['key'=>'EMAIL','text'=>'EMAIL','checked'=>0],
					['key'=>'PHONE','text'=>'PHONE','checked'=>0],
					['key'=>'CPF','text'=>'CPF','checked'=>0],
					['key'=>'CNPJ','text'=>'CNPJ','checked'=>0],
					['key'=>'RANDOM','text'=>'RANDOM','checked'=>0],
				],
			],
			['type'=>'input','key'=>'account_name','text'=>'姓名','summary'=>'真实姓名','default'=>''],
			['type'=>'input','key'=>'account_no','text'=>'账号','summary'=>'pix账号','default'=>''],
			['type'=>'input','key'=>'ext_no','text'=>'扩展号码','summary'=>'CPF号码','default'=>''],
		];
	}
}
