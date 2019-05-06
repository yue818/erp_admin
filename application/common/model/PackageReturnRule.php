<?php

namespace app\common\model;

use erp\ErpModel;
use think\Model;

class PackageReturnRule extends ErpModel
{
    //状态 1启用 0禁用
    const STATUS_ENABLE = 1;
    const STATUS_DIAABLE = 0;
    //固定对应的id
    //退货距发货超过三个月
    const TYPE_OVEN_3_MON = 10;
    //速卖通线上渠道退回
    const TYPE_OnlineAliExpress = 11;
    //调拨单退回
    const TYPE_ALLOCATION = 13;

    // 是否在前端查询显示，0隐藏，1显示
    const VISIBLE = 1;
    const HIDDNE = 0;
    //
    protected function initialize()
    {
        parent::initialize();
    }
}
