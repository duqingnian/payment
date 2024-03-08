<?php

namespace App\Channel\Kirinpayment;

class Base extends \App\Channel\BaseChannel
{
	protected $payin_appid = 'stage';
	protected $payin_secret = 'STAGE_API_KEY';
	
	protected $payout_appid = 'stage';
	protected $payout_secret = 'STAGE_API_KEY';
}
