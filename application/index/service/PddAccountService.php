<?php
namespace app\index\service;

use app\common\exception\JsonErrorException;
use app\common\model\pdd\PddAccount;
use app\common\cache\Cache;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use think\Request;
use service\pdd\PddApi;
use think\Db;
use think\Exception;
use app\common\model\ChannelAccountLog;
use app\index\service\AccountService;

/**
 * Created by PhpStorm.
 * User: zhaixueli
 * Date: 2018/9/11
 * Time: 10:00
 */
class PddAccountService
{
    protected $pddAccountModel;
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
        'name' => ['name'=>'账号名称','type'=>null],
        'code' => ['name'=>'账号简称','type'=>null],
        'site' => ['name'=>'站点','type'=>null],
        'lazada_name' => ['name'=>'Lazada账号','type'=>null],
        'seller_id' => ['name'=>'sellerid','type'=>null],
        'merchant_id' => ['name'=>'商户ID','type'=>null],
        'email' => ['name'=>'邮箱','type'=>null],
        'access_token' => ['name'=>'token','type'=>null],
        'client_id' => ['name'=>'应用程序ID','type'=>null],
        'client_secret' => ['name'=>'应用程序密钥','type'=>'key'],
        'status' => [
            'name'=>'系统状态', 
            'type'=>'list',
            'value'=>[
                0 =>'停用',
                1 =>'使用' ,
            ],
        ],
        'platform_status' => [
            'name'=>'Pdd状态',
            'type'=>'list',
            'value'=>[
                0 =>'失效',
                1 =>'有效' ,
            ],
        ],
        'is_invalid' => [
            'name'=>'系统invalid状态',
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
        'download_return' => ['name'=>'下载','type'=>'time'],
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
        if (is_null($this->pddAccountModel)) {
            $this->pddAccountModel = new PddAccount();
        }
    }
    /**
     * 得到错误信息
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 账号列表
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
        $sort = !empty($req['sort_val']) && $req['sort_val'] == 2 ? 'desc' : 'asc';
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

        $count = $this->pddAccountModel
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

        $field = 'am.id,am.code,am.name,am.download_listing,am.update_id,am.create_id,am.seller_id pdd_seller_id,am.platform_status,am.status,am.refresh_token,am.logo_url,am.token_expire_time,am.refresh_expire_time,am.site,am.is_invalid,am.is_authorization,am.download_order,am.download_return,am.sync_delivery,am.create_time,am.update_time,am.client_id,am.client_secret,am.access_token,s.site_status,c.seller_id,c.customer_id,a.account_create_time register_time,a.fulfill_time';
        //有数据就取出
        $list = $this->pddAccountModel
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

    /** 账号列表 (已废弃)2019-4-23
     * @param Request $request
     * @return array
     * @throws \think\Exception
     */
    public function accountList(Request $request)
    {
        $where = [];
        $params = $request->param();
        if(isset($params['site'])){
            $where['site'] = ['eq', $params['site']];
        }
        if (isset($params['status']) && $params['status'] != '' ) {
            $params['status'] = $params['status'] == 'true' ? 1 : 0;
            $where['status'] = ['eq', $params['status']];
        }
        if (isset($params['authorization']) && $params['authorization']!='' && $params['authorization']!=-1) {
            $params['authorization'] = $params['authorization'] == 1? 1 : 0;
            $where['is_authorization'] = ['eq', $params['authorization']];
        }
        if (isset($params['download_order']) && $params['download_order'] > -1) {
            if(empty($params['download_order'])){
                $where['download_order'] = ['eq', 0];
            }else{
                $where['download_order'] = ['>', 0];
            }
        }
        if (isset($params['download_listing']) && $params['download_listing'] > -1) {
            if(empty($params['download_listing'])){
                $where['download_listing'] = ['eq', 0];
            }else{
                $where['download_listing'] = ['>', 0];
            }
        }
        if (isset($params['sync_delivery']) && $params['sync_delivery'] > -1) {
            if(empty($params['sync_delivery'])){
                $where['sync_delivery'] = ['eq', 0];
            }else{
                $where['sync_delivery'] = ['>', 0];
            }
        }
        if (isset($params['snType']) && isset($params['snText']) && !empty($params['snText'])) {
            switch ($params['snType']) {
                case 'account_name':
                    $where['name'] = ['like', '%' . $params['snText'] . '%'];
                    break;
                case 'code':
                    $where['code'] = ['like', '%' . $params['snText'] . '%'];
                    break;
                default:
                    break;
            }
        }

        if(isset($params['taskName']) && isset($params['taskCondition']) && isset($params['taskTime']) && $params['taskName'] !== '' && $params['taskTime'] !== '') {
            $where[$params['taskName']] = [trim($params['taskCondition']), $params['taskTime']];
        }
        $orderBy='';
        $orderBy .= fieldSort($params);
        $orderBy .= 'create_time desc,update_time desc';
        $page = $request->get('page', 1);
        $pageSize = $request->get('pageSize', 20);
        $field = 'id,code,name,download_listing,update_id,create_id,seller_id,platform_status,status,refresh_token,logo_url,token_expire_time,refresh_expire_time,site,is_invalid,is_authorization,download_order,download_return,sync_delivery,create_time,update_time,client_id,client_secret,access_token';
        $count = $this->pddAccountModel->field($field)->where($where)->count();
        $accountList = $this->pddAccountModel->field($field)->where($where)->order($orderBy)->page($page, $pageSize)->select();
        $new_array = [];
        foreach ($accountList as $k => $v) {
            $temp = $v->toArray();
            $new_array[$k] = $temp;
        }
        $result = [
            'data' => $new_array,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
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

            $old_data = [];

            $user = Common::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? $uid;
            $operator['operator'] = $user['realname'] ?? '';

            if ($id == 0){
                //检查产品是否已存在
                if ($this->pddAccountModel->check(['name' => $data['name']])) {
                    $this->error =  $data['name'].'账号已经存在无法重复添加';
                    return false;
                }

                if ($this->pddAccountModel->check(['code' => $data['code']])) {
                    $this->error = $data['code'].'简称已经存在无法重复添加';
                    return false;
                }
                //必须要去账号基础资料里备案
               \app\index\service\BasicAccountService::isHasCode(ChannelAccountConst::channel_Pdd,$data['code']);
            } else{
                // $is_ok = $this->pddAccountModel->field('id')->where(['code' => $data['code']])->where('id','<>',$id)->find();
                // if($is_ok){
                //     $this->error = 'Pdd简称已经存在无法修改';
                //     return false;
                // }
                $save_data['name'] = $data['name'];
                $save_data['create_time'] = $time;
                $save_data['id'] = $id;
                $save_data['update_id'] = $uid;

                $old_data = $this->pddAccountModel::get($id);

                unset($save_data['code'], $save_data['base_account_id'], $save_data['site']);
                //更新缓存
                $cache = Cache::store('PddAccount');
                foreach ($save_data as $key => $val) {
                    $cache->updateTableRecord($id, $key, $val);
                }
            }

            $new_data = $save_data;
            $new_data['site_status'] = isset($data['site_status']) ? intval($data['site_status']) : 0;

            $save_id = $this->pddAccountModel->add($save_data);

            $operator['account_id'] = $save_id;
            if ($id && isset($new_data['site_status'])) {
                if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                    $old_data['site_status'] = AccountService::setSite(
                        ChannelAccountConst::channel_Pdd,
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

            return $this->read($id);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage());
        }
    }

    /** 更新
     * @param $id
     * @param $data
     * @return \think\response\Json
     */
    public function update($id, $data)
    {
        if ($this->pddAccountModel->isHas($id, $data['code'], '')) {
            throw new JsonErrorException('代码或者用户名已存在', 400);
        }
        $model = $this->pddAccountModel->get($id);

        if (!$model) {
            throw new JsonErrorException('找不到账号', 400);
        }

        $old_data = $model->toArray();
        $user = Common::getUserInfo(Request::instance());
        $operator = [];
        $operator['operator_id'] = $user['user_id'] ?? 0;
        $operator['operator'] = $user['realname'] ?? '';

        Db::startTrans();
        try {
            //赋值
            $model->code = isset($data['code'])?$data['code']:'';
            $model->name = isset($data['name'])?$data['name']:'';
            $model->site = isset($data['site'])?$data['site']:'';
            $model->client_id = isset($data['client_id'])?$data['client_id']:'';
            $model->download_order = isset($data['download_order'])?$data['download_order']:'';
            $model->download_listing = isset($data['download_listing'])?$data['download_listing']:'';
            $model->sync_delivery = isset($data['sync_delivery'])?$data['sync_delivery']:'';
            $model->update_time =time();
            unset($data['id']);
            $new_data = $model->toArray();

            $operator['account_id'] = $id;
            //插入数据
            $res = $model->allowField(true)->isUpdate(true)->save();

            $new_data['site_status'] = isset($data['site_status']) ? intval($data['site_status']) : 0;

            if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                $old_data['site_status'] = AccountService::setSite(
                    ChannelAccountConst::channel_Pdd,
                    $old_data,
                    $operator['operator_id'],
                    $new_data['site_status']
                );
                $model->site_status = $new_data['site_status'];
            }
            
            $res and self::addLog(
                $operator,
                ChannelAccountLog::UPDATE,
                $new_data,
                $old_data
            );
            //删除缓存
            Cache::store('pddAccount')->delAccount();
            Db::commit();
            return $model;
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }


    /** 账号信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function read($id)
    {
        $accountInfo = $this->pddAccountModel->field('id,code,base_account_id,name,client_id,download_order,sync_delivery,download_listing,client_secret,token_expire_time,platform_status')->where(['id' => $id])->find();
        //var_dump($accountInfo);die;
        if(empty($accountInfo)){
            throw new JsonErrorException('账号不存在',500);
        }
        $accountInfo['site_status'] = AccountService::getSiteStatus($accountInfo['base_account_id'], $accountInfo['code']);
        return $accountInfo;
    }



    /** 批量更新抓取的时间
     * @param $ids
     * @param $data
     * @return \think\response\Json
     */
    public function update_download($ids, $data)
    {
        $user = Common::getUserInfo(Request::instance());
        $operator = [];
        $operator['operator_id'] = $user['user_id'] ?? 0;
        $operator['operator'] = $user['realname'] ?? '';

        Db::startTrans();
        try {
            //赋值
            $param['status'] = $data['status'] == 1 ? 1 : 0;
            $param['download_order'] = isset($data['download_order'])?$data['download_order']:'';
            $param['download_listing'] = isset($data['download_listing'])?$data['download_listing']:'';
            $param['sync_delivery'] = isset($data['sync_delivery'])?$data['sync_delivery']:'';

            $idsArr = json_decode($ids,true);
            if (!$idsArr) {
                throw new Exception('参数错误');
            }
            $old_data_list = $this->pddAccountModel
                ->where(['id' => ['in', $idsArr]])
                ->select();

            $new_data = $param;

            /**
             * 判断是否可更改状态
             */
            if (isset($new_data['status'])) {
                (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_Pdd, $idsArr);
            }

            $res = $this->pddAccountModel
                ->allowField(true)
                ->update(
                    $param,
                    ['id' => ['in', $idsArr]]
                );

            $new_data['site_status'] = isset($data['site_status']) ? intval($data['site_status']) : 0;
            foreach ($old_data_list as $old_data) {
                if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                    $old_data['site_status'] = AccountService::setSite(
                        ChannelAccountConst::channel_Pdd,
                        $old_data,
                        $operator['operator_id'],
                        $new_data['site_status']
                    );
                }
                $operator['account_id'] = $old_data['id'];
                self::addLog(
                    $operator, 
                    ChannelAccountLog::UPDATE, 
                    $new_data, 
                    $old_data
                );
            }

            //删除缓存
            Cache::store('pddAccount')->delAccount();
            Db::commit();
            return $new_data;
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
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
            $accountInfo = $this->pddAccountModel->where('id', $id)->find();
            if (!$accountInfo) {
                throw new Exception('账号不存在');
            }

            /**
             * 判断是否可更改状态
             */
            (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_Pdd, [$id]);

            if ($accountInfo->status == $enable) {
                return true;
            }

            $user = Common::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $id;

            $old_data = $accountInfo->toArray();

            $accountInfo->status = $enable;
            $accountInfo->update_id = $operator['operator_id'];
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
                Cache::store('PddAccount')->delAccount($id);
            }

            return true;
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
    }

    /** 获取Token
     * @param $id
     * @param $data
     * @return array
     * @throws \think\Exception
     */
    public function getToken($id, $data)
    {
        $accountInfo = $this->pddAccountModel->where('id', $id)->find();
        if (!$accountInfo) {
            throw new JsonErrorException('账号不存在', 500);
        }
        $pdd    = new PddApi();
        $result= $pdd->getToken($data);
        $expires_in=0;
        if($result['access_token'] && !empty($result['access_token'])){
            $expires_in=$result['expires_in']??0;
            $data['access_token'] = $result['access_token'];
            $data['refresh_token'] = $result['refresh_token'];
            $data['token_expire_time'] =time()+intval($expires_in);
            $data['seller_id'] = $result['owner_id'];
            $data['is_authorization'] = 1;
            $data['update_time'] = time();
        }else{
            return '账号授权失败';
        }

        $old_data = $accountInfo->toArray();

        try {
            $this->pddAccountModel->allowField(true)->save($data, ['id' => $id]);
            //删除缓存
            Cache::store('pddAccount')->delAccount();

            /**
             * 插入日志
             */
            $new_data = $data;
            $user = Common::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $id;

            self::addLog(
                $operator,
                ChannelAccountLog::UPDATE,
                $new_data,
                $old_data
            );
            return date('Y-m-d', time()+intval($expires_in));
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }


    public function refresh_token($id) {
        //var_dump($id);die;
        $cache = Cache::store('PddAccount');
        $account = $cache->getAccount($id);
        if(empty($account['client_id']) || empty($account['client_secret']) || empty($account['refresh_token'])){
            return json_error('帐号授权信息不完整');
        }
        $pddAccountModel = new pddAccount();
        //检测账号的token
        $pdd    = new PddApi();
        $result = $pdd->refreshToken($account);
        //var_dump($result);die;
        $expires_in=0;
        if ($result) {
            //更新token
            $temp['access_token'] = $result['access_token'];
            $temp['refresh_token'] = $result['refresh_token'];
            $temp['seller_id'] = $result['owner_id'];
            $temp['token_expire_time']= time()+intval($result['expires_in']);
            $temp['refresh_expire_time']=$result['expires_in'];
            //入库
            $pddAccountModel->where(['id' => $account['id']])->update($temp);
            $cache->delAccount($id);

            /**
             * 插入日志
             */
            $new_data = $temp;
            $old_data = $account;
            $user = Common::getUserInfo(Request::instance());
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $id;

            self::addLog(
                $operator,
                ChannelAccountLog::UPDATE,
                $new_data,
                $old_data
            );

            return json(['message' => '更新成功', 'data' => $cache->getAccount($id)]);
        }else {
            return json_error('更新失败');
        }
    }

    /** 授权页面
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function authorization($id)
    {
        $result = $this->pddAccountModel->field('client_id','client_secret')->where(['id' => $id])->select();

        return $result;
    }

    /**
     * @doc 查询
     * @param $keyword
     * @param int $page
     * @param int $pageSize
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function query($keyword, $page = 1, $pageSize = 20)
    {
        $model = new pddAccount();
        $model->whereLike("code|name", "%$keyword%");
        $model->field('code as label, name as name, id as value');
        $data = $model->page($page, $pageSize)->select();
        $model = new pddAccount();
        $model->whereLike("code|name", "%$keyword%");
        $count= $model->count();
        return ['count'=>$count, 'page'=>$page, 'pageSize'=>$pageSize, 'data'=>$data];
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
            'channel_id' => ChannelAccountConst::channel_Pdd,
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
    public function getPddLog(array $req = []): array
    {
        $page = isset($req['page']) ? intval($req['page']) : 1;
        $pageSize = isset($req['pageSize']) ? intval($req['pageSize']) : 10;
        $account_id = isset($req['id']) ? intval($req['id']) : 0;

        return (new ChannelAccountLog)->getLog(
            ChannelAccountConst::channel_Pdd, 
            $account_id, 
            true, 
            $page, 
            $pageSize
        );
    }
}