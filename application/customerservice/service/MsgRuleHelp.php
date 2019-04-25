<?php
namespace app\customerservice\service;

use app\common\model\ebay\EbayMessage;
use app\common\model\MsgRuleSet as MsgRuleSetModel;
use app\common\exception\JsonErrorException;
use app\common\model\MsgRuleSetItem as MsgRuleSetItemModel;
use think\Db;
use think\Exception;
use app\order\service\OrderRuleCheckService;
use app\goods\controller\Category;
use app\warehouse\service\Warehouse;
use app\customerservice\service\MsgTemplateHelp;
use app\customerservice\queue\MsgReviewAutoSendQueue;
use app\common\cache\Cache;
use app\common\service\ChannelAccountConst;
use app\common\model\aliexpress\AliexpressOnlineOrder;
use app\common\model\Tag;
use app\common\model\Order;
use app\common\model\Country;
use app\common\model\OrderPackage;
use app\common\model\OrderDetail;
use app\common\service\Order as OrderService;
use app\common\model\MsgEmailSend;
use think\Log;
use app\common\model\customerservice\AmazonEmailContent;
use app\common\model\ebay\EbayMessageBody;

/**
 * Created by tb.
 * User: PHILL
 * Date: 2017/04/01
 * Time: 10:14
 */

class MsgRuleHelp
{   
    //日志内容
    private $log_content_arr = [];

    public function __construct(){
        $this->log_content_arr = [];
    }

    /**
     * 获取触发规则条件
     * @return array
     */
    public function getTriggerRules($channel)
    {
        $lists = [];

        switch ($channel) {
            case 1:
                $message_datas = [];
                $lists['message']['title'] = '回复规则';

                $message_datas[] = [
                    'id' => 'E11',
                    'name' => MsgRuleSetModel::$TRIGGER_RULE['E11']
                ];

                 $lists['message']['datas'] = $message_datas;

                 $feedback_datas = [];
                 $lists['feedback']['title'] = '评价规则';
                 foreach (MsgRuleSetModel::$TRIGGER_RULE_FEEDBACK as $k => $list) {
                     if ($k == 'F1' || $k == 'F2' || $k == 'F3')
                     {
                         $feedback_datas[] = [
                             'id' => $k,
                             'name' => $list
                         ];
                     }
                 }
                 $lists['feedback']['datas'] = $feedback_datas;
                 break;
            case 2:
                $message_datas = [];
                $lists['message']['title'] = '回复规则';
                foreach (MsgRuleSetModel::$TRIGGER_RULE as $k => $list) {
                    if ($k == 'E12' || $k == 'E13' || $k == 'E14')
                    {
                        $message_datas[] = [
                            'id' => $k,
                            'name' => $list
                        ];
                    }
                }
                $lists['message']['datas'] = $message_datas;

//                $feedback_datas = [];
//                $lists['feedback']['title'] = '评价规则';
//                $lists['feedback']['datas'] = $feedback_datas;
                break;
            case 4:
                $message_datas = [];
                $lists['message']['title'] = '回复规则';
                foreach (MsgRuleSetModel::$TRIGGER_RULE as $k => $list) {
                    if ($k == 'E2' || $k == 'E9' || $k == 'E10' || $k == 'E11')
                    {
                        $message_datas[] = [
                            'id' => $k,
                            'name' => $list
                        ];
                    }
                }
                $lists['message']['datas'] = $message_datas;

//                $feedback_datas = [];
//                $lists['feedback']['title'] = '评价规则';
//                $lists['feedback']['datas'] = $feedback_datas;
                break;
            default:
                break;
        }
        return $lists;
    }
    
    
    /**
     * 获取发送邮件规则条件
     * @return array
     */
    public function getSendEmailRules($channel,$trigger_rule)
    {
        $lists = [];
        switch ($channel){
            case 1:
                if ('E' == strtoupper(substr($trigger_rule,0,1)))
                {
                    foreach(MsgRuleSetModel::$SEND_EMAIL_RULE as $k => $list) {
                        if ( $k == 4)
                        {
                            $lists[] = [
                                'id'   => $k,
                                'name' => $list
                            ];
                        }
                    }
                }else if('F' == strtoupper(substr($trigger_rule,0,1))) {
                    foreach(MsgRuleSetModel::$SEND_EMAIL_RULE as $k => $list)
                    {
                        if ($k == 5 || $k == 6)
                        {
                            $lists[] = [
                                'id' => $k,
                                'name' => $list
                            ];
                        }
                    }
                }else {
                    foreach(MsgRuleSetModel::$SEND_EMAIL_RULE as $k => $list)
                    {
                        if ($k == 4 || $k == 5 || $k == 6)
                        {
                            $lists[] = [
                                'id'   => $k,
                                'name' => $list
                            ];
                        }
                    }
                }
                break;
            case 2:
                $lists[] = [
                    'id'   => 1,
                    'name' => MsgRuleSetModel::$SEND_EMAIL_RULE[1]
                ];
                break;
            case 4:
                $lists[] = [
                    'id'   => 4,
                    'name' => MsgRuleSetModel::$SEND_EMAIL_RULE[4]
                ];
                break;
            default:
                break;
        }
        return $lists;
    }


    /**
     * 获取平台
     * @return array
     */
    public function getPlatform()
    {
        $lists = [];
        foreach(MsgRuleSetModel::$PLATFORM as $k => $list) {
            $lists[] = [
                'id'   => $k,
                'name' => $list
            ];
        }
        return $lists;
    }


    /**
     * 验证延迟发送时间规则 （1、回复模板不能为空； 2、天和时必须有一个不为空）
     * @param unknown $delay_time_arr
     * @throws JsonErrorException
     */
    public function checkDelayTimeSend($delay_time_arr){
        //验证延迟发送时间规则 （1、回复模板不能为空； 2、天和时必须有一个不为空）
        if($delay_time_arr){
            $delay_time = json_decode($delay_time_arr,true);
            if(!empty($delay_time)){
                $sameData = [];
                foreach ($delay_time as $vo){
                    if(empty(param($vo, 'template_id'))){
                        throw new JsonErrorException('延迟发送时间：回复模板不能为空！');
                    }
//                    if(empty(param($vo, 'day')) && empty(param($vo, 'hour'))){
//                        throw new JsonErrorException('延迟发送时间：“天” 和  “时” 必须填写一个！');
//                    }
                    $sameId = $vo['day']."-".$vo['hour']."-".$vo['template_id'];
                    if(in_array($sameId,$sameData)){
                        throw new JsonErrorException('相同的时间只能设置一个模板');
                    }
                    $sameData[] = $vo['day']."-".$vo['hour']."-".$vo['template_id'];
                }
            }
        
        }
    }
    
    /**
     * 触发订单事件
     * @desc 
     * @author wangwei
     * @date 2018-11-2 14:17:41
     * @param string $event_name //Y 事件名称 @see \app\common\model\MsgRuleSet::$TRIGGER_RULE
     * 'E1' => '买家下单之后付款(资金未到账)',
     * 'E2' => '订单收到买家付款',---
     * 'E3' => '订单分配库存',
     * 'E4' => '订单标记打印',
     * 'E5' => '订单执行发货',
     * 'E6' => '订单同步发货状态成功',
     * 'E7' => '订单妥投/签收',
     * 'E8' => 'Invoice生成成功',
     * 'E9' => '长时间未付款',---
     * 'E10' => '买家提起纠纷后'
     * 
     * @param array $order_data //Y 渠道订单相关数据
     * @example $order_data = [
     *             'channel_id'=>'',//Y 渠道id
     *             'account_id'=>'',//Y 账号id
     *             'channel_order_number'=>'',//Y 渠道订单号
     *             'msg_rule_set_id'=>'0',//N 自动发信规则设置表id 
     *             'channel_order'=>[],//N 渠道订单数据(包含可缺省的订单明细->channel_order.channel_order_items)
     *             'ebay_message_data'=>[ //N ebay消息特有字段
     *                     'created_time'=>'',//消息本地创建时间
     *                     'item_id'=>'',//商品id
     *                     'sender'=>'',//消息发送人(发信时的收件人)
     *                     'comment_time'=>'',//差评时间
     *                      'message_id'=>'',//站内信标识号
     *             ]
     *              'amazon_message_data'=>[    amazon消息特有字段
     *                      'lastest_delivery_time'=>'' //最晚预计到达时间
     *                      'trigger_time'=>'' //标记联系退款时间
     *                      'content_id'=>'' //邮件内容id
     *              ]
     *              'extra_params' => [   //附加字段 json格式
     *                      'feedback_id' => '' //ebay评价id
     *              ]
            ]
     * ];
     */
    public function triggerEvent($event_name, $order_data){
        $this->log('===============log_start_' . param($order_data, 'channel_order_number') . '===================');
        
        /**
         * 1、参数校验
         */
        //$event_name
        if(!in_array($event_name, ['E2','E9','E10','E11','E12','E13','E14','F1','F2', 'F3'])){
            $this->log('不支持事件:'.$event_name);
            return false;
        }
        //$order_data.channel_id
        if(!$channel_id = param($order_data, 'channel_id')){
            $this->log('$order_data.channel_id不能为空');
            return false;
        }
        //$order_data.account_id
        if(!$account_id = param($order_data, 'account_id')){
            $this->log('$order_data.account_id不能为空');
            return false;
        }
        //$order_data.receiver
        if(!$receiver = param($order_data, 'receiver')){
            $this->log('$order_data.receiver不能为空');
            return false;
        }
        //$order_data.channel_order_number
        if($channel_id == 1 || $channel_id == 2){
            $channel_order_number = param($order_data, 'channel_order_number');
        }else{
            if(!$channel_order_number = param($order_data, 'channel_order_number')){
                $this->log('$order_data.channel_order_number不能为空');
                return false;
            }
        }
        //$order_data.msg_rule_set_id
        $msg_rule_set_id = param($order_data, 'msg_rule_set_id', 0);
        //$order_data.channel_order
        $channel_order = param($order_data, 'channel_order', []);
        //$order_data.ebay_message_data
        $ebay_message_data = param($order_data, 'ebay_message_data', []);
        //$order_data.amazon_message_data
        $amazon_message_data = param($order_data, 'amazon_message_data', []);
        //$order_data.extra_params
        $extra_params = param($order_data, 'extra_params', []);
        $log_arr = [
            'event_name'=>$event_name,
            'order_data'=>$order_data,
        ];
        $this->log('触发参数:' . print_r($log_arr,1));
        
        /**
         * 2、获取订单匹配数据
         */
        $md_param = [
            'event_name'=>$event_name,
            'channel_id'=>$channel_id,
            'account_id'=>$account_id,
            'channel_order_number'=>$channel_order_number,
            'channel_order'=>$channel_order,
            'ebay_message_data'=>$ebay_message_data,
            'amazon_message_data'=>$amazon_message_data,
            'extra_params'=>$extra_params,
        ];
        $match_data = $this->getMatchData($md_param);
        //未获取到满足事件的订单信息，直接返回
        if(!$match_data){
            $this->log('$match_data为空,未获取到满足事件的订单信息');
            return false;
        }
        
        /**
         * 3、匹配规则
         */
        $matchParams = [
            'channel_order_number'=>$channel_order_number,
            'channel_id'=>$channel_id,
            'account_id'=>$account_id,
            'receiver'=>$receiver,
            'event_name'=>$event_name,
            'match_data'=>$match_data,
            'channel_order'=>$channel_order,
            'ebay_message_data'=>$ebay_message_data,
            'amazon_message_data'=>$amazon_message_data,
            'extra_params'=>$extra_params,
        ];
        $this->log('$matchParams:' . print_r($matchParams, 1));
        //如果是指定规则检查
        if($msg_rule_set_id){
            $send_check = 1;
            $this->log('指定检查单条规则:' . $msg_rule_set_id);
            $cmRe = $this->checkMatch($msg_rule_set_id, $this->conversion($matchParams), $send_check);
            $this->log('指定检查单条规则:' . $msg_rule_set_id .','. ($cmRe ? '匹配成功======>Ok' : '匹配失败======>Error'));
            return  $cmRe;
        }
        
        return $this->match($matchParams);
    }
    
    /**
     * @desc 获取订单匹配数据
     * @author wangwei
     * @date 2018-11-2 15:28:48
     * @param string $event_name //Y
     * 'E1' => '买家下单之后付款(资金未到账)',
     * 'E2' => '订单收到买家付款',---
     * 'E3' => '订单分配库存',
     * 'E4' => '订单标记打印',
     * 'E5' => '订单执行发货',
     * 'E6' => '订单同步发货状态成功',
     * 'E7' => '订单妥投/签收',
     * 'E8' => 'Invoice生成成功',
     * 'E9' => '长时间未付款',---
     * 'E10' => '买家提起纠纷后'
     * 
     * @param int $channel_id //Y 渠道id
     * @param int $account_id //Y 账号id
     * @param string $channel_order_number //渠道订单号
     * @param array $channel_order_row //渠道订单数据(包含可缺省的订单明细->$channel_order_row.channel_order_items)
     * @return string[]|boolean[]|unknown[]|number[][]|string[][]
     */
    private function getMatchData($params){
        //接收参数
        $event_name = param($params, 'event_name','');
        $channel_id = param($params, 'channel_id','');
        $account_id = param($params, 'account_id','');
        $receiver = param($params, 'receiver','');
        $channel_order_number = param($params, 'channel_order_number','');
        $channel_order_row = param($params, 'channel_order',[]);
        $ebay_message_data = param($params, 'ebay_message_data',[]);
        $amazon_message_data = param($params, 'amazon_message_data',[]);
        $extra_params = param($params, 'extra_params',[]);

        /**
         * 1、检测事件
         */
        $match_data = [
            'datum_time'=>time(),//触发事件基准时间(北京时间戳)
            'source_site'=>'',//订单站点
            'buyer_selected_logistics_arr'=>[],//['DHL_ES'] 买家选择的运输类型--order.buyer_selected_logistics（逗号拆分）
            'country_code'=>'',//'CN' 订单目的国家代码--order.country_code
            'country_zone_code'=>'',//'Asia' 订单目的地--根据order.country_code 查 country.zone_code字段
            'child_order'=>false,//是否是子订单--[暂时不做]
            'shipping_id_arr'=>[],// ['2032','5524']实际发货邮寄方式order_package.shipping_id
            'warehouse_id_arr'=>[],//['70','50'] 发货仓库--order_package.warehouse_id
            
            'tag_arr'=>[], //['易碎','玻璃'] 订单货品属性包含(数组)----Cache::store('Goods')->getGoodsInfo($good_id).tags
            'category_id_arr'=>[],//[33,34,35] 订单至少存在一件货品属于(数组)--Cache::store('Goods')->getGoodsInfo($good_id).category_id
            'sku_arr'=>[],//["LA00001ZZ","LA00003ZZ"] 订单货品包含(数组)--Cache::store('goods')->getSkuInfo($sku_id).sku
            
            'is_partial_payment'=>false,//是否 预售商品部分付款。速卖通:
        ];
        $event_check = [];
        $datum_time_map = [];
        $channel_order_detail = [];
        $channel_order = [];

        switch ($channel_id){
            case ChannelAccountConst::channel_aliExpress:

                $channel_order = $channel_order_row ? $channel_order_row : (array)Db::table('aliexpress_online_order')->where('order_id',$channel_order_number)->find();
                //订单明细
                $order_items = param($channel_order_row, 'channel_order_items', []);
                if($aliexpress_order_detail = $order_items ? $order_items : (array)Db::table('aliexpress_online_order_detail')->where('aliexpress_online_order_id',param($channel_order, 'id'))->select()){
                    foreach ($aliexpress_order_detail as $aod){
                        $channel_order_detail[] = [
                            'channel_sku'=>$aod['sku_code'] ? $aod['sku_code'] : 1,
                            'sku_quantity' => $aod['product_count'],
                        ];
                    }
                }
                //预售商品部分付款
                $match_data['is_partial_payment'] = param($channel_order, 'biz_type')=='AE_PRE_SALE';

                switch ($event_name)
                {
                    case 'E2':
                    case 'E9':
                        if($channel_order){
                            //触发事件
                            $event_check['E2'] = param($channel_order, 'gmt_pay_time') > 0 && param($channel_order, 'order_status')=='3';
                            $event_check['E9'] = param($channel_order, 'gmt_pay_time') == 0 && param($channel_order, 'order_status')=='1';
                            //触发事件基准时间(北京时间戳)
                            $datum_time_map['E2'] =  param($channel_order, 'gmt_pay_time', time());//付款时间
                            $datum_time_map['E9'] =  param($channel_order, 'gmt_create', time());//订单创建时间
                        }
                        break;
                    case 'E10':
                        //速卖通纠纷
                        $issue_order = Db::table('aliexpress_issue')->where('order_id',$channel_order_number)->field('create_time')->find();
                        $event_check['E10'] = true;
                        $datum_time_map['E10'] =  param($issue_order, 'create_time', time());//纠纷创建时间
                        break;
                    case 'E11':
                        //买家第一封站内信事件
                        $msg_id = param($extra_params, 'message_id');
                        $create_time = Db::table('aliexpress_msg_detail')->where('msg_id', $msg_id)->value('create_time');
                        $event_check['E11'] = true;
                        $datum_time_map['E11'] = $create_time;//第一封站内信时间
                        break;
                    default:
                        break;
                }
                break;
            case ChannelAccountConst::channel_ebay:
                if($ebay_order = Db::table('ebay_order')->where('order_id',$channel_order_number)
                        ->field('id,created_time,shipping_service_selected,shipping_address_country')->find()){
                    $match_data['buyer_selected_logistics_arr'][] = $ebay_order['shipping_service_selected'];
                    $match_data['country_code'] = $ebay_order['shipping_address_country'];
                }
                switch ($event_name){
                    case 'F1':
                        //ebay买家下单事件
                        $event_check['F1'] = true;
                        $datum_time_map['F1'] =  param($ebay_order, 'created_time', time());//买家下单时间
                        break;
                    case 'F2':
                        //差评事件
                        $event_check['F2'] = true;
                        $datum_time_map['F2'] =  param($ebay_message_data, 'comment_time', time());//差评时间
                        break;
                    case 'F3':
                        //好评事件
                        $event_check['F3'] = true;
                        $datum_time_map['F3'] =  param($ebay_message_data, 'comment_time', time());//好评时间
                        break;
                    case 'E11':
                        //买家第一封站内信事件
                        $event_check['E11'] = true;
                        $datum_time_map['E11'] =  param($ebay_message_data, 'created_time', time());//第一封站内信时间
                        break;
                    default:
                        break;
                }
                //订单明细
                $order_items = param($channel_order_row, 'channel_order_items', []);
                if($ebay_order_detail = $order_items ? $order_items : (array)Db::table('ebay_order_detail')->where('oid',param($ebay_order, 'id'))->select()){
                    foreach ($ebay_order_detail as $eod){
                        $channel_order_detail[] = [
                            'channel_sku'=>$eod['sku'] ? $eod['sku'] : 1,
                            'sku_quantity' => $eod['quantity_purchased'],
                        ];
                    }
                }
                break;
            case ChannelAccountConst::channel_amazon:
                if($amazon_order = Db::table('amazon_order')->where('order_number',$channel_order_number)
                    ->field('or_transport,country')->find()){
                    $match_data['buyer_selected_logistics_arr'][] = $amazon_order['or_transport'];
                    $match_data['country_code'] = $amazon_order['country'];
                }
                switch ($event_name) {
                    case 'E12':
                        //最晚预计到达时间
                        $event_check['E12'] = true;
                        $datum_time_map['E12'] = param($amazon_message_data, 'lastest_delivery_time', time());
                        break;
                    case 'E13':
                        //标记联系退款
                        $event_check['E13'] = true;
                        $datum_time_map['E13'] = param($amazon_message_data, 'trigger_time', time());
                        break;
                    case 'E14':
                        //买家发送第一封邮件
                        $event_check['E14'] = true;
                        $datum_time_map['E14'] = param($amazon_message_data, 'create_time', time());
                        break;
                    default:
                        break;
                }
                break;
            default:
                break;
        }
        //不满足事件触发条件，不触发事件
        if(!(isset($event_check[$event_name]) && $event_check[$event_name]) ){
            $this->log('$event_check:' . print_r($event_check,1));
            return false;
        }
        //触发事件基准时间(北京时间戳)
        if(isset($datum_time_map[$event_name])){
            $match_data['datum_time'] = $datum_time_map[$event_name];
        }
        
        /**
         * 2、查订单数据
         */
        $good_id_arr = [];
        $sku_id_arr = [];
        $country_code = '';
        
        //1、订单存在，查订单数据
        $o_where = [
            'channel_account'=>$channel_id * 10000 + $account_id,
            'channel_order_number'=>$channel_order_number
        ];
        $o_field = 'id,buyer_selected_logistics,country_code';
        if($o_row = Order::where($o_where)->field($o_field)->find()){//系统订单存在，查订单数据
            //buyer_selected_logistics_arr
            $match_data['buyer_selected_logistics_arr'] = explode(',', $o_row['buyer_selected_logistics']);
            //country_code
            $match_data['country_code'] = $o_row['country_code'];
            /*
             * 订单包裹
             * shipping_id_arr、warehouse_id_arr
             */
            if($o_package_rows = OrderPackage::where('order_id',$o_row['id'])->field('shipping_id,warehouse_id')->select()){
                $match_data['shipping_id_arr'] = array_unique(array_column($o_package_rows, 'shipping_id'));
                $match_data['warehouse_id_arr'] = array_unique(array_column($o_package_rows, 'warehouse_id'));
            }
            /*
             * 订单明细
             * $good_id_arr、$sku_id_arr
             */
            if($o_detail_rows = OrderDetail::where('order_id',$o_row['id'])->field('goods_id,sku_id')->select()){
                $good_id_arr = array_unique(array_column($o_detail_rows, 'goods_id'));
                $sku_id_arr = array_unique(array_column($o_detail_rows, 'sku_id'));
            }
        }else{//系统订单不存在，查平台订单数据
            switch ($channel_id){
                case ChannelAccountConst::channel_aliExpress:
                    //buyer_selected_logistics_arr
                    $lt_rows = array_unique(explode(',', param($channel_order, 'logistics_type', '')));
                    foreach($lt_rows as $lt_row) {
                        if($lt_row && $bsl = Cache::store('AliexpressShippingMethod')->getNameByServiceKey($lt_row)){
                            $match_data['buyer_selected_logistics_arr'][] = $bsl;
                        }
                    }
                    //country_code
                    $address = json_decode(param($channel_order, 'receipt_address', ''), true);
                    $match_data['country_code'] = param($address, 'country', '');
                    $match_data['country_code'] = $match_data['country_code'] == 'UK' ? 'GB' : $match_data['country_code']; // 转换国家编码UK为GB
                    break;
                case ChannelAccountConst::channel_ebay:
                    break;
                case ChannelAccountConst::channel_amazon:
                    break;
                default:
                    break;
            }
            //$good_id_arr、$sku_id_arr
            if($channel_order_detail){
                $lsRe = (new OrderService())->channelSkuToLocSku($channel_id, $account_id, $channel_order_detail);
                foreach ($lsRe as $ls_v){
                    if(!$goods_id = param($ls_v, 'goods_id')){
                        continue;
                    }
                    if(!$sku_id = param($ls_v, 'sku_id')){
                        continue;
                    }
                    if(!in_array($ls_v['goods_id'], $good_id_arr)){
                        $good_id_arr[] = $ls_v['goods_id'];
                    }
                    if(!in_array($ls_v['sku_id'], $sku_id_arr)){
                        $sku_id_arr[] = $ls_v['sku_id'];
                    }
                }
            }
        }
        
        //country_zone_code
        if($match_data['country_code']){
            if($country_row = (new Country())->field('zone_code')->where(['country_code' => $match_data['country_code']])->find()){
                $match_data['country_zone_code'] = $country_row['zone_code'];
            }
        }
        
        /**
         * 3、查订单产品数据
         */
        foreach ($good_id_arr as $good_id){
            $goodsInfo = Cache::store('Goods')->getGoodsInfo($good_id);
            if(!$goodsInfo){
                continue;
            }
            //tag_arr
            $tag_arr = preg_split('/，|、/', $goodsInfo['tags']);
            foreach ($tag_arr as $tag){
                if(!in_array($tag, $match_data['tag_arr'])){
                    $match_data['tag_arr'][] = $tag;
                }
            }
            //category_id_arr
            if(!in_array($goodsInfo['category_id'], $match_data['category_id_arr'])){
                $match_data['category_id_arr'][] = $goodsInfo['category_id'];
            }
        }
        
        //sku_arr
        foreach ($sku_id_arr as $sku_id){
            $skuInfo = Cache::store('goods')->getSkuInfo($sku_id);
            if(!in_array($skuInfo['sku'], $match_data['sku_arr'])){
                $match_data['sku_arr'][] = $skuInfo['sku'];
            }
        }
        
        return $match_data;
    }

    /*
     * 匹配规则
     * @return array
     */
    public function match($params){
        /**
         * 1、查询所有规则
         */
        //ebay消息特有字段
        $ebay_message_data = param($params, 'ebay_message_data',[]);
        $extra_params = param($params, 'extra_params',[]);
        $receiver = param($params, 'receiver','');

        //数据格式转换
        $datas = $this->conversion($params);
        $result = Db::table('msg_rule_set')->order('sort asc')->field('id, trigger_rule, send_email_rule, delay_time_send')
            ->where(['status'=> 0, 'trigger_rule'=>$datas['event_name']])->select();
        if(empty($result)){
            $this->log('未获取到规则详细数据,trigger_rule:' . $datas['event_name']);
            return false;
        }

        /**
         * 2、按优先级依次匹配规则
         */
        $match_rule = [];//匹配到的规则
        foreach ($result as $item){
            $this->log("开始匹配规则:{$item['id']}");
            if(empty($item['delay_time_send'])){
                $this->log("触发时间为空,不匹配规则");
                continue;
            }
            if($this->checkMatch($item['id'], $datas, 0)){
                $this->log("规则:{$item['id']},匹配成功======>Ok");
                $match_rule = $item;
                break;
            }
            $this->log("规则:{$item['id']},匹配失败======>Error");
        }
        
        /**
         * 3、执行规则动作
         */
        if(!empty($match_rule)){
            $msgTemplateHelp = new MsgTemplateHelp();
            $delay = json_decode($match_rule['delay_time_send'],true);
            $extra_params_json = $extra_params ? json_encode($extra_params) : '';
            foreach ($delay as $v){
                $delay_second = $v['day']*3600*24 + $v['hour']*3600;
                $cron_time = param($datas, 'datum_time' ,time()) + $delay_second;
                $data = [
                    'channel_id'=>$datas['channel_id'],// 渠道id
                    'account_id'=>$datas['account_id'],// 账号id
                    'channel_order_number'=>$datas['channel_order_number'],// 渠道订单号
                    'send_email_rule'=>$match_rule['send_email_rule'],// 发送邮箱方式
                    'template_id'=>$v['template_id'],//模板id
                    'cron_time'=>$cron_time,// 预计发送时间
                    'create_time'=>time(),// 插入时间
                    'msg_rule_set_id'=>$match_rule['id'],// 规则设置表id
                    'delay_second'=>$delay_second,// 延迟发送秒数
                    'trigger_rule'=>$match_rule['trigger_rule'],// 触发规则条件
//                    'item_id'=>param($ebay_message_data, 'item_id', ''), // ebay唯一商品id
//                    'sender'=>param($ebay_message_data, 'sender', ''), // ebay站内信 消息发送人
                    'extra_params'=>$extra_params_json, //自动发信参数 json格式
                    'receiver'=>$receiver, //收信人
                ];
                //防止重复触发
                $has_arr = [
                    'trigger_rule'=>$data['trigger_rule'],
                    'channel_id'=>$data['channel_id'],
                    'account_id'=>$data['account_id'],
                    'channel_order_number'=>$data['channel_order_number'],
                    'delay_second'=>$data['delay_second'],
                    'template_id'=>$data['template_id'],
                    'extra_params'=>$extra_params_json,
                ];
                $data['only_key'] = md5(join(',',$has_arr));
                if(!MsgEmailSend::where('only_key',$data['only_key'])->field('id')->find()){
                    (new MsgEmailSend())->save($data);
                }
            }
        }
        if(empty($match_rule)){
            $this->log('没有匹配到规则');
        }
        return $match_rule;
    }
    
    /*
     * @desc 匹配单个规则
     * @author denghaibo
     * @date 2018-11-2 15:12:11
     * @param int $id
     * @param array $datas
     */
    public function checkMatch($id, $datas, $send_check){
        if(!$id || !$datas){
            $this->log('$id或者$datas为空！');
            return false;
        }
        $rules = Db::table('msg_rule_set_item')->field('rule_item_id,param_value')->where('rule_id', $id)->select();
        if(!$rules){
            $this->log("未找到规则明细数据");
            return false;
        }
        $match = true;
        foreach ($rules as $v) {
            $rule = json_decode($v['param_value'], true);
            $this->log('规则明细:' . print_r($v,1));
            switch ($v['rule_item_id']) {
                case 1:
                    $this->checkRule_source($rule, $datas['source'], $match);
                    break;
                case 2:
                    $this->checkRule_transport($rule, $datas['transport'], $match);
                    break;
                case 3:
                    $this->checkRule_childOrder($rule, $datas['child_order'], $match);
                    break;
                case 4:
                    $this->checkRule_destination($rule, $datas['destination'], $match);
                    break;
                case 5:
                    $this->checkRule_warehouse($rule, $datas['warehouse'], $match);
                    break;
                case 6:
                    $this->checkRule_express($rule, $datas['express'], $match);
                    break;
                case 7:
                    $this->checkRule_tags($rule, $datas['tags'], $match);
                    break;
                case 8:
                    $this->checkRule_categorys($rule, $datas['categorys'], $match);
                    break;
                case 9:
                    $this->checkRule_sku($rule, $datas['sku'], $match);
                    break;
                case 10:
                    $this->checkRule_activity($rule, $datas['activity'], $match);
                    break;
                case 11:
                    if (!$send_check)
                    {
                        $this->checkRule_keywords($rule, $datas['keywords'], $datas['channel_id'], $match);
                    }
                    break;
                default:
                    break;
            }
            if(!$match){
                break;
            }
        }
        return $match;
    }


    /*
     * 检查--是否子单
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_childOrder($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $val){
            if($val['key'] == $data['child_order']){
                $match=true;
                break;
            }
        }
    }
    
    /*
     * 检查--订单仓库
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_warehouse($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $val){
            if(in_array($val['key'], $data['warehouse_id_arr'])){
                $match=true;
                break;
            }
        }
    }

    /*
     * 检查--订单来源
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_source($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $v){
            $is_election = false;
            if (isset($v['election']) && !empty($v['election'])) {
                $is_election = true;   //排除算法
            }
            if($v['group']=='channel' && $v['key'] == $data['channel_id']){
                if(empty($v['child'])){
                    $match = true;
                    break;
                }
                foreach ($v['child'] as $vv){
                    if($vv['group']=='site' && $vv['key']==$data['source_site']){
                        $match = true;
                        break 2;
                    }else if($vv['group']=='account'){
                        if ($is_election){ //排除
                            if ($vv['key']==$data['account_id']){
                                $match = false;
                                break 2;
                            }else{
                                $match = true;
                            }
                        }else{
                            if ($vv['key']==$data['account_id']){
                                $match = true;
                                break 2;
                            }else{
                                $match = false;
                            }
                        }
                    }
                }
            }
        }
    }

    /*
     * 检查--买家选择的运输类型
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_transport($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $v){
            if($v['key'] == $data['channel_id']){
                if(empty($v['child'])){
                    $match = true;
                    break;
                }
                foreach ($v['child'] as $vv){
                    if(in_array($vv['key'], $data['buyer_selected_logistics_arr'])){
                        $match = true;
                        break 2;
                    }
                }
            }
        }
    }

    /*
     * 检查-- 订单目的地
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_destination($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $v){
            if(trim($v['key']) == trim($data['country_zone_code'])){
                if(empty($v['child'])){
                    $match = true;
                    break;
                }
                foreach ($v['child'] as $vv){
                    if(trim($vv['key']) == trim($data['country_code'])){
                        $match = true;
                        break 2;
                    }
                }
            }
        }
    }

    /*
     * 检查-- 实际发货邮寄方式
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_express($rule, $data, &$match)
    {
        $match=false;
        if(is_array($rule) && !empty($rule) && isset($rule[0]['value'])){
            foreach ($rule[0]['value'] as $v){
                if(in_array($v['id'], $data['shipping_id_arr'])){
                    $match = true;
                    break;
                }
            }
        }
    }

    /*
     * 检查-- 订单货品属性
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_tags($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $v){
            if($v['key'] && $t_rwo = Tag::where('id',$v['key'])->field('name')->find()){
                if(in_array(trim($t_rwo['name']), $data['tag_arr'])){
                    $match = true;
                    break;
                }
            }
        }
    }

    /*
     * 检查-- 订单货品品类
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_categorys($rule, $data, &$match)
    {
        $match=false;
        $category_id_arr = is_array($rule) && !empty($rule) ? $rule[0]['value'] : [];
        if(empty($category_id_arr)){
            $match = true;
            return true;
        }
        if(is_array($data['category_id_arr']) && !empty($data['category_id_arr'])){
            $match = !!array_intersect($data['category_id_arr'],$category_id_arr);
        }
    }

    /*
     * 检查-- 订单货品sku
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_sku($rule, $data, &$match)
    {
        $match=false;
        $sku_arr = is_array($rule) && !empty($rule) ? $rule[0]['value'] : [];
        if(empty($sku_arr)){
            $match = true;
            return true;
        }
        if(is_array($data['sku_arr']) && !empty($data['sku_arr'])){
            $match = !!array_intersect($data['sku_arr'],$sku_arr);
        }
    }
    
    /*
     * 检查-- 平台活动
     * @param $rule
     * @param $data
     * @param &$match
     */
    public function checkRule_activity($rule, $data, &$match)
    {
        $match=false;
        foreach ($rule as $v){
            if($v['key']=='1'){//预售商品部分付款
                if($data['is_partial_payment']===true){
                    $match = true;
                    break;
                }
            }
            
        }
    }

    /*
     * 检查-- 关键字
     * @param $rule
     * @param $data
     * @param &$match
     * $rule 格式
     * [{"key": "||", "child": [], "group": "", "other": "", "value": true, "operator": ""},
     * {"key": "keywords", "child": [], "group": "", "other": "", "value": "not recieve,not deliver", "operator": ""}]
     */
    public function checkRule_keywords($rule, $data, $channel_id, &$match)
    {
        $match=false;
        $message_id = 0;
        $key = '';
        $content = [];
        $method = '';
        $keywords = [];

        foreach ($rule as $value) {
            if ($value['key'] == '&' || $value['key'] == '||') {
                $method = $value['key'];
            }
            if ($value['key'] == 'keywords') {
                $keywords = explode(',', $value['value']);
            }
        }
        if (empty($method)) {
            $method = '||';
        }

        //正则匹配
        if ($channel_id == ChannelAccountConst::channel_ebay)
        {
//            $ebayMessageBody = new EbayMessageBody();
//            $content = $ebayMessageBody->where('message_id', $data['message_id'])->value('message_html');
            $ebayMessage = new EbayMessage();
            $message = $ebayMessage->where('message_id', $data['message_id'])->field('id,message_text')->find();
            $message_id = $message['id'];
            $content = $message['message_text'];
            $key = 'keyword_matching:' . ChannelAccountConst::channel_ebay . ':' . 0 . ':' . $message_id;
        } elseif ($channel_id == ChannelAccountConst::channel_amazon)
        {
            $amazonEmailContent = new AmazonEmailContent();
            $content = $amazonEmailContent->where('id', $data['message_id'])->field('id,content')->find();
            $message_id = $data['message_id'];
            $key = 'keyword_matching:' . ChannelAccountConst::channel_amazon . ':' . 1 . ':' . $message_id;
        }elseif ($channel_id == ChannelAccountConst::channel_aliExpress)
        {
            $where['msg_id'] = $data[0]['message_id'];
            $where['channel_id'] = $data[1]['aliexpress_channel_id'];

            $message = Db::table('aliexpress_msg_detail')->where($where)->field('id,content')->find();
            $message_id = $message['id'];
            $content = $message['content'];
            $key = 'keyword_matching:' . ChannelAccountConst::channel_aliExpress . ':' . 0 . ':' . $message_id;
        }


        $temp = join('|', $keywords);
        $patten = '/' . $temp . '/i';
        $count = preg_match_all($patten, $content, $regular_matchs);
        if(!$count)
        {
            return false;
        } else {
            if ($method == '&')
            {
                $regular_matchs_low = array_map('strtolower', $regular_matchs[0]);
                foreach ($keywords as $item)
                {
                    if(!in_array(strtolower($item), $regular_matchs_low))
                    {
                        $match = false;
                        return false;
                    }
                }
            }
        }
        $match = true;
        foreach ($regular_matchs[0] as $regular_match)
        {
            $regular_match = strtolower($regular_match);
            Cache::handler()->set($key. ':' .$regular_match,$regular_match);
        }
    }

    /**
     * @desc 记录日志
     * @author wangwei
     * @date 2018-11-10 9:24:34
     * @param unknown $str
     * @return boolean
     */
    public function log($log){
        if(empty($log)){
            return false;
        }
        if(defined('___SHOW_LOG___') && ___SHOW_LOG___===true){
            $str = !is_string($log) ? print_r($log, true) : $log;
            echo '[' . date ( 'Y-m-d H:i:s' ) . ']' . $str . "\n";
        }else if(defined('___WRITE_LOG___') && ___WRITE_LOG___===true){
            $str = !is_string($log) ? print_r($log, true) : $log;
            $str = '[' . date ( 'Y-m-d H:i:s' ) . ']' . $str . "\r\n";
            $file = LOG_PATH . 'swoole/MsgRuleHelp_' . date('Y-m-d_H') . '_.log';
            $handle = fopen($file, 'a');
            fwrite($handle, $str);
            fclose($handle);
        }
        $this->log_content_arr[] = !is_string($log) ? print_r($log, true) : $log;;
    }

    /**
     * 数据格式转换
     * @param $params
     * @return array
     */
    public function conversion($params): array
    {
        $match_data = $params['match_data'];
        $datas = [
            'event_name' => $params['event_name'],
            'datum_time'=>param($match_data, 'datum_time' , time()),//触发事件基准时间(北京时间戳)
            'channel_id' => $params['channel_id'],
            'account_id' => $params['account_id'],
            'channel_order_number' => $params['channel_order_number'],
            'source' => ['channel_id' => $params['channel_id'], 'source_site' => $match_data['source_site'],'account_id'=>$params['account_id']],
            'transport' => ['channel_id' => $params['channel_id'], 'buyer_selected_logistics_arr' => $match_data['buyer_selected_logistics_arr']],
            'child_order' => ['child_order' => $match_data['child_order']],
            'destination' => ['country_code' => $match_data['country_code'],'country_zone_code'=>$match_data['country_zone_code']],
            'warehouse' => ['warehouse_id_arr'=>$match_data['warehouse_id_arr']],
            'express' => ['shipping_id_arr' => $match_data['shipping_id_arr']],
            'tags' => ['tag_arr' => $match_data['tag_arr']],
            'categorys' => ['category_id_arr' => $match_data['category_id_arr']],
            'sku' => ['sku_arr' => $match_data['sku_arr']],
            'activity'=>['is_partial_payment'=>$match_data['is_partial_payment']],
            'keywords' => [],
        ];

        if ($params['channel_id'] == ChannelAccountConst::channel_ebay)
        {
            if (isset($params['extra_params']['message_id']))
            {
                $datas['keywords'] = ['message_id'=>$params['extra_params']['message_id']];
            }
        }elseif ($params['channel_id'] == ChannelAccountConst::channel_amazon)
        {
            if (isset($params['extra_params']['message_id']))
            {
                $datas['keywords'] = ['message_id' => $params['extra_params']['message_id']];
            }
        }elseif ($params['channel_id'] == ChannelAccountConst::channel_aliExpress)
        {
            if (isset($params['extra_params']['message_id']) && isset($params['extra_params']['aliexpress_channel_id']))
            {
                $datas['keywords'][0] = ['message_id' => $params['extra_params']['message_id']];
                $datas['keywords'][1] = ['aliexpress_channel_id' => $params['extra_params']['aliexpress_channel_id']];
            }
        }
        return $datas;
    }

    /**
     * @desc 获取日志内容
     * @author wangwei
     * @date 2018-11-10 9:24:34
     * @return array
     */
    public function getLogContentArr(){
        return $this->log_content_arr;
    }

    public function set_only_key_md5()
    {
        set_time_limit(0);
        //循环查询
        while ($datas = Db::table('msg_email_send')->where(['only_key' => ''])->field('id,trigger_rule,channel_id,account_id,channel_order_number,delay_second,template_id,extra_params')->limit(2000)->select())
        {
            {
                foreach ($datas as $data)
                {
                    $has_arr = [
                        'trigger_rule'=>$data['trigger_rule'],
                        'channel_id'=>$data['channel_id'],
                        'account_id'=>$data['account_id'],
                        'channel_order_number'=>$data['channel_order_number'],
                        'delay_second'=>$data['delay_second'],
                        'template_id'=>$data['template_id'],
                        'extra_params'=>$data['extra_params'],
                    ];

                    $data['only_key'] = md5(join(',',$has_arr));
                    Db::table('msg_email_send')->update($data);
                }
            }
        }
    }

    public function set_content_md5()
    {
        set_time_limit(0);

        $where['md5_content'] = '';
        $where['content'] = array('neq','');

        //循环查询
        while ($datas = Db::table('msg_email_send')->where($where)->field('id,content')->limit(2000)->select())
        {
            foreach ($datas as $data)
            {
                $data['md5_content'] = md5($data['content']);
                Db::table('msg_email_send')->update($data);
            }
        }

    }

    /**
     * @param $channel_id 渠道id
     * @param $account_id 账号id
     * @param $channel_order_number 平台订单号
     * @param $content 内容
     * @return array|bool
     */
    public function add_send_message($channel_id, $account_id, $channel_order_number, $content)
    {
        $return = [
            'ask'=>0,
            'message'=>'add_send_message_new error'
        ];

        $msgEmailSend = new MsgEmailSend();
        $send_email_rule = 0;

        if ($channel_id == 1)
        {
            $send_email_rule = 4;
        }elseif ($channel_id == 2){
            $send_email_rule = 1;
        }elseif ($channel_id == 4){
            $send_email_rule = 4;
        }else{
            $return['message'] = 'channel not support!';
            return $return;
        }

        $md5_content = md5($content);
        $has_arr = [
            'channel_id'=>$channel_id,
            'md5_content'=>$md5_content,
            'channel_order_number'=>$channel_order_number,
            'account_id'=>$account_id,
            'send_email_rule'=>$send_email_rule,
        ];

        $message = $msgEmailSend->where($has_arr)->find();
        if (!$message)
        {
            $data = [
                'channel_id'=>$channel_id,
                'content'=>$content,
                'md5_content'=>$md5_content,
                'channel_order_number'=>$channel_order_number,
                'account_id'=>$account_id,
                'cron_time'=>time(),
                'send_email_rule'=>$send_email_rule,
                'create_time'=>time(),
            ];

            $re = $msgEmailSend->insert($data);

            if ($re)
            {
                $return['ask'] = !!$re;
                $return['message'] = '添加成功';
            }else{
                $return['ask'] = !!$re;
                $return['message'] = '添加失败';
            }
            return $return;
        }else{
            $return['ask'] = 0;
            $return['message'] = '记录已存在';
            return $return;
        }

    }
}