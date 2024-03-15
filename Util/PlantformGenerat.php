<?php

namespace App\Util;

class PlantformGenerat
{
    //Generate plantform order number
    public function GetPno($meta)
    {
        $plantform_order_no = '';

        $prefix = $meta['prefix'];
		$po_end = $meta['end'];
		
		$randomLength = 4;
		list($usec, $sec) = explode(" ", microtime());
		$millisecond = (int)($usec * 1000000);

		$randomPart = '';
		for ($i = 0; $i < $randomLength; $i++) 
        {
			$randomPart .= mt_rand(0, 9);
		}

		$plantform_order_no = $prefix . $meta['merchant']->getId() . $sec . $millisecond . $randomPart . $po_end;
		if($meta['merchant']->isIsTest())
		{
			$plantform_order_no = 'TEST'.$plantform_order_no;
		}

        return $plantform_order_no;
    }
}

