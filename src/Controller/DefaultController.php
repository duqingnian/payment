<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends BaseController
{
    #[Route('/', name: 'app_default')]
    public function index(): Response
    {
		return new Response('payment index');
    }
	
	#[Route('/pay_result', name: 'pay_result')]
    public function pay_result(): Response
    {
		return new Response('操作完成');
    }
}
