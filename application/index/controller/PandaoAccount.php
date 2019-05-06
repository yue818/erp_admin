<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 18-4-24
 * Time: 上午10:58
 */

namespace app\index\controller;

use app\common\cache\Cache;
use app\common\controller\Base;
use app\common\exception\JsonErrorException;
use app\common\service\Common;
use app\index\service\PandaoAccountService;
use think\Db;
use think\Exception;
use think\Request;
use app\common\model\pandao\PandaoAccount as PandaoAccountModel;
use app\index\service\AccountService;
use app\common\model\ChannelAccountLog;
use app\common\service\ChannelAccountConst;

/**
 * @module 账号管理
 * @title pandao账号
 * @url /pandao-account
 * @package app\publish\controller
 * @author joy
 */
class PandaoAccount extends Base
{
    /**
     * @title pandao账号列表
     * @method GET
     * @param Request $request
     * @return \think\response\Json
     * @throws \Exception
     */
    public function index(Request $request)
    {
        try{
            $response = (new PandaoAccountService())->getList($request->param());
            // $params = $request->param();
            // $page = $request->get('page', 1);
            // $pageSize = $request->get('pageSize', 10);
            // $response = (new PandaoAccountService())->getList($params,$page,$pageSize);
            return json($response);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @title 添加账号
     * @method POST
     * @url /pandao-account/add
     * @param Request $request
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        try{
            $params = $request->param();
            $user = Common::getUserInfo($request);
            $uid = $user['user_id'];
            $response = (new PandaoAccountService())->add($params,$uid);
            return json($response);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * @title  pandao账号授权
     * @method post
     * @url /pandao-account/authorization
     * @param Request $request
     * @return \think\response\Json
     */

    public function authorization(Request $request)
    {
        try{
            $params = $request->param();
            $uid = Common::getUserInfo($request) ? Common::getUserInfo($request)['user_id'] : 0;
            $response = (new PandaoAccountService())->authorization($params, $uid);
            return json($response);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }
    /**
     * @title 查看账号
     * @method get
     * @param  int $id
     * @url /pandao-account/:id
     * @param Request $request
     * @return \think\response\Json
     */
    public function read($id)
    {
        $response = (new PandaoAccountService())->getOne($id);
        $response['site_status'] = AccountService::getSiteStatus($response['base_account_id'], $response['code']);
        return json($response);
    }
    /**
     * @title 编辑账号
     * @method get
     * @param  int $id
     * @url /pandao-account/:id/edit
     * @param Request $request
     * @return \think\response\Json
     */
    public function edit($id)
    {
        $response = (new PandaoAccountService())->getOne($id);
        $response['site_status'] = AccountService::getSiteStatus($response['base_account_id'], $response['code']);
        return json($response);
    }

    /**
     * @title 更新账号
     * @method POST
     * @url /pandao-account/update
     * @return \think\Response
     */
    public function update(Request $request)
    {
        $params = $request->param();
        $user = Common::getUserInfo($request);
        $uid = $user['user_id'];
        $response = (new PandaoAccountService())->update($params,$uid);
        return json($response);
    }

    /**
     * @title 停用，启用账号
     * @method post
     * @url /pandao-account/states
     */
    public function changeStatus(Request $request)
    {
        $params = $request->param();

        if (!isset($params['id']) || !isset($params['is_invalid'])) {
            return json(['message' => '参数错误[id,is_invalid]'], 400);
        }

        $id = $params['id'] ?? 0;
        $status = $params['is_invalid'] ?? null;

        $response = (new PandaoAccountService())->changeStatus($id, $status);
        if ($response === true) {
            return json(['message' => '切换系统状态成功'], 200);
        }

        return json(['message' => $response], 400);
    }

    /**
     * @title 批量开启
     * @url batch-set
     * @method post
     * @param Request $request
     * @return \think\response\Json
     * @throws Exception
     */
    public function batchSet(Request $request)
    {
        $params = $request->post();
        $result = $this->validate($params,[
            'ids|帐号ID' => 'require|min:1',
            'is_invalid|系统状态' => 'require|number',
            'sync_listing|抓取Listing数据' => 'require|number',
            'download_order|抓取订单数据' => 'require|number',
            'sync_delivery|同步发货时间' => 'require|number',
        ]);

        if ($result != true) {
            throw new Exception($result);
        }

        //实例化模型
        $model = new PandaoAccountModel();

        if (isset($params['is_invalid']) && $params['is_invalid'] != '') {
            $data['is_invalid'] = (int)$params['is_invalid'];   //0-停用 1-启用
        }
        if (isset($params['sync_listing']) && $params['sync_listing'] != '') {
            $data['sync_listing'] = (int)$params['sync_listing'];
        }
        if (isset($params['download_order']) && $params['download_order'] != '') {
            $data['download_order'] = (int)$params['download_order'];
        }
        if (isset($params['sync_delivery']) && $params['sync_delivery'] != '') {
            $data['sync_delivery'] = (int)$params['sync_delivery'];
        }

        $idArr = array_merge(array_filter(array_unique(explode(',',$params['ids']))));
        $old_data_list = $model->where(['id' => ['in', $idArr]])->select();

        $user = Common::getUserInfo($request);
        $operator = [];
        $operator['operator_id'] = $user['user_id'] ?? 0;
        $operator['operator'] = $user['realname'] ?? '';

        /**
         * 判断是否可更改状态
         */
        if (isset($data['is_invalid'])) {
            (new \app\index\service\ChannelAccountService())->checkChangeStatus(ChannelAccountConst::channel_Pandao, $idArr);
        }

        //开启事务
        Db::startTrans();
        try {
            if (empty($data)) {
                return json(['message' => '数据参数不能为空'], 200);
            }
            $data['update_time'] = time();
            $new_data = $data;
            $model->allowField(true)->update($data, ['id' => ['in', $idArr]]);

            $new_data['site_status'] = isset($params['site_status']) ? intval($params['site_status']) : 0;
            foreach ($old_data_list as $old_data) {
                if (in_array($new_data['site_status'], [1, 2, 3, 4])) {
                    $old_data['site_status'] = AccountService::setSite(
                        ChannelAccountConst::channel_Pandao,
                        $old_data,
                        $operator['operator_id'],
                        $new_data['site_status']
                    );
                }
                $operator['account_id'] = $old_data['id'];
                PandaoAccountService::addLog(
                    $operator, 
                    ChannelAccountLog::UPDATE, 
                    $new_data, 
                    $old_data
                );
            }

            Db::commit();
            return json(['message' => '更新成功'], 200);
        } catch (Exception $ex) {
            Db::rollback();
            return json(['message' => '更新失败,' . $ex->getMessage()], 400);
        }
    }

    /**
     * @title 获取Mymall账号日志
     * @method get
     * @url /pandao-account/log/:id
     * @param  \think\Request $request
     * @param  string $site
     * @return \think\Response
     */
    public function getLog(Request $request)
    {
        return json(
            (new PandaoAccountService)->getPandaoLog($request->param())
            , 200
        );
    }
}