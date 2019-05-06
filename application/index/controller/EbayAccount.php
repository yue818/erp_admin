<?php

namespace app\index\controller;

use app\common\service\ebay\EbayRestful;
use think\Controller;
use think\Exception;
use think\Request;
use app\common\controller\Base;
use app\common\service\Common as CommonService;
use think\Db;
use app\common\cache\Cache;
use app\common\model\ebay\EbayAccount as EbayAccountModel;
use service\ebay\EbayProductApi;

use app\common\model\paypal\PaypalAccount as PaypalAccountModel;
use service\ebay\EbayAccountApi;
use app\common\exception\JsonErrorException;
use app\index\service\AccountService;
//use app\common\model\ServerAccountMap;z
use app\common\service\ChannelAccountConst;
use app\index\service\ManagerServer;
use app\index\service\DownloadFileService;
use app\index\service\EbaySetNotificationHelper;
use app\publish\helper\ebay\EbayPublish as ebayPublish;
use app\index\service\EbayAccountService;

/**
 * @module 账号管理
 * @title Ebay账号
 */
class EbayAccount extends Base
{

    /**
     * @title ebay账号列表
     * @author tanbin
     * @method GET
     * @apiParam name:account_name type:string desc:账号名称
     * @url /ebay-account
     * @apiRelate app\goods\controller\ChannelCategory::read
     * @apiRelate app\purchase\controller\SupplierOffer::currency
     * @apiRelate app\index\controller\PaypalAccount::index
     * @apiRelate app\index\controller\User::staffs
     * @apiRelate app\index\controller\MemberShip::memberInfo
     * @apiRelate app\index\controller\MemberShip::save
     * @apiRelate app\index\controller\MemberShip::update
     */
    public function index(Request $request)
    {
        return json(
            (new EbayAccountService)->getList($request->param()),
            200
        );
    }


    /**
     * @title 新增ebay账号
     * @author tanbin
     * @method POST
     * @apiParam name:account_name type:string desc:账号名称
     * @url /ebay-account
     */
    public function save(Request $request)
    {
        $params = $request->param();
        $data['code'] = trim(param($params, 'code'));
        $data['account_name'] = trim(param($params, 'account_name'));
        $data['type'] = param($params, 'type');
        $data['site_id'] = param($params, 'site_id');

        $data['email'] = trim(param($params, 'email'));
        $data['email_password'] = trim(param($params, 'email_password'));
        $data['phone'] = trim(param($params, 'phone'));

        $data['download_order'] = $request->post('download_order', 10);
        $data['sync_delivery'] = $request->post('sync_delivery', 0);
        $data['sync_payment'] = $request->post('sync_payment', 0);
        $data['sync_feedback'] = $request->post('sync_feedback', 0);
        $data['download_message'] = $request->post('download_message', 0);
        $data['download_listing'] = $request->post('download_listing', 0);
        $data['health_monitor'] = $request->post('health_monitor', 0);


        if (empty($params['code']) || empty($data['account_name']) || !is_numeric($data['type'])) {
            return json(['msg' => '参数不能为空'], 400);
        }
        $ebayAccount = new EbayAccountModel();
        $validateAccount = validate('EbayAccount');
        if (!$validateAccount->check($data)) {
            return json($validateAccount->getError(), 400);
        }

        //*******************过滤正确的site_id 站点，保存到数据库*********
        if ($data['site_id']) {
            $service = new AccountService();
            $site_check = $service->checkEbaySite(json_decode($data['site_id'], true));
        }
        //*******************过滤正确的site_id 站点，保存到数据库*********
        $data['site_id'] = empty($site_check) ? [] : $site_check;
        $data['site_id'] = json_encode($data['site_id']);

        \app\index\service\BasicAccountService::isHasCode(ChannelAccountConst::channel_ebay,$data['code']);
        //启动事务 
        Db::startTrans();
        try {
            $data['create_time'] = time();
            $data['update_time'] = time();
            //获取操作人信息
            $user = CommonService::getUserInfo($request);
            $data['created_user_id'] = $user['user_id'];
            $ebayAccount->allowField(true)->isUpdate(false)->save($data);

            //获取最新的数据返回
            $new_id = $ebayAccount->id;

            Db::commit();
            //删除缓存
            Cache::store('EbayAccount')->setTableRecord($new_id);

            /**
             * 插入日志
             */
            $operator = [];
            $operator['operator_id'] = $user['user_id'] ?? 0;
            $operator['operator'] = $user['realname'] ?? '';
            $operator['account_id'] = $new_id;
            $new_data = $data;
            EbayAccountService::addLog(
                $operator,
                0,
                $new_data,
                []
            );

            return json(['message' => '新增成功', 'id' => $new_id]);
        } catch (Exception $e) {
            Db::rollback();
            return json(['message' => '新增失败'], 500);
        }
    }


    /**
     * @title 查看ebay账号
     * @author tanbin
     * @method GET
     * @apiParam name:id type:int desc:ID自增长
     * @apiReturn id:账号ID
     * @apiReturn account_name:账号用户名
     * @apiReturn code:账号简称
     * @apiReturn feedback_score:信用评价
     * @apiReturn positive_feedback_percent:好评率
     * @apiReturn country_name:国家
     * @apiReturn state_or_province:省
     * @apiReturn city_name:城市
     * @apiReturn street:街道
     * @apiReturn phone:联系电话
     * @apiReturn postal_code:邮编
     * @apiReturn email:ebay邮件
     * @apiReturn register_time:注册日期
     * @apiReturn seller_show:表现
     * @apiReturn seller_level:卖家级别
     * @apiReturn seller_discount:超级卖家折扣
     * @url /ebay-account/:id
     */
    public function read($id)
    {
        $account_list = Cache::store('EbayAccount')->getTableRecord($id);
        $temp[0] = $account_list;
        $field = 'id,account_name,base_account_id,code,feedback_score,positive_feedback_percent,email,phone,country_name,state_or_province,city_name,street,phone,name,postal_code,email,register_time';
        $account_list = Cache::filter($temp, [], $field);
        $result = $account_list[0];
        $result['register_time'] = empty($result['register_time']) ? '' : date('Y-m-d H:i:s', $result['register_time']);
        $result['seller_show'] = '';
        $result['seller_level'] = '';
        $result['seller_discount'] = '';

        //$serverAccountMapModel = new ServerAccountMap();
        //$server = $serverAccountMapModel->alias('m')->field('s.id,s.name,s.ip')->where(['channel_id' => ChannelAccountConst::channel_ebay,'account_id' => $id])->join('server s','m.server_id = s.id','left')->find();
        $result['server_ip'] = '';
        $result['server_name'] = '';
        $result['server_id'] = '';
        $result && $result['site_status'] = AccountService::getSiteStatus($result['base_account_id'], $result['code']);
        return json($result, 200);
    }


    /**
     * @title 编辑
     * @author tanbin
     * @method GET
     * @apiParam name:id type:int desc:ID自增长
     * @url /ebay-account/:id/edit
     */
    public function edit($id)
    {
        $account = Cache::store('EbayAccount')->getTableRecord($id);
        $result = [
            'id' => $account['id'],
            'base_account_id' => $account['base_account_id'],
            'code' => $account['code'],
            'account_name' => $account['account_name'],
            'download_order' => (int)$account['download_order'],
            'download_message' => (int)$account['download_message'],
            'download_listing' => (int)$account['download_listing'],
            'email' => $account['email'],
            //'email_password' => $account['email_password'],
            'phone' => $account['phone'],
            'sync_payment' => (int)$account['sync_payment'],
            'sync_delivery' => (int)$account['sync_delivery'],
            'sync_feedback' => (int)$account['sync_feedback'],
            'type' => (int)$account['type'],
            'currency' => json_decode($account['currency'], true),
            'site_id' => json_decode($account['site_id'], true),
            'is_invalid' => (int)$account['is_invalid'],
            'health_monitor' => isset($account['health_monitor']) ? (int)$account['health_monitor'] : (int)0,
        ];
        if (is_null($result['currency'])) {
            $result['currency'] = [];
        }
        if (is_null($result['site_id'])) {
            $result['site_id'] = [];
        }
        //获取服务器信息
        //$serverAccountMapModel = new ServerAccountMap();
        // $server = $serverAccountMapModel->alias('m')->field('s.id,s.name,s.ip')->where(['channel_id' => ChannelAccountConst::channel_ebay,'account_id' => $id])->join('server s','m.server_id = s.id','left')->find();
        $result['server_ip'] = '';
        $result['server_name'] = '';
        $result['server_id'] = '';
        $result && $result['site_status'] = AccountService::getSiteStatus($result['base_account_id'], $result['code']);
        return json($result, 200);
    }


    /**
     * @title 更新
     * @author tanbin
     * @method PUT
     * @apiParam name:id type:int desc:ID自增长
     * @url /ebay-account/:id
     */
    public function update(Request $request, $id)
    {
        $old_data = $info = EbayAccountModel::get($id);
        if (empty($info)) {
            return json(['msg' => '该数据不存在！'], 400);
        }

        $params = $request->param();
        $data['id'] = $info['id'];
        $data['account_name'] = trim($info['account_name']);
        // 产品要求 code 不能修改
        // $data['code'] = trim(param($params, 'code'));
        $data['code'] = $old_data['code'];
        $site_id = json_decode(param($params, 'site_id'), true);
        $data['type'] = param($params, 'type');

        $data['email'] = trim(param($params, 'email'));
        $data['email_password'] = trim(param($params, 'email_password'));
        $data['phone'] = trim(param($params, 'phone'));


        //*******************过滤正确的site_id 站点，保存到数据库*********
        if ($site_id) {
            $service = new AccountService();
            $site_check = $service->checkEbaySite($site_id);
        }
        //*******************过滤正确的site_id 站点，保存到数据库*********
        $data['site_id'] = empty($site_check) ? [] : $site_check;
        $data['site_id'] = json_encode($data['site_id']);

        $data['download_order'] = param($params, 'download_order') > 0 ? (int)$params['download_order'] : 0;
        $data['sync_delivery'] = param($params, 'sync_delivery') > 0 ? (int)$params['sync_delivery'] : 0;
        $data['sync_payment'] = param($params, 'sync_payment') > 0 ? (int)$params['sync_payment'] : 0;
        $data['sync_feedback'] = param($params, 'sync_feedback') > 0 ? (int)$params['sync_feedback'] : 0;
        $data['download_message'] = param($params, 'download_message') > 0 ? (int)$params['download_message'] : 0;
        $data['download_listing'] = param($params, 'download_listing') > 0 ? (int)$params['download_listing'] : 0;
        $data['health_monitor'] = param($params, 'health_monitor', 0);


        //验证数据
        $validateAccount = validate('EbayAccount');
        if (!$validateAccount->check($data)) {
            return json($validateAccount->getError(), 400);
        }

        $model = new EbayAccountModel();

        $user = CommonService::getUserInfo($request);
        $operator = [];
        $operator['operator_id'] = $user['user_id'];
        $operator['operator'] = $user['realname'];

        //启动事务
        Db::startTrans();
        try {
            $data['update_time'] = time();
            if (empty($data)) {
                return json(['message' => '数据参数不能为空'], 200);
            }
            $model->allowField(true)->save($data, ['id' => $id]);

            $managerServer = new ManagerServer();
            //获取最新的数据返回
            if (isset($data['server_id']) && !empty($data['server_id'])) {
                $managerServer->addAccountMap(ChannelAccountConst::channel_ebay, $id, $data['server_id']);
            }

            $new_data = $data;
            $new_data['site_status'] = isset($params['site_status']) ? intval($params['site_status']) : 0;

            if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                $old_data['site_status'] = AccountService::setSite(
                    ChannelAccountConst::channel_ebay,
                    $old_data,
                    $operator['operator_id'],
                    $new_data['site_status']
                );
            }
            $operator['account_id'] = $old_data['id'];
            EbayAccountService::addLog(
                $operator,
                1,
                $new_data,
                $old_data
            );

            Db::commit();
            //更新缓存
            $cache = Cache::store('EbayAccount');
            foreach($data as $key=>$val) {
                $cache->updateTableRecord($id, $key, $val);
            }
            return json(['message' => '更新成功'], 200);
        } catch (Exception $e) {
            Db::rollback();
            return json(['message' => '更新失败' . $e->getMessage()], 500);
        }
    }


    /**
     * @title ebay批量设置批量设置抓取参数；
     * @author 冬
     * @method post
     * @apiParam ids:多个ID用英文,分割
     * @url /ebay-account/set
     */
    public function batchSet(Request $request)
    {
        try {
            $params = $request->post();
            $result = $this->validate($params, [
                'ids|帐号ID' => 'require|min:1',
                'status|系统状态' => 'require|number',
                'download_order|抓取eBay订单功能' => 'require|number',
                'download_message|抓取eBay站内信功能' => 'require|number',
                'download_listing|抓取eBay Listing功能' => 'require|number',
                'sync_payment|同步付款状态到eBay功能' => 'require|number',
                'sync_feedback|同步eBay好评功能' => 'require|number',
                'sync_delivery|同步发货状态到eBay功能' => 'require|number',
                'health_monitor|同步eBay账号健康数据' => 'require|number'
            ]);
            if ($result !== true) {
                throw new Exception($result);
            }
            $model = new EbayAccountModel();

            $data['is_invalid'] = (int)$params['status'];
            $data['download_order'] = (int)$params['download_order'];
            $data['sync_delivery'] = (int)$params['sync_delivery'];
            $data['sync_payment'] =  (int)$params['sync_payment'];
            $data['sync_feedback'] = (int)$params['sync_feedback'];
            $data['download_message'] = (int)$params['download_message'];
            $data['download_listing'] = (int)$params['download_listing'];
            $data['health_monitor'] = (int)$params['health_monitor'];

            $idArr = array_merge(array_filter(array_unique(explode(',', $params['ids']))));

            $data['update_time'] = time();

            $new_data = $data;
            isset($params['site_status']) and $new_data['site_status'] = intval($params['site_status']);

            /**
             * 判断是否可更改状态
             */
            if (isset($new_data['is_invalid'])) {
                (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_ebay, $idArr);
            }

            $old_data_list = $model->where('id', 'in', $idArr)->select();

            $user = CommonService::getUserInfo($request);
            $operator = [];
            $operator['operator_id'] = $user['user_id'];
            $operator['operator'] = $user['realname'];

            if (empty($data)) {
                return json(['message' => '数据参数不能为空'], 200);
            }
            $model->allowField(true)->update($data, ['id' => ['in', $idArr]]);

            Db::commit();
            //更新缓存,写入日志
            $cache = Cache::store('EbayAccount');
            foreach ($old_data_list as $old_data) {
                if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                    $old_data['site_status'] = AccountService::setSite(
                        ChannelAccountConst::channel_ebay,
                        $old_data,
                        $operator['operator_id'],
                        $new_data['site_status']
                    );
                }
                $operator['account_id'] = $old_data['id'];
                /**
                 * 插入日志
                 */
                EbayAccountService::addLog(
                    $operator,
                    1,
                    $new_data,
                    $old_data
                );
                foreach($data as $key=>$val) {
                    $cache->updateTableRecord($old_data['id'], $key, $val);
                }
            }

            return json(['message' => '更新成功'], 200);
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }


    /**
     * 删除指定资源
     * @disabled
     */
    public function delete($id)
    {

    }


    /**
     * @title 启用/停用 账号
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @url /ebay-account/status
     */
    public function changeStatus(Request $request)
    {
        $params = $request->param();
        if (!isset($params['id']) || !isset($params['status'])) {
            return json(['message' => '参数错误[id,status]'], 400);
        }

        $id = $params['id'] ?? 0;
        $status = $params['status'] ?? null;

        $response = (new EbayAccountService())->changeStatus($id, $status);
        if ($response === true) {
            return json(['message' => '切换系统状态成功'], 200);
        }

        return json(['message' => $response], 400);
    }


    /**
     * @title 获取session的ID
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @url /ebay-account/getEbaySessionId
     */
    public function getEbaySessionId()
    {

        $request = Request::instance();
        $accountId = $request->post('id', '');
        if (empty($accountId)) {
            return json(['msg' => '参数缺失'], 400);
        }
        $account = Cache::store('EbayAccount')->getTableRecord($accountId);

        /*****************  update by tb 2017.05.10 **********************************/
        if (empty($account)) {
            throw  new JsonErrorException('此用户数据不存在！');
        }
        $runame = $account['ru_name'];
        $runame_url = 'https://signin.ebay.com/ws/eBayISAPI.dll?SignIn&runame=' . $runame;
        $config = [
            'devID' => $account['dev_id'],
            'appID' => $account['app_id'],
            'certID' => $account['cert_id'],
        ];
        $ebay = new EbayAccountApi($config);
        $sessId = $ebay->getSessionID($runame);

        if ($sessId) {
            $url = $runame_url . "&SessID=" . $sessId;
            $rs = array("url" => $url, 'sessId' => $sessId);
            return json(['message' => $rs], 200);
        } else {
            return json(['message' => 'Session 获取失败'], 400);
        }

        /*****************  update by tb 2017.05.10 **********************************/

    }


    /**
     * @title 获取援权的token
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @apiParam name:sessId type:string desc:sessId
     * @url /ebay-account/getFetchEbayToken
     */
    public function getFetchEbayToken()
    {
        $request = Request::instance();
        $accountId = $request->post('id', '');
        $sessionId = $request->post('sessId', '');
        if (empty($accountId)) {
            return json(['message' => '账号id参数缺失'], 400);
        }
        if (empty($sessionId)) {
            return json(['message' => 'sessionId参数缺失'], 400);
        }
        //$sessionId   = json_decode($sessionId,true);

        $account = Cache::store('EbayAccount')->getTableRecord($accountId);

        if (empty($account)) {
            return json(['message' => '账号不存在'], 400);
        }
        $old_data = $account;

        $config = [
            'devID' => $account['dev_id'],
            'appID' => $account['app_id'],
            'certID' => $account['cert_id'],
        ];
        $ebay = new EbayAccountApi($config);
        $result = $ebay->getFetchEbayToken($sessionId);

        /*****************  update by tb 2017.05.10 **********************************/

        try {

            $ebayAccount = new EbayAccountModel();
            if ($result && $result->Ack == 'Success') {
                if (!empty($result->eBayAuthToken)) {
                    $data['token'] = '["' . $result->eBayAuthToken . '"]';
                    $data['token_invalid_time'] = strtotime($result->HardExpirationTime);
                    $data['update_time'] = time();
                    $data['account_status'] = 1;
                    $data['token_valid_status'] = 1;

                    $ebayAccount->allowField(true)->save($data, ['id' => $accountId]);

                    $new_data = $data;

                    $cache = Cache::store('EbayAccount');
                    //缓存里面是字符串，不是JSON，所以变回来；
                    if (is_string($result->eBayAuthToken)) {
                        $data['token'] = $result->eBayAuthToken;
                    } else {
                        $tokenArr = json_decode($data['token'], true);
                        $data['token'] = $tokenArr[0] ?? '';
                    }
                    foreach($data as $key=>$val) {
                        $cache->updateTableRecord($accountId, $key, $val);
                    }

                    /**
                     * 插入日志
                     */
                    $user = CommonService::getUserInfo($request);
                    $operator = [];
                    $operator['operator_id'] = $user['user_id'];
                    $operator['operator'] = $user['realname'];
                    EbayAccountService::addLog(
                        $operator,
                        1,
                        $new_data,
                        $old_data
                    );

                    return json(['message' => '获取Token有效 ', 'token' => $result->eBayAuthToken], 200);
                } else {
                    return json(['message' => '获取Token无效'], 400);
                }
            } else {
                return json(['message' => '获取Token 失败'], 400);
            }
        } catch (Exception $e) {
            return json(['message' => '数据异常'], 400);
        }

    }


    /**
     * @title 检测账号用户
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @apiParam name:sessId type:string desc:sessId
     * @url /ebay-account/getConfirmIdentity
     */
    public function getConfirmIdentity($sessionId)
    {
        $request = Request::instance();
        $accountId = $request->get('id', '');
        if (empty($accountId)) {
            return json(['msg' => '参数缺失'], 400);
        }

        $account = Cache::store('EbayAccount')->getTableRecord($accountId);
        if (empty($account)) {
            return json(['message' => '账号不存在'], 400);
        }
        $config = [
            'devID' => $account['dev_id'],
            'appID' => $account['app_id'],
            'certID' => $account['cert_id'],
        ];
        $ebay = new EbayAccountApi($config);
        $result = $ebay->getConfirmIdentity($sessionId);

        return $result;
    }


    /**
     * @title 验证ebay的token是否有效
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @apiParam name:sessId type:string desc:sessId
     * @url /ebay-account/geteBayOfficialTime
     */
    public function geteBayOfficialTime($accountId = 0, $userToken)
    {
        if (empty($accountId)) {
            return json(['msg' => '参数缺失'], 400);
        }
        $account = Cache::store('EbayAccount')->getTableRecord($accountId);
        if (empty($account)) {
            return json(['message' => '账号不存在'], 400);
        }

        $config = [
            'devID' => $account['dev_id'],
            'appID' => $account['app_id'],
            'certID' => $account['cert_id'],
        ];
        $ebay = new EbayAccountApi($config);
        $result = $ebay->geteBayOfficialTime();
        return $result;
    }

    /**
     * @title 查看 - ebay账号绑定paypal
     * @author tanbin
     * @method GET
     * @apiParam name:id type:int require:1 desc:id
     * @url /ebay-account/mapPaypal/view
     * @apiRelate app\purchase\controller\SupplierOffer::currency
     * @return \think\Response
     */
    public function ebayMapPaypalView(Request $request)
    {
        $account_id = $request->get('id', '');
        if (!$account_id) {
            return json(['message' => '参数错误：id为空'], 400);
        }
        $account = Cache::store('EbayAccount')->getTableRecord($account_id);
        if (empty($account)) {
            return json(['message' => '数据不存在！'], 400);
        }
        $min_paypal = $this->conversionPaypal($account['min_paypal_id']);
        $max_paypal = $this->conversionPaypal($account['max_paypal_id']);

        //更新老数据；
        $json_min = json_encode($min_paypal);
        $json_max = json_encode($max_paypal);
        if($json_min != $account['min_paypal_id'] || $json_max != $account['max_paypal_id']) {
            //更新缓存
            $cache = Cache::store('EbayAccount');
            $cache->updateTableRecord($account_id, 'min_paypal_id', $json_min);
            $cache->updateTableRecord($account_id, 'max_paypal_id', $json_max);
            //更新数据库
            $ebayAccount = new EbayAccountModel();

            $accountInfo = $ebayAccount->where(['id' => $account_id])->find();

            $old_data = $accountInfo->toArray();
            $new_data = ['min_paypal_id' => $json_min, 'max_paypal_id' => $json_max];

            $ebayAccount->allowField(true)->save($new_data, ['id' => $account_id]);

            /**
             * 插入日志
             */
            $user = CommonService::getUserInfo($request);
            $operator = [];
            $operator['operator_id'] = $user['user_id'];
            $operator['operator'] = $user['realname'];
            $operator['account_id'] = $old_data['id'];
            EbayAccountService::addLog(
                $operator,
                1,
                $new_data,
                $old_data
            );
        }

        $result = [
            'id' => $account['id'],
            'account_name' => $account['account_name'],
            'min_paypal_id' => $min_paypal,
            'max_paypal_id' => $max_paypal,
            'currency' => !empty($account['currency']) ? json_decode($account['currency'], true) : ''
        ];
        unset($account_id);
        unset($account);
        return json($result, 200);

    }


    /**
     * @title ebay绑定paypal邮箱
     * @author tanbin
     * @method POST
     * @apiParam name:id type:int desc:ID自增长
     * @apiParam name:min_paypal_id type:int desc:小额Paypal收款账户
     * @apiParam name:max_paypal_id type:int desc:大额Paypal收款账户
     * @apiParam name:currency type:json desc:小额收款条件
     * @url /ebay-account/ebayMapPaypal
     */
    public function ebayMapPaypal(Request $request)
    {
        $params = $request->param();
        $data['id'] = $params['id'];

        if (empty($data['id'])) {
            return json(['message' => 'Id参数缺失'], 400);
        }

        //判断数据是否存在
        $account = EbayAccountModel::field('id,is_invalid,min_paypal_id,max_paypal_id')->where(['id' => $data['id']])->find();
        if (empty($account)) {
            return json(['message' => '账号不存在'], 400);
        }

        $id = $data['id'];
        $data['min_paypal_id'] = $params['min_paypal_id'] ?? 0;
        $data['max_paypal_id'] = $params['max_paypal_id'] ?? 0;

        //把参数转成数组；
        $data['min_paypal_id'] = $this->conversionPaypal($data['min_paypal_id']);
        $data['max_paypal_id'] = $this->conversionPaypal($data['max_paypal_id']);

        $data['currency'] = param($params, 'currency');
        $ebayAccount = new EbayAccountModel();
        $ebayPublish = new ebayPublish();
        /** 在线listing更换paypal账户信息 linpeng time 2019/1/26 17:08 */
        $listingData = [
            'account_id' => intval($id),
            'old_max_paypal' => param($account, 'max_paypal_id') ? json_decode($account['max_paypal_id'], true) : [],
            'old_min_paypal' => param($account, 'min_paypal_id') ? json_decode($account['min_paypal_id'], true) : [],
            'max_paypal' => $data['max_paypal_id'],
            'min_paypal' => $data['min_paypal_id']
        ];

        $old_data = $account;

        $user = CommonService::getUserInfo($request);
        $operator = [];
        $operator['operator_id'] = $user['user_id'];
        $operator['operator'] = $user['realname'];
        $operator['account_id'] = $old_data['id'];

        //启动事务
        Db::startTrans();
        try {
            //更新数据
            $min_paypal = $data['min_paypal_id'];
            $max_paypal = $data['max_paypal_id'];
            $data['min_paypal_id'] = json_encode($data['min_paypal_id']);
            $data['max_paypal_id'] = json_encode($data['max_paypal_id']);
            //获取需要插入日志的最新数据
            $new_data = $data;

            $ebayAccount->allowField(true)->save($data, ['id' => $id]);

            //插入日志
            EbayAccountService::addLog(
                $operator,
                1,
                $new_data,
                $old_data
            );

            $ebayPublish->updateOLListingPaypal($listingData);

            //ebay账号如果启用状态，则开启paypal
            if ($account['is_invalid'] == 1) {
                $update = [
                    'is_invalid' => 1
                ];
                $update_id = [];
                foreach($min_paypal as $arr) {
                    $update_id[] = $arr['id'];
                }
                foreach($max_paypal as $arr) {
                    $update_id[] = $arr['id'];
                }
                //不为空，才更新paypal
                if(!empty($update_id)) {
                    $where['id'] = ['in', $update_id];
                    PaypalAccountModel::update($update, $where);
                    //删除paypal缓存
                    Cache::handler()->del('cache:paypalAccount');
                }
            }

            Db::commit();
            //更新缓存
            $cache = Cache::store('EbayAccount');
            foreach($data as $key=>$val) {
                $cache->updateTableRecord($id, $key, $val);
            }

            return json(['message' => '更新成功'], 200);
        } catch (Exception $e) {
            Db::rollback();
            var_dump($e->getMessage());
            die;
            return json(['message' => '更新失败'], 500);
        }
    }

    private function conversionPaypal($paypal) {
        //本地 type = 1; 海外 type = 2;
        if(is_numeric($paypal)) {
            $paypal = (int)$paypal;
            if($paypal == 0) {
                return [];
            }
            return [['id' => $paypal, 'type' => 1], ['id' => $paypal, 'type' => 2]];
        } else if (is_array($paypal)){
            $new_array = [];
            foreach($paypal as $val) {
                if(isset($val['id'], $val['type'])) {
                    $new_array[] = $val;
                }
            }
            return $new_array;
        } else {
            $paypal = json_decode($paypal, true);
            if(!is_array($paypal)) {
                return [];
            }
            $new_array = [];
            foreach($paypal as $val) {
                if(isset($val['id'], $val['type'])) {
                    $new_array[] = $val;
                }
            }
            return $new_array;
        }
    }


    /**
     * @title ebay帐号绑定paypal下载；
     * @author zahngdongdong
     * @method GET
     * @url /ebay-account/down
     */
    public function down()
    {
        $request = Request::instance();
        $where = [];
        $params = $request->param();
        if (isset($params['snType']) && !empty(param($params, 'snText'))) {
            switch ($params['snType']) {
                case 'account_name':
                    $where['account_name'] = ['EQ', $params['snText']];
                    break;
                case 'code':
                    $where['code'] = ['EQ', $params['snText']];
                    break;
                default:
                    break;
            }
        }
        if (isset($params['token_valid_status'])) {
            if ($params['token_valid_status'] >= 0) {
                $where['token_valid_status'] = ['EQ', $params['token_valid_status']];
            }
        }
        if (isset($params['is_invalid'])) {
            if (is_numeric($params['is_invalid'])) {
                if ($params['is_invalid'] >= 0) {
                    $where['is_invalid'] = ['EQ', $params['is_invalid']];
                }
            }
        }
        if (isset($params['account_status'])) {
            $where['account_status'] = ['EQ', $params['account_status']];
        }
        if (isset($params['site_id'])) {
            if (!empty($params['site_id'])) {
                $where['binary site_id'] = ['like', '%"' . $params['site_id'] . '"%'];
            }
        }

        $ids = $request->get('ids', '');
        $ids = array_filter(explode(',', $ids));
        if(!empty($ids)) {
            $where['id'] = ['in', $ids];
        }

        //列表字段：序号、简称、eBay账户、站点、eBay状态、大额PayPal-本地仓、大额PayPal-海外仓、小额PayPal-本地仓、小额PayPal-海外仓
        $accountLists = EbayAccountModel::field('*')->where($where)->select();
        $paypalList = PaypalAccountModel::field('*')->column('account_name', 'id');
        $exportLists = [];
        foreach($accountLists as $key => $account) {
            $exportLists[$key]['id'] = $account['id'];
            $exportLists[$key]['code'] = $account['code'];
            $exportLists[$key]['account_name'] = $account['account_name'];
            $exportLists[$key]['site_id'] = json_decode($account['site_id'], true);
            $exportLists[$key]['is_invalid'] = $account['is_invalid'] == 0? '停用' : '启用';
            $max_paypal = $this->conversionPaypal($account['max_paypal_id']);
            $min_paypal = $this->conversionPaypal($account['min_paypal_id']);

            $exportLists[$key]['max_paypal_local'] = [];
            $exportLists[$key]['max_paypal_sea'] = [];
            $exportLists[$key]['min_paypal_local'] = [];
            $exportLists[$key]['min_paypal_sea'] = [];
            foreach($max_paypal as $paypal) {
                if($paypal['type'] == 1) {
                    $exportLists[$key]['max_paypal_local'][] = $paypalList[$paypal['id']]?? 'paypal未知ID：'. $paypal['id'];
                } else if($paypal['type'] == 2) {
                    $exportLists[$key]['max_paypal_sea'][] = $paypalList[$paypal['id']]?? 'paypal未知ID：'. $paypal['id'];
                }
            }
            foreach($min_paypal as $paypal) {
                if($paypal['type'] == 1) {
                    $exportLists[$key]['min_paypal_local'][] = $paypalList[$paypal['id']]?? 'paypal未知ID：'. $paypal['id'];
                } else if($paypal['type'] == 2) {
                    $exportLists[$key]['min_paypal_sea'][] = $paypalList[$paypal['id']]?? 'paypal未知ID：'. $paypal['id'];
                }
            }
            foreach($exportLists[$key] as $k => $v) {
                if(is_array($v)) {
                    $exportLists[$key][$k] = implode("\r\n", $exportLists[$key][$k]);
                }
            }
        }

        try {
            $header = [
                ['title' => '序号', 'key' => 'id', 'width' => 10],
                ['title' => '简称', 'key' => 'code', 'width' => 20],
                ['title' => 'eBay账户', 'key' => 'account_name', 'width' => 20],
                ['title' => '站点', 'key' => 'site_id', 'width' => 20],
                ['title' => 'eBay状态', 'key' => 'is_invalid', 'width' => 20],
                ['title' => '大额PayPal-本地仓', 'key' => 'max_paypal_local', 'width' => 30],
                ['title' => '大额PayPal-海外仓', 'key' => 'max_paypal_sea', 'width' => 30],
                ['title' => '小额PayPal-本地仓', 'key' => 'min_paypal_local', 'width' => 30],
                ['title' => '小额PayPal-海外仓', 'key' => 'min_paypal_sea', 'width' => 30],
            ];

            $file = [
                'name' => 'Ebay帐号列表',
                'path' => 'index'
            ];
            $ExcelExport = new DownloadFileService();
            $result = $ExcelExport->export($exportLists, $header, $file);
            return json($result);

        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @title ebay帐号获取通知配置；
     * @author zahngdongdong
     * @method GET
     * @url /ebay-account/getevent
     */
    public function getEventfield() {
        try {
            $account_id = request()->get('account_id', 0);
            $sync = request()->get('sync', 0);
            if($account_id == 0) {
                return json(['message' => '缺少参数account_id'], 400);
            }
            $notificationHelper = new EbaySetNotificationHelper();
            $fields = $notificationHelper->getNotificationField($account_id, $sync);
            return json(['data' => $fields]);
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @title ebay帐号设置通知配置；
     * @author zahngdongdong
     * @method POST
     * @url /ebay-account/setEvent
     */
    public function setNotification() {
        try {
            $data = request()->post();
            $result = $this->validate($data, [
                'account_id|ebay帐号ID' => 'require|number',
            ]);
            if ($result !== true) {
                return json(['message' => $result], 400);
            }

            $params = empty($data['data'])? [] : json_decode($data['data'], true);
            $notificationHelper = new EbaySetNotificationHelper();
            $result = $notificationHelper->setNotificationEvent($data['account_id'], $params);

            if($result !== false) {
                return json(['message' => '设置成功', 'data' => $result]);
            } else {
                return json(['message' => '设置失败'], 400);
            }
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }


    /**
     * @title oauth 认证时，获取登录链接
     * @author wlw2533
     * @method GET
     * @url /ebay-account/:account_id/oauth-login
     */
    public function getOAuthLoginUrl($account_id)
    {
        try {
            $accountInfo = EbayAccountModel::get($account_id);
            if (empty($accountInfo)) {
                throw new Exception('获取账号信息失败');
            }
            $accountInfo = $accountInfo->toArray();
            $url = 'https://auth.ebay.com/oauth2/authorize?client_id=';
            $url .= $accountInfo['app_id'];
            $url .= '&response_type=code&redirect_uri='.$accountInfo['ru_name'];
            $url .= '&scope=https://api.ebay.com/oauth/api_scope ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.marketing.readonly ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.marketing ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.inventory ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.account.readonly ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.account ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.fulfillment ';
            $url .= 'https://api.ebay.com/oauth/api_scope/sell.analytics.readonly';//注意最后没有空格
//            $url .= 'https://api.ebay.com/oauth/api_scope/commerce.catalog.readonly';
            return json(['result'=>true, 'data'=>$url], 200);
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @title oauth 认证时，获取token并保存
     * @author wlw2533
     * @method POST
     * @url /ebay-account/:account_id/oauth-token
     * @param Request $request
     * @param $account_id
     * @throws Exception
     */
    public function getOAuthToken(Request $request, $account_id)
    {
        try {
            $accountInfo = EbayAccountModel::get($account_id);
            if (empty($accountInfo)) {
                throw new Exception('获取账号信息失败');
            }
            $old_data = $accountInfo = $accountInfo->toArray();
            
            $oauthCode = $request->param('oauth_code');
            $oauthCode = urldecode($oauthCode);
//            Cache::handler()->set('ebay:debug:oauth_code', $oauthCode);
            //header
            $header['Content-Type'] = 'application/x-www-form-urlencoded';
            $baseEncodeCredentials = base64_encode($accountInfo['app_id'].':'.$accountInfo['cert_id']);
            $header['Authorization'] = 'Basic '.$baseEncodeCredentials;
            //url
            $url = 'https://api.ebay.com/identity/v1/oauth2/token';
            //post data
            $data['grant_type'] = 'authorization_code';
            $data['redirect_uri'] = $accountInfo['ru_name'];
            $data['code'] = $oauthCode;

            $restful = new EbayRestful('POST', $header);
            $response = $restful->sendRequest($url, $data);
            $res = json_decode($response, true);
            if (isset($res['error'])) {
                throw new Exception($res['error_description']);
            }
            $update['id'] = $account_id;
            $update['oauth_token'] = $res['access_token'];
            $update['ot_invalid_time'] = time() + $res['expires_in'];
            $update['oauth_refresh_token'] = $res['refresh_token'];
            $update['ort_invalid_time'] = time() + $res['refresh_token_expires_in'];

            $new_data = $update;

            EbayAccountModel::update($update);

            //插入日志
            $user = CommonService::getUserInfo($request);
            $operator = [];
            $operator['operator_id'] = $user['user_id'];
            $operator['operator'] = $user['realname'];
            $operator['account_id'] = $old_data['id'];
            EbayAccountService::addLog(
                $operator,
                1,
                $new_data,
                $old_data
            );

            return json(['result'=>true, 'message'=>'获取成功'], 200);
        } catch (Exception $e) {
            return json($e->getFile().'|'.$e->getLine().'|'.$e->getMessage(), 500);
        }
    }

    /**
     * @title 获取Ebay账号日志
     * @method get
     * @url /ebay-account/log/:id
     * @param  \think\Request $request
     * @param  string $site
     * @return \think\Response
     */
    public function getLog(Request $request)
    {
        return json(
            (new EbayAccountService)->getEbayLog($request->param())
            , 200
        );
    }
}