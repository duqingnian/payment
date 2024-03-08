<?php

namespace App\Country\Nigeria;

class Column
{
	public function render()
	{
		return [
			['type'=>'input','key'=>'bank_code','text'=>'银行代码','is_require'=>1,'summary'=>'','default'=>''],
			['type'=>'input','key'=>'account_name','text'=>'姓名','is_require'=>1,'summary'=>'','default'=>''],
			['type'=>'input','key'=>'account_no','text'=>'卡号','is_require'=>1,'summary'=>'','default'=>''],
			['type'=>'input','key'=>'email','text'=>'邮箱','is_require'=>0,'summary'=>'','default'=>''],
		];
	}
}
