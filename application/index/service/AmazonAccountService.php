<?php

namespace app\index\service;

use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\model\amazon\AmazonAccount as AmazonAccountModel;
use app\common\service\ChannelAccountConst;
use app\common\service\Common as CommonService;
use think\Db;
use think\Exception;
use app\common\model\ChannelAccountLog;
use think\Request;

/**
 * @desc 亚马逊账号管理
 * @author wangwei
 * @date 2018-9-29 15:46:15
 */
class AmazonAccountService
{
    /**
     * @var 站点市场划分
     */
    public static $siteMap = [
        //北美
        'CA' => 'NA',
        'US' => 'NA',
        'MX' => 'NA',
        //欧洲
        'DE' => 'CO',
        'ES' => 'CO',
        'FR' => 'CO',
        'IN' => 'CO',
        'IT' => 'CO',
        'UK' => 'CO',
        //远东
        'JP' => 'JP',
        //中国
        'CN' => 'CN',
        //澳洲
        'AU' => 'AU',
    ];

    /**
     * 日志配置
     * 字段名称[name] 
     * 格式化类型[type]:空 不需要格式化,time 转换为时间,list
     * 格式化值[value]:array
     */
    public static $log_config = [
        'sales_company_id' => ['name'=>'运营公司','type'=>null],
        'base_account_id' => ['name'=>'账号基础资料','type'=>null],
        'account_name' => ['name'=>'账号名','type'=>null],
        'code' => ['name'=>'账号简称','type'=>null],
        'site' => ['name'=>'站点','type'=>null],
        'merchant_id' => ['name'=>'Amazon商户号','type'=>null],
        'developer_code' => ['name'=>'开发者账号','type'=>null],
        'access_key_id' => ['name'=>'卖家 Access Key ID','type'=>null],
        'secret_key' => ['name'=>'开发者 Access Key','type'=>null],
        'status' => [
            'name'=>'系统状态',
            'type'=>'list',
            'value'=>[
                0 =>'停用',
                1 =>'使用' ,
            ],
        ],
        'is_invalid' => [
            'name'=>'Amazon状态',
            'type'=>'list',
            'value'=>[
                0 =>'停用',
                1 =>'使用' ,
            ],
        ],
        'is_authorization' => [
            'name'=>'Amazon授权状态',
            'type'=>'list',
            'value'=>[
                0 =>'停用',
                1 =>'使用' ,
            ],
        ],
        'assessment_of_usage' => [
            'name'=>'账号使用情况考核',
            'type'=>'list',
            'value'=>[
                0 =>'开启',
                1 =>'停用' ,
            ],
        ],
        'download_listing' => ['name'=>'抓取AmazonListing时间','type'=>'time'],
        'download_anti_sale' => ['name'=>'抓取Amazon反跟卖数据','type'=>'time'],
        'download_health' => ['name'=>'同步健康数据','type'=>'time'],
        'download_order' => ['name'=>'抓取订单时间','type'=>'time'],
        'sync_delivery' => ['name'=>'同步发货状态时间','type'=>'time'],
        'sync_feedback' => ['name'=>'同步中差评时间','type'=>'time'],
        'site_status' => [
            'name'=>'账号状态',
            'type'=>'list',
            'value'=>[
                0 => '未分配',
                1 => '运营中',
                2 => '回收中',
                3 => '冻结中',
                4 => '申诉中',
            ]
        ],
    ];

    /**
     * @desc 获取指定站点的开发者授权信息
     * @author wangwei
     * @date 2018-9-29 16:19:24
     * @param string $site
     * @return array|array|string[]
     */
    public function getDeveloperAccount($site)
    {
        $return = [];
        $site = strtoupper($site);
        $developer = [
            //北美
            'NA' => [
                'name' => 'Sirmoon',
                'id' => '7193-4483-7966',
                'access_key_id' => 'AKIAJZMSTTITQPLAYEIQ',
                'secret_key' => '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',
            ],
            //欧洲
            'CO' => [
                'name' => 'Lanlary',
                'id' => '2608-9180-2986',
                'access_key_id' => 'AKIAJ7QZVI3XHTGJ4V6A',
                'secret_key' => '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',
            ],
            //日本
            'JP' => [
                'name' => 'anhuiwuzhixunxinxikeji',
                'id' => '7240-7784-6314',
                'access_key_id' => 'AKIAIRDIRP5BIKIZFJEA',
                'secret_key' => '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',
            ],
        ];
        if (!isset(self::$siteMap[$site])) {
            return $return;
        }
        $market = self::$siteMap[$site];
        return isset($developer[$market]) ? $developer[$market] : [];
    }

    public function sava($data, $user_id)
    {
        $ret = [
            'msg' => '',
            'code' => ''
        ];
        $amazonAccount = new AmazonAccountModel();
        $re = $amazonAccount->where(['code' => trim($data['code'])])->find();
        if ($re) {
            $ret['msg'] = '账户名重复';
            $ret['code'] = 400;
            return $ret;
        }
        \app\index\service\BasicAccountService::isHasCode(ChannelAccountConst::channel_amazon, $data['code'], $data['site']);

        $user = CommonService::getUserInfo(Request::instance());
        $operator = [];
        $operator['operator_id'] = $user['user_id'] ?? 0;
        $operator['operator'] = $user['realname'] ?? '';

        //启动事务
        Db::startTrans();
        try {
            $data['create_time'] = time();
            //获取操作人信息

            /** warning: 重构时记得传created_user_id linpeng 2019-2-19*/
            if (!param($data, 'created_user_id')) {
                $data['created_user_id'] = $user_id;
            }

            /**
             * 设置默认状态为启用
             */
            if (empty($data['id']) && !isset($data['is_invalid']) && !isset($data['status'])) {
                $data['is_invalid'] = $data['status'] = 1;
            }
            $new_data = $data;
            $amazonAccount->allowField(true)->isUpdate(false)->save($data);


            //开通wish服务时，新增一条list数据，如果存在，则不加
            if (isset($data['download_health'])) {
                (new AmazonAccountHealthService())->openAmazonHealth($amazonAccount->id, $data['download_health']);
            }

            Db::commit();

            $operator['account_id'] = $amazonAccount->id;
            self::addLog(
                $operator,
                ChannelAccountLog::INSERT,
                $new_data,
                []
            );
            //新增缓存
            Cache::store('AmazonAccount')->setTableRecord($amazonAccount->id);
            $ret = [
                'msg' => '新增成功',
                'code' => 200,
                'id' => $amazonAccount->id
            ];
            return $ret;
        } catch (Exception $e) {
            Db::rollback();
            $ret = [
                'msg' => '新增失败',
                'code' => 500
            ];
            return $ret;
        }
    }

    public function reAge($id)
    {
        $amazonAccount = new AmazonAccountModel();
        $where['id'] = $id;

        $temp['assessment_of_usage'] = 0;
        $temp['updated_time'] = time();
        $amazonAccount->where($where)->update($temp);//修改账号表把assessment_of_usage 设置为0开启
        ///修改缓存
        $data['assessment_of_usage'] = 0;
        $data['updated_time'] = time();
        $data['id'] = $id;
        $cache = Cache::store('AmazonAccount');
        foreach ($data as $key => $val) {
            $cache->updateTableRecord($id, $key, $val);
        }
        return true;
    }

    /**
     * 获取账号列表
     * lingjiawen
     */
    public function getList(array $req)
    {
        /**
         * 初始化参数
         */
        $operator = ['eq' => '=', 'gt' => '>', 'lt' => '<'];
        $page = isset($req['page']) ? intval($req['page']) : 1;
        $pageSize = isset($req['pageSize']) ? intval($req['pageSize']) : 50;
        $time_type = isset($req['time_type']) && in_array($req['time_type'],['register','fulfill']) ? $req['time_type'] : '';
        $start_time = isset($req['start_time']) ? strtotime($req['start_time']) : 0;
        $end_time = isset($req['end_time']) ? strtotime($req['end_time']) : 0;
        $site = $req['site'] ?? '';
        $status = isset($req['status']) && is_numeric($req['status']) ? intval($req['status']) : -1;
        $site_status = isset($req['site_status']) && is_numeric($req['site_status']) ? intval($req['site_status']) : -1;
        $seller_id = isset($req['seller_id']) ? intval($req['seller_id']) : 0;
        $customer_id = isset($req['customer_id']) ? intval($req['customer_id']) : 0;
        $is_authorization = isset($req['authorization']) && is_numeric($req['authorization']) ? intval($req['authorization']) : -1;
        $is_invalid = isset($req['is_invalid']) && is_numeric($req['is_invalid']) ? intval($req['is_invalid']) : -1;
        $snType = !empty($req['snType']) && in_array($req['snType'], ['account_name', 'code']) ? $req['snType'] : '';
        $snText = !empty($req['snText']) ? $req['snText'] : '';
        $taskName = !empty($req['taskName']) && in_array($req['taskName'], ['download_listing', 'download_order', 'sync_delivery', 'download_health']) ? $req['taskName'] : '';
        $taskCondition = !empty($req['taskCondition']) && isset($operator[trim($req['taskCondition'])]) ? $operator[trim($req['taskCondition'])] : '';
        $taskTime = isset($req['taskTime']) && is_numeric($req['taskTime']) ? intval($req['taskTime']) : '';

        /**
         * 参数处理
         */
        if ($time_type && $end_time && $start_time > $end_time) {
            return [
                'count' => 0,
                'data' => [],
                'page' => $page,
                'pageSize' => $pageSize,
            ];
        }
        !$page and $page = 1;
        if ($page > $pageSize) {
            $pageSize = $page;
        }

        /**
         * where数组条件
         */
        $where = [];
        $seller_id and $where['c.seller_id'] = $seller_id;
        $customer_id and $where['c.customer_id'] = $customer_id;
        $is_invalid >= 0 and $where['am.is_invalid'] = $is_invalid;
        $is_authorization >= 0 and $where['am.is_authorization'] = $is_authorization;
        $site and $where['am.site'] = $site;
        $status >= 0 and $where['am.status'] = $status;
        $site_status >= 0 and $where['s.site_status'] = $site_status;

        if ($taskName && $taskCondition && !is_string($taskTime)) {
            $where['am.' . $taskName] = [$taskCondition, $taskTime];
        }

        if ($snType && $snText) {
            $where['am.' . $snType] = ['like', '%' . $snText . '%'];
        }

        /**
         * 需要按时间查询时处理
         */
        if ($time_type) {
            /**
             * 处理需要查询的时间类型
             */
            switch ($time_type) {
                case 'register':
                    $time_type = 'a.account_create_time';
                    break;
                case 'fulfill':
                    $time_type = 'a.fulfill_time';
                    break;

                default:
                    $start_time = 0;
                    $end_time = 0;
                    break;
            }
            /**
             * 设置条件
             */
            if ($start_time && $end_time) {
                $where[$time_type] = ['between time', [$start_time, $end_time]];
            } else {
                if ($start_time) {
                    $where[$time_type] = ['>', $start_time];
                }
                if ($end_time) {
                    $where[$time_type] = ['<', $end_time];
                }
            }
        }

        $amazonAccount = new AmazonAccountModel();
        $count = $amazonAccount
            ->alias('am')
            ->where($where)
            ->join('__ACCOUNT__ a', 'a.id=am.base_account_id')
            ->join('__CHANNEL_USER_ACCOUNT_MAP__ c', 'c.account_id=am.id AND c.channel_id=a.channel_id', 'LEFT')
            ->join('__ACCOUNT_SITE__ s', 's.base_account_id=am.base_account_id AND s.account_code=am.code', 'LEFT')
            ->count();

        //没有数据就返回
        if (!$count) {
            return [
                'count' => 0,
                'data' => [],
                'page' => $page,
                'pageSize' => $pageSize,
            ];
        }

        $field = 'am.id,am.code,am.account_name,am.status,am.is_invalid,am.is_authorization,am.updated_time,am.site,am.download_order,am.sync_delivery,am.sync_feedback,am.download_listing,am.download_health,am.assessment_of_usage,s.site_status,c.seller_id,c.customer_id,a.account_create_time register_time,a.fulfill_time';
        //有数据就取出
        $list = $amazonAccount
            ->alias('am')
            ->field($field)
            ->join('__ACCOUNT__ a', 'a.id=am.base_account_id')
            ->join('__CHANNEL_USER_ACCOUNT_MAP__ c', 'c.account_id=am.id AND c.channel_id=a.channel_id', 'LEFT')
            ->join('__ACCOUNT_SITE__ s', 's.base_account_id=am.base_account_id AND s.account_code=am.code', 'LEFT')
            ->where($where)
            ->page($page, $pageSize)
            ->order('am.id DESC')
            ->select();

        $site_status_info = new \app\index\service\BasicAccountService();
        foreach ($list as &$val) {
            $seller =  Cache::store('User')->getOneUser($val['seller_id']);
            $val['seller_name'] = $seller ? $seller['realname'] : '';
            $val['seller_on_job'] = $seller ? $seller['on_job'] : '';
            $customer = Cache::store('User')->getOneUser($val['customer_id']);
            $val['customer_name'] = $customer ? $customer['realname'] : '';
            $val['customer_on_job'] = $customer ? $customer['on_job'] : '';
            $val['site_status_str'] = $site_status_info->accountStatusName($val['site_status']);
        }

        return [
            'count' => $count,
            'data' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * 更改状态
     * @author lingjiawen
     * @dateTime 2019-04-25
     * @param    int|integer $id     账号id
     * @param    int|integer $enable 是否启用 0 停用，1 启用
     * @return   true|string         成功返回true,失败返回string 原因
     */
    public function changeStatus(int $id = 0, bool $enable)
    {
        try {
            $model = new AmazonAccountModel();
            $accountInfo = $model->where('id', $id)->find();
            if (!$accountInfo) {
                throw new Exception('记录不存在');
            }

            /**
             * 判断是否可更改状态
             */
            (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_amazon, [$id]);

            if ($accountInfo->status == $enable) {
                return true;
            }

            $user = CommonService::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $id;

            $old_data = $accountInfo->toArray();

            $accountInfo->status = $enable;
            $accountInfo->updated_user_id = $operator['operator_id'];
            $accountInfo->updated_time = time();

            $new_data = $accountInfo->toArray();

            if ($accountInfo->save()) {
                self::addLog(
                    $operator,
                    ChannelAccountLog::UPDATE,
                    $new_data,
                    $old_data
                );

                Cache::store('AmazonAccount')->delAccount($id);
            }
            
            return true;
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
    }

    /**
     * 添加日志
     * @author lingjiawen
     */
    public static function addLog(array $base_info = [], int $type = 0, array $new_data = [], $old_data = []): void
    {
        $insert_data = [];
        $remark = [];
        if (ChannelAccountLog::INSERT == $type) {
            $insert_data = $new_data;
            foreach ($new_data as $k => $v) {
                if (isset(self::$log_config[$k])) {
                    $remark[] = ChannelAccountLog::getRemark(self::$log_config[$k], $type, $k, $v);
                }
            }
        }
        if (ChannelAccountLog::DELETE == $type) {
            $insert_data = (array)$old_data;
        }
        if (ChannelAccountLog::UPDATE == $type) {
            foreach ($new_data as $k => $v) {
                if (isset(self::$log_config[$k]) and isset($old_data[$k]) and $v != $old_data[$k]) {
                    $remark[] = ChannelAccountLog::getRemark(self::$log_config[$k], $type, $k, $v, $old_data[$k]);
                    $insert_data[$k] = $old_data[$k];
                }
            }
        }
        $insert_data and ChannelAccountLog::addLog([
            'channel_id' => ChannelAccountConst::channel_amazon,
            'account_id' => $base_info['account_id'],
            'type' => $type,
            'remark' => json_encode($remark, JSON_UNESCAPED_UNICODE),
            'operator_id' => $base_info['operator_id'],
            'operator' => $base_info['operator'],
            'data' => json_encode($insert_data, JSON_UNESCAPED_UNICODE),
            'create_time' => input('server.REQUEST_TIME'),
        ]);
    }

    /**
     * 获取日志
     */
    public function getAmazonLog(array $req = [])
    {
        $page = isset($req['page']) ? intval($req['page']) : 1;
        $pageSize = isset($req['pageSize']) ? intval($req['pageSize']) : 10;
        $account_id = isset($req['id']) ? intval($req['id']) : 0;

        return (new ChannelAccountLog)->getLog(
            ChannelAccountConst::channel_amazon,
            $account_id,
            true,
            $page,
            $pageSize
        );
    }
}
