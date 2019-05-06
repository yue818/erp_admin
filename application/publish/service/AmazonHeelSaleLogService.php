<?php
namespace app\publish\service;

use app\common\exception\JsonConfirmException;
use app\common\model\amazon\AmazonActionLog;
use app\common\model\amazon\AmazonHeelSaleLog as AmazonheelSaleLogModel;
use app\common\model\amazon\AmazonHeelSaleLog;
use app\common\model\amazon\AmazonListingDetail;
use app\common\model\amazon\AmazonSellerHeelSale;
use app\common\model\amazon\AmazonUpLowerFrameRule as AmazonUpLowerFrameRuleModel;
use app\common\model\amazon\AmazonListing as AmazonListingModel;
use app\common\model\amazon\AmazonUpLowerFrameRule;
use app\common\model\amazon\AmazonUpLowerFrameRuleDetail;
use app\common\model\amazon\AmazonUpOpenLog;
use app\common\model\ChannelUserAccountMap;
use app\common\model\User;
use app\common\service\CommonQueuer;
use app\listing\controller\Amazon;
use app\listing\service\AmazonActionLogsHelper;
use app\publish\queue\AmazonHeelSaleQueuer;
use app\common\service\UniqueQueuer;
use app\publish\queue\AmazonTimerUpLowerQueuer;
use think\Db;
use think\Exception;
use app\common\model\amazon\AmazonAccount as AmazonAccountModel;
use app\common\model\User as UserModel;
use app\common\model\amazon\AmazonHeelSaleComplain as AmazonHeelSaleComplainModel;
use app\publish\queue\AmazonHeelSaleComplaintQueuer;
use app\common\model\amazon\AmazonListing;
use app\publish\queue\AmazonHeelSaleComplainPriceQueuer;
use app\goods\service\GoodsSkuMapService;
use app\common\model\amazon\AmazonUpOpenLog as AmazonUpOpenLogModel;
use app\publish\queue\AmazonAddUpOpenLogQueuer;
use app\common\model\ChannelUserAccountMap as ChannelUserAccountMapModel;
use app\common\service\ChannelAccountConst;
use Waimao\AmazonMws\AmazonSubscribe;
use app\common\model\amazon\AmazonAccount;
use app\common\model\amazon\AmazonSellerHeelSale as AmazonSellerHeelSaleModel;
use app\publish\queue\AmazonHeelSalePriceQueuer;
use app\publish\queue\AmazonHeelSaleQuantityQueuer;
use app\common\cache\Cache;
use Waimao\AmazonMws\AmazonConfig;
use Waimao\AmazonMws\AmazonSqs;

class AmazonHeelSaleLogService
{

    //定时上下架类型
    public static $up_type = 1;
    public static $lower_type = 2;

    //上下架规则周一到周天
    public static $up_lower_time = [
        0 => 'sunday_up_lower',
        1 => 'monday_up_lower',
        2 => 'tuesday_up_lower',
        3 => 'wednesday_up_lower',
        4 => 'thursday_up_lower',
        5 => 'friday_up_lower',
        6 => 'saturday_up_lower',
    ];

    /**
     * amazon批量跟卖
     * @param $heelSale
     * @return array
     */
    public function bathHeelSale($heelSal, $rule_id, $rules, $lang)
    {
        try {

            $where = [
                'asin1' => $heelSal['asin'],
                'seller_type' => 1
            ];

            $listingModel = new AmazonListing();

            $amazon = $listingModel->field('id,account_id')->where($where)->find();

            //不存在asin
            if (!$amazon) {
                return $this->heelSaleCheck($heelSal, $rule_id, $lang);
            }

            $mapModel = new ChannelUserAccountMapModel;
            $where = [
                'account_id' => $amazon['account_id'],
                'channel_id' => ChannelAccountConst::channel_amazon
            ];

            $channelMap = $mapModel->where($where)->field('seller_id')->find();

            if (!$channelMap) {
                $message = $lang == 'zh' ? '为公司内部ASIN' : 'For internal ASIN';
                return ['sku' => $heelSal['sku'], 'asin' => $heelSal['asin'], 'status' => $heelSal['asin'] . $message];
            }


            $sellerId = $channelMap->toArray()['seller_id'];

            //登陆用户id和销售人员相等,则可以跟卖,否则不能跟卖
            if ($sellerId != $heelSal['create_id']) {
                $message = $lang == 'zh' ? '为公司内部ASIN' : 'For internal ASIN';
                return ['sku' => $heelSal['sku'], 'asin' => $heelSal['asin'], 'status' => $heelSal['asin'] . $message];
            } else {
                return $this->heelSaleCheck($heelSal, $rule_id, $lang);
            }
        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *
     *添加跟卖验证
     */
    public function heelSaleCheck($heelSal, $rule_id, $lang)
    {
        $saleLogModel = new AmazonheelSaleLogModel();
        $accountModel = new AmazonAccount();

        //1.根据日志查询是否已经跟卖
        $where = [
            'asin' => ['=', $heelSal['asin']],
            'type' => ['=', 1],
        ];

        //同个站点不可以跟卖。不同站点可以跟卖
        $saleLog = $saleLogModel->field('account_id')->where($where)->whereNotIn('status', 2)->select();
        $return = ['sku' => $heelSal['sku'], 'asin' => $heelSal['asin'], 'status' => $lang == 'zh' ? '已被跟卖' : 'Has been sold with'];

        $sites = [];
        $site = '';
        if ($saleLog) {
            //有数据,再根据数据查询账号站点.以及根据传过来的账号判断是否是同个站点
            $accountIds = array_column($saleLog, 'account_id');

            //之前跟卖过的站点
            $sites = $accountModel->field('site')->whereIn('id', $accountIds)->select();
            $sites = array_column($sites, 'site');

            //此次跟卖站点
            $site = $accountModel->field('site')->where('id', $heelSal['account_id'])->find();
            $site = $site->toArray()['site'];
        }


        //为空,或者不同站点可以跟卖
        if (empty($saleLog) || ($site && $sites && !in_array($site, $sites))) {

            //sku不足20位,则自动补全
            if (mb_strlen($heelSal['sku']) < 20) {
                $service = new GoodsSkuMapService();
                $heelSal['sku'] = $service->createSkuNotInTable($heelSal['sku'], $heelSal['account_id']);
            }

            //2.没有跟卖的话,则添加log
            $data = [
                'account_id' => $heelSal['account_id'],
                'asin' => $heelSal['asin'],
                'price' => $heelSal['price'],
                'sales_price' => $heelSal['price'],
                'quantity' => $heelSal['quantity'],
                'created_time' => time(),
                'type' => 1,
                'create_id' => $heelSal['create_id'],
                'sku' => $heelSal['sku'],
                'modify_price_type' => $heelSal['modify_price_type'],
                'modify_price' => $heelSal['modify_price'],
                'rule_id' => $rule_id,
                'lowest_price' => $heelSal['lowest_price'],
            ];

            try {
                Db::startTrans();
                $id = $saleLogModel->insertGetId($data);

                //原来是交给独立的队列去处理，但是现在交给集成的队列去处理；
                //(new UniqueQueuer(AmazonHeelSaleQueuer::class))->push($id);
                //增加一条ActionLog数据；
                $actionModel = new AmazonActionLog();
                $actionModel->insert([
                    'amazon_listing_id' => 0,
                    'account_id' => $heelSal['account_id'],
                    'seller_sku' => $heelSal['sku'],
                    'site' => 0,
                    'type' => AmazonActionLogsHelper::$scence_type['asin'],
                    'callback_type' => 3,
                    'callback_param' => json_encode(['heel_id' => $id]),
                    'old_value' => '[]',
                    'new_value' => json_encode(['asin' => $heelSal['asin']]),
                    'create_id' => $heelSal['create_id'],
                    'create_time' => time(),
                    'cron_time' => 0,
                    'remark' => '',
                ]);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }
            $return = ['sku' => $heelSal['sku'], 'asin' => $heelSal['asin'], 'status' => $lang == 'zh' ? '跟卖信息已提交' : 'Follow-up information has been submitted'];
        }

        return $return;
    }


    /**
     * 上传跟卖产品数据的回调！！！；
     * (因为是在回调函数里调执的，所以是不支持跳转)
     * @param $action
     */
    public function backUploadProduct($action)
    {
        //这里因为没有amazon_listing,所以 $action['amazon_listing_id'] = 0;

        $params = empty($action['callback_param']) ? [] : json_decode($action['callback_param'], true);
        //没有回调参数，则停止
        if (empty($params['heel_id'])) {
            $action->save(['callback_status' => 1]);
            return;
        }

        //查询heel-log,无数据停止执行;
        $saleLogModel = new AmazonheelSaleLogModel();
        $saleLog = $saleLogModel->where(['id' => $params['heel_id']])->find();
        if (empty($saleLog)) {
            $action->save(['callback_status' => 1]);
            return;
        }
        $time = time();
        //要修改的数据；
        $saleLogData = [];
        $saleLogData['last_request_time'] = $time;
        //saleLog,status: 0未抓取结果,1已抓取,2意外失败,3上传中;4:取消
        $saleLogData['product_status'] = $action['status'];

        //错误信息；
        //当产品上传成功时，状态调成3，并开始上传库存和价格；
        if ($action['status'] == 2) {
            //产品上传失败
            $saleLogData['status'] = $action['status'];
            $saleLogData['error_desc'] = $action['message'];
            $saleLog->save($saleLogData);
            $action->save(['callback_status' => 1]);
        } else if ($action['status'] == 1) {
            try {
                Db::startTrans();
                //产品上传成功；
                $saleLogData['status'] = 3;
                $saleLog->save($saleLogData);

                //加listing;
                $listingId = $this->addListingFromSaleLog($saleLog);
                //修改actionLog表的AmazonListingId；
                $saleLog->save(['listing_id' => $listingId]);

                $this->addPriceActionLog($saleLog);
                $this->addQuantityActionLog($saleLog);
                $action->save(['amazon_listing_id' => $listingId, 'callback_status' => 1]);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }
        }
    }


    /**
     * 产品上传成功后，增加一条listing数据；
     * @param $saleLog
     * @return int
     */
    public function addListingFromSaleLog($saleLog) : int
    {
        $listingModel = new AmazonListing();
        $detailModel = new AmazonListingDetail();
        //跟卖成功,添加listing数据
        $account = Cache::store('AmazonAccount')->getAccount($saleLog['account_id']);

        $where = [
            'account_id' => $account['id'],
            'seller_sku' => $saleLog['sku'],
        ];
        $old = $listingModel->where($where)->find();
        if (empty($old)) {
            //加入listing数据；
            $listingId = $listingModel->insertGetId([
                'seller_sku' => $saleLog['sku'],
                'account_id' => $saleLog['account_id'],
                'seller_type' => 2,
                'currency' => AmazonCategoryXsdConfig::getCurrencyBySite($account['site']),
                'price' => 0,
                'quantity' => 0,
                'asin1' => $saleLog['asin'],
                'site' => (string)$account['site'],
                'modify_time' => time(),
                'create_time' => time(),
                'rule_id' => $saleLog['rule_id'],
            ]);
            $detailModel->insert(['listing_id' => $listingId, 'description' => '']);
        } else {
            $listingId = $old['id'];
        }

        return $listingId;
    }


    /**
     *
     * @param $saleLog
     * @param $listingId
     */
    public function addQuantityActionLog($saleLog)
    {
        //加日志；
        $acctionService = new AmazonActionLogsHelper();
        $actionData = [];
        $actionData[] = [
            'amazon_listing_id' => $saleLog['listing_id'],
            'account_id' => $saleLog['account_id'],
            'new_value' => $saleLog['quantity'],
            'old_value' => 0,
        ];
        $callback_type = 4;
        $callback_param = json_encode(['heel_id' => $saleLog['id']]);
        $acctionService->editListingData(json_encode($actionData), 'quantity', 0, '', 0, $callback_type, $callback_param);
    }


    public function addPriceActionLog($saleLog)
    {
        //加日志；
        $acctionService = new AmazonActionLogsHelper();
        $actionData = [];
        $actionData[] = [
            'amazon_listing_id' => $saleLog['listing_id'],
            'account_id' => $saleLog['account_id'],
            'new_value' => $saleLog['price'],
            'old_value' => 0,
        ];
        $callback_type = 4;
        $callback_param = json_encode(['heel_id' => $saleLog['id']]);
        $acctionService->editListingData(json_encode($actionData), 'price', 0, '', 0, $callback_type, $callback_param);
    }


    /**
     * 上传跟卖产品数据的回调！！！；
     * (因为是在回调函数里调执的，所以是不支持跳转)
     * @param $action
     */
    public function backUploadPriceAndQuantity($action)
    {
        /** @var  $lock \app\common\cache\driver\Lock */
        $lock = Cache::store('Lock');
        //1.加锁，失败则证明重了，需要下次处理；
        $params = empty($action['callback_param']) ? [] : json_decode($action['callback_param'], true);
        //没有回调参数，则停止
        if (empty($params['heel_id'])) {
            $action->save(['callback_status' => 1]);
            return;
        }

        $lockParams = ['action' => 'amazon_heel_sale_log_quantity_price', 'type' => $action['type'], 'account_id' => $action['account_id']];
        //$lock->unlockParams($lockParams);
        //此处使用唯一锁,锁住120秒，足够完成所有查询了；
        try {
            Db::startTrans();
            if ($lock->lockParams($lockParams, true, 20)) {
                //查询heel-log,无数据停止执行;
                $saleLogModel = new AmazonheelSaleLogModel();
                $saleLog = $saleLogModel->where(['id' => $params['heel_id']])->find();
                if (empty($saleLog)) {
                    $lock->unlockParams($lockParams);
                    $action->save(['callback_status' => 1]);
                    Db::commit();
                    return;
                }

                $time = time();
                //要修改的数据；
                $saleLogData = [];
                $saleLogData['last_request_time'] = $time;
                //saleLog,status: 0未抓取结果,1已抓取,2意外失败,3上传中;4:取消
                $field = ($action['type'] == 2) ? 'price_status' : 'quantity_status';
                $field2 = ($action['type'] == 2) ? 'quantity_status' : 'price_status';
                $saleLogData[$field] = $action['status'];

                //错误信息；
                if ($action['status'] == 2) {
                    //产品上传失败
                    $saleLogData['status'] = $action['status'];
                    $saleLogData['error_desc'] = $action['message'];
                    $saleLog->save($saleLogData);
                } else if ($action['status'] == 1) {
                    //更新listing数据；
                    $upField = ($action['type'] == 2) ? 'price' : 'quantity';
                    AmazonListing::update([$upField => $saleLog[$upField], 'modify_time' => $time], ['id' => $saleLog['listing_id']]);
                    //产品上传成功；
                    if ($saleLog['product_status'] == 1 && $saleLog[$field2] == 1) {
                        $saleLogData['status'] = 1;
                        $saleLogData['is_sync'] = 1;
                    }
                    $saleLogModel->update($saleLogData, ['id' => $saleLog['id']]);
                }
                $action->save(['callback_status' => 1]);
                $lock->unlockParams($lockParams);
            }
            Db::commit();
        } catch (Exception $e) {
            $lock->unlockParams($lockParams);
            Db::rollback();
            throw $e;
        }
    }


    /**
     * 定时上下架库存数据的回调！！！；
     * (因为是在回调函数里调执的，所以是不支持跳转)
     * @param $action \app\common\model\amazon\AmazonActionLog
     */
    public function backUpLowerPriceAndQuantity($action)
    {
        /** @var  $lock \app\common\cache\driver\Lock */
        $lock = Cache::store('Lock');
        //1.加锁，失败则证明重了，需要下次处理；
        $params = empty($action['callback_param']) ? [] : json_decode($action['callback_param'], true);
        //没有回调参数，则停止
        if (empty($params['heel_id'])) {
            $action->save(['callback_status' => 1]);
            return;
        }

        $lockParams = ['action' => 'amazon_heel_up_lower_quantity', 'type' => $action['type'], 'account_id' => $action['account_id']];
        //$lock->unlockParams($lockParams);
        //此处使用唯一锁,锁住120秒，足够完成所有查询了；
        try {
            Db::startTrans();
            if ($lock->lockParams($lockParams, true, 20)) {
                //查询heel-log,无数据停止执行;
                $saleLogModel = new AmazonheelSaleLogModel();
                $saleLog = $saleLogModel->where(['id' => $params['heel_id']])->find();
                if (empty($saleLog)) {
                    $lock->unlockParams($lockParams);
                    $action->save(['callback_status' => 1]);
                    Db::commit();
                    return;
                }

                $time = time();
                //要修改的数据；
                $saleLogData = [];
                $saleLogData['last_request_time'] = $time;
                //saleLog,status: 0未抓取结果,1已抓取,2意外失败,3上传中;4:取消
                $field = ($action['type'] == 2) ? 'price_status' : 'quantity_status';
                $field2 = ($action['type'] == 2) ? 'quantity_status' : 'price_status';
                $saleLogData[$field] = $action['status'];

                //错误信息；
                if ($action['status'] == 2) {
                    //产品上传失败
                    $saleLogData['status'] = $action['status'];
                    $saleLogData['error_desc'] = $action['message'];
                    $saleLog->save($saleLogData);
                } else if ($action['status'] == 1) {
                    //更新listing数据；
                    $upField = ($action['type'] == 2) ? 'price' : 'quantity';
                    $newData = is_array($action['new_value']) ? $action['new_value'] : json_decode($action['new_value'], true);
                    if (isset($newData[$upField])) {
                        $saleLogData[$upField] = $newData[$upField];
                        AmazonListing::update([$upField => $newData[$upField], 'modify_time' => $time], ['id' => $saleLog['listing_id']]);
                    }

                    //产品上下传成功；
                    if ($saleLog['product_status'] == 1 && $saleLog[$field2] == 1) {
                        if ($upField == 'quantity') {
                            if (isset($newData[$upField]) && $newData[$upField] == 0) {
                                $saleLogData['status'] = 4;
                            } else {
                                $saleLogData['status'] = 1;
                            }
                            $saleLogData['upper_request_time'] = $time;
                        }
                        if ($upField == 'price') {
                            $saleLogData['is_sync'] = 1;
                        }
                    }
                    $saleLogModel->update($saleLogData, ['id' => $saleLog['id']]);
                }
                $action->save(['callback_status' => 1]);
                $lock->unlockParams($lockParams);
            }
            Db::commit();
        } catch (Exception $e) {
            $lock->unlockParams($lockParams);
            Db::rollback();
            throw $e;
        }
    }


    /**
     * 添加跟卖开启定时上架
     */
    public function heelSaleOpen($rules, $id)
    {
        foreach ($rules as $key => $val) {

            $start_time = $val['start_time'];
            $end_time = $val['end_time'];

            //开始/结束时间的年月
            $startYm = date('Ym', $start_time);
            $endYm = date('Ym', $end_time);

            //开始/结束时间的天
            $startDay = date('d', $start_time);
            $endDay = date('d', $end_time);

            //如果是开始时间和结束时间为同个月
            if ($startYm == $endYm) {

                for ($i = $startDay; $i <= $endDay; $i++) {

                    $dayTime = date($startYm . $i);
                    $weekTime = date('w', strtotime($dayTime));

                    //周一到周天
                    if (in_array($weekTime, [0, 1, 2, 3, 4, 5, 6])) {

                        $up_lower_time = self::$up_lower_time[$weekTime];
                        if (isset($val["{$up_lower_time}"]) && $val["{$up_lower_time}"]) {
                            $this->heelSaleUpPush($val["{$up_lower_time}"], $dayTime, $id);
                            break;
                        }
                    }
                }

            } else {

                //同年
                //开始时间
                $startYear = date('Y', $start_time);
                //截止时间
                $endYear = date('Y', $end_time);

                if ($startYear == $endYear) {

                    //开始时间月
                    $startMonth = date('m', $start_time);

                    //开始月份的开始天
                    $startDay = date('d', $start_time);
                    //截止月份的最后一天
                    $endDay = date('d', $end_time);

                    $this->heelSaleUpLowerValidTime($startMonth, $startYear, $startDay, $endDay, $val, $id);
                } else {

                    //不同年:开始年
                    //开始月份的开始天
                    $startDay = date('d', $start_time);

                    //开始月份的最后一天
                    $startEnd = date($startYear . '-12');
                    $endDay = date('d', strtotime("$startEnd +1 month -1 day"));
                    //开始时间月
                    $startMonth = date('m', $start_time);

                    $this->heelSaleUpLowerValidTime($startMonth, $startYear, $startDay, $endDay, $val, $id);

                }
            }
        }
    }

    /**
     *添加跟卖定时上下架开启 有效时间计算
     *
     */
    public function heelSaleUpLowerValidTime($startMonth, $startYear, $startDay, $endDay, $val, $id)
    {

        $firstday = date($startYear . '-' . $startMonth . '-' . '1');
        $lastDay = date('d', strtotime("$firstday +1 month -1 day"));

        for ($m = $startDay; $m <= $lastDay; $m++) {

            $dayTime = date($startYear . $startMonth . $m);
            $weekTime = date('w', strtotime($dayTime));

            //周一到周天
            if (in_array($weekTime, [0, 1, 2, 3, 4, 5, 6])) {

                $up_lower_time = self::$up_lower_time[$weekTime];
                if (isset($val["{$up_lower_time}"]) && $val["{$up_lower_time}"]) {
                    $this->heelSaleUpPush($val["{$up_lower_time}"], $dayTime, $id);
                    break;
                }
            }
        }
    }


    /**
     *添加跟卖定时上架加入消息队列
     *
     */
    protected function heelSaleUpPush($up_lower, $dayTime, $id)
    {
        $up_lower = \GuzzleHttp\json_decode($up_lower, true);

        if ($up_lower['up_lower_tme']) {
            foreach ($up_lower['up_lower_tme'] as $upLowerVal) {

                //定时上架
                if (isset($upLowerVal['up_time']) && $upLowerVal['up_time']) {
                    if (strpos($upLowerVal['up_time'], ':') !== false) {
                        $upOpen = strtotime(date($dayTime . $upLowerVal['up_time'] . ':00'));
                    } else {
                        $upOpen = strtotime(date($dayTime . $upLowerVal['up_time']));
                    }

                    //加入定时上架消息队列
                    (new UniqueQueuer(AmazonHeelSaleQueuer::class))->push($id, $upOpen);
                }

                break;
            }
        }

    }


    /**
     * 亚马逊跟卖列表
     * @param
     */
    public function heelSaleList($post, $page, $pageSize)
    {
        $where = [];

        //asin,本地sku,本地spu
        if (isset($post['search_type']) && $post['search_type'] && $post['search_content']) {

            $searchContent = $post['search_content'];

            if (is_string($searchContent)) {
                $searchContent = \GuzzleHttp\json_decode($searchContent, true);
                $searchContent = implode(',', $searchContent);
            }

            //asin
            if ($post['search_type'] == 1) {
                $where['m.asin'] = ['in', $searchContent];
            }

            //本地sku
            if ($post['search_type'] == 2) {
                $where['a.sku'] = ['in', $searchContent];
            }

            //本地spu
            if ($post['search_type'] == 3) {
                $where['a.spu'] = ['in', $searchContent];
            }

            //平台sku
            if ($post['search_type'] == 4) {
                $where['m.sku'] = ['in', $searchContent];
            }
        }

        //账号简称
        if (isset($post['account_id']) && $post['account_id']) {
            $where['m.account_id'] = ['=', $post['account_id']];
        }

        //上传状态
        if (isset($post['status']) && is_numeric($post['status'])) {
            $where['m.status'] = ['=', $post['status']];
        }

        //创建时间
        //开始
        if (isset($post['star_time']) && isset($post['end_time'])) {

            //开始时间,截止时间都存在
            if ($post['star_time'] && $post['end_time']) {
                $where['m.created_time'] = ['BETWEEN TIME', [strtotime($post['star_time']), strtotime($post['end_time'] . ' 23:59:59')]];

            } elseif ($post['star_time']) {//开始时间
                $star = strtotime($post['star_time']);
                $where['m.created_time'] = ['>=', $star];

            } elseif ($post['end_time']) {//截止时间
                $end = strtotime($post['end_time']);

                $where['m.created_time'] = ['<=', $end];
            }

        }


        //创建人
        if (isset($post['saler_id']) && $post['saler_id']) {
            $where['m.create_id'] = ['=', $post['saler_id']];
        }

        //定时规则id
        $ruleModel = new AmazonUpLowerFrameRule;

        //定时规则
        if (isset($post['rule_name']) && $post['rule_name']) {
            $ruleIds = $ruleModel->field('id')->where(['rule_name' => ['like', '%' . $post['rule_name'] . '%']])->select();

            if (!$ruleIds) {
                return ['data' => [], 'count' => 0, 'page' => $page, 'pageSize' => $pageSize];
            }

            $ruleIds = array_column($ruleIds, 'id');
            $where['m.rule_id'] = ['in', $ruleIds];
        }

        //用户名
        $where['m.type'] = ['=', 1];

        $model = new AmazonheelSaleLogModel;
        $ruleModel = new AmazonUpLowerFrameRule;

        $count = $model->alias('m')->where($where)->count('m.id');

        $data = $model->alias('m')
            ->order('id desc,status')
            ->field('distinct m.id,m.asin,m.account_id,m.price,m.quantity,m.status,m.create_id,m.sku publish_sku,m.created_time,m.error_desc,u.realname,m.lowest_price,m.modify_price_type,m.modify_price,m.rule_id,m.last_request_time, m.upper_request_time')
            ->with(['account' => function ($query) {
                $query->field('id,account_name');
            }])
            ->join('user u', 'm.create_id = u.id', 'LEFT')
            ->join('amazon_listing a', 'a.seller_sku = m.sku', 'LEFT')
            ->order('m.id desc')
            ->where($where)
            ->page($page, $pageSize)
            ->select();

        if ($data) {
            $data = \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($data), true);

            foreach ($data as $key => $val) {
                $data[$key]['created_time'] = $val['created_time'] ? date('Y/m/d H:i:s', $val['created_time']) : '';
                $data[$key]['last_request_time'] = $val['last_request_time'] ? date('Y/m/d H:i:s', $val['last_request_time']) : '';
                $data[$key]['upper_request_time'] = $val['upper_request_time'] ? date('Y/m/d H:i:s', $val['upper_request_time']) : '';

                //调价类型:0:无;1:百分比调价;2:金额调价

                if ($val['modify_price_type']) {

                    $modify_price_type = $val['modify_price_type'];
                    $modify_price = $val['modify_price'];

                    $modify_price = $modify_price_type == 1 ? $modify_price . '%' : $modify_price;

                    $data[$key]['modify_price'] = $modify_price;
                }


                $data[$key]['rule_name'] = '';
                //有定时上下架规则
                if ($val['rule_id']) {

                    $rule_name = $ruleModel->field('rule_name')->where(['id' => $val['rule_id']])->find()['rule_name'];
                    $data[$key]['rule_name'] = $rule_name;
                }
            }
        }

        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];
    }


    /**
     *定时上下架添加/编辑规则
     */
    public function addUpLowerRule(array $data, $lang)
    {
        try {
            $data['start_time'] = strtotime($data['start_time']);
            $data['end_time'] = strtotime($data['end_time']);

            $upLowerModel = new AmazonUpLowerFrameRuleModel;
            $ruleDetailModel = new AmazonUpLowerFrameRuleDetail();

            $checkRule = $this->checkUpLowerRule($data, $lang, $upLowerModel);
            if (isset($checkRule['status']) && $checkRule['status'] < 0) {
                return $checkRule;
            }

            //编辑
            if (!empty($data['id'])) {
                //规则再引用,同时规则的截止时间大于当前时间,则为引用中.
                $checkRuleUse = $this->checkRuleUse($data['id'], $lang, $upLowerModel);
                if (isset($checkRuleUse['status']) && $checkRuleUse['status'] < 0) {
                    return $checkRuleUse;
                }
                $oldDetails = $ruleDetailModel->where(['rid' => $data['id']])->order('id', 'asc')->select();
                $details = $this->buildRuleDetail($data);

                $data['updated_time'] = time();
                $id = $data['id'];
                unset($data['id'], $data['create_id']);

                try {
                    Db::startTrans();
                    $upLowerModel->update($data, ['id' => $id]);
                    foreach ($details as $key=>$val) {
                        $old = $oldDetails[$key] ?? [];
                        if (empty($old['id'])) {
                            $val['rid'] = $id;
                            $ruleDetailModel->insert($val);
                        } else {
                            $ruleDetailModel->update($val, ['id' => $old['id']]);
                            unset($oldDetails[$key]);
                        }
                    }
                    foreach ($oldDetails as $val) {
                        $ruleDetailModel->where(['id' => $val['id']])->delete();
                    }
                    Db::commit();
                } catch (Exception $e) {
                    Db::rollBack();
                    throw $e;
                }
            } else {//添加
                $data['created_time'] = time();
                $details = $this->buildRuleDetail($data);
                try {
                    Db::startTrans();
                    $id = $upLowerModel->insertGetId($data);
                    foreach ($details as $key=>$val) {
                        $val['rid'] = $id;
                        $ruleDetailModel->insert($val);
                    }
                    Db::commit();
                } catch (Exception $e) {
                    Db::rollback();
                    throw $e;
                }
            }

            $message = isset($id) && $id && $lang == 'zh' ? '操作成功' : 'operation failed';
            return ['message' => $message, 'status' => 0];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    public function buildRuleDetail($data) :array
    {
        $week = ['monday_up_lower' => 1, 'tuesday_up_lower' => 2, 'wednesday_up_lower' => 3, 'thursday_up_lower' => 4, 'friday_up_lower' => 5, 'saturday_up_lower' => 6, 'sunday_up_lower' => 0];
        $details = [];
        foreach ($week as $key=>$val) {
            $day = empty($data[$key]) ? [] : json_decode($data[$key], true);
            if (empty($day) || empty($day['up_lower_tme'])) {
                continue;
            }
            $check = $day['is_check'] ?? 0;
            foreach ($day['up_lower_tme'] as $up_lower_tme) {
                $tmp1 = [
                    'action' => 1,
                    'time' => (strtotime($up_lower_tme['up_time'] ?? '00:00') + 8 * 3600) % 86400 + $val * 86400,
                    'status' => $check
                ];
                $tmp2 = [
                    'action' => 2,
                    'time' => (strtotime($up_lower_tme['lower_time'] ?? '00:00') + 8 * 3600) % 86400 + $val * 86400,
                    'status' => $check
                ];
                array_push($details, $tmp1, $tmp2);
            }
        }

        return $details;
    }


    /**
     * 检查添加/编辑规则
     */
    public function checkUpLowerRule($data, $lang, $upLowerModel)
    {

        $time = time();

        if ($data['start_time'] < strtotime(date('Y-m-d'))) {
            //$message = $lang == 'zh' ? '有效开始时间不能小于当前时间' : 'Effective start time should not be less than the current time';
            //return ['message' => $message, 'status' => -1];
        }

        if ($data['end_time'] < $time) {
            //$message = $lang == 'zh' ? '有效截止时间不能小于当前时间' : 'Effective deadline should not be less than the current time';
            //return ['message' => $message, 'status' => -1];
        }


        if ($data['end_time'] < $data['start_time']) {
            $message = $lang == 'zh' ? '有效截止时间不能小于开始时间' : 'The effective deadline should not be less than the start time';
            return ['message' => $message, 'status' => -1];
        }

        //1.检测是否已经有相同的规则名称
        $where = ['rule_name' => ['=', $data['rule_name']]];

        if (isset($data['id']) && $data['id']) {
            $where['id'] = ['not in', $data['id']];
        }

        $checkRuleName = $upLowerModel->field('id')->where($where)->find();
        if ($checkRuleName) {
            $message = $lang == 'zh' ? '规则名称已经存在' : 'Rule name already exists';
            return ['message' => $message, 'status' => -1];
        }

        $up_lower_time = self::$up_lower_time;
        $is_up_lower = false;
        $i = 0;
        $j = 0;
        $is_up_lower_time = false;


        //2.检测上架时间是否有效
        foreach ($up_lower_time as $val) {
            if (isset($data[$val]) && $data[$val]) {


                $is_up_lower = $this->upLowerTime($data[$val]);
                if ($is_up_lower) {
                    $i++;
                    if ($i == count($up_lower_time)) {
                        $is_up_lower = true;
                    }
                }

                $upLowerTme = \GuzzleHttp\json_decode($data[$val], true)['up_lower_tme'];
                if (empty(count($upLowerTme))) {
                    $j++;
                    if ($j == count($up_lower_time)) {
                        $is_up_lower_time = true;
                    }
                }
            }
        }


        if ($is_up_lower) {
            $message = $lang == 'zh' ? '请选择星期' : 'Please choose a week.';
            return ['message' => $message, 'status' => -1];
        }

        if ($is_up_lower_time) {
            $message = $lang == 'zh' ? '请选择上架时间' : 'Please choose the shelf time.k';
            return ['message' => $message, 'status' => -1];
        }

        return true;
    }


    /**
     * 检查规则是否再引用中,并且有效
     */
    public function checkRuleUse($id, $lang, $upLowerModel, $type = 'edit')
    {
        return true;
        $heelSaleLogModel = new AmazonHeelSaleLog();

        $id = is_array($id) ? $id : [$id];
        $heelSaleLog = $heelSaleLogModel->field('id')->whereIn('rule_id', $id)->whereNotIn('status', [2])->find();
        if (!$heelSaleLog) {
            return true;
        }

        //检查规则截止时间是否小于当前时间
        $upLowerEndTime = $upLowerModel->field('end_time,rule_name')->whereIn('id', $id)->find();
        if (time() >= $upLowerEndTime['end_time']) {
            return true;
        }

        $type = $type == 'edit' ? ($lang == 'zh' ? '编辑' : 'edit') : ($lang == 'zh' ? '删除' : 'delete');

        $message = $upLowerEndTime['rule_name'] . '被引用，不可' . $type;
        if ($lang == 'en') {
            $message = 'If the rule is referenced by one or N takeover data, prompt' . $upLowerEndTime['rule_name'] . 'Not to be quoted' . $type;
        }

        return ['message' => $upLowerEndTime['rule_name'] . '被引用，不可' . $type, 'status' => -1];
    }


    /**
     *亚马逊定时上下架规则列表
     * @param
     */
    public function upLowerRuleList($post, $page, $pageSize)
    {

        try {
            $where = ['is_delete' => ['=', 0]];

            //规则名称
            if (isset($post['rule_name']) && $post['rule_name']) {
                $where['m.rule_name'] = ['like', '%' . trim($post['rule_name']) . '%'];
            }

            //查询开启状态
            if (isset($post['status']) && empty($post['status'])) {
                $where['m.status'] = ['=', 0];
            }

            $model = new AmazonUpLowerFrameRuleModel;
            $count = $model->alias('m')->where($where)->count('m.id');

            $data = $model->alias('m')
                ->order('created_time desc')
                ->field('m.id, m.rule_name, FROM_UNIXTIME(m.start_time,"%Y-%c-%d") as start_time, FROM_UNIXTIME(m.end_time,"%Y-%c-%d") as end_time, m.status, FROM_UNIXTIME(m.created_time,"%Y-%c-%d") as created_time, u.realname username, m.monday_up_lower, m.tuesday_up_lower, m.wednesday_up_lower, m.thursday_up_lower, m.friday_up_lower, m.saturday_up_lower, m.sunday_up_lower')
                ->join('user u', 'm.create_id = u.id', 'LEFT')
                ->where($where)
                ->page($page, $pageSize)
                ->select();

            return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *亚马逊定时上下架状态修改
     *
     */
    public function upLowerRuleStatus(array $post, $lang)
    {
        try {
            $model = new AmazonUpLowerFrameRuleModel;
            $id = $model->update(['status' => $post['status']], ['id' => $post['id']]);

            if (!$id) {
                return ['message' => ($lang == 'zh' ? '状态修改失败' : 'Failure of state modification')];
            }


            //修改定时上下架状态

            //status:0开启/1关闭
            $is_up_open = $post['status'] == 1 ? 1 : 0;

            $amazonListModel = new AmazonListing();
            $listingId = $amazonListModel->field('id')->where(['rule_id' => $post['id']])->find();

            if ($listingId) {
                $listingId = $listingId->toArray()['id'];

                $upOpenLogModel = new AmazonUpOpenLog();
                $data = ['is_up_open' => $is_up_open];

                if (empty($is_up_open)) {

                    $time = time();
                    $data['up_open_time'] = ['>=', $time];
                }

                $upOpenLogModel->update($data, ['listing_id' => $listingId]);
            }

            return ['message' => ($lang == 'zh' ? '状态修改成功' : 'Successful state modification')];
        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *亚马逊定时上下架规则删除
     *
     */
    public function upLowerRuleDelete($ids, $lang)
    {
        try {

            $model = new AmazonUpLowerFrameRuleModel;
            //规则再引用,同时规则的截止时间大于当前时间,则为引用中.
            $checkRuleUse = $this->checkRuleUse($ids, $lang, $model, 'del');
            if (isset($checkRuleUse['status']) && $checkRuleUse['status'] < 0) {
                return $checkRuleUse;
            }

            $id = $model->update(['is_delete' => 1], ['id' => ['in', $ids]]);

            if (!$id) {
                return ['message' => ($lang == 'zh' ? '删除失败' : 'Delete failed'), 'status' => -1];
            }

            $amazonListModel = new AmazonListing();
            $listingId = $amazonListModel->field('id')->where(['rule_id' => ['in', $ids]])->select();

            if ($listingId) {

                $ids = array_map(function ($val) {
                    return $val['id'];
                }, $listingId);

                $upOpenLogModel = new AmazonUpOpenLog();
                $upOpenLogModel->whereIn('listing_id', $ids)->delete();
            }

            return ['message' => ($lang == 'zh' ? '删除成功' : 'Delete successful'), 'status' => 0];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *定时上架规则详情
     *
     */
    public function upLowerRuleDetail($id)
    {
        try {
            $model = new AmazonUpLowerFrameRuleModel;

            $data = $model->alias('m')
                ->field('m.id, m.rule_name, FROM_UNIXTIME(m.start_time,"%Y-%c-%d") as start_time, FROM_UNIXTIME(m.end_time,"%Y-%c-%d") as end_time, m.status, m.monday_up_lower,m.tuesday_up_lower,m.wednesday_up_lower,m.thursday_up_lower,m.friday_up_lower,m.saturday_up_lower,m.sunday_up_lower')
                ->where('id', '=', $id)
                ->find();

            return $data;

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     * 定时开启上下架
     *
     */
    public function upLowerOpen($ids, $rule_id, $lang = 'zh')
    {
        try {
            $ids = \GuzzleHttp\json_decode($ids, true);

            $model = new AmazonHeelSaleLog();
            $heelIds = $model->where(['listing_id' => ['in', $ids]])->column('id');
            if (empty($heelIds)) {
                $message = $lang == 'zh' ? '请选择定时上下架的跟卖的listing' : 'Please select the listing for the timing of the upper and lower shelves.';
                return ['message' => $message, 'status' => false];
            }

            $model->update(['rule_id' => $rule_id], ['id' => ['in', $heelIds]]);
            return ['message' => '', 'status' => true];

            //---------------新改了上下架规则，不再需要走下面的流程；


            $where = ['id' => ['in', $ids]];
            //亚马逊 list
            $model = new AmazonListingModel();
            $amazonList = $model->alias('a')->field('id,seller_sku,rule_id,is_up_lower')->where($where)->select();
            $amazonList = $this->objectToArray($amazonList);

            if (empty($amazonList)) {
                $message = $lang == 'zh' ? '请选择定时上下架的listing' : 'Please select the listing for the timing of the upper and lower shelves.';
                return ['message' => $message, 'status' => false];
            }


            $ruleId = $rule_id;
            if (empty($ruleId)) {
                $message = $lang == 'zh' ? '请先选择定时上下架规则' : 'Choose the Timing Up and Down Rules first';
                return ['message' => $message, 'status' => false];
            }

            $rules = $this->upLowerRuleInfo($ruleId);

            if (empty($rules)) {
                $message = $lang == 'zh' ? '请选择规则日期' : 'Please select the rule date';
                return ['message' => $message, 'status' => false];
            }

            //跟卖规则中有效的开启时间
            //1.检查上下架规则是否选中,没有选中,则去除

            //更新是否定时上下架
            foreach ($amazonList as $val) {
                $model->update(['is_up_lower' => 1, 'rule_id' => $ruleId], ['id' => $val['id']]);
            }

            foreach ($rules as $key => $val) {

                $start_time = $val['start_time'];
                $end_time = $val['end_time'];

                //开始/结束时间的年月
                $startYm = date('Ym', $start_time);
                $endYm = date('Ym', $end_time);

                //开始/结束时间的天
                $startDay = date('j', $start_time);
                $endDay = date('j', $end_time);

                //如果是开始时间和结束时间为同个月
                if ($startYm == $endYm) {

                    for ($i = $startDay; $i <= $endDay; $i++) {

                        $dayTime = date($startYm . ($i < 10 ? '0' . $i : $i));
                        $weekTime = date('w', strtotime($dayTime));

                        $this->daysWeek($weekTime, $val, $dayTime, $amazonList);
                    }

                } else {

                    //同年
                    //开始时间
                    $startYear = date('Y', $start_time);
                    //截止时间
                    $endYear = date('Y', $end_time);

                    if ($startYear == $endYear) {

                        //开始时间月
                        $startMonth = date('m', $start_time);
                        //截止时间月
                        $endMonth = date('m', $end_time);

                        //开始月份的开始天
                        $startDay = date('j', $start_time);
                        //截止月份的最后一天
                        $endDay = date('j', $end_time);

                        $this->upLowerValidTime($startMonth, $endMonth, $startYear, $startDay, $endDay, $val, $amazonList);
                    } else {
                        //不同年

                        //开始年
                        //开始月份的开始天
                        $startDay = date('d', $start_time);

                        //开始月份的最后一天
                        $startEnd = date($startYear . '-12');
                        $endDay = date('j', strtotime("$startEnd +1 month -1 day"));
                        //开始时间月
                        $startMonth = date('m', $start_time);
                        $endMonth = 12;

                        $this->upLowerValidTime($startMonth, $endMonth, $startYear, $startDay, $endDay, $val, $amazonList);


                        //截止年
                        $startMonth = 1;
                        $endMonth = date('m', $end_time);
                        $startDay = 1;

                        //截止月份的最后一天
                        $endDay = date('j', $end_time);
                        $this->upLowerValidTime($startMonth, $endMonth, $endYear, $startDay, $endDay, $val, $amazonList);
                    }
                }
            }

            return ['message' => '', 'status' => true];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }

    /**
     *定时上下架开启 有效时间计算
     *
     */
    public function upLowerValidTime($startMonth, $endMonth, $startYear, $startDay, $endDay, $val, $amazonList)
    {
        //开始月到截止的倒数第二个月
        for ($j = $startMonth; $j <= $endMonth; $j++) {

            $firstday = date($startYear . '-' . $j . '-' . '1');

            $lastDay = date('d', strtotime("$firstday +1 month -1 day"));

            //如果开始天大于每个月的第一天,则已开始天为准
            $firstday = $j == $startMonth ? $startDay : 1;

            //如果不是月份最后一天,则以本次为截止时间
            $lastDay = $endMonth == $j ? $endDay : $lastDay;

            for ($m = $firstday; $m <= $lastDay; $m++) {

                $dayTime = date($startYear . '-' . $j . '-' . ($m < 10 ? '0' . $m : $m));
                $weekTime = date('w', strtotime($dayTime));

                $this->daysWeek($weekTime, $val, $dayTime, $amazonList);
            }
        }
    }


    /**
     *根据规则id查询开启的规则
     *
     */
    public function upLowerRuleInfo($ruleId)
    {
        //跟卖规则
        $where = [
            'id' => ['in', $ruleId],
            'status' => 0,
            'is_delete' => 0
        ];

        $ruleModel = new AmazonUpLowerFrameRuleModel();
        $rules = $ruleModel->alias('a')->field('id, start_time, end_time, monday_up_lower, tuesday_up_lower, wednesday_up_lower, thursday_up_lower, friday_up_lower, saturday_up_lower, sunday_up_lower')->where($where)->select();

        $rules = $this->objectToArray($rules);

        foreach ($rules as $key => $val) {

            //星期一
            if ($val['monday_up_lower'] && $this->upLowerTime($val['monday_up_lower'])) {
                unset($rules[$key]['monday_up_lower']);
            }

            //星期二
            if ($val['tuesday_up_lower'] && $this->upLowerTime($val['tuesday_up_lower'])) {
                unset($rules[$key]['tuesday_up_lower']);
            }
            //星期三
            if ($val['wednesday_up_lower'] && $this->upLowerTime($val['wednesday_up_lower'])) {
                unset($rules[$key]['wednesday_up_lower']);
            }

            //星期四
            if ($val['thursday_up_lower'] && $this->upLowerTime($val['thursday_up_lower'])) {
                unset($rules[$key]['thursday_up_lower']);
            }

            //星期五
            if ($val['friday_up_lower'] && $this->upLowerTime($val['friday_up_lower'])) {
                unset($rules[$key]['friday_up_lower']);
            }

            //星期六
            if ($val['saturday_up_lower'] && $this->upLowerTime($val['saturday_up_lower'])) {
                unset($rules[$key]['saturday_up_lower']);
            }

            //星期日
            if ($val['sunday_up_lower'] && $this->upLowerTime($val['sunday_up_lower'])) {
                unset($rules[$key]['sunday_up_lower']);
            }
        }

        $time = strtotime(date('Y-m-d'));
        //检查是否有选择日期
        $is_rules = [];
        if ($rules) {
            foreach ($rules as $key => $val) {

                //如果开始时间和截止时间都小于当前时间,则跳出循环
                if ($val['start_time'] < $time && $val['end_time'] < $time) {
                    break;
                }

                //如果开始时间小于当前时间,则以当前时间计算
                if ($val['start_time'] < $time) {
                    $rules[$key]['start_time'] = $time;
                }
                foreach (array_keys($val) as $v) {
                    if (in_array($v, self::$up_lower_time)) {
                        $is_rules = $val;
                    }
                }
            }
        }

        return $is_rules ? $rules : [];
    }


    /**
     *跟卖规则中有效的开启时间
     *
     */
    protected function upLowerTime($up_lower_time)
    {
        $up_lower_time = \GuzzleHttp\json_decode($up_lower_time, true);

        if (isset($up_lower_time['is_check']) && $up_lower_time['is_check'] == 0) {
            return true;
        }

        return false;
    }


    /**
     *对象转数组
     *
     */
    protected function objectToArray($object)
    {
        return \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($object), true);
    }


    /**
     *定时上下架加入消息队列
     *
     */
    protected function addupLowerPush($up_lower, $dayTime, $amazonList)
    {
        $up_lower = \GuzzleHttp\json_decode($up_lower, true);
        if ($up_lower['up_lower_tme']) {
            foreach ($up_lower['up_lower_tme'] as $upLowerVal) {

                //定时上架
                if (isset($upLowerVal['up_time']) && $upLowerVal['up_time']) {

                    dump($dayTime);
                    if (strpos($upLowerVal['up_time'], ':') !== false) {
                        $upOpen = strtotime(date($dayTime . ' ' . $upLowerVal['up_time'] . ':00'));
                    } else {
                        $upOpen = strtotime(date($dayTime . ' ' . $upLowerVal['up_time']));
                    }

                    //当前时间小于定时上架时间,则写入队列
                    if ($upOpen > time()) {
                        foreach ($amazonList as $amazon) {

                            $seller_status = self::$up_type;
                            $up_open_time = $upOpen;

                            $data = [
                                'listing_id' => $amazon['id'],
                                'seller_sku' => $amazon['seller_sku'],
                                'up_open_time' => $up_open_time,
                                'seller_status' => $seller_status

                            ];


                            //添加进入到上下架日志队列
                            (new UniqueQueuer(AmazonAddUpOpenLogQueuer::class))->push($data);
                        }
                    }
                }


                //定时下架
                if (isset($upLowerVal['lower_time']) && $upLowerVal['lower_time']) {


                    if (strpos($upLowerVal['lower_time'], ':') !== false) {
                        $upClose = strtotime(date($dayTime . ' ' . $upLowerVal['lower_time'] . ':00'));
                    } else {
                        $upClose = strtotime(date($dayTime . ' ' . $upLowerVal['lower_time']));
                    }

                    //检查开始时间大于下架时间,则下架时间需加1天
                    if (isset($upOpen) && $upOpen > $upClose) {
                        $upClose = strtotime(date('Y-m-d H:i:s', strtotime('+1 day', $upClose)));
                    }

                    //加入定时下架消息队列
                    foreach ($amazonList as $amazon) {

                        $seller_status = self::$lower_type;
                        $up_open_time = $upClose;

                        $data = [
                            'listing_id' => $amazon['id'],
                            'seller_sku' => $amazon['seller_sku'],
                            'up_open_time' => $up_open_time,
                            'seller_status' => $seller_status

                        ];

                        //添加进入到上下架日志队列
                        (new UniqueQueuer(AmazonAddUpOpenLogQueuer::class))->push($data);
                    }

                }
            }
        }
    }


    /**
     * @param $amazon
     * @param $seller_status
     * @param $up_open_time
     * 添加定时上下架日志
     */
    public function addUpOpenLog($params)
    {
        //首先检测是否存在log,没有,则写入数据
        $logModel = new  AmazonUpOpenLogModel;

        $data = [
            'listing_id' => $params['listing_id'],
            'seller_sku' => $params['seller_sku'],
            'up_open_time' => $params['up_open_time']
        ];

        if (!$upOpenLog = $logModel->where($data)->find()) {

            $data['seller_status'] = $params['seller_status'];
            $data['created_time'] = time();

            $logModel->insertGetId($data);
        }
    }


    protected function daysWeek($weekTime, $val, $dayTime, $amazonList)
    {
        //周一到周天
        if (in_array($weekTime, [0, 1, 2, 3, 4, 5, 6])) {

            $up_lower_time = self::$up_lower_time[$weekTime];
            if (isset($val["{$up_lower_time}"]) && $val["{$up_lower_time}"]) {
                $this->addupLowerPush($val["{$up_lower_time}"], $dayTime, $amazonList);
            }
        }
    }


    /**
     *速卖通跟跟卖投诉管理列表
     *
     */
    public function heelSaleComplain($post, $page, $pageSize)
    {
        try {

            $where = ['is_delete' => 0];

            //asin,平台sku
            if (isset($post['search_type']) && $post['search_type'] && $post['search_content']) {

                $search_content = trim($post['search_content']);
                $search_content = implode(',', \GuzzleHttp\json_decode($search_content, true));

                //asin
                if ($post['search_type'] == 1) {
                    $where['c.asin'] = ['in', $search_content];
                }

                //平台sku
                if ($post['search_type'] == 2) {
                    $where['c.sku'] = ['in', $search_content];
                }
            }

            //账号简称,销售员
            if (isset($post['account_person_type']) && $post['account_person_type'] && $post['account_person_content']) {

                $account_person_content = trim($post['account_person_content']);

                //账号简称
                if ($post['account_person_type'] == 1) {

                    $AmazonAccountModel = new AmazonAccountModel;
                    $accounts = $AmazonAccountModel->where('code', 'like', '%' . $account_person_content . '%')->field('id')->select();

                    if (!$accounts) {
                        return ['data' => [], 'count' => 0, 'page' => $page, 'pageSize' => $pageSize];
                    }

                    $accounts = $this->objectToArray($accounts);
                    $accountIds = array_map(function ($val) {
                        return $val['id'];
                    }, $accounts);

                    $accountIds = implode(',', $accountIds);
                    $where['c.account_id'] = ['in', $accountIds];

                }

                //销售员
                if ($post['account_person_type'] == 2) {

                    $UserModel = new UserModel;
                    $users = $UserModel->where('realname', 'like', '%' . $account_person_content . '%')->field('id')->select();
                    if (!$users) {
                        return ['data' => [], 'count' => 0, 'page' => $page, 'pageSize' => $pageSize];
                    }

                    //转为数组
                    $users = $this->objectToArray($users);
                    $userIds = array_map(function ($val) {
                        return $val['id'];
                    }, $users);
                    $userIds = implode(',', $userIds);
                    $where['c.salesperson_id'] = ['in', $userIds];

                }
            }

            //处理状态 :0:全部; 1:处理;2:未处理
            if (isset($post['status']) && $post['status']) {

                //处理
                if ($post['status'] == 1) {
                    $where['c.status'] = ['=', 1];
                }

                //未处理
                if ($post['status'] == 2) {
                    $where['c.status'] = ['=', 0];
                }
            }

            //是否下架 0:全部; 1:跟卖;2:未跟卖
            if (isset($post['is_heel_sale']) && $post['is_heel_sale']) {

                //跟卖
                if ($post['is_heel_sale'] == 1) {
                    $where['c.is_heel_sale'] = ['=', 1];
                }

                //未跟卖
                if ($post['is_heel_sale'] == 2) {
                    $where['c.is_heel_sale'] = ['=', 0];
                }
            }


            //time_type 1:跟卖时间;2:处理时间
            $end_time = '';
            $update_time = '';
            if (isset($post['time_type']) && isset($post['star_time']) && isset($post['end_time'])) {

                //开始时间,截止时间都存在
                if ($post['star_time'] && $post['end_time']) {
                    $update_time = ['BETWEEN TIME', [strtotime($post['star_time']), strtotime($post['end_time'] . ' 23:59:59')]];

                } elseif ($post['star_time']) {//开始时间
                    $star = strtotime($post['star_time']);
                    $update_time = ['>=', $star];

                } elseif ($post['end_time']) {//截止时间

                    $end = strtotime($post['end_time']);
                    $update_time = ['<=', $end];

                    $end_time = $post['time_type'] == 1 ? ['heel_sale_time' => ['>', 0]] : ['c.update_time' => ['>', 0]];
                }

                //1:跟卖时间;
                if ($post['time_type'] == 1 && $update_time) {

                    $sellerModel = new AmazonSellerHeelSaleModel;
                    $heelSaleTimes = $sellerModel->field('heel_sale_complain_id')->where(['heel_sale_time' => $update_time])->where($end_time)->select();

                    if (!$heelSaleTimes) {
                        return ['data' => [], 'count' => 0, 'page' => $page, 'pageSize' => $pageSize];
                    }

                    $heelSaleComplainIds = array_column($heelSaleTimes, 'heel_sale_complain_id');

                    $where['c.id'] = ['in', $heelSaleComplainIds];
                }

                //1:处理时间;
                if ($post['time_type'] == 2 && $update_time) {
                    $where['c.update_time'] = $update_time;
                }
            }


            //品牌
            if (isset($post['brand']) && $post['brand']) {
                $where['c.brand'] = ['like', '%' . $post['brand'] . '%'];
            }


            //卖家id
            if (isset($post['seller_id']) && $post['seller_id']) {

                $sellerModel = new AmazonSellerHeelSaleModel;

                $sellerIds = $sellerModel->where(['seller_id' => $post['seller_id']])->field('heel_sale_complain_id')->select();

                if (!$sellerIds) {
                    return ['data' => [], 'count' => 0, 'page' => $page, 'pageSize' => $pageSize];
                }

                $heelSaleComplainIds = array_column($sellerIds, 'heel_sale_complain_id');

                $where['c.id'] = ['in', $heelSaleComplainIds];
            }


            $model = new AmazonHeelSaleComplainModel();

            $count = $model->alias('c')->where($where)->count();


            $complains = $model->alias('c')->field('c.id,c.img,c.asin,c.sku, c.create_id, c.status, c.create_time, c.update_time, c.is_heel_sale, c.is_delete, c.modify_price_type, c.modify_price, c.lowest_price, c.price,c.account_id,u.realname as salesperson,c.brand')->where($where)->where($end_time)
                ->with(['account' => function ($query) {
                    $query->field('id,account_name,code');
                }, 'user' => function ($query) {
                    $query->field('id,realname');
                }, 'sellerHeelSale'])
                ->join('user u', 'c.salesperson_id = u.id', 'LEFT')
                ->order('c.create_time desc')
                ->page($page, $pageSize)
                ->select();

            $base_url = Cache::store('configParams')->getConfig('innerPicUrl')['value'] . '/';

            if (empty($complains)) {
                return ['data' => [], 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];
            }

            $complains = $this->objectToArray($complains);

            $heelAccountIds = [];
            foreach ($complains as $val) {
                if (empty($val['seller_heel_sale'])) {
                    continue;
                }
                foreach ($val['seller_heel_sale'] as $v) {
                    if (!empty($v['account_id'])) {
                        $heelAccountIds[] = $v['account_id'];
                    }
                }
            }

            $sellers = $this->accountAndSellerNameByAccountIds($heelAccountIds);

            foreach ($complains as $key => $val) {

                $complains[$key]['img'] = $val['img'] ? $base_url . $val['img'] : '';
                $complains[$key]['create_time'] = $val['create_time'] ? date('Y/m/d H:i:s', $val['create_time']) : '';
                $complains[$key]['update_time'] = $val['update_time'] ? date('Y/m/d H:i:s', $val['update_time']) : '';
                $complains[$key]['create_id'] = $val['create_id'] ? $val['user']['realname'] : '';

                if ($val['modify_price_type']) {
                    $complains[$key]['modify_price'] = $val['modify_price_type'] == 1 ? $val['modify_price'] . '%' : $val['modify_price'];
                }

                if (!empty($val['seller_heel_sale'])) {
                    foreach ($val['seller_heel_sale'] as $sellerK => $sellerV) {
                        $complains[$key]['seller_heel_sale'][$sellerK]['heel_sale_time'] = date('Y-m-d H:i:s', $sellerV['heel_sale_time']);
                        $complains[$key]['seller_heel_sale'][$sellerK]['code'] = '-';
                        $complains[$key]['seller_heel_sale'][$sellerK]['realname'] = '-';
                        if ($sellerV['account_id'] > 0 && !empty($sellers[$sellerV['account_id']])) {
                            $complains[$key]['seller_heel_sale'][$sellerK]['code'] = $sellers[$sellerV['account_id']]['code'];
                            $complains[$key]['seller_heel_sale'][$sellerK]['realname'] = $sellers[$sellerV['account_id']]['realname'];

                        }
                    }
                }
            }

            return ['data' => $complains, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    public function accountAndSellerNameByAccountIds(array $accountIds) : array
    {
        $accountIds = array_unique(array_filter($accountIds));
        if (empty($accountIds)) {
            return [];
        }

        $accountIds = array_values($accountIds);
        $sellers = [];

        //查姓名；
        $channelModel = new ChannelUserAccountMap();
        $users = $channelModel->alias('c')
            ->join('amazon_account a', 'a.id=c.account_id')
            ->join('user u', 'u.id=c.seller_id')
            ->field('u.realname,a.code,c.account_id')
            ->where(['c.account_id' => ['in', $accountIds], 'c.channel_id' => ChannelAccountConst::channel_amazon])
            ->select();
        $users2 = [];
        foreach ($users as $val) {
            $users2[$val['account_id']] = $val->toArray();
        }
        $extraIds = [];
        foreach ($accountIds as $id) {
            if (empty($users2[$id])) {
                $sellers[$id] = ['realname' => '-', 'code' => '-'];
                $extraIds[] = $id;
            } else {
                $sellers[$id] = $users2[$id];
            }
        }

        //查不到用户名字，可能可以查到帐号名字
        if (!empty($extraIds)) {
            //先查帐号；
            $accountModel = new AmazonAccount();
            $accounts = $accountModel->where(['id' => ['in', $accountIds]])->column('code', 'id');
            foreach ($accounts as $id=>$val) {
                $sellers[$id]['code'] = $val;
            }
        }

        return $sellers;
    }


    /**
     *处理跟卖投诉状态
     *
     */
    public function complainStatus($posts, $uid)
    {
        try {

            $model = new AmazonHeelSaleComplainModel();

            $id = $posts['id'];
            unset($posts['id']);

            $posts['update_time'] = time();
            $posts['create_id'] = $uid;

            $lang = 'zh';
            if (isset($posts['lang'])) {

                if ($posts['lang'] == 'en') {
                    $lang = $posts['lang'];
                }
                unset($posts['lang']);
            }

            $id = $model->isUpdate(true)->save($posts, ['id' => $id]);

            if ($id) {

                //推送跟卖投诉队列
                (new UniqueQueuer(AmazonHeelSaleComplainPriceQueuer::class))->push($id);
                return ['message' => '处理成功,稍后执行....'];
            }

            return ['message' => '处理失败'];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *删除跟卖投诉
     *
     */
    public function complainDelete($ids, $lang)
    {
        try {
            $model = new AmazonHeelSaleComplainModel();

            $id = $model->update(['is_delete' => 1], ['id' => ['in', $ids]]);

            if ($id) {

                (new AmazonSellerHeelSale())->whereIn('heel_sale_complain_id', $ids)->delete();

                $message = $lang == 'zh' ? '删除成功' : 'Delete successful';
                return ['message' => $message, 'status' => 0];
            }

            $message = $lang == 'zh' ? '删除失败' : 'Delete failed';
            return ['message' => $message, 'status' => -1];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *抓取asin跟卖
     *
     */
    public function heelSaleGet($ids)
    {
        try {

            $listingModel = new  AmazonListingModel();
            $queuerService = new UniqueQueuer(AmazonHeelSaleComplaintQueuer::class);

            //上架 自主上架
            $where = [
                'seller_status' => 1,
                'seller_type' => 1,
                'id' => ['in', $ids]
            ];

            $list = $listingModel->where($where)->field('id,asin1,account_id')->select();

            if (!$list) {
                return ['message' => '请选择在售,自主上架listing'];
            }

            $list = $this->objectToArray($list);

            foreach ($list as $key => $val) {

                if ($val['asin1']) {

                    $params = [
                        'id' => $val['id'],
                        'asin' => $val['asin1'],
                        'account_id' => $val['account_id'],
                    ];
                    $queuerService->push($params);
                }
            }

            return ['message' => '操作成功,系统稍后会执行....'];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    /**
     *关闭定时下架
     *
     */
    public function upLowerClose($ids, $lang)
    {

        try {
            $ids = \GuzzleHttp\json_decode($ids, true);
            if (is_array($ids)) {
                $model = new AmazonListingModel();
                //$upOpenLogModel = new AmazonUpOpenLogModel;

                $where = ['id' => ['in', $ids]];
                $list = $model->field('*')->where($where)->select();
                foreach ($list as $val) {
                    $model->update(['is_up_lower' => 0, 'rule_id' => 0], ['id' => $val['id']]);


                    //$upOpenLogModel->where(['listing_id' => ['in', $ids]])->delete();
                    /* $up_lower_type = [self::$up_type, self::$lower_type];
                    foreach ($up_lower_type as $typeVal){

                        //上架,下架 关闭
                        $data = [
                            'id' => $val['id'],
                            'seller_sku' => $val['seller_sku'],
                            'seller_status' => $typeVal
                        ];

                        if((new UniqueQueuer(AmazonTimerUpLowerQueuer::class))->exist($data)){
                            (new UniqueQueuer(AmazonTimerUpLowerQueuer::class))->remove($data);
                        }

                    }*/
                }
            }


            $message = $lang == 'zh' ? '关闭成功，稍后执行...' : 'Close successfully and execute later.';

            return ['message' => $message, 'status' => true];

        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }


    public function reviewAsin($posts)
    {

        $model = new AmazonListing;

        //存在asin
        $exist_asin = [];
        //不存在的asin
        $not_exist_asin = [];
        foreach ($posts as $key => $val) {

            $data = $model->field('id')->where(['asin1' => $val])->find();
            if ($data) {
                $exist_asin[] = $val;
            } else {
                $not_exist_asin[] = $val;
            }
        }


        return ['exist_asin' => $exist_asin, 'not_exist_asin' => $not_exist_asin];
    }


    public function heelSaleInfo($ids)
    {
        $heelSaleModel = new AmazonheelSaleLogModel;

        $ids = explode(',', $ids);

        $data = $heelSaleModel->alias('a')->field('a.id, a.account_id, a.sku, a.asin, a.price, a.quantity, a.lowest_price, a.modify_price_type, a.modify_price, a.rule_id')->with(['account' => function ($query) {
            return $query->field('id, account_name');
        }, 'frameRule' => function ($query) {
            return $query->field('id, rule_name');
        }])->whereIn('a.id', $ids)->order('a.id desc')
            ->select();

        if ($data) {
            $ruleModel = new AmazonUpLowerFrameRuleModel;

            foreach ($data as $key => $val) {

                $is_rule_status = 0;
                if ($val['rule_id']) {
                    $is_rule_status = $ruleModel->field('id')->where(['id' => $val['rule_id']])->where('(status = 1 or is_delete = 1)')->find();

                    $is_rule_status = $is_rule_status ? 1 : 0;
                }

                $data[$key]['is_rule_status'] = $is_rule_status;
            }
        }

        return $data;
    }


    public function heelSaleBatchEdit($posts)
    {

        $heelSaleModel = new AmazonheelSaleLogModel;
        $upOpenLogModel = new AmazonUpOpenLog();
        $amazonListingModel = new AmazonListing();

        $success = 0;
        foreach ($posts as $key => $val) {

            if ($val['id']) {

                $data = [
                    'price' => $val['price'],
                    'quantity' => $val['quantity'],
                    'lowest_price' => $val['lowest_price'],
                    'modify_price_type' => $val['modify_price_type'],
                    'modify_price' => $val['modify_price'],
                    'rule_id' => $val['new_rule_id'] ? $val['new_rule_id'] : $val['rule_id'],
                    'status' => 0,//待上传,
                    'submission_id' => 0,
                    'price_status' => 0,
                    'quantity_status' => 0,
                    'is_sync' => 0
                ];

                $editId = $heelSaleModel->update($data, ['id' => $val['id']]);

                //编辑成功,同时,旧的规则与新的规则不同
                if (empty($editId)) {
                    continue;
                }

                $success++;

                //修改listing信息
                $amazonListing = $amazonListingModel->field('id')->where(['seller_type' => 2, 'account_id' => $val['account_id'], 'asin1' => $val['asin'], 'seller_sku' => $val['sku']])->find();

                if (!$amazonListing) {
                    continue;
                }

                $is_up_lower = $val['new_rule_id'] ? 1 : 0;
                $amazonListingModel->update(['price' => $val['price'], 'quantity' => $val['quantity'], 'rule_id' => $data['rule_id'], 'is_up_lower' => $is_up_lower], ['id' => $amazonListing['id']]);


                /*//将价格加入队列中
                if($val['price']) {
                    (new UniqueQueuer(AmazonHeelSalePriceQueuer::class))->push($val['id']);
                }

                //将库存加入队列中
                if($val['quantity']) {
                    (new UniqueQueuer(AmazonHeelSaleQuantityQueuer::class))->push($val['id']);
                }*/
                (new UniqueQueuer(AmazonHeelSaleQueuer::class))->push($val['id']);

                //如果旧的规则id与新的规则id都为空,则跳出
                if (empty($val['rule_id']) && empty($val['new_rule_id'])) {
                    continue;
                }

                if ($val['rule_id'] != $val['new_rule_id']) {

                    //旧的规则不为0,则清除之前的规则
                    if ($val['rule_id']) {
                        $upOpenLogModel->where(['listing_id' => $amazonListing['id']])->delete();
                    }

                    //新的规则不为0,则添加新的规则
                    if ($val['new_rule_id']) {
                        $this->upLowerOpen($amazonListing['id'], $val['new_rule_id']);
                    }
                } else {
                    //编辑现在的规则
                    if ($val['rule_id']) {
                        $this->upLowerOpen($amazonListing['id'], $val['rule_id']);
                    }

                }
            }
        }

        return $success;
    }


    public function heelSaleBatchDel($ids)
    {

        $heelSaleModel = new AmazonheelSaleLogModel;
        $upOpenLogModel = new AmazonUpOpenLog();
        $listingModel = new AmazonListing();

        $heelSaleList = $heelSaleModel->field('account_id, asin, sku')->where(['id' => ['in', $ids]])->select();

        if ($heelSaleList) {

            foreach ($heelSaleList as $key => $val) {

                $data = ['account_id' => $val['account_id'], 'sku' => $val['sku']];

                //删除跟卖listing
                $listingModel->where($data)->where(['seller_type' => 2, 'asin1' => $val['asin']])->delete();

                $heelSaleInfo = $heelSaleModel->where($data)->where(['type' => 4, 'asin' => $val['asin']])->field('id, listing_id')->find();
                if (!$heelSaleInfo) {
                    continue;
                }

                $listingId = $heelSaleInfo['listing_id'];

                //删除定时跟卖数据
                $heelSaleModel->where(['id' => $heelSaleInfo['id']])->delete();

                if (empty($listingId)) {
                    continue;
                }

                //删除定时跟卖日志数据
                $upOpenLogModel->where(['listing_id' => $listingId])->delete();
            }

            //删除跟卖数据
            $heelSaleModel->where(['id' => ['in', $ids]])->delete();

        }

        return true;
    }


    /**
     *定时上下架同步listing
     *
     */
    public function timerUpLowerSyncListing($params)
    {

        $where = ['id' => $params['listing_id'], 'seller_type' => 2];
        $model = new AmazonListingModel;
        $list = $model->alias('m')->field('id')->where($where)->find();

        if (empty($list)) {
            $this->amazonListingHeelSale($params);
        }

        return true;
    }


    /**
     *亚马逊listing添加跟卖log
     *
     */
    public function amazonListingHeelSale($params)
    {

        $logModel = new AmazonHeelSaleLog();

        $logList = $logModel->field('account_id, sku, price, quantity, asin, rule_id')->where(['id' => $params['id']])->find();

        //跟卖成功,添加listing数据
        $account = Cache::store('AmazonAccount')->getAccount($logList['account_id']);
        $currency = AmazonCategoryXsdConfig::getCurrencyBySite($account['site']);

        $data = [
            'seller_sku' => $logList['sku'],
            'account_id' => $logList['account_id'],
            'seller_type' => 2,
            'currency' => $currency,
            'price' => $logList['price'],
            'quantity' => $logList['quantity'],
            'asin1' => $logList['asin'],
            'site' => (string)$account['site'],
            'modify_time' => time(),
            'rule_id' => $logList['rule_id'],
        ];

        (new AmazonListing())->insertGetId($data);

        return;
    }


    /**
     *根据站点获取账号名称
     *
     */
    public function sellerNames($params)
    {
        $asin = $params['asin'];
        $id = $params['id'];
        $seller_id = $params['seller_id'];

        $marketplaceId = AmazonConfig::$marketplaceId;
        $marketplaceId = $marketplaceId[$params['site']];

        $amazonWebsiteUrl = AmazonConfig::$AmazonWebsiteUrl;
        $amazonWebsiteUrl = $amazonWebsiteUrl[$params['site']];

        $amazonWebsiteUrl = $amazonWebsiteUrl . "/sp?_encoding=UTF8&asin=" . $asin . "&isAmazonFulfilled=0&isCBA=&marketplaceID={$marketplaceId}&orderID=&seller=$seller_id&tab=&vasStoreID=";

        $contents = file_get_contents($amazonWebsiteUrl); //抓取页面所有内容存入字符串
        preg_match('#<h1 id="sellerName">([^<]*)</h1>#', $contents, $reg);

        if ($reg) {
            $seller_name = $reg[1];
            $model = new AmazonSellerHeelSaleModel();

            $model->update(['seller_name' => $seller_name], ['id' => $id]);

        }

        return true;
    }


    /**
     *
     *
     */
    public function AmazonHeelSaleComplain($queueUrl)
    {
        $amazonSqs = new AmazonSqs();
        $response = $amazonSqs->receive_message($queueUrl);
        $response = (array)$response;


        if(empty($response)) {
            return false;
        }

        //1.取数据
        //2.将数据写入到缓存中
        //3.同时推送到队列中,获取数据,检测是否跟卖,同时删除缓存数据;
        $hash_key = 'AnyOfferChangedQueue';
        foreach($response as $val){
            if(isset($val['Messages']) && $val['Messages']){

                foreach ($val['Messages'] as $key => $messageVal){


                    if(isset($messageVal['MD5OfBody']) && $messageVal['MD5OfBody']){

                        $result = (array)simplexml_load_string($messageVal['Body']);
                        $result = \GuzzleHttp\json_encode($result);
                        //写入到缓存中
                        Cache::handler()->hSet($hash_key, $messageVal['MD5OfBody'], $result);

                        $params = ['id' => $messageVal['MD5OfBody']];
                        (new UniqueQueuer(AmazonHeelSaleComplaintQueuer::class))->push($params);
                    }

                    //查询之后,将查询的相应队列消费掉
                    if(isset($messageVal['ReceiptHandle']) && $messageVal['ReceiptHandle']) {

                        $receiptHandle = $messageVal['ReceiptHandle'];
                        $amazonSqs->delete_message($queueUrl, $receiptHandle);
                    }
                }
            }
        }

        return true;

    }
}