<?php
namespace app\customerservice\task;

use app\index\service\AbsTasker;
use app\common\service\UniqueQueuer;
use app\customerservice\queue\TemporaryQueue;
use Exception;

class TemporaryTask extends AbsTasker
{
    /**
     * 定义任务名称
     * @return string
     */
    public function getName()
    {
        return '更新ebay_email receiver_id(临时)';
    }

    /**
     * 定义任务描述
     * @return string
     */
    public function getDesc()
    {
        return '更新ebay_email receiver_id(临时)';
    }

    /**
     * 定义任务作者
     * @return string
     */
    public function getCreator()
    {
        return 'denghaibo';
    }

    /**
     * 定义任务参数规则
     * @return array
     */
    public function getParamRule()
    {
        return [];
    }

    /**
     * @throws TaskException
     */
    public  function execute()
    {
        try{
            $queue = new UniqueQueuer(TemporaryQueue::class);
            $queue->push(['id'=> 1]);
        } catch (Exception $e){
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

}

