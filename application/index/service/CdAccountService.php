<?php

namespace app\index\service;

use app\common\exception\JsonErrorException;
use app\common\model\cd\CdAccount;
use app\common\cache\Cache;
use app\common\service\ChannelAccountConst;
use cd\CdBaseApi;
use cd\CdOrderApi;
use think\Request;
use app\common\service\Common;
use think\Db;
use cd\CdAccountApi;
use app\common\model\ChannelAccountLog;
use app\index\service\AccountService;
use think\Exception;

/**
 * Created by PhpStorm.
 * User: libaimin
 * Date: 2017/5/25
 * Time: 11:17
 */
class CdAccountService
{
    protected $cdAccountModel;
    protected $error = '';

    /**
     * 日志配置
     * 字段名称[name] 
     * 格式化类型[type]:空 不需要格式化,time 转换为时间,list
     * 格式化值[value]:array
     */
    public static $log_config = [
        'sales_company_id' => ['name'=>'运营公司','type'=>null],
        'base_account_id' => ['name'=>'账号基础资料名','type'=>null],
        'account_name' => ['name'=>'账号名称','type'=>null],
        'code' => ['name'=>'账号简称','type'=>null],
        'merchant_id' => ['name'=>'商户ID','type'=>null],
        'email' => ['name'=>'邮箱','type'=>null],
        'access_token' => ['name'=>'token','type'=>null],
        'client_id' => ['name'=>'应用程序ID','type'=>null],
        'password' => ['name'=>'应用登录密码','type'=>'key'],
        'client_secret' => ['name'=>'应用程序密钥','type'=>'key'],
        'status' => [//数据库字段可能未使用
            'name'=>'状态',
            'type'=>'list',
            'value'=>[
                0 =>'停用',
                1 =>'启用' ,
            ],
        ],
        'enabled' => [
            'name'=>'CD状态',
            'type'=>'list',
            'value'=>[
                0 =>'失效',
                1 =>'有效' ,
            ],
        ],
        'is_invalid' => [
            'name'=>'系统状态',
            'type'=>'list',
            'value'=>[
                0 =>'未启用',
                1 =>'启用' ,
            ],
        ],
        'is_authorization' => [
            'name'=>'授权状态',
            'type'=>'list',
            'value'=>[
                0 =>'未授权',
                1 =>'已授权' ,
            ],
        ],
        'download_listing' => ['name'=>'抓取Listing时间','type'=>'time'],
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

    public function __construct()
    {
        if (is_null($this->cdAccountModel)) {
            $this->cdAccountModel = new CdAccount();
        }
    }

    public function getError()
    {
        return $this->error;
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
        $is_invalid = isset($req['status']) && is_numeric($req['status']) ? intval($req['status']) : -1;//对应表字段is_invalid，取别名
        $site_status = isset($req['site_status']) && is_numeric($req['site_status']) ? intval($req['site_status']) : -1;
        $seller_id = isset($req['seller_id']) ? intval($req['seller_id']) : 0;
        $customer_id = isset($req['customer_id']) ? intval($req['customer_id']) : 0;
        $is_authorization = isset($req['authorization']) && is_numeric($req['authorization']) ? intval($req['authorization']) : -1;
        $snType = !empty($req['snType']) && in_array($req['snType'], ['account_name', 'code']) ? $req['snType'] : '';
        $snText = !empty($req['snText']) ? $req['snText'] : '';
        $taskName = !empty($req['taskName']) && in_array($req['taskName'], ['download_listing', 'download_order', 'sync_delivery', 'download_health']) ? $req['taskName'] : '';
        $taskCondition = !empty($req['taskCondition']) && isset($operator[trim($req['taskCondition'])]) ? $operator[trim($req['taskCondition'])] : '';
        $taskTime = isset($req['taskTime']) && is_numeric($req['taskTime']) ? intval($req['taskTime']) : '';
        //排序
        $sort_type = !empty($req['sort_type']) && in_array($req['sort_type'], ['account_name', 'code']) ? $req['sort_type'] : '';
        $sort = !empty($req['sort_val']) && $req['sort_val'] == 1 ? 'asc' : 'desc';
        $order_by = 'am.id DESC';
        $sort_type && $order_by = "am.{$sort_type} {$sort},{$order_by}";

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

        $count = $this->cdAccountModel
            ->alias('am')
            ->where($where)
            ->join('__ACCOUNT__ a', 'a.id=am.base_account_id', 'LEFT')
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

        $field = 'am.id,am.account_name,am.code,am.is_invalid status,am.create_time,am.update_time,am.is_authorization,am.sync_delivery,am.base_account_id,am.download_order,am.creator_id,am.updater_id,am.download_listing,am.expiry_time,am.enabled,s.site_status,c.seller_id,c.customer_id,a.account_create_time register_time,a.fulfill_time';
        //有数据就取出
        $list = $this->cdAccountModel
            ->alias('am')
            ->field($field)
            ->join('__ACCOUNT__ a', 'a.id=am.base_account_id', 'LEFT')
            ->join('__CHANNEL_USER_ACCOUNT_MAP__ c', 'c.account_id=am.id AND c.channel_id=a.channel_id', 'LEFT')
            ->join('__ACCOUNT_SITE__ s', 's.base_account_id=am.base_account_id AND s.account_code=am.code', 'LEFT')
            ->where($where)
            ->page($page, $pageSize)
            ->order($order_by)
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
     * 新增
     * @param $data
     * @return bool
     */
    public function add($data, $uid = 0)
    {
        try {
            $id = $data['id'] ?? 0;
            $time = time();

            $save_data['update_time'] = $time;
            $save_data['code'] = $data['code'];
            $save_data['download_order'] = $data['download_order'] ?? 0;
            $save_data['download_listing'] = $data['download_listing'] ?? 0;
            $save_data['sync_delivery'] = $data['sync_delivery'] ?? 0;
            $save_data['base_account_id'] = $data['base_account_id'] ?? 0;

            $old_data = [];
            $operator = [];
            $operator['operator_id'] = $data['user_id'];
            $operator['operator'] = $data['realname'];

            if ($id == 0) {
                //检查产品是否已存在
                if ($this->cdAccountModel->check(['account_name' => $data['account_name']])) {
                    $this->error = 'Cd账号已经存在无法重复添加';
                    return false;
                }
                if ($this->cdAccountModel->check(['code' => $data['code']])) {
                    $this->error = 'Cd简称已经存在无法重复添加';
                    return false;
                }
                //必须要去账号基础资料里备案
                \app\index\service\BasicAccountService::isHasCode(ChannelAccountConst::channel_CD,$data['code']);
                $save_data['account_name'] = $data['account_name'];
                $save_data['create_time'] = $time;
                $save_data['creator_id'] = $uid;

            } else{
                // $is_ok = $this->cdAccountModel->field('id')->where(['code' => $data['code']])->where('id','<>',$id)->find();
                // if($is_ok){
                //     $this->error = 'Cd简称已经存在无法修改';
                //     return false;
                // }

                $old_data = $this->cdAccountModel::get($id);

                $save_data['id'] = $id;
                $save_data['updater_id'] = $uid;
                unset($save_data['code'],$save_data['base_account_id']);
                //更新缓存
                $cache = Cache::store('CdAccount');
                foreach ($save_data as $key => $val) {
                    $cache->updateTableRecord($id, $key, $val);
                }
            }

            $new_data = $save_data;
            $new_data['site_status'] = isset($data['site_status']) ? intval($data['site_status']) : 0;

            $save_id = $this->cdAccountModel->add($save_data);

            $operator['account_id'] = $save_id;
            if ($id && isset($new_data['site_status'])) {
                if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                    $old_data['site_status'] = AccountService::setSite(
                        ChannelAccountConst::channel_CD,
                        $old_data,
                        $operator['operator_id'],
                        $new_data['site_status']
                    );
                }
            }

            $save_id and self::addLog(
                $operator, 
                $id ? ChannelAccountLog::UPDATE : ChannelAccountLog::INSERT, 
                $new_data, 
                $old_data
            );

            return $this->getOne($id);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage());
        }
    }


    /** 获取账号信息
     * @param $id
     * @return array
     */
    public function getOne($id)
    {
        $field = 'id,account_name,code,base_account_id,is_invalid status,create_time,update_time,is_authorization,sync_delivery,download_order,download_listing,expiry_time,enabled';
        if ($id == 0) {
            return $this->cdAccountModel->field($field)->order('id desc')->find();
        }
        return $this->cdAccountModel->where('id', $id)->field($field)->find();
    }

    /** 获取订单授权信息
     * @param $id
     * @return array
     */
    public function getTokenOne($id)
    {
        return $this->cdAccountModel->where('id', $id)->field('id,code,account_name,email,client_id,client_secret')->find();
    }


    /**
     * 封装where条件
     * @param array $params
     * @return array
     */
    function getWhere($params = [])
    {
        $where = [];
        if (isset($params['status']) && $params['status'] != '' ) {
            $params['status'] = $params['status'] == 'true' ? 1 : 0;
            $where['is_invalid'] = ['eq', $params['status']];
        }
        if (isset($params['authorization']) && $params['authorization'] > -1) {
            $where['is_authorization'] = ['eq', $params['authorization']];
        }
        if (isset($params['download_order']) && $params['download_order'] > -1) {
            if (empty($params['download_order'])) {
                $where['download_order'] = ['eq', 0];
            } else {
                $where['download_order'] = ['>', 0];
            }
        }
        if (isset($params['download_listing']) && $params['download_listing'] > -1) {
            if (empty($params['download_listing'])) {
                $where['download_listing'] = ['eq', 0];
            } else {
                $where['download_listing'] = ['>', 0];
            }
        }
        if (isset($params['sync_delivery']) && $params['sync_delivery'] > -1) {
            if (empty($params['sync_delivery'])) {
                $where['sync_delivery'] = ['eq', 0];
            } else {
                $where['sync_delivery'] = ['>', 0];
            }
        }
        if (isset($params['snType']) && isset($params['snText']) && !empty($params['snText'])) {
            switch ($params['snType']) {
                case 'account_name':
                    $where['account_name'] = ['like', '%' . $params['snText'] . '%'];
                    break;
                case 'code':
                    $where['code'] = ['like', '%' . $params['snText'] . '%'];
                    break;
                default:
                    break;
            }
        }
        if (isset($params['taskName']) && isset($params['taskCondition']) && isset($params['taskTime']) && $params['taskName'] !== '' && $params['taskTime'] !== '') {
            $where[$params['taskName']] = [trim($params['taskCondition']), $params['taskTime']];
        }
        return $where;
    }

    /**
     * 更改状态
     * @author lingjiawen
     * @dateTime 2019-04-26
     * @param    int|integer $id     账号id
     * @param    int|integer $enable 是否启用 0 停用，1 启用
     * @return   true|string         成功返回true,失败返回string 原因
     */
    public function changeStatus(int $id = 0, bool $enable)
    {
        try {
            $accountInfo = $this->cdAccountModel->where('id', $id)->find();
            if (!$accountInfo) {
                throw new Exception('账号不存在');
            }

            /**
             * 判断是否可更改状态
             */
            (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_CD, [$id]);

            if ($accountInfo->is_invalid == $enable) {
                return true;
            }

            $user = Common::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $id;

            $old_data = $accountInfo->toArray();

            $accountInfo->is_invalid = $enable;
            $accountInfo->updater_id = $operator['operator_id'];
            $accountInfo->update_time = time();

            $new_data = $accountInfo->toArray();

            if ($accountInfo->save()) {
                self::addLog(
                    $operator,
                    ChannelAccountLog::UPDATE,
                    $new_data,
                    $old_data
                );
                //删除缓存
                Cache::store('CdAccount')->delAccount($id);
            }

            return true;
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
    }

    /** 验证账号
     * @param $data
     * @return array
     */
    public function check($data)
    {
        $this->error = '验证失败：';
        $cache = Cache::store('CdAccount');
        $account = $cache->getAccount($data['id']);
        if (!isset($account)) {
            $this->error .= '账号不存在';
            return false;
        }
        if(!$account['client_id'] || !$account['client_secret']){
            $this->error .= '账号程序ID或者秘钥不存在';
            return false;
        }
        try {

            $api = new CdOrderApi($account);
            $token = $api->getTokens();
            if(!$token) {
                $this->error .= '账号秘钥没有更新，或者没有绑定IP';
                return false;
            }
            $updata = [];
            $updata['is_authorization'] = 1;
            $updata['update_time'] = time();
            $this->cdAccountModel->allowField(true)->save($updata, ['id' => $data['id']]);
            //修改缓存
            $cache = Cache::store('CdAccount');
            foreach ($updata as $key => $val) {
                $cache->updateTableRecord($data['id'], $key, $val);
            }
            return true;
        } catch (\Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
    }



    /** 刷新refresh_token
     * @param $data
     * @param $uid
     * @return array
     */
    public function refresh_token($data, $uid = 0)
    {
        if (empty($data['client_id']) || empty($data['client_secret']) || empty($data['id'])) {
            $this->error = '帐号授权信息不完整';
            return false;
        }
        $cache = Cache::store('CdAccount');
        $account = $cache->getAccount($data['id']);
        if (!isset($account)) {
            $this->error = '账号不存在';
            return false;
        }

        $old_data = $account;

        $account['client_id'] = $updata['client_id'] = $data['client_id'];
        $account['client_secret'] = $updata['client_secret'] = $data['client_secret'];
        $updata['enabled'] = 1;

        $new_data = $updata;

        $operator = [];
        $operator['operator_id'] = $data['user_id'];
        $operator['operator'] = $data['realname'];
        $operator['account_id'] = $account['id'];

        $this->cdAccountModel->allowField(true)->save($updata, ['id' => $data['id']]);

        //插入日志
        self::addLog(
            $operator,
            ChannelAccountLog::UPDATE,
            $new_data,
            $old_data
        );

        //修改缓存
        $cache = Cache::store('CdAccount');
        foreach ($updata as $key => $val) {
            $cache->updateTableRecord($data['id'], $key, $val);
        }
        return true;
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
            'channel_id' => ChannelAccountConst::channel_CD,
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
    public function getCdLog(array $req = []): array
    {
        $page = isset($req['page']) ? intval($req['page']) : 1;
        $pageSize = isset($req['pageSize']) ? intval($req['pageSize']) : 10;
        $account_id = isset($req['id']) ? intval($req['id']) : 0;

        return (new ChannelAccountLog)->getLog(
            ChannelAccountConst::channel_CD, 
            $account_id, 
            true, 
            $page, 
            $pageSize
        );
    }

}