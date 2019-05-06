<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 18-5-28
 * Time: 下午5:56
 */

namespace app\publish\queue;

use app\common\exception\QueueException;
use app\publish\helper\lazada\LazadaHelper;
use think\Exception;

class LazadaPublishQueue //extends SwooleQueueJob
{
    const PRIORITY_HEIGHT = 10;

    public static function swooleTaskMaxNumber():int
    {
        return 5;
    }

    public function getName(): string
    {
        return 'lazada刊登队列';
    }

    public function getDesc(): string
    {
        return 'lazada刊登队列';
    }

    public function getAuthor(): string
    {
        return 'thomas';
    }

    public function execute()
    {
        set_time_limit(0);
        try{
//            $id = $this->params;
            $id = 2898;
            $res = (new LazadaHelper())->create($id);
            if ($res !== true) {
                throw new Exception($res);
            }
        }catch (Exception $exp) {
            throw new QueueException($exp->getMessage());
        }
    }
}