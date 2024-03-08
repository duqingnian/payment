<?php

namespace App\Channel\Betcatpay;

class Base extends \App\Channel\BaseChannel
{
	protected $payin_appid = 'b59eee8d8721dc00bb44199dd511363f';
	protected $payin_secret = '7b6656276d4dd8fbbdbc8991304056e7';
	
	protected $payout_appid = 'fa7778c5259e7bee6247caf69d5895ce';
	protected $payout_secret = 'aac91503f27e8480d929778ec2550118';
}
