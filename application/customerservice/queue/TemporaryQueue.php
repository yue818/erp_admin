<?php
namespace  app\customerservice\queue;

use app\common\service\SwooleQueueJob;
use app\customerservice\service\EbayEmail;
use Exception;

class TemporaryQueue extends SwooleQueueJob
{
  
    public function getName(): string
    {
        return "更新ebay_email sender_id(临时)";
    }

    public function getDesc(): string
    {
        return "更新ebay_email sender_id(临时)";
    }

    public function getAuthor(): string
    {
        return "denghaibo";
    }

    public static function swooleTaskMaxNumber():int
    {
        return 1;
    }


    public function execute()
    {

        try{
            set_time_limit(0);

            $id = $this->params['id'];

            $ebayEmail = new EbayEmail();
            $ebayEmail->set_ebay_email_receiver_id();

        } catch (Exception $e){
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }
}