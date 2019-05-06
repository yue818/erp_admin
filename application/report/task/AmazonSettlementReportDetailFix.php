<?php

namespace app\report\task;

use app\common\model\amazon\AmazonSettlementReport;
use app\common\model\amazon\AmazonSettlementReportDetail;
use app\common\service\UniqueQueuer;
use app\index\service\AbsTasker;
use app\report\queue\AmazonSettlementReportDetailFixQueue;
use think\Exception;

/**
 * Class AmazonSettlementReportDetailFix
 * Created by linpeng
 * createTime: 2019/4/24 15:54
 * updateTime: 2019/4/24 15:54
 * @package app\report\task
 */
class AmazonSettlementReportDetailFix extends AbsTasker
{
    /**
     * 定义任务名称
     * @return string
     */
    public function getName()
    {
        return '亚马逊报告修复详情数据(临时)';
    }

    /**
     * 定义任务描述
     * @return string
     */
    public function getDesc()
    {
        return '亚马逊报告修复详情数据(临时)';
    }


    /**
     * 定义任务作者
     * @return string
     */
    public function getCreator()
    {
        return 'linpeng';
    }

    /**
     * 定义任务参数规则
     * @return array
     */
    public function getParamRule()
    {

        return ['type|处理类型:'       => 'require|select:更新主表:1,更新详细:2'];
    }

    /**
     * 执行方法
     */
    public function execute()
    {
        try{
            $type      = 2;
            switch ($type){
                case 1:
                    break;
                case 2:
                    $this->fixDetail();
                    break;

            }
        }catch (Exception $ex){
            throw new Exception($ex->getMessage() . $ex->getFile() . $ex->getLine());
        }

    }

    public function fixReport()
    {
        // $model = new
    }

    public function fixDetail()
    {
        try{
            $reportModel = new AmazonSettlementReport();
            $detail = new AmazonSettlementReportDetail();
            $res = $detail->field('amazon_settlement_report_id')
                ->where('posted_date', '=', 0)
                ->page(1, 2000)->group('amazon_settlement_report_id')
                ->select();
            foreach ($res as $val)
            {
                (new UniqueQueuer(AmazonSettlementReportDetailFixQueue::class))->push($val['amazon_settlement_report_id']);
            }
        }catch (Exception $ex)
        {
            throw new Exception($ex->getMessage() . $ex->getFile() . $ex->getLine());
        }

    }
}