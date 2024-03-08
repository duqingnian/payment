<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class TestController extends BaseController
{
    #[Route('/test.payout.api', name: 'test_payout_api')]
    public function test_payout_api(): Response
    {
		echo 'post_data:';
		echo file_get_contents('php://input');
		die();
    }
}
