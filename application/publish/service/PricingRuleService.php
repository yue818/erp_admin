<?php
namespace app\publish\service;

use app\common\exception\JsonErrorException;
use app\common\model\PriceRuleSet;
use app\common\model\PriceRuleSetItem;
use app\common\service\ChannelAccountConst;
use app\index\service\EbayAccountService;
use app\index\service\WishShippingRateService;
use app\order\service\OrderRuleExecuteService;
use app\purchase\service\PurchaseOrder;
use app\warehouse\service\ShippingMethod;
use think\Db;
use app\common\cache\Cache;
use think\Exception;
use think\Request;
use app\common\model\GoodsDiscount as GoodsDiscountModel;

/**
 * Created by PhpStorm.
 * User: XPDN
 * Date: 2017/7/18
 * Time: 10:43
 */
class PricingRuleService
{
    protected $priceRuleSetModel;
    protected $priceRuleSetItemModel;

    public function __construct()
    {
        if (is_null($this->priceRuleSetModel)) {
            $this->priceRuleSetModel = new PriceRuleSet();
        }
        if (is_null($this->priceRuleSetItemModel)) {
            $this->priceRuleSetItemModel = new PriceRuleSetItem();
        }
    }

    /** 规则列表
     * @param array $where
     * @param $page
     * @param $pageSize
     * @return array
     * @throws Exception
     */
    public function ruleList(array $where, $page, $pageSize)
    {
        try {
            $orderRuleSetList = $this->priceRuleSetModel->field('id,sort,title,operator,operator_id,channel_id,site,status,create_time,update_time,start_time,end_time')->where($where)->page($page,
                $pageSize)->order('sort asc,id asc')->select();
            $count = $this->priceRuleSetModel->where($where)->count();
            $new_array = [];
            foreach ($orderRuleSetList as $k => $v) {
                $temp = $v;
                $temp['channel'] = !empty($v['channel_id']) ? Cache::store('channel')->getChannelName($v['channel_id']) : '所有';
                unset($temp['channel_id']);
                array_push($new_array, $temp);
            }
            $result = [
                'data' => $new_array,
                'page' => $page,
                'pageSize' => $pageSize,
                'count' => $count,
            ];
            return $result;
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /** 获取可选条件
     * @return mixed
     */
    public function item()
    {
        $data['label'] = '销售价规则';
        $item = [
            0 => [
                'name' => '刊登来源为',
                'statement' => '指定/平台/站点/账号',
                'code' => 'source',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            1 => [
                'name' => '发货仓库为',
                'statement' => '指定仓库',
                'code' => 'warehouse',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            2 => [
                'name' => '产品SKU需包含',
                'statement' => '指定货品SKU（搜索并指定产品SKU）',
                'code' => 'goods',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            3 => [
                'name' => '刊登到AliExpress的一下平台分类',
                'statement' => '请选择AliExpress的平台分类',
                'code' => 'smtCategory',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_aliExpress,
            ],
            4 => [
                'name' => '物流属性为',
                'statement' => '指定物流属性（勾选多个则是“或”的关系）',
                'code' => 'containsLogisticsAttributesPrice',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_amazon
            ],
            5 => [
                'name' => '毛重重量范围(g)',
                'statement' => '指定范围(g)',
                'code' => 'goodsWeight',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_amazon
            ],

        ];
        $data['child'] = $item;
        return $data;
    }

    /** 获取默认设置项
     * @return array
     */
    public function defaultRule()
    {
        $data['label'] = '销售价计算参数设置';
        $item = [
             [
                'name' => '币种与汇率为',
                'statement' => '币种与汇率为',
                'code' => 'pricingConvertedRate',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
             ],
            [
                'name' => 'wish物流运输费',
                'statement' => 'wish物流运输费',
                'code' => 'wishShippingRate',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_wish
            ],
             [
                'name' => '物流运输费',
                'statement' => '物流运输费',
                'code' => 'pricingTransportationCosts',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_aliExpress,
            ],
            [
                'name' => '平邮运输方式选择',
                'statement' => '平邮运输方式选择',
                'code' => 'pricingShippingCountry',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => '平邮运输方式选择',
                'statement' => '平邮运输方式选择',
                'code' => 'pricingShippingCountry',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_amazon
            ],
            [
                'name' => 'EUB运输方式选择（US站点专用）',
                'statement' => 'EUB运输方式选择（US站点专用）',
                'code' => 'pricingShippingEUB',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
             [
                'name' => '平台佣金率为(币种为站点币种)',
                'statement' => '平台佣金率为(币种为站点币种)',
                'code' => 'pricingPlatformCommission',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
             [
                'name' => '销售毛利润率为',
                'statement' => '销售毛利润率为',
                'code' => 'pricingGrossProfit',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            [
                'name' => '促销返点',
                'statement' => '促销返点',
                'code' => 'promotionRebate',
                'type' => 3,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_amazon
            ],
            [
                'name' => '退款',
                'statement' => '退款',
                'code' => 'refund',
                'type' => 3,
                'rule_type' => 0,
                'classified' => 5,
                'channel' => ChannelAccountConst::channel_amazon
            ],
             [
                'name' => '促销折扣率',
                'statement' => '促销折扣率',
                'code' => 'pricingDiscountRate',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_aliExpress
            ],
             [
                'name' => 'Paypal-大额账号交易费（币种为站点币种）',
                'statement' => 'Paypal-大额账号交易费（币种为站点币种）',
                'code' => 'pricingLargeAccountTransactionFee',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
             [
                'name' => 'Paypal-小额账号交易费（币种为站点币种）',
                'statement' => 'Paypal-小额账号交易费（币种为站点币种）',
                'code' => 'pricingSmallAccountTransactionFee',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => '货币转换费率（英国/澳洲/加拿大/德国专用）',
                'statement' => '货币转换费率（英国/澳洲/加拿大/德国专用）',
                'code' => 'currencyConversionRate',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            [
                'name' => '其他成本为',
                'statement' => '其他成本为',
                'code' => 'pricingOtherCosts',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            [
                'name' => '发挂号时的物流运输费',
                'statement' => '发挂号时的物流运输费',
                'code' => 'pricingTransportationCostsTime',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_aliExpress
            ],
            [
                'name' => '最终平邮售价取值（US站专用，币种为站点币种）',
                'statement' => '最终平邮售价取值（US站专用，币种为站点币种）',
                'code' => 'pricingPriceOfSurfaceMail',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => 'EUB运费价格(US站点专用，币种为站点币种)',
                'statement' => 'EUB运费价格(US站点专用，币种为站点币种)',
                'code' => 'pricingShippingPriceEUB',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => '最终平邮售价取值（非US站点专用，币种为站点币种）',
                'statement' => '最终平邮售价取值（非US站点专用，币种为站点币种）',
                'code' => 'pricingPriceMail',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => '国际运费价格(非US站点专用)',
                'statement' => '国际运费价格(非US站点专用)',
                'code' => 'pricingPriceInternationalShipping',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_ebay
            ],
            [
                'name' => '发挂号时的物流运输费（币种为站点币种）',
                'statement' => '发挂号时的物流运输费（币种为站点币种）',
                'code' => 'wishShippingFee',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_wish
            ],
            [
                'name' => '最终平邮售价金额随机（币种为站点币种）',
                'statement' => '最终平邮售价金额随机（币种为站点币种）',
                'code' => 'pricingPriceRand',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            [
                'name' => '售价金额取整',
                'statement' => '售价金额取整',
                'code' => 'pricingSellingPriceFixed',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
            [
                'name' => '销售价与运费的拆分比例设置（计算结果四舍五入，保留小数点后2位数。单位：站点币种）',
                'statement' => '销售价与运费的拆分比例设置（计算结果四舍五入，保留小数点后2位数。单位：站点币种）',
                'code' => 'pricingSplitScaleSettings',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_wish,
            ],
            [
                'name' => '吊牌价设置（四舍五入，取整数。此处“总销售价”已减去“允许降价金额”。单位：站点币种）',
                'statement' => '吊牌价设置（四舍五入，取整数。此处“总销售价”已减去“允许降价金额”。单位：站点币种）',
                'code' => 'pricingTagPriceSetting',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => ChannelAccountConst::channel_wish
            ],
             [
                'name' => '最终平邮售价金额范围（币种为站点币种）',
                'statement' => '最终平邮售价金额范围（币种为站点币种）',
                'code' => 'pricingPriceRange',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ],
             [
                'name' => '允许销售员降价幅度（计算结果四舍五入，保留小数点后2位数。单位：站点币种）',
                'statement' => '允许销售员降价幅度（计算结果四舍五入，保留小数点后2位数。单位：站点币种）',
                'code' => 'pricingCutPercentage',
                'type' => 0,
                'rule_type' => 0,
                'classified' => 0,
                'channel' => 0
            ]
        ];
        $data['child'] = $item;
        return $data;
    }

    /** 信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function info($id)
    {
        $where['id'] = ['=', $id];
        $ruleSetList = $this->priceRuleSetModel->field('id,title,status,channel_id,start_time,end_time,action_value')->where($where)->find();
        if (empty($ruleSetList)) {
            return '该记录不存在';
        }
        $result = $ruleSetList;
        $rules = [];
        $action_value = [];
        $ruleSetItemList = $this->priceRuleSetItemModel->field(true)->where(['rule_id' => $id])->select();
        foreach ($ruleSetItemList as $k => $v) {
            $temp['item_code'] = $v['rule_item_code'];
            $item_value = json_decode($v['param_value'], true);
            $temp['choose'] = $item_value;
            array_push($rules, $temp);
        }
        $rulesList = json_decode($ruleSetList['action_value'], true);
        foreach ($rulesList as $k => $v) {
            $temp['item_code'] = $v['item_source'];
            $temp['choose'] = $v['item_value'];
            array_push($action_value, $temp);
        }
        $result['action_value'] = $action_value;
        $result['rules'] = $rules;
        return $result;
    }

    /** 新增
     * @param $ruleSet
     * @param $rules
     * @return array|bool
     */
    public function add($ruleSet, $rules)
    {
        $rules = json_decode($rules, true);
        if (empty($rules)) {
            throw new JsonErrorException('请选择一条规则条件');
        }
        //启动事务
        Db::startTrans();
        try {
            $this->priceRuleSetModel->allowField(true)->isUpdate(false)->save($ruleSet);
            $rule_id = $this->priceRuleSetModel->id;
            $ruleSetItem = $this->setItem($rules, $rule_id);
            $this->priceRuleSetItemModel->allowField(true)->isUpdate(false)->saveAll($ruleSetItem);
            Db::commit();
            Cache::store('pricingRule')->delPricingRuleInfo();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 更新
     * @param $id
     * @param $ruleSet
     * @param $rules
     * @return array|bool
     */
    public function update($id, $ruleSet, $rules)
    {
        $rules = json_decode($rules, true);
        if (empty($rules)) {
            throw new JsonErrorException('请选择一条规则条件');
        }
        //查出规则信息
        $where['id'] = ['<>', $id];
        $where['title'] = ['eq', $ruleSet['title']];
        $ruleSetInfo = $this->priceRuleSetModel->where($where)->find();
        if (!empty($ruleSetInfo)) {
            throw new JsonErrorException('规则名称已存在');
        }
        //启动事务
        Db::startTrans();
        try {
            $this->priceRuleSetModel->where(["id" => $id])->update($ruleSet);
            //删除原来的规则设置条件
            $this->priceRuleSetItemModel->where(['rule_id' => $id])->delete();
            $ruleSetItem = $this->setItem($rules, $id);
            $this->priceRuleSetItemModel->allowField(true)->isUpdate(false)->saveAll($ruleSetItem);
            Db::commit();
            Cache::store('pricingRule')->delPricingRuleInfo();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 设置规则item
     * @param $rules
     * @param $id
     * @return array
     */
    private function setItem($rules, $id)
    {
        $ruleSetItem = [];
        foreach ($rules as $k => $v) {
            $ruleSetItem[$k]['rule_id'] = $id;
            $ruleSetItem[$k]['create_time'] = time();
            $ruleSetItem[$k]['update_time'] = time();
            $ruleSetItem[$k]['rule_item_code'] = $v['item_source'];
            $ruleSetItem[$k]['param_value'] = json_encode($v['item_value']);
        }
        return $ruleSetItem;
    }

    /** 规则排序
     * @param $sort
     * @return array
     */
    public function sort($sort)
    {
        foreach ($sort as $k => $v) {
            if (!$this->priceRuleSetModel->isHas($v['id'])) {
                throw new JsonErrorException('该规则不存在');
            }
        }
        try {
            $this->priceRuleSetModel->isUpdate(true)->saveAll($sort);
            Cache::store('pricingRule')->delPricingRuleInfo();
            return true;
        } catch (\Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 删除
     * @param $id
     * @return array
     */
    public function delete($id)
    {
        if (!$this->priceRuleSetModel->isHas($id)) {
            throw new JsonErrorException('该规则不存在！');
        }
        //查看规则是否在启用中
        $info = $this->priceRuleSetModel->where(['id' => $id])->find();
        if ($info['status'] == 0) {
            throw new JsonErrorException('请先停用该规则！', 500);
        }
        //启动事务
        Db::startTrans();
        try {
            $this->priceRuleSetItemModel->where(['rule_id' => $id])->delete();
            $this->priceRuleSetModel->where(['id' => $id])->delete();
            Db::commit();
            Cache::store('pricingRule')->delPricingRuleInfo();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 复制
     * @param $params
     * @param $ruleSetInfoNew
     * @return \think\response\Json
     */
    public function copy($params, $ruleSetInfoNew)
    {
        //查出规则信息
        $ruleSetInfo = $this->priceRuleSetModel->where(['id' => $params['id']])->find();
        if (empty($ruleSetInfo)) {
            throw new JsonErrorException('规则不存在');
        }
        $ruleSetInfoNew['title'] = $params['title'];
        $ruleSetInfoNew['create_time'] = time();
        $ruleSetInfoNew['update_time'] = time();
        $ruleSetInfoNew['channel_id'] = $ruleSetInfo['channel_id'];
        $ruleSetInfoNew['sort'] = $ruleSetInfo['sort'];
        $ruleSetInfoNew['end_time'] = $ruleSetInfo['end_time'];
        $ruleSetInfoNew['start_time'] = $ruleSetInfo['start_time'];
        $ruleSetInfoNew['status'] = 1;
        $ruleSetInfoNew['action_value'] = $ruleSetInfo['action_value'];
        $validateRuleSet = validate('PriceRuleSet');
        if (!$validateRuleSet->check($ruleSetInfoNew)) {
            throw new JsonErrorException($validateRuleSet->getError(), 500);
        }
        //启动事务
        Db::startTrans();
        try {
            $this->priceRuleSetModel->allowField(true)->isUpdate(false)->save($ruleSetInfoNew);
            $rule_id = $this->priceRuleSetModel->id;
            //查规则条件
            $ruleSetItemList = $this->priceRuleSetItemModel->where(['rule_id' => $params['id']])->select();
            $new_array = [];
            foreach ($ruleSetItemList as $k => $v) {
                $temp = [];
                $temp['rule_item_code'] = $v['rule_item_code'];
                $temp['param_value'] = $v['param_value'];
                $temp['is_exclusive'] = $v['is_exclusive'];
                $temp['rule_id'] = intval($rule_id);
                $temp['create_time'] = time();
                $temp['update_time'] = time();
                array_push($new_array, $temp);
            }
            $this->priceRuleSetItemModel->allowField(true)->isUpdate(false)->saveAll($new_array);
            Db::commit();
            Cache::store('pricingRule')->delPricingRuleInfo();
            return $rule_id;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 更改规则状态
     * @param $data
     * @return array
     */
    public function status($data)
    {
        if (!$this->priceRuleSetModel->isHas($data['id'])) {
            throw new JsonErrorException('该规则不存在');
        }
        $id = $data['id'];
        unset($data['id']);
        $result = $this->priceRuleSetModel->where(['id' => $id])->update($data);
        if ($result) {
            Cache::store('pricingRule')->delPricingRuleInfo();
            return true;
        } else {
            throw new JsonErrorException('操作失败', 500);
        }
    }

    /** 接口计算销售价
     * @param $publishInfo
     * @param $detail
     * @return array
     */
    public function calculate($publishInfo, array $detail)
    {
        $priceRule = [];
        foreach ($detail as $key => $value) {
            $detailInfo = [];
            $detailInfo['weight'] = 0;
            $detailInfo['goods_id'] = 0;
            $skuInfo = Cache::store('goods')->getSkuInfo($value);

            if (empty($skuInfo)) {
                throw new JsonErrorException('系统不存在skuID[' . $value . ']的产品信息');
            }
            $detailInfo['weight'] = $skuInfo['weight'];
            $detailInfo['goods_id'] = $skuInfo['goods_id'];
            $detailInfo['sku_id'] = $value;
            $detailInfo['sku'] = $skuInfo['sku'];
            $detailInfo['cost_price'] = $skuInfo['cost_price'];
            $priceRule[$value] = $this->matchRule($publishInfo, $detailInfo);
        }
        $priceRule = $this->againValue($priceRule,$publishInfo['channel_id']);
        return $priceRule;
    }

    /**
     * 再次优化价格
     * @param $priceRule
     * @param $channelId
     * @return mixed
     */
    public function againValue($priceRule,$channelId)
    {
        switch ($channelId){
            case ChannelAccountConst::channel_wish:
                $priceRule = $this->againValues($priceRule);
                break;
            case ChannelAccountConst::channel_ebay:
                $priceRule = $this->againValues($priceRule,'total_price','shipping_fee_EUB');
                break;
        }
        return $priceRule;
    }

    /**
     * 计算运费是否一致，不一致时，调整为最小值
     * @param $priceRule
     * @param string $prices
     * @param string $shippingFee
     * @return mixed
     */
    private function againValues($priceRule,$prices = 'sale_price',$shippingFeeStr = 'shipping_fee')
    {
        if(count($priceRule) > 1){
            foreach ($priceRule  as $v) {
                if (!isset($v[$prices])) {
                    return $priceRule;
                }else{
                    break;
                }
            }
            //1.判断是否相等，并找出最小值

            $shippingFee = array_column($priceRule,$shippingFeeStr);
            $min =  $shippingFee[0];
            $isRun = false;
            foreach ($shippingFee  as $v){
                if($min != $v){
                    $isRun = true;
                }
                if($min > $v){
                    $min = $v;
                }
            }
            //2.如果不相等，则设置为最小值
            if($isRun){
                foreach ($priceRule  as &$v){
                    $diff = $v[$shippingFeeStr] - $min;
                    $v[$shippingFeeStr] = $min;
                    $v[$prices] += $diff;
                    $v[$prices] = sprintf("%.2f",$v[$prices]);
                }
            }
        }
        return $priceRule;
    }

    /** 匹配规则
     * @param $publishInfo
     * @param $detail
     * @return array
     * @throws \think\Exception
     */
    public function matchRule($publishInfo, $detail)
    {
        try {
            $log = [];
            $orderRuleExecuteService = new OrderRuleExecuteService();
            $ruleSetList = Cache::store('pricingRule')->pricingRuleInfo($publishInfo['channel_id']);

            $is_ok = false;
            $priceRule = [];
            $other = [];
            foreach ($ruleSetList as $k => $v) {
                //判断规则是否在有效期内
                $time = time();
                if ($time < $v['start_time'] || $time > $v['end_time']) {
                    continue;
                }
                //提前准备一些必要的数据 other
                foreach ($v['item'] as $kk => $vv) {
                    $item_value = is_string($vv['param_value']) ? json_decode($vv['param_value'], true) : [];
                    $item_value = is_array($item_value) ? $item_value : [];
                    if($vv['rule_item_code'] == 'warehouse' && $item_value){
                        $other['warehouses'] = [];
                        foreach ($item_value as $ks => $vs){
                            $other['warehouses'][] = $vs['key'];
                        }
                    }
                }
                foreach ($v['item'] as $kk => $vv) {
                    $item_value = is_string($vv['param_value']) ? json_decode($vv['param_value'], true) : [];
                    $item_value = is_array($item_value) ? $item_value : [];
                    if($vv['rule_item_code'] == 'containsLogisticsAttributes'){
                        continue;
                    }
                    $is_ok = $orderRuleExecuteService->check($vv['rule_item_code'], $item_value, $publishInfo, $detail,
                        $is_ok);

                    if (!$is_ok) {
                        $this->log($v['title'], $vv['rule_item_code'], $publishInfo, $detail);
                        break;
                    }
                }
                if ($is_ok) {
                    $action_value = json_decode($v['action_value'], true);
                    $hasGet = $this->matchDefaultRule($publishInfo, $priceRule, $action_value, [$detail], $v,
                        $orderRuleExecuteService,$other);
                    if($hasGet){
                        $newLog = [
                            'message' => '刊登记录账号[' . $publishInfo['channel_account_id'] . '],成功刊登定价规则[' . $v['title'] . ']',
                            'operator' => '系统自动',
                            'process_id' => 0
                        ];
                        array_push($log, $newLog);
                        break;
                    }
                }
            }
            if (!$is_ok) {
                $priceRule['error'] = '无法匹配规则';
            }
            return $priceRule;
        } catch (Exception $e) {
            $this->error($e->getMessage() . $e->getFile() . $e->getLine());
            return ['error' => $e->getMessage() . $e->getFile() . $e->getLine()];
        }
    }

    /** 求出价格
     * @param $publishInfo
     * @param $priceRule
     * @param $action_value
     * @param $detail
     * @param $rule
     * @param $orderRuleExecuteService
     * @throws Exception
     */
    private function matchDefaultRule(
        $publishInfo,
        &$priceRule,
        $action_value,
        $detail,
        $rule,
        OrderRuleExecuteService $orderRuleExecuteService,
        $other = ''
    ) {
        $title = $rule['title'];
        try {
            $purchaseService = new PurchaseOrder();
            $siteCurrency = 'USD';
            $payPalInfo = [];
            if ($publishInfo['channel_id'] == ChannelAccountConst::channel_ebay) {
                //获取大小账号
                $ebayService = new EbayAccountService();
                $siteCurrency = $ebayService->getSiteCurrency($publishInfo['site_code']);
                $payPalInfo = $ebayService->getEbayMapPaypalAccout($publishInfo['channel_account_id']);
            }
            //获取最新采购价
//            $skuPrice = $purchaseService->getSkuLastPurchaseInfo($detail[0]['sku_id']);
//            $purchasePrice = isset($skuPrice['price']) ? $skuPrice['price'] : $detail[0]['cost_price'];
            //先查看是否有符合的跌价
            //跌价后成本价=原成本价-跌价金额
            $purchasePrice = $this->discountSkuInfo($detail);
            $currency_code = 'CNY';   //使用的币种
            $firstWeightFee = 0;  //首重费用
            $continuedWeightFee = 0;  //续重费用
            $firstWeight = 0;  //首重
            $totalPrice = 0;  //总销售价
            $totalPriceEUB = 0;  //最终EUB售价 = min(小额，大额)
            $channelCommission = 1;  //平台佣金率
            $currencyCommission = 0;  //货币转换费率
            $channelCommissionMin = false;  //平台佣金最低值
            $channelCommissionRate = 0;  //平台佣金固定金额
            $grossProfit = 0;  //毛利润
            $fixedCosts = 0;   //固定成本
            $dynamicCost = 0;  //动态成本
            $rate = 1;  //汇率
            $discount = 1;  //促销折扣率
            $payPalBigAmountFee = 0;  //大额费率
            $payPalBigFixedFee = 0;  //大额固定
            $payPalBigFixedFeeMoney = 0;  //平邮大额售价
            $payPalSmallAmountFee = 0;  //小额费率
            $payPalSmallFixedFee = 0;  //小额固定
            $payPalSmallFixedFeeMoney = 0;  //平邮小额售价
            $logisticsSurcharge = 0;  //物流附加费
            $salePrice = 0;  //销售价
            $shippingFee = 0;  //运费
            $tagPrice = 0;  //吊牌价
            $reduction = 0;  //降价幅度
            $country_code = '';  //国家二字码
            $shipping_methods = ''; // 物流方式
            $shipping_methods_EUB = ''; // EUB物流方式
            $shippingFeeEUB = 0; // EUB运费价格
            $shippingCarrier = '';
            $goodsProperty = 0; // 物品属性
            $shippingFeeInternational = 0; // 国际运费
            $registrationFee = 0;  //物流挂号费
            $wishShippingMode = 0; //wish平台的运费类型
            $wishShippingRegistration = 0; //wish平台的挂号运费
            $totalPriceRmb = 0;//费用RMB
            $is_two_ok = true;
            $promotionRebate = 0;//促销返点
            $refund = 0;//退款


            foreach ($action_value as $k => $v) {
                switch ($v['item_source']) {
                    case 'pricingTransportationCosts':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'ptcO':  //全部申报
                                    $firstWeightFee = $value['other'] >0 ? $value['other'] :0;
                                    $firstWeight = $value['value'];
                                    break;
                                case 'ptcT':
                                    if (is_numeric($value['value'])) {
                                        if ($detail[0]['weight'] > $firstWeight) {
                                            $interval = ceil(($detail[0]['weight'] - $firstWeight) / $value['value']);
                                            $continuedWeightFee = $interval * $value['other'];
                                        }
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingPlatformCommission':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'ppcO':  //平台佣金
                                    if(isset($value['value']) && $value['value']){
                                        $channelCommission = ((float)$value['value']) / 100;
                                    }
                                    break;
                                case 'ppcT':  //平台佣金低于多少时
                                    if($value['other'] > 0){
                                        $channelCommissionRate = $value['other'];
                                        $channelCommissionMin = $value['value'];
                                    }

                                    break;
                            }
                        }
                        break;
                    case 'pricingGrossProfit':
                        if(isset($publishInfo['gross_profit']) && $publishInfo['gross_profit'] !== false){
                            $grossProfit = $publishInfo['gross_profit'] / 100;
                        }else {
                            foreach ($v['item_value'] as $key => $value) {
                                switch ($value['key']) {
                                    case 'pgpO':  //毛利润
                                        $grossProfit = $value['value'] / 100;
                                        break;
                                }
                            }
                        }
                        break;
                    case 'promotionRebate'://促销返点
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pgpO':
                                    $promotionRebate = is_numeric($value['value']) ? $value['value']/100 : 0;
                                    break;
                            }
                        }
                        break;
                    case 'refund'://退款
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pgpO':
                                    $refund = is_numeric($value['value']) ? $value['value']/100 : 0;
                                    break;
                            }
                        }
                        break;
                    case 'pricingOtherCosts':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pocO':
                                    $fixedCosts = is_numeric($value['value']) ? $value['value'] : 0;
                                    break;
                                case 'pocT':
                                    $dynamicCost = is_numeric($value['value']) ? $value['value'] : 0;
                                    break;
                            }
                        }


                        //$shippingFee 运费
                        if($country_code && $shipping_methods){
                            $noShippingOK = $this->checkShipping($shipping_methods,$rule);
                            if($noShippingOK){
                                $is_two_ok = false;
                                break 2;
                            }
                            $allShpping = [0,$shippingFee];
                            foreach ($other['warehouses'] as $warehouseId){
                                $data = [
                                    'warehouse_id' => $warehouseId, //仓库ID
                                    'country_code' => $country_code,
                                    'weight' => $detail[0]['weight'],
                                    'volume' => 1,
                                    'property' => $goodsProperty,
                                    'shipping_methods' => '[' . $shipping_methods. ']',
                                ];
                                $shippings = (new ShippingMethod())->trial($data);
                                foreach ($shippings as $shippingMoney){
                                    $allShpping[] = $shippingMoney['cny_amount'];
                                }
                            }
                            $shippingFee = max($allShpping);  //运费
                        }

                        if($country_code && $shipping_methods_EUB) {
                            $noShippingOK = $this->checkShipping($shipping_methods_EUB,$rule);
                            if($noShippingOK){
                                $is_two_ok = false;
                                break 2;
                            }
                            $allShpping = [0, $shippingFeeEUB];
                            foreach ($other['warehouses'] as $warehouseId) {
                                $data = [
                                    'warehouse_id' => $warehouseId, //仓库ID
                                    'country_code' => $country_code,
                                    'weight' => $detail[0]['weight'],
                                    'volume' => 1,
                                    'property' => $goodsProperty,
                                    'shipping_methods' => '['. $shipping_methods_EUB . ']',
                                ];
                                $shippings = (new ShippingMethod())->trial($data);
                                foreach ($shippings as $shippingMoney) {
                                    $allShpping[] = $shippingMoney['cny_amount'];
                                }
                            }
                            $shippingFeeEUB = max($allShpping);  //EUB运费}
                        }
                        $totalRate = 1 - $grossProfit - $channelCommission - $currencyCommission;
                        //算出总的销售价
                        switch($publishInfo['channel_id']){
                            case ChannelAccountConst::channel_ebay :
                                //转换币种计算
                                $payPalBigFixedFee = $payPalBigFixedFee * $rate;

                                $payPalSmallFixedFee = $payPalSmallFixedFee * $rate;;
                                //大小额 总销售价=（采购价格+运费+Paypal固定金额费用*汇率+其他固定成本）/（1-毛利润率-平台佣金率-Paypal收款账户费率）/ 汇率


                                $totalPriceRmb = ($purchasePrice + $shippingFee + $fixedCosts);

                                $payPalSmallFixedFeeMoney = ($totalPriceRmb + $payPalSmallFixedFee) / ($totalRate - $payPalSmallAmountFee) / $rate;
                                $payPalBigFixedFeeMoney = ($totalPriceRmb + $payPalBigFixedFee) / ($totalRate - $payPalBigAmountFee) / $rate;



                                $totalPriceRmbEUB = ($purchasePrice + $shippingFeeEUB + $fixedCosts);
                                $payPalSmallFixedFeeMoneyEUB = ($totalPriceRmbEUB + $payPalSmallFixedFee) / ($totalRate - $payPalSmallAmountFee) / $rate;
                                $payPalBigFixedFeeMoneyEUB = ($totalPriceRmbEUB + $payPalBigFixedFee) / ($totalRate - $payPalBigAmountFee) / $rate;
                                $totalPriceEUB = min($payPalSmallFixedFeeMoneyEUB,$payPalBigFixedFeeMoneyEUB);
                                break;
                            case ChannelAccountConst::channel_amazon:
                                //总销售价=（采购价格+运费+其他固定成本）/（1-毛利润率-平台佣金率）
                                $totalPriceRmb = ($purchasePrice + $shippingFee + $fixedCosts);

                                //1-毛利润率-平台佣金率-折扣率-促销返点-退款
                                $totalRate = $totalRate - $promotionRebate - $refund;
                                break;
                            case ChannelAccountConst::channel_aliExpress:
                                //销售价=（采购价格+重量*运费/g+其他固定成本+重量*其他动态成本/g）/（1-毛利润率）/ 汇率；
                                $totalPriceRmb = $purchasePrice + $firstWeightFee + $continuedWeightFee + $fixedCosts + $dynamicCost * $detail[0]['weight'];

                                break;
                            case ChannelAccountConst::channel_wish :
                                // 销售价=（采购价格+重量*运费/g+物流附加费+物流挂号费+其他固定成本+重量*其他动态成本/g）/（1-毛利润率-平台佣金率）/ 汇率
                                $otherFee = WishShippingRateService::getFee($detail[0]['weight'],$wishShippingMode);
                                $wishShippingRegistration = $purchasePrice + $otherFee['registration_fee'] + $firstWeightFee + $continuedWeightFee + $fixedCosts + $dynamicCost * $detail[0]['weight'];

                                $totalPriceRmb = $purchasePrice + $otherFee['surface_fee'] + $firstWeightFee + $continuedWeightFee + $fixedCosts + $dynamicCost * $detail[0]['weight'];
                                break;
                            default:
                                $totalPriceRmb = $purchasePrice + $firstWeightFee + $continuedWeightFee + $logisticsSurcharge + $fixedCosts + $dynamicCost * $detail[0]['weight'];
                                $totalRate = $totalRate - $discount;
                        }

                        $data['purchasePrice'] = '采购价:' . $purchasePrice;
                        $data['firstWeightFee'] = '首重费用:' . $firstWeightFee;
                        $data['continuedWeightFee'] = '续重费用:' . $continuedWeightFee;
                        $data['logisticsSurcharge'] = '物流附加费:' . $logisticsSurcharge;
                        $data['fixedCosts'] = '固定成本:' . $fixedCosts;
                        $data['dynamicCost'] = '动态成本:' . $dynamicCost;
                        $data['grossProfit'] = '毛利润:' . $grossProfit;
                        $data['channelCommission'] = '平台佣金率:' . $channelCommission;
                        $data['discount'] = '促销折扣率:' . $discount;
                        $data['siteCurrency'] = '站点币种:' . $siteCurrency;
                        $data['payPalBigFixedFee'] = '大额固定:' . $payPalBigFixedFee;
                        $data['payPalSmallFixedFee'] = '小额固定:' . $payPalSmallFixedFee;
                        $data['payPalBigAmountFee'] = '大额费率:' . $payPalBigAmountFee;
                        $data['payPalSmallAmountFee'] = '小额费率:' . $payPalSmallAmountFee;
                        $data['totalPriceRmb'] = '总价格人民币:' . $totalPriceRmb;
                        $data['title'] = '规则名称:' . $title;
                        $data['rate'] = '汇率:' . $rate;
                        $data['payPalSmallFixedFeeMoney'] = '小额平邮价格:' . $payPalSmallFixedFeeMoney;
                        $data['payPalBigFixedFeeMoney'] = '大额平邮价格:' . $payPalBigFixedFeeMoney;
                        $data['totalPriceEUB'] = '最终EUB售价:' . $totalPriceEUB;


                        $this->pricingData($data);

                        $totalPrice = ($totalPriceRmb / $totalRate) / $rate;

                        //平台佣金率为
                        if($channelCommissionMin !== false ){
                            $channelCommissionMoeny = $totalPrice * $channelCommission;
                            if($channelCommissionMoeny < $channelCommissionMin){
                                //按固定平台佣金计算的总销售价=（采购价格+运费+其他固定成本+平台佣金金额）/（1-毛利润率）
                                $totalPriceRmb = ($purchasePrice + $shippingFee + $fixedCosts + $channelCommissionRate * $rate);
                                $totalRate = 1 - $grossProfit;
                                $totalPrice = ($totalPriceRmb / $totalRate) / $rate;
                            }
                        }

                        $data['detail'] = $detail;
                        $this->pricingData($data);

                        break;
                    case 'pricingConvertedRate':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcrO':
                                    //币种
                                    $currency_code = !empty($value['operator']) ? $value['operator'] : 'CNY';
                                    if (empty($value['operator'])) {
                                        $this->error($title . '规则的申报价格的计算方法，币种没有选择');
                                    }
                                    break;
                                case 'pcrT':   //系统汇率
                                    if ($value['value'] == true) {
                                        $currencyData = Cache::store('currency')->getCurrency($currency_code);
                                        $rate = $currencyData['system_rate'];
                                    }
                                    break;
                                case 'pcrH':   //自定义汇率
                                    if ($value['value'] == true) {
                                        $rate = $value['operator'];
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingDiscountRate':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pdrO':  //促销折扣率
                                    $discount = is_numeric($value['value']) ? $value['value'] / 100 : 0;
                                    break;
                            }
                        }
                        break;
                    case 'pricingLargeAccountTransactionFee':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'platO':
                                    $payPalBigAmountFee = $value['value'] / 100;
                                    break;
                                case 'platT':
                                    $payPalBigFixedFee = $value['value'];
                                    break;
                            }
                        }
                        break;
                    case 'pricingSmallAccountTransactionFee':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'psatO':
                                    $payPalSmallAmountFee = $value['value'] / 100;
                                    break;
                                case 'psatT':
                                    $payPalSmallFixedFee = $value['value'];
                                    break;
                            }
                        }
                        break;
                    case 'currencyConversionRate':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'ccrO':
                                    if(isset($value['value']) && $value['value']){
                                        $currencyCommission = ((float)$value['value']) / 100;
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingLogisticsSurchargeSetting':
                        $is_ok = false;
                        $surcharge = 0;
                        $item_value = [];
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'plssO':
                                    if (!empty($value['value'])) {
                                        $surcharge = $value['value'];
                                    }
                                    break;
                                default:
                                    if ($value['value']) {
                                        $goodsProperty += $value['key'];
                                        array_push($item_value, $key);
                                    }
                                    break;
                            }
                        }
                        $is_ok = $orderRuleExecuteService->check('goodsProperty', $item_value, $publishInfo, $detail,
                            $is_ok);
                        if ($is_ok) {
                            $logisticsSurcharge = $surcharge;
                        }
                        break;
                    case 'pricingSplitScaleSettings':
                        $is_two = true;
                        foreach ($v['item_value'] as $key => $value) {

                            $is_one = false;
                            switch ($value['key']) {
                                case 'psss0':
                                    if($value['value'] != ''){
                                        if ($totalPrice <= $value['value']) {
                                            $is_one = true;
                                            $is_two = false;
                                            $total = $value['child'][0]['value'] + $value['child'][0]['other'];
                                            $salePrice = $totalPrice / $total * $value['child'][0]['value'];
                                            $shippingFee = $totalPrice / $total * $value['child'][0]['other'];
                                        }
                                    }
                                    break;
                                case 'psssT':
                                    if($value['value'] != '') {
                                        if ($totalPrice > $value['value'] && $totalPrice <= $value['other']) {
                                            $is_one = true;
                                            $is_two = false;
                                            $total = $value['child'][0]['value'] + $value['child'][0]['other'];
                                            $salePrice = $totalPrice / $total * $value['child'][0]['value'];
                                            $shippingFee = $totalPrice / $total * $value['child'][0]['other'];
                                        }
                                    }
                                    break;
                                case 'psssH':
                                    if($value['value'] != '') {
                                        if ($totalPrice > $value['value']) {
                                            $is_one = true;
                                            $is_two = false;
                                            $total = $value['child'][0]['value'] + $value['child'][0]['other'];
                                            $salePrice = $totalPrice / $total * $value['child'][0]['value'];
                                            $shippingFee = $totalPrice / $total * $value['child'][0]['other'];
                                        }
                                    }
                                    break;
                            }
                            //保留运费取整
                            if($is_one){
                                if($shippingFee >= 1){
                                    $decimals = $shippingFee - intval($shippingFee);
                                    $salePrice += $decimals;
                                    $shippingFee = intval($shippingFee);
                                }
                            }
                        }
                        if($is_two){
                            $salePrice = $totalPrice;
                        }
                        break;
                    case 'pricingCutPercentage':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcp0':
                                    if ($value['value'] != '') {
                                        if ($totalPrice <= $value['value']) {
                                            if ($value['child'][0]['value'] != '') {
                                                $reduction = $value['child'][0]['value'] / 100;
                                            }
                                        }
                                    }
                                    break;
                                case 'pcpT':
                                    if ($value['value'] != '') {
                                        if ($totalPrice > $value['value'] && $totalPrice <= $value['other']) {
                                            if ($value['child'][0]['value'] != '') {
                                                $reduction = $value['child'][0]['value'] / 100;
                                            }
                                        }
                                    }
                                    break;
                                case 'pcpH':
                                    if ($value['value'] != '') {
                                        if ($totalPrice > $value['value']) {
                                            if ($value['child'][0]['value'] != '') {
                                                $reduction = $value['child'][0]['value'] / 100;
                                            }
                                        }
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingTagPriceSetting':
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'ptpsO':
                                    if($value['value'] != ''){
                                        $tagPrice = $totalPrice * $value['value'];
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingShippingCountry':
                        if(!isset($other['warehouses'])){
                            break;
                        }
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'volO':
                                    if($value['operator'] != ''){
                                        $country_code = $value['operator'];
                                    }
                                    break;
                                case 'vol1':
                                    if($value['operator'] != ''){
                                        $shipping_methods = $value['operator'];
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingShippingEUB':
                        if(!isset($other['warehouses'])){
                            break;
                        }
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'volO':
                                    if(isset($value['operator']) && $value['operator'] != ''){
                                        $shipping_methods_EUB = $value['operator'];
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingSellingPriceFixed':

                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pgpO':  //
                                    if(isset($value['value']) && $value['value'] != ''){
                                        $totalPrice = sprintf("%.2f", $totalPrice) ;
                                        $totalPrice = substr($totalPrice,0,strlen($totalPrice)-1) . $value['value'];
                                    }

                                    break;
                            }
                        }
                        break;
                    case 'pricingPriceRange' :
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcp0':
                                    if($value['value'] != ''){
                                        if ($totalPrice <= $value['value']) {

                                        }else{
                                            $is_two_ok = false;
                                        }
                                    }
                                    break;
                                case 'pcpT':
                                    if($value['value'] != '') {
                                        if ($totalPrice > $value['value'] && $totalPrice <= $value['other']) {

                                        }else{
                                            $is_two_ok = false;
                                        }
                                    }
                                    break;
                                case 'pcpH':
                                    if($value['value'] != '') {
                                        if ($totalPrice > $value['value']) {

                                        }else{
                                            $is_two_ok = false;
                                        }
                                    }
                                    break;
                            }
                        }
                        break;
                    case 'pricingPriceOfSurfaceMail' :
                        $minSmall = false;
                        $maxSmall = false;
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcpO':
                                    $isPcpOk = false;
                                    if($value['value'] > 0 && $value['other'] > 0){
                                        if ($payPalSmallFixedFeeMoney > $value['value'] && $payPalSmallFixedFeeMoney <=  $value['other']) {
                                            $isPcpOk = true;
                                        }
                                    }else if($value['value'] > 0){
                                        if ($payPalSmallFixedFeeMoney > $value['value']) {
                                            $isPcpOk = true;
                                        }
                                    }else if($value['other'] > 0){
                                        if ($payPalSmallFixedFeeMoney <=  $value['other']) {
                                            $isPcpOk = true;
                                        }
                                    }

                                    if($isPcpOk){
                                        $payPalSmallFixedFeeMoney = $value['child'][0]['value'] ?? $payPalSmallFixedFeeMoney;
                                    }
                                    break;
                                case 'pcpT':
                                    if($value['value'] != '' && $minSmall === false) {
                                        $minSmall = $value['value'];
                                    }
                                    break;
                                case 'pcpW':
                                    if($value['value'] != '' && $maxSmall === false) {
                                        $maxSmall = $value['value'];
                                    }
                                    break;
                            }
                        }

                        if($minSmall && $payPalSmallFixedFeeMoney < $minSmall){
                            $totalPrice = $payPalSmallFixedFeeMoney;
                        }elseif($maxSmall && $payPalSmallFixedFeeMoney < $maxSmall){
                            $totalPrice = ($payPalSmallFixedFeeMoney + $totalPriceEUB) / 2;
                        }else{
                            $totalPrice = $totalPriceEUB;
                        }

                        break;
                    case 'pricingShippingPriceEUB' :
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcpO':
                                    $isShippingFeeEUB = false;
                                    $shippingFeeEUB = 0;
                                    if($value['value'] > 0 && $value['other'] > 0){
                                        if ($totalPrice > $value['value'] && $totalPrice <=  $value['other']) {
                                            $isShippingFeeEUB = true;
                                        }
                                    }else if($value['value'] > 0){
                                        if ($totalPrice > $value['value']) {
                                            $isShippingFeeEUB = true;
                                        }
                                    }else if($value['other'] > 0){
                                        if ($totalPrice <=  $value['other']) {
                                            $isShippingFeeEUB = true;
                                        }
                                    }

                                    if($isShippingFeeEUB){
                                        $shippingFeeEUB = $totalPriceEUB - $payPalSmallFixedFeeMoney;
//                                        $shippingCarrier = 'Standard Shipping from outside US (5-10 days)';
                                        $shippingCarrier = '';
                                    }
                                    break;
                            }
                        }

                        break;
                    case 'pricingPriceMail' :
                        $minSmall = false;
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcpO':
                                    $isPcpOk = false;
                                    if($value['value'] > 0 && $value['other'] > 0){
                                        if ($payPalSmallFixedFeeMoney > $value['value'] && $payPalSmallFixedFeeMoney <=  $value['other']) {
                                            $isPcpOk = true;
                                        }
                                    }else if($value['value'] > 0){
                                        if ($payPalSmallFixedFeeMoney > $value['value']) {
                                            $isPcpOk = true;
                                        }
                                    }else if($value['other'] > 0){
                                        if ($payPalSmallFixedFeeMoney <=  $value['other']) {
                                            $isPcpOk = true;
                                        }
                                    }
                                    if($isPcpOk){
                                        $payPalSmallFixedFeeMoney = $value['child'][0]['value'] ?? $payPalSmallFixedFeeMoney;
                                    }
                                    break;
                                case 'pcpT':
                                    if($value['value'] != '' && $minSmall === false) {
                                        $minSmall = $value['value'];
                                    }
                                    break;
                            }
                        }
                        if($minSmall){
                            if($payPalSmallFixedFeeMoney < $minSmall){
                                $totalPrice = $payPalSmallFixedFeeMoney;
                            }else{
                                $totalPrice = $totalPriceEUB;
                            }
                        }
                        break;
                    case 'pricingPriceInternationalShipping' :
                        $is_po = false;
                        $eachGRMB = 0;
                        $fixedRMB = 0;
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'riqO':
                                    if (!empty($value['operator']) && !empty($value['value'])) {
                                        $is_po = $orderRuleExecuteService->checkOperator($value['operator'],
                                            $detail[0]['weight'],
                                            $value['value']);
                                    }
                                    break;
                                case 'pcpH':
                                    if (!empty($value['value'])) {
                                        if(($totalPrice * $rate) > $value['value']){
                                            $is_po = true;
                                        }
                                    }
                                    break;

                            }
                        }
                        
                        if($is_po){
                            $shippingFeeInternational =  $payPalBigFixedFeeMoney - $totalPrice;
                        }else{
                            $shippingFeeInternational =  0;
                        }

                        break;
                    case 'pricingPriceRand' :
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'weiO':
                                    if (!empty($value['operator']) && !empty($value['value'])) {
                                        $floatMin = 0;
                                        $floatMax = 0;
                                        if($value['operator'] == 1){
                                            $floatMin = -$value['value'];
                                            $floatMax = $value['value'];
                                        }elseif($value['operator'] == 2){
                                            $floatMax = $value['value'];
                                        }elseif($value['operator'] == 3){
                                            $floatMin = -$value['value'];
                                        }
                                        $floatMin = $floatMin + mt_rand() / mt_getrandmax() * ($floatMax - $floatMin);
                                        $totalPrice += $floatMin;
                                        break;
                                    }

                            }
                        }
                        break;
                    case 'pricingTransportationCostsTime' :
                        $is_one = false;
                        $is_po = false;

                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'ptctO':
                                    if (!empty($value['operator']) && !empty($value['value'])) {
                                        $is_one = true;
                                        $is_po = $orderRuleExecuteService->checkOperator($value['operator'],
                                            $totalPrice,
                                            $value['value']);
                                    } else {
                                        $is_po = false;
                                    }
                                    break;
                                case 'ptctT':  //全部申报
                                    $firstWeightFee = $value['other'] >0 ? $value['other'] :0;
                                    $firstWeight = $value['value'];
                                    break;
                                case 'ptctF':
                                    if (is_numeric($value['value'])) {
                                        if ($detail[0]['weight'] > $firstWeight) {
                                            $interval = ceil(($detail[0]['weight'] - $firstWeight) / $value['value']);
                                            $continuedWeightFee = $interval * $value['other'];
                                        }
                                    }
                                    break;
                            }
                        }
                        if($is_one && $is_po){
                            //销售价=（采购价格+重量*运费/g+物流附加费+物流挂号费+其他固定成本+重量*其他动态成本/g）/（1-毛利润率-平台佣金率-折扣率）/ 汇率
                            $totalPriceRmb = $purchasePrice + $firstWeightFee + $continuedWeightFee + $logisticsSurcharge + $fixedCosts + $dynamicCost * $detail[0]['weight'] + $registrationFee;
                        }
                        $totalRate = 1 - $grossProfit - $channelCommission - $discount;
                        $totalPrice = $totalPriceRmb / $totalRate / $rate;

                        break;
                    case 'wishShippingRate' :

                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'pcrO':
                                    if (!empty($value['operator']) && !empty($value['value'])) {
                                        $wishShippingMode = $value['operator'];
                                    }
                            }
                        }
                        break;
                    case 'wishShippingFee' :
                        $is_one = false;
                        $is_po = false;
                        foreach ($v['item_value'] as $key => $value) {
                            switch ($value['key']) {
                                case 'riqT':
                                    if (!empty($value['operator']) && !empty($value['value'])) {
                                        $is_one = true;
                                        $is_po = $orderRuleExecuteService->checkOperator($value['operator'],
                                            $totalPrice,
                                            $value['value']);
                                    } else {
                                        $is_po = false;
                                    }
                                    break;
                            }
                        }

                        if($is_one && $is_po){
                            $totalRate = 1 - $grossProfit - $channelCommission;
                            $totalPrice =        $wishShippingRegistration  / $totalRate / $rate;
                        }

                        break;


                }
            }


            //再次验证是否符合
            if(!$is_two_ok){
                return $is_two_ok;
            }

            $priceRule['total_price'] = sprintf("%.2f", $totalPrice); //
            $priceRule['sale_price'] = sprintf("%.2f", $salePrice); // 销售价
            $priceRule['shipping_fee'] = sprintf("%.2f", $shippingFee); // 运费
            $priceRule['shipping_fee_nternational'] = sprintf("%.2f", $shippingFeeInternational);
            $priceRule['shipping_fee_EUB'] = sprintf("%.2f", $shippingFeeEUB);
            $priceRule['tag_price'] = sprintf("%.2f", $tagPrice);  // 吊牌价
            $priceRule['reduction'] = ($reduction * 100) . '%';
            $priceRule['cut_price'] = sprintf("%.2f", $salePrice * (1 - $reduction));   //降价之后的价格
            $priceRule['rule_title'] = $title;
            $priceRule['gross_profit'] = ($grossProfit * 100) . '%';
            $priceRule['currency_code'] = $currency_code;
            $priceRule['shipping_carrier'] = $shippingCarrier;
            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /**
     * 检查物流是否停用，如果停用就停用该定价规则
     * @param $shippingId
     */
    private function checkShipping($shippingId,$rule )
    {
        $noShippingOK = false;
        $shipping = Cache::store('Shipping')->getShipping($shippingId);
        if(!$shipping){
            $noShippingOK = true;
        }
        if($shipping['status'] == 0){
            $data['id'] = $rule['id'];
            $data['update_time'] = time();
            $data['status'] = 1;
            //查出是谁操作的
            $data['operator'] = '规则匹配';
            $data['operator_id'] = 0;
            $this->status($data);
            $noShippingOK = true;
        }
        return $noShippingOK;
    }

    /** 记录日志
     * @param $rule
     * @param $item_code
     * @param $package
     * @param $detail
     */
    public function log($rule, $item_code, $package, $detail)
    {
        $folderPath = LOG_PATH . "rule/";
        if(!is_dir($folderPath) && !mkdir($folderPath, 0777 ,true)) {
            throw new Exception("Unable to create folder: $folderPath");
        }

        $fileName = date('Y-m-d', time());
        $logFile =  $folderPath . $fileName . "_pricing.log";
        file_put_contents($logFile,
            "-------规则为" . $rule . "的item code为" . $item_code . "匹配不成功，数据" . "-------\r\n" . json_encode($package) . "\r\n-----详情数据为-----\r\n" . json_encode($detail) . "\r\n",
            FILE_APPEND);
    }

    /** 记录错误信息
     * @param $message
     */
    public function error($message)
    {
        $folderPath = LOG_PATH . "rule/";
        if(!is_dir($folderPath) && !mkdir($folderPath, 0777 ,true)) {
            throw new Exception("Unable to create folder: $folderPath");
        }

        $fileName = date('Y-m-d', time());
        $logFile = $folderPath . $fileName . "_pricing_error.log";
        file_put_contents($logFile, $message . "\r\n", FILE_APPEND);
    }

    /** 定价数据信息
     * @param $data
     */
    public function pricingData($data)
    {
        $folderPath = LOG_PATH . "rule/";
        if(!is_dir($folderPath) && !mkdir($folderPath, 0777 ,true)) {
            throw new Exception("Unable to create folder: $folderPath");
        }
        $fileName = date('Y-m-d', time());
        $logFile = LOG_PATH . "rule/" . $fileName . "_pricing_data.log";
        file_put_contents($logFile, json_encode($data, JSON_UNESCAPED_UNICODE) . "\r\n", FILE_APPEND);
    }


    /**
     *合格的sku跌价信息
     *
     */
    public function discountSkuInfo($detail)
    {
        $discountModel = new GoodsDiscountModel();

        $time = time();
        $where = [
            'sku_id' => ['=', $detail[0]['sku_id']],
            'status' => ['=', 1],
            'valid_time' => ['<', $time],
            'over_time' => ['>', $time],
        ];
        //跌价SKU需处于审核通过状态、在跌价有效期内且跌价已售量小于跌价产品总数的
        $discountInfo = $discountModel->field('sku_id, discount_type, inventory_price, discount_value')->where($where)->where('sell_num < discount_num')->find();

        $purchasePrice = $detail[0]['cost_price'];
        if($discountInfo) {
            $discountInfo = $discountInfo->toArray();
            $discount_value = $discountInfo['discount_type'] == 2 ? ($discountInfo['inventory_price']*$discountInfo['discount_value'])/100 : $discountInfo['discount_value'];
            $purchasePrice = $purchasePrice > $discount_value ? $purchasePrice-$discount_value : $purchasePrice;
        }

        return $purchasePrice;
    }


    /** 获取资源
     * @param $code
     * @return array
     * @throws \app\common\cache\Exception
     */
    public static function getResource($code)
    {
        $resource = new Resource();
        $result = $resource->read($code);
        return $result;
    }
}