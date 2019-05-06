<?php


namespace app\index\controller;


use app\common\controller\Base;
use app\common\service\Common;
use app\index\service\ChannelDistribution as ServiceChannelDistribution;
use think\Exception;

/**
 * @title 平台分配设置
 * @module 基础设置
 * @url /channel-distribution
 * @author starzhan <397041849@qq.com>
 */
class ChannelDistribution extends Base
{
    /**
     * @title 获取展示的产品状态
     * @method get
     * @url status
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function getStatus()
    {
        $service = new ServiceChannelDistribution();
        return json($service->getStatus(), 200);
    }

    /**
     * @title 获取一级分类
     * @url first-categories
     * @author starzhan <397041849@qq.com>
     */
    public function getFirstCategories()
    {
        $service = new ServiceChannelDistribution();
        return json($service->getFirstCategories(), 200);
    }

    /**
     * @title 获取站点
     * @url :id/sites
     * @author starzhan <397041849@qq.com>
     */
    public function getSites($id)
    {
        $service = new ServiceChannelDistribution();
        return json($service->getSites($id), 200);
    }

    /**
     * @title 获取平台帐号
     * @param $id
     * @url :id/accounts
     * @method get
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function getAccounts($id)
    {
        $param = $this->request->param();
        $service = new ServiceChannelDistribution();
        return json($service->getAccounts($id, $param), 200);
    }

    /**
     * @title 获取平台部门
     * @param $id
     * @method get
     * @url :id/departments
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function getDepartments($id)
    {
        $service = new ServiceChannelDistribution();
        return json($service->getDepartments($id), 200);
    }

    /**
     * @title 获取受限职位
     * @method get
     * @url positions
     * @author starzhan <397041849@qq.com>
     */
    public function getPositions()
    {
        $service = new ServiceChannelDistribution();
        return json($service->getPositions(), 200);
    }

    /**
     * @title 整个保存
     * @author starzhan <397041849@qq.com>
     */
    public function update($id)
    {
        $param = $this->request->param();
        $userInfo = Common::getUserInfo();
        try {
            $service = new ServiceChannelDistribution();
            $result = $service->update($id, $param, $userInfo);
            return json($result, 200);
        } catch (Exception $ex) {
            $err = [];
            $err['file'] = $ex->getFile();
            $err['line'] = $ex->getLine();
            $err['message'] = $ex->getMessage();
            return json($err, 400);
        }
    }

    /**
     * @title 整个读取
     * @param $id
     * @author starzhan <397041849@qq.com>
     */
    public function read($id)
    {
        try {
            $service = new ServiceChannelDistribution();
            $result = $service->read($id);
            return json($result, 200);
        } catch (Exception $ex) {
            $err = [];
            $err['file'] = $ex->getFile();
            $err['line'] = $ex->getLine();
            $err['message'] = $ex->getMessage();
            return json($err, 400);
        }

    }

    /**
     * @title 导入excell筛选帐号
     * @method post
     * @url :id/import
     * @author starzhan <397041849@qq.com>
     */
    public function import($id)
    {
        $param = $this->request->param();
        try {
            $service = new ServiceChannelDistribution();
            $result = $service->import($id, $param);
            return json($result, 200);
        } catch (Exception $ex) {
            $err = [
                'file' => $ex->getFile(),
                'line' => $ex->getLine(),
                'message' => $ex->getMessage()
            ];
            return json($err, 400);
        }
    }

    public static function convertData(array $data)
    {
        $spu = '';
        $flag = false;
        $result = [];
        foreach ($data as $k => $row) {
            halt($data);
        }
        return $result;
    }
}