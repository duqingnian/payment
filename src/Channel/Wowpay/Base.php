<?php

namespace App\Channel\Wowpay;

class Base extends \App\Channel\BaseChannel
{
	protected $payin_appid = '';
	protected $payin_secret = '';
	
	protected $payout_appid = '';
	protected $payout_secret = '';
}
