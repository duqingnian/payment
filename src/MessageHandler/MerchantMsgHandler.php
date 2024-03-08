<?php
namespace App\MessageHandler;

use App\Message\MerchantMsg;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

#[AsMessageHandler]
class MerchantMsgHandler implements MessageHandlerInterface
{
	public EntityManagerInterface $entityManager;
	
	public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
	
    public function __invoke(MerchantMsg $message)
    {
		$content = $message->getContent();
		$data = json_decode($content, true);
		
		if('MULTI_PAYOUT_CREATED' == $data['action']) //批量代付
		{
			$this->__MULTI_PAYOUT_CREATED($content);
		}
		else{}
		echo 'MULTI_PAYOUT_CREATED';
    }
	
	private function __MULTI_PAYOUT_CREATED($content)
	{
		$util_payout = new \App\Utils\UtilPayout();
		echo $util_payout->dispatch($this->entityManager,$content);
	}
	
	
}

