<?php

namespace app\index\controller;

use app\common\exception\JsonErrorException;
use app\index\service\CountryAreaService;
use app\common\controller\Base;
use think\Exception;
use think\Log;
use think\Request;

/**
 * @module 全球城市管理
 * @title 全球城市管理
 * @author Dana
 * @url /country-area
 * Class CountryArea
 * @package app\index\controller
 */
class CountryArea extends Base
{
    /**
     * @var CountryAreaService
     */
	protected $countryAreaService;

	/**
	 * 初始化
	 */
	protected function init()
	{
		if (is_null($this->countryAreaService)) {
			$this->countryAreaService = new CountryAreaService();
		}
	}

	/**
	 * @title 显示资源列表
	 * @method GET
	 * @param snText （country_code,country_en_name,country_cn_name）
	 * @url /country-area
	 * @return \think\Response
	 */
	public function index()
	{
		$request = Request::instance();
		$param = $request->param();
		$page = param($param, 'page', 1);
		$pageSize = param($param, 'pageSize', 10);
		$result = $this->countryAreaService->index($param, $page, $pageSize);
		return json($result);
	}


	/**
	 * @title 添加
	 * @method POST
	 * @url /country-area
	 * @param  \think\Request $request
	 * @return \think\Response
	 */
	public function save(Request $request)
	{
		$param = $request->param();
		$this->countryAreaService->save($param);
		return json(['message' => '添加成功']);
	}

	/**
	 * @title 显示国家城市列表
	 * @method GET
	 * @url /country-area/city-list
	 * @return \think\Response
	 */
	public function cityList()
	{
		$request = Request::instance();
		$param = $request->param();
		$page = $request->get('page', 1);
		$pageSize = $request->get('pageSize', 10);
		$result = $this->countryAreaService->cityList($param, $page, $pageSize);
		return json($result);
	}


	/**
	 * @title 保存更新的资源
	 * @method PUT
	 * @url /country-area/:id
	 * @param  \think\Request $request
	 * @param  int $id
	 * @return \think\Response
	 */
	public function update(Request $request, $id)
	{
		if (empty($id)) {
			return json(['message' => '请求参数错误'], 400);
		}
		if ($id < $this->countryAreaService::OVERSEA) {  // 45055以后 为海外城市ID
			return json(['message' => '不能修改中国城市'], 400);
		}
		$params = $request->param();
		$result = $this->countryAreaService->update($params, $id);
		return json($result);
	}

	/**
	 * @title 删除指定资源
	 * @url /country-area/:id
	 * @param  int $id
	 * @return \think\Response
	 */
	public function delete($id)
	{
		if (empty($id)) {
			return json(['message' => '请求参数错误'], 400);
		}
		if ($id < $this->countryAreaService::OVERSEA) {  // 45055以后 为海外城市ID
			return json(['message' => '不能删除中国城市'], 400);
		}
		$this->countryAreaService->delete($id);
		return json(['message' => '删除成功']);
	}
}
