<?php

namespace app\goods\controller;

use app\api\controller\Get;
use app\api\controller\Post;
use app\common\exception\JsonErrorException;
use app\common\model\GoodsBrandLinkSku;
use app\common\model\GoodsPublishMap;
use app\common\model\GoodsTortDescription;
use app\common\service\UniqueQueuer;
use app\common\validate\BrandLink;
use app\goods\queue\GoodsSkuToBrandLinkQueue;
use app\goods\queue\SyncGoodsImgQueue;
use app\goods\service\ExportTemplate;
use app\goods\service\GoodsBrandsLink;
use app\goods\service\GoodsGalleryDhash;
use app\goods\service\GoodsToDistribution;
use app\goods\service\TortImport;
use app\purchase\service\SupplierService;
use DTS\eBaySDK\Shopping\Types\PopularSearchesType;
use think\Exception;
use think\Request;
use app\common\controller\Base;
use app\common\model\Goods as GoodsModel;
use app\goods\service\GoodsHelp;
use app\goods\service\GoodsQcItems;
use app\common\service\Common;
use app\goods\service\GoodsImport;
use app\goods\service\GoodsImageOption;
use app\publish\queue\GoodsPublishMapQueue;
use app\index\queue\ExportDownQueue;
use app\common\cache\Cache;
use app\report\model\ReportExportFiles;
use app\common\service\CommonQueuer;
use app\goods\queue\GoodsCvsExportQueue;
use app\goods\queue\GoodsPhashQueue;
use app\goods\queue\GoodsDhashQueue;
use app\goods\service\GoodsSku;
use app\goods\service\GoodsToIrobotbox;
use app\goods\service\GoodsImage;
use app\goods\queue\GoodsToDistributionQueue;
use app\goods\service\GoodsExport;
use app\goods\service\GoodsTort;
use app\goods\service\GoodsGalleryHash;
use app\report\service\StatisticShelf;
use app\common\validate\GoodsTortDescription as GoodsTortDescriptionValidate;


/**
 * @module 商品系统
 * @title 商品管理
 * @url /goods
 * @author ZhaiBin
 * Class Goods
 * @package app\goods\controller
 */
class Goods extends Base
{
    /**
     * @title 商品刊登统计
     * @url /goods/publish-statistics/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function statistics($id)
    {
        if (empty($id)) {
            return json_error('产品ID不能为空');
        }
        try {
            //$where['goods_id'] = ['=', $id];
            //$data = GoodsPublishMap::where($where)->alias('a')->join('channel b', 'a.channel=b.id', 'LEFT')->select();
            $StatisticShelf = new StatisticShelf();
            $data = $StatisticShelf->statistics($id);
            return json($data);
        } catch (Exception $ex) {
            throw new JsonErrorException($ex->getMessage());
        }
    }

    /**
     * @title 商品列表
     * @url /goods
     * @return \think\Response
     * @apiRelate app\goods\controller\Category::index
     * @apiRelate app\goods\controller\Goods::downloadImages
     */
    public function index()
    {
        try {
            $request = Request::instance();
            $page = $request->get('page', 1);
            $pageSize = $request->get('pageSize', 50);
            //搜索条件
            $params = $request->param();
            $domain = $request->domain() . '/upload';
            $goodsHelp = new GoodsHelp();
            $wheres = $goodsHelp->getWhere($params);
            $count = $goodsHelp->getCount($wheres);
            $fields = "distinct(g.id),g.thumb,g.channel_id,g.category_id,g.spu,g.publish_time,g.stop_selling_time,g.name,g.sales_status,g.type,g.transport_property,g.hs_code,g.developer_id,g.purchaser_id,g.declare_name,g.declare_en_name,g.channel_id";
            $lists = $goodsHelp->getList($wheres, $fields, $page, $pageSize, $domain);
            $result = [
                'data' => $lists,
                'page' => $page,
                'pageSize' => $pageSize,
                'count' => $count,
            ];
            return json($result, 200);
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()],
                500);
        }

    }

    /**
     * 保存新建的资源
     * @title 商品添加
     * @url /goods
     * @method post
     * @param  \think\Request $request
     * @return \think\Response
     * @apiRelate app\goods\controller\Unit::dictionary
     * @apiRelate app\warehouse\controller\Delivery::getWarehouseChannel
     * @apiRelate app\goods\controller\Goodsdev::getPlatformSale
     * @apiRelate app\index\controller\User::staffs
     * @apiRelate app\goods\controller\Brand::dictionary
     */
    public function save(Request $request)
    {
        $params = $request->post();
        $goodsHelp = new GoodsHelp();
        $userInfo = Common::getUserInfo($request);
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];
        try {
            //$id = $goodsHelp->add($params, $user_id);
            // return json(['message' => intval($id)], 200);
            return json(['message' => '该功能未开发，添加商品请走预开发流程或紧急导入'], 400);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * 显示指定的资源
     * @title 商品查看
     * @url /goods/:id
     * @method get
     * @param  int $id
     * @return \think\Response
     */
    public function read($id)
    {
        if (!is_numeric($id)) {
            return json(['message' => '参数错误'], 400);
        }
        $goodsModel = new GoodsModel();
        if (!$goodsModel->isHas($id)) {
            return json(['message' => ''], 400);
        }
    }

    /**
     * @title 更改商品销售状态
     * @url /goods/changeStatus
     * @method POST
     * @param Request $request
     * @return \think\response\Json
     */
    public function changeStatus(Request $request, GoodsHelp $service)
    {
        $goods_id = $request->param('goods_id', 0);
        $status = $request->param('status', 0);
        if (!is_numeric($goods_id) || empty($goods_id) || empty($status) || !is_numeric($status)) {
            return json(['message' => '参数错误'], 400);
        }
        $userInfo = Common::getUserInfo($request);
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];
        try {
            $result = $service->changeStatus($goods_id, $status, $user_id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }


    /**
     * @title 获取商品sku列表
     * @url /goods/skus/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function getGoodsSkus($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getGoodsSkus($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }

    /**
     * @title 查看商品基础详情
     * @url /goods/base/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     * @apiRelate app\goods\controller\Tag::dictionary
     * @apiRelate app\goods\controller\Brand::dictionary
     * @apiRelate app\goods\controller\Brand::tortDictionary
     * @apiRelate app\system\controller\Lang::dictionary
     * @apiRelate app\goods\controller\Goods::getQcItems
     * @apiRelate app\goods\controller\GoodsImage::read
     */
    public function getBaseInfo($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getBaseInfo($id);
            $result['categoryMap'] = $goodsHelp->getGoodsCategoryMap($id);
            return json($result, 200);
        } catch (Exception $ex) {
            $err = [
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ];
            return json($err, 500);
        }
    }

    /**
     * @title 更新产品基础信息
     * @url /goods/base/:id(\d+)
     * @match ['id' => '\d+']
     * @method put
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     * @apiRelate app\goods\controller\Tag::dictionary
     * @apiRelate app\goods\controller\Brand::dictionary
     * @apiRelate app\goods\controller\Brand::tortDictionary
     * @apiRelate app\system\controller\Lang::dictionary
     * @apiRelate app\goods\controller\Goods::getQcItems
     * @apiRelate app\goods\controller\GoodsImage::read
     */
    public function updateBaseInfo(Request $request, $id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        $params = $request->param();
        $userInfo = Common::getUserInfo($request);
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];
        try {
            $goodsHelp = new GoodsHelp();
            $goodsHelp->updateBaseInfo($id, $params, $user_id);
            return json(['message' => '更新成功'], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage(), 'line' => $ex->getLine(), 'file' => $ex->getFile()], 500);
        }
    }

    /**
     * @title 获取平台状态列表
     * @url /goods/platform-sale-status
     * @method get
     * @public
     * @return \think\Response
     */
    public function getPlatformSaleStatus()
    {
        $goods = new GoodsHelp();
        $result = $goods->getPlatformSaleStatus();
        return json($result, 200);
    }

    /**
     * @title 获取商品规格信息
     * @url /goods/specification/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function getSpecification($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getAttributeInfo($id, 1);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }


    /**
     * @title 查看商品属性列表
     * @url /goods/attribute/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function getAttribute($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getAttributeInfo($id, 0, 0);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }

    /**
     * @title 商品供应商列表
     * @url /goods/supplier/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function getSupplier($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getSupplierInfo($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }

    /**
     * @title 根据goods_id返回供应商列表
     * @url /goods/getGoodSupplierList
     * @method get
     * @param int $goods_id
     * @return \think\Response
     */
    public function getGoodSupplierList()
    {
        $request = Request::instance();
        $params = $request->param();
        $result = GoodsHelp::getGoodSupplierList($params);
        return json($result, 200);
    }


    /**
     * @title 获取商品描述
     * @url /goods/description/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function getDescription(Request $request, $id)
    {
        $lang_id = $request->param('lang', 0);

        if (empty($id)) {
            return json(['message' => '商品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getProductDescription($id, $lang_id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage(), 'file' => $ex->getFile(), 'line' => $ex->getLine()], 500);
        }
    }

    /**
     * @title 更新商品规格参数
     * @url /goods/specification/:id(\d+)
     * @method put
     * @match ['id' => '\d+']
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function updateSpecification(Request $request, $id)
    {
        $params = $request->param();
        $goodsHelp = new GoodsHelp();
        try {
            $attributes = $goodsHelp->formatAttribute($params['attributes']);
            if (empty($id) || empty($attributes)) {
                return json(['message' => '产品ID不能为空或属性不能为空'], 500);
            }
            $result = $goodsHelp->modifyAttribute($id, $attributes, 1);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 500);
        }
    }

    /**
     * @title 编辑商品属性
     * @url /goods/attribute/:id(\d+)/edit
     * @method get
     * @match ['id' => '\d+']
     * @param int $id
     * @return \think\Response
     */
    public function editAttribute($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getAttributeInfo($id, 0);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }

    /**
     * @title 更新产品属性
     * @url /goods/attribute/:id(\d+)
     * @match ['id' => '\d+']
     * @method put
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function updateAttribute(Request $request, $id)
    {
        $params = $request->param();
        $goodsHelp = new GoodsHelp();
        $attributes = $goodsHelp->formatAttribute($params['attributes']);
        if (empty($id) || empty($attributes)) {
            return json(['message' => '产品ID不能为空或属性不能为空'], 500);
        }
        try {
            $result = $goodsHelp->modifyAttribute($id, $attributes, 0);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 500);
        }
    }

    /**
     * @title 更新产品描述
     * @url /goods/description/:id(\d+)
     * @method put
     * @match ['id' => '\d+']
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function updateDescription(Request $request, $id)
    {
        $params = $request->param();
        $goodsHelp = new GoodsHelp();
        try {
            $descriptions = $goodsHelp->formatDescription($params['descriptions']);
            if (empty($id) || empty($descriptions)) {
                return json(['message' => '产品ID不能为空或描述不能为空'], 400);
            }
            $userInfo = Common::getUserInfo($request);
            $result = $goodsHelp->modifyProductDescription($id, $descriptions, $userInfo['user_id']);
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

    /**
     * @title 更新产品与渠道映射表
     * @url /goods/goodsCategoryMap/:id(\d+)
     * @method put
     * @match ['id' => '\d+']
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function updateGoodsCategoryMap(Request $request, $id)
    {
        $params = $request->param();
        $goodsHelp = new GoodsHelp();
        $userInfo = Common::getUserInfo($request);
        try {
            if (empty($id) || empty($params['platform'])) {
                throw  new Exception('产品ID不能为空或渠道分类信息不能为空');
            }
            $params['platform'] = json_decode($params['platform'], true);
            $result = $goodsHelp->saveGoodsCategoryMap($id, $params['platform'], $userInfo->user_id);
            if ($result) {
                throw  new Exception($result);
            }
            return json('保存成功', 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 400);
        }
    }

    /**
     * @title 获取产品的渠道映射
     * @url /goods/goodsCategoryMap/:id(\d+)
     * @method get
     * @match ['id' => '\d+']
     * @param $id
     * @author starzhan <397041849@qq.com>
     */
    public function getGoodsCategoryMap($id)
    {
        $goodsHelp = new GoodsHelp();
        try {
            if (empty($id)) {
                throw  new Exception('产品ID不能为空');
            }
            $result = $goodsHelp->getGoodsCategoryMap($id);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 400);
        }
    }

    /**
     * @title 产品日志列表
     * @url /goods/log/:id(\d+)
     * @method get
     * @match ['id' => '\d+']
     * @param int $id 开发产品Id
     * @return \think\Response
     */
    public function getLog($id)
    {
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getLog($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 500);
        }
    }

    /**
     * @title 添加产品备注信息
     * @url /goods/log/:id(\d+)
     * @method post
     * @match ['id' => '\d+']
     * @param \think\Request $request
     * @param int $id
     * @return \think\Response
     */
    public function addLog(Request $request, $id)
    {
        $remark = $request->param('remark');
        if (empty($id)) {
            return json(['message' => '产品ID不能为空'], 500);
        }
        try {
            $goodsHelp = new GoodsHelp();
            $goodsHelp->addLog($id, $remark);
            return json(['message' => '添加成功'], 200);
        } catch (Exception $ex) {
            return json(['message' => '添加失败'], 500);
        }
    }

    /**
     * @title 查看产品sku信息列表
     * @url /goods/skuinfo/:id(\d+)
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function getSkuInfo($id)
    {
        $goods = new GoodsHelp();

        try {
            $result = $goods->getSkuInfo($id);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 500);
        }
    }

    /**
     * @title 编辑产品sku的信息
     * @url /goods/skuinfo/:id(\d+)/edit
     * @match ['id' => '\d+']
     * @method get
     * @param int $id
     * @return \think\Response
     */
    public function editSkuInfo($id)
    {
        $goods = new GoodsHelp();

        try {
            ## $result = $goods->getSkuLists($id);
            $result = $goods->getSkuInfo($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => '获取失败'], 400);
        }
    }

    /**
     * @title 保存sku列表信息
     * @url /goods/skuinfo/:id(\d+)
     * @match ['id' => '\d+']
     * @method put
     * @param Request $request
     * @param int $id
     * @return \think\Response
     */
    public function saveSkuInfos(Request $request, $id)
    {
        $goodsSku = new GoodsSku();
        $param = $request->param();
        try {
            if (!isset($param['lists']) || !$param['lists']) {
                throw new Exception('lists不能为空');
            }
            $list = json_decode($param['lists'], true);
            if (!$list) {
                throw new Exception('lists不能为空');
            }
            $userInfo = Common::getUserInfo($request);
            $result = $goodsSku->saveSkuInfo($id, $list, $userInfo['user_id']);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => '保存失败' . ' ' . $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 400);
        }
    }

    /**
     * @title 保存平台销售信息
     * @url /goods/:id/platformSale
     * @param Request $request
     * @method put
     * @author starzhan <397041849@qq.com>
     */
    public function savePlatformSale(Request $request)
    {
        $param = $request->param();
        $goods = new GoodsHelp();
        $platformSale = json_decode($param['platform_sale'], true);
        $id = $param['id'];
        try {
            $goods->savePlatformSale($id, $platformSale);
            return json(['message' => '保存成功']);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => '保存失败' . ' ' . $message], 500);
        }
    }

    /**
     * @title 查看产品质检信息
     * @url /goods/qcitems/:id(\d+)
     * @method get
     * @match ['id' => '\d+']
     * @param int $id
     * @return \think\Response
     */
    public function getQcItems($id)
    {
        $qcItems = new GoodsQcItems();
        try {
            $result = $qcItems->getGoodsQcItems($id, 1);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 500);
        }
    }

    /**
     * @title 编辑产品质检信息
     * @url /goods/qcitems/:id(\d+)/edit
     * @method get
     * @match ['id' => '\d+']
     * @param int $id
     * @return \think\Response
     */
    public function editQcItems($id)
    {
        $qcItems = new GoodsQcItems();
        try {
            $result = $qcItems->getGoodsQcItems($id, 0);
            return json($result, 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => $message], 500);
        }
    }

    /**
     * @title 保存产品质检信息
     * @url /goods/qcitems/:id(\d+)
     * @match ['id' => '\d+']
     * @method put
     * @param int $id
     * @return \think\Response
     */
    public function saveQcItems(Request $request, $id)
    {
        $qcItems = new GoodsQcItems();
        $lists = json_decode($request->param('lists'), true);

        try {
            $qcItems->saveGoodsQcItems($id, $lists);
            return json(['message' => '保存成功'], 200);
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            return json(['message' => '保存失败' . $message], 500);
        }
    }

    /**
     * @title 获取产品出售状态
     * @url /goods/sales-status
     * @method get
     * @public
     * @return \think\Response
     */
    public function getSalesStatus()
    {
        $goods = new GoodsHelp();
        $lists = $goods->getSalesStatus();
        return json($lists, 200);
    }

    /**
     * @title 获取物流属性列表
     * @url /goods/transport-property
     * @method get
     * @public
     * @return \think\Response
     */
    public function transportProperty()
    {
        $goods = new GoodsHelp();
        $lists = $goods->getTransportProperies();
        return json($lists, 200);
    }

    /**
     * @title 获取修图需求列表
     * @url /goods/img-requirement
     * @method get
     * @public
     * @return \think\Response
     */
    public function imgRequirements()
    {
        $service = new GoodsImageOption();
        $lists = $service->getImgRequirements();
        return json($lists, 200);
    }

    /**
     * @title 查询spu列表
     * @url /goods/goodsToSpu
     * @method get
     * @return \think\response\Json
     */
    public function goodsToSpu()
    {
        $request = Request::instance();
        $params = $request->param();
        $result = GoodsHelp::goodsToSpu($params);
        return json($result, 200);
    }

    /**
     * @title 更改商品销售状态
     * @url /goods/skuStatus
     * @method POST
     * @param Request $request
     * @return \think\response\Json
     */
    public function changeSkuStatus(Request $request, GoodsHelp $service)
    {
        $sku_id = $request->param('sku_id', 0);
        $status = $request->param('status', 0);
        if (!is_numeric($sku_id) || empty($sku_id) || empty($status) || !is_numeric($status)) {
            return json(['message' => '参数错误'], 400);
        }
        $userInfo = Common::getUserInfo($request);
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];
        try {
            $service->changeSkuStatus($sku_id, $status, $user_id);
            return json(['message' => '操作成功'], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @title 编辑sku重量尺寸信息
     * @method get
     * @url /sku-check/:id(\d+)/edit
     */
    public function editSkuCheck($id, GoodsHelp $service)
    {
        try {
            $info = $service->getSkuCheckInfo($id);
            return json($info);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @title 确认sku重量尺寸信息
     * @method put
     * @url /sku-check/:id(\d+)
     */
    public function updateSkuCheck($id, Request $request, GoodsHelp $service)
    {
        try {
            $params = $request->param();
            $service->updateSkuCheckInfo($id, $params);
            return json(['message' => '更新成功']);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @title 获取比对信息
     * @method get
     * @url /goods/comparison/:id(\d+)
     */
    public function comparison($id, GoodsHelp $service)
    {
        try {
            $info = $service->getComparisonInfo($id);
            return json($info);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @title 产品导入
     * @method post
     * @url /goods/import
     * @apiRelate app\index\controller\DownloadFile::downExportFile
     * @author starzhan <397041849@qq.com>
     */
    public function import(Request $request)
    {
        $userInfo = Common::getUserInfo($request);
        $params = $request->param();
        try {
            $import = new GoodsImport();
            $message = $import->import($params, $userInfo['user_id']);
            $errmsg = [];
            $fail_num = 0;
            $errmsg[] = "spu[添加成功:{$message['spu']['s']},添加失败:{$message['spu']['f']}";
            if ($message['spu']['r']) {
                $fail_num = 1;
                $errmsg[] = "原因:";
                foreach ($message['spu']['r'] as $k => $msg) {
                    $key = $k + 1;
                    $errmsg[] = $key . "." . $msg;
                }
            }
            $errmsg[] = "sku[添加成功:{$message['sku']['s']},添加失败:{$message['sku']['f']}";
            if ($message['sku']['r']) {
                $errmsg[] = "原因:";
                $fail_num = 1;
                foreach ($message['sku']['r'] as $k => $msg) {
                    $key = $k + 1;
                    $errmsg[] = $key . "." . $msg;
                }
            }
            return json([
                'message' => $errmsg,
                'fail_num' => $fail_num
            ], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()]);
        }
    }

    /**
     * @title 产品导入修改
     * @param Request $request
     * @author starzhan <397041849@qq.com>
     * @apiRelate app\index\controller\DownloadFile::downExportFile
     * @method post
     * @url /goods/importUpdate
     */
    public function importUpdate(Request $request)
    {
        $params = $request->param();
        $userInfo = Common::getUserInfo($request);
        try {
            $import = new GoodsImport();
            $message = $import->importUpdate($params, $userInfo['user_id']);
            $errmsg = [];
            $fail_num = 0;
            $errmsg[] = "spu[修改成功:{$message['spu']['s']},修改失败:{$message['spu']['f']}";
            if ($message['spu']['r']) {
                $errmsg[] = "原因:";
                $fail_num = 1;
                foreach ($message['spu']['r'] as $k => $msg) {
                    $key = $k + 1;
                    $errmsg[] = $key . "." . $msg;
                }
            }
            $errmsg[] = "sku[修改成功:{$message['sku']['s']},修改失败:{$message['sku']['f']}";
            if ($message['sku']['r']) {
                $errmsg[] = "原因:";
                $fail_num = 1;
                foreach ($message['sku']['r'] as $k => $msg) {
                    $key = $k + 1;
                    $errmsg[] = $key . "." . $msg;
                }
            }
            return json([
                'message' => $errmsg,
                'fail_num' => $fail_num
            ], 200);

        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage() . $ex->getFile() . $ex->getLine()]);
        }
    }

    /**
     * @title 获取SKU附属参数
     * @date 2017/07/20
     * @time 20:30
     * @author yangweiquan
     * @method get
     * @url /goods/getSkuIncidentalParameter
     */
    public function getSkuIncidentalParameter()
    {
        $request = Request::instance();
        $params = $request->param();
        $userInfo = Common::getUserInfo($request);
        $result = GoodsHelp::getSkuIncidentalParameter($params);
        return json($result, 200);
    }

    /**
     * @title 下载远程服务器图片
     * @method post
     * @url /goods/download
     * @apiParam name:goods_id type:string desc:产品ID
     * @apiParam name:path type:string desc:下载地址，不填则自动计算地址
     * @param Request $request
     * @return \think\response\Json
     */
    public function downloadImages(Request $request)
    {
        try {
            $params = $request->param();
            if (!param($params, 'goods_id')) {
                return json(['message' => '参数错误！'], 400);
            }
            $goods = \app\common\model\Goods::get($params['goods_id']);
            if (empty($goods)) {
                return json(['message' => '产品不存在！'], 400);
            }
            $data = [
                'goods_id' => $params['goods_id'],
                'spu' => $goods['spu']
            ];
            if (param($params, 'path')) {
                //判断路径是否符合
                if (!strpos($params['path'], '\\' . config('image_ftp_host'))) {
                    return json(['message' => '地址路径不合法！'], 400);
                }
                $data['path'] = str_replace('\\', '/', $params['path']);
                $GoodsHelp = new GoodsHelp();
                $GoodsHelp->saveThumbPath($params['goods_id'], $data['path']);
            }
            $queue = new UniqueQueuer(SyncGoodsImgQueue::class);
            $queue->push($data);
            return json(['message' => '操作成功'], 200);
        } catch (Exception $exception) {
            return json(['message' => $exception->getMessage()], 400);
        }
    }

    /**
     * @title 导出商品转成joom格式
     * @author tanbin
     * @date 2017-09-09
     * @url /goods/export
     * @method get
     */
    public function export(Request $request)
    {
        try {
            //搜索条件
            $params = $request->param();
            $ids = [];
            if (isset($params['ids'])) {
                $ids = json_decode($params['ids'], true);
            }
            if (!$ids) {
                throw  new Exception('勾选ID不能为空');
            }
            $GoodsHelp = new GoodsHelp();
            $result = $GoodsHelp->export($ids);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 500);
        }

    }

    /**
     * @title 获取sku打印标签
     * @url sku-label
     * @author starzhan <397041849@qq.com>
     */
    public function getSkuLabel()
    {
        try {
            $params = $this->request->param();
            if (!isset($params['ids']) || !$params['ids']) {
                throw  new Exception('ids不能为空！');
            }
            $ids = json_decode($params['ids'], true);
            if (!$ids) {
                throw  new Exception('ids不能为空！');
            }
            $is_band_area = isset($params['is_band_area']) ?? 0;
            $GoodsHelp = new GoodsHelp();
            $userInfo = Common::getUserInfo($this->request);
            $warehouse_id = param($params, 'warehouse_id', 2);
            $result = $GoodsHelp->getSkuLabel($ids, $is_band_area, $userInfo['user_id'], $warehouse_id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 500);
        }

    }

    /**
     * @title 批量抓图
     * @method post
     * @url batch-catch-photo
     * @author starzhan <397041849@qq.com>
     */
    public function batchCatchPhoto()
    {
        try {
            $params = $this->request->param();
            if (!isset($params['ids']) || !$params['ids']) {
                throw  new Exception('ids不能为空！');
            }
            $ids = json_decode($params['ids'], true);
            if (!$ids) {
                throw  new Exception('ids不能为空！');
            }
            $GoodsHelp = new GoodsHelp();
            return json($GoodsHelp->batchCatchPhoto($ids), 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 500);
        }
    }

    /**
     * @title 推送到赛盒
     * @url batch/push-irobotbox
     * @method post
     * @author starzhan <397041849@qq.com>
     */
    public function pushIrobotbox()
    {
        try {
            $params = $this->request->param();
            if (!isset($params['ids']) || !$params['ids']) {
                throw  new Exception('ids不能为空！');
            }
            $ids = json_decode($params['ids'], true);
            if (!$ids) {
                throw  new Exception('ids不能为空！');
            }
            $GoodsHelp = new GoodsHelp();
            return json($GoodsHelp->pushIrobotbox($ids), 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 500);
        }
    }

    /**
     * @title 测试推送队列
     * @url push-queue
     * @method get
     * @author starzhan <397041849@qq.com>
     */
    public function pushGoodsPublishMapQueue()
    {
        try {
            $params = $this->request->param();
            if (!isset($params['goods_id']) || !$params['goods_id']) {
                throw  new Exception('goods_id不能为空！');
            }
            $queue = new CommonQueuer(GoodsPublishMapQueue::class);
            $goods_id = $params['goods_id'];
            $goodsInfo = Cache::store('goods')->getGoodsInfo($goods_id);
            if (!$goodsInfo) {
                throw new Exception('查无此商品');
            }
            $row = [];
            $row['id'] = $goodsInfo['id'];
            $row['spu'] = $goodsInfo['spu'];
            $row['status'] = $goodsInfo['status'];
            $row['platform_sale'] = $goodsInfo['platform_sale'];
            $row['sales_status'] = $goodsInfo['sales_status'];
            $row['category_id'] = $goodsInfo['category_id'];
            $queue->push($row);
            return json(['message' => '加入成功!'], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 500);
        }

    }

    /**
     * @title 导出商品sku
     * @url /goods/export-sku
     * @method post
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function exportSku()
    {
        try {
            $userId = Common::getUserInfo()->toArray()['user_id'];
            $userInfo = Cache::store('user')->getOneUser($userId);
            $param = $this->request->param();
            $goodsImport = new GoodsImport();
            $count = $goodsImport->getExportCount($param);
            $categoryName = '全部商品';
            $param['category_id'] = $param['category_id'] ?? 0;
            if ($param['category_id']) {
                $GoodsModel = new GoodsModel();
                $categoryName = $GoodsModel->getCategoryAttr('', ['category_id' => $param['category_id']]);
            }
            $fileName = $goodsImport->getExportFileName($param, $categoryName, $userInfo['realname']);
            $param['file_name'] = $fileName;
            $field = empty($param['field']) ? [] : json_decode($param['field'], true);
            $header = $goodsImport->getExportSkuField($field);
            //只要是勾选，不进入队列，也能直接下载
            if (isset($param['ids']) && !empty($param['ids']) && $idsArr = json_decode($param['ids'], true)) {
                if (is_array($idsArr) && $idsArr) {
                    $result = $goodsImport->getExportSkuData($param, $header);
                    return json($result, 200);
                }
            }
            if ($count > GoodsImport::ZIP_LEN) {
                $param['download'] = 1;
                $cache = Cache::handler();
                $key = 'GoodsImport:exportSku:lastExportTime:' . $userId . ":" . $param['category_id'];
                $lastApplyTime = $cache->get($key);
                if ($lastApplyTime && time() - $lastApplyTime < 5 * 60) {
                    throw new Exception('5分钟内只能请求一次', 400);
                } else {
                    $cache->set($key, time());
                    $cache->expire($key, 3600);
                }
                try {
                    $model = new ReportExportFiles();
                    $data['applicant_id'] = $userId;
                    $data['apply_time'] = time();
                    $data['export_file_name'] = $fileName;
                    $data['export_file_name'] = str_replace('.csv', '.zip', $data['export_file_name']);;
                    $data['status'] = 0;
                    $data['applicant_id'] = $userId;
                    $model->allowField(true)->isUpdate(false)->save($data);
                    $param['file_name'] = $fileName;
                    $param['apply_id'] = $model->id;
                    (new CommonQueuer(GoodsCvsExportQueue::class))->push($param);
                    return json(['status' => 0, 'message' => '导出数据太多，已加入导出队列，稍后请自行下载'], 200);
                } catch (\Exception $ex) {
                    throw new JsonErrorException('申请导出失败');
                }
            } else {
                $result = $goodsImport->getExportSkuData($param, $header);
                return json($result, 200);
            }
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage() . $ex->getFile() . $ex->getLine()], 400);
        }

    }

    /**
     * @title 导出noon格式商品
     * @url /goods/export-noon
     * @method post
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function exportNooN()
    {
        try {
            $userId = Common::getUserInfo()->toArray()['user_id'];
            $userInfo = Cache::store('user')->getOneUser($userId);
            $param = $this->request->param();

            $goodsImport = new GoodsImport();
            $count = $goodsImport->getExportCount($param);
            $categoryName = '全部商品';
            $param['category_id'] = $param['category_id'] ?? 0;
            if ($param['category_id']) {
                $GoodsModel = new GoodsModel();
                $categoryName = $GoodsModel->getCategoryAttr('', ['category_id' => $param['category_id']]);
            }
            $fileName = $goodsImport->getExportFileName($param, $categoryName, $userInfo['realname']);
            $param['file_name'] = $fileName;
            $GoodsNotice = new GoodsExport();
            $header = $GoodsNotice->NOON();
            if ($count > GoodsImport::ZIP_LEN) {
                $param['download'] = 1;
                $cache = Cache::handler();
                $key = 'GoodsImport:exportNooN:lastExportTime:' . $userId . ":" . $param['category_id'];
                $lastApplyTime = $cache->get($key);
                if ($lastApplyTime && time() - $lastApplyTime < 5 * 60) {
                    throw new Exception('5分钟内只能请求一次', 400);
                } else {
                    $cache->set($key, time());
                    $cache->expire($key, 3600);
                }
                try {
                    $model = new ReportExportFiles();
                    $data['applicant_id'] = $userId;
                    $data['apply_time'] = time();
                    $data['export_file_name'] = $fileName;
                    $data['export_file_name'] = str_replace('.csv', '.zip', $data['export_file_name']);;
                    $data['status'] = 0;
                    $data['applicant_id'] = $userId;
                    $model->allowField(true)->isUpdate(false)->save($data);
                    $param['file_name'] = $fileName;
                    $param['apply_id'] = $model->id;
                    (new CommonQueuer(GoodsCvsExportQueue::class))->push($param);
                    return json(['status' => 0, 'message' => '导出数据太多，已加入导出队列，稍后请自行下载'], 200);
                } catch (\Exception $ex) {
                    throw new JsonErrorException('申请导出失败');
                }
            } else {
                $result = $goodsImport->getExportSkuData($param, $header);
                return json($result, 200);
            }
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage() . $ex->getFile() . $ex->getLine()], 400);
        }

    }


    /**
     * @title 设置采购员
     * @url set-purchaser
     * @method post
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function setPurchaserId()
    {
        try {
            $param = $this->request->param();
            if (!isset($param['id']) || !$param['id']) {
                throw  new Exception('请勾选对应商品！');
            }
            $id = json_decode($param['id'], true);
            if (!is_array($id)) {
                throw  new Exception('请勾选对应商品！');
            }
            if (!isset($param['purchaser_id']) || !$param['purchaser_id']) {
                throw  new Exception('采购员id不能为空！');
            }
            $GoodsHelp = new GoodsHelp();
            return json($GoodsHelp->setPurchaserId($id, $param['purchaser_id']), 200);

        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @title 获取可供选择的导出字段
     * @url export-field
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     * @apiFilter app\common\filter\GoodsExportFilter
     */
    public function getExportField()
    {
        $goodsImport = new GoodsImport();
        $data = $goodsImport->getBaseField();
        $result = $data;
        return json($result, 200);
    }

    /**
     * @url irobobox-push
     * @method get
     * @title 推送赛盒
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function testIrobotbox()
    {
        $param = $this->request->param();
        try {
            if (!isset($param['goods_id']) || !$param['goods_id']) {
                throw  new Exception('goods_id不能为空！');
            }
            $GoodsToIrobotbox = new GoodsToIrobotbox();
            $result = $GoodsToIrobotbox->upload($param['goods_id'], true);
            return json($result, 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @url distribution-push
     * @method post
     * @title 推送分销
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function testDistribution()
    {
        $param = $this->request->param();
        try {
            $GoodsToDistribution = new GoodsBrandsLink();
            if (!isset($param['goods_id']) || !$param['goods_id']) {
                if (!isset($param['spu']) || !$param['spu']) {
                    throw  new Exception('spu不能为空！');
                }
                $GoodsHelp = new GoodsHelp();
                $aGoods = $GoodsHelp->spu2goodsId($param['spu']);
                if (!$aGoods) {
                    throw  new Exception('spu不存在');
                }
                $GoodsToDistribution->createGoods(reset($aGoods));
                return json(['message' => '成功'], 200);
            } else {
                $goodsId = json_decode($param['goods_id'], true);
                if (!$goodsId) {
                    throw  new Exception('goods_id不能为空');
                }
                $goodsHelp = new GoodsHelp();
                $queue = new UniqueQueuer(GoodsToDistributionQueue::class);
                foreach ($goodsId as $goods_id) {
                    $goodsInfo = $goodsHelp->getGoodsInfo($goods_id);
                    if ($goodsInfo['sales_status'] == 2) {
                        continue;
                    }
                    $aPlatform = $goodsHelp->getPlatformSale($goodsInfo['platform']);
                    $canPush = true;
                    foreach ($aPlatform as $v) {
                        if ($v['id'] == 31) {
                            $canPush = $v['value_id'];
                            break;
                        }
                    }
                    if (!$canPush) {
                        throw new Exception('当前商品分销平台禁止出售，无法推送');
                    }
                    $queue->push($goods_id);
                }
                return json(['message' => '加入队列成功'], 200);
            }

        } catch (Exception $ex) {
            return json(
                ['message' => $ex->getMessage(), 'file' => $ex->getFile(), 'line' => $ex->getLine()], 400);
        }
    }

    /**
     * @title 根据id返回商品信息详情
     * @url api/:id/info
     * @noauth
     * @author starzhan <397041849@qq.com>
     */
    public function apiGoodsInfo($id)
    {
        try {
            $goodsHelp = new GoodsHelp();
            $result = $goodsHelp->getBaseInfo($id);
            if (!$result) {
                throw new Exception('该商品信息不存在');
            }
            $GoodsImage = new GoodsImage();
            $result['img_list'] = $GoodsImage->getImgByGoodsId($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([], 400);
        }
    }

    /**
     * @title 获取图片phash
     * @url get-phash
     * @method post
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function getPhash()
    {
        try {
            $request = Request::instance();
            $param = $request->param();
            if (!isset($param['file']) || !$param['file']) {
                throw  new Exception('文件内容不能为空！');
            }
            $GoodsGalleryHash = new GoodsGalleryHash();
            $phash = $GoodsGalleryHash->search($param['file']);
            return json(['phash' => $phash], 200);
        } catch (Exception $e) {
            return json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * @title 跑phash数据
     * @method get
     * @url run-phash
     * @author starzhan <397041849@qq.com>
     */
    public function runPhash()
    {
        try {
            $request = Request::instance();
            $param = $request->param();
            if (!isset($param['pwd'])) {
                throw  new Exception('密码不能为空！');
            }
            if ($param['pwd'] != 'a673449') {
                throw  new Exception('密码错误！');
            }
            if (!isset($param['id']) || !$param['id']) {
                throw  new Exception('id不能为空！');
            }
            $queue = new CommonQueuer(GoodsPhashQueue::class);
            $queue->push($param['id']);
            return json(['message' => '放进队列了'], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @title 跑phash数据
     * @method get
     * @url run-dhash
     * @author starzhan <397041849@qq.com>
     */
    public function runDhash()
    {
        try {
            $request = Request::instance();
            $param = $request->param();
            if (!isset($param['pwd'])) {
                throw  new Exception('密码不能为空！');
            }
            if ($param['pwd'] != 'a673449') {
                throw  new Exception('密码错误！');
            }
            if (!isset($param['id']) || !$param['id']) {
                throw  new Exception('id不能为空！');
            }
            $queue = new CommonQueuer(GoodsDhashQueue::class);
            $queue->push($param['id']);
            return json(['message' => '放进队列了'], 200);
        } catch (Exception $ex) {
            return json(['message' => $ex->getMessage()], 400);
        }
    }

    /**
     * @url :id(\d+)/tort
     * @method get
     * @title 获取侵权下架
     * @apiFilter app\common\filter\GoodsTortFilter
     * @author starzhan <397041849@qq.com>
     */
    public function getTort($id)
    {
        try {
            $GoodsTort = new GoodsTort();
            $result = $GoodsTort->read($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 400);
        }
    }

    /**
     * @url :id(\d+)/tort
     * @method post
     * @title 保存侵权下架
     * @param $id
     * @author starzhan <397041849@qq.com>
     */
    public function saveTort($id)
    {
        try {
            $GoodsTort = new GoodsTort();
            $param = $this->request->param();
            $param['goods_id'] = $id;
            $userInfo = Common::getUserInfo();
            $result = $GoodsTort->save($param, $userInfo['user_id']);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 推送每日开发数
     * @url pull-count-develop
     * @method get
     * @author starzhan <397041849@qq.com>
     */
    public function pullCountDevelop()
    {
        try {
            $param = $this->request->param();
            if (!isset($param['date']) || !$param['str_time']) {
                throw new Exception('日期不能为空');
            }
            $GoodsHelp = new GoodsHelp();
            $GoodsHelp->pullCountDevelop($param['date']);
            return json(['message' => 'ok'], 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 统计每日开发数
     * @url count-develop
     * @method get
     * @author starzhan <397041849@qq.com>
     */
    public function countDevelop()
    {
        try {
            $param = $this->request->param();
            if (!isset($param['date']) || !$param['str_time']) {
                throw new Exception('日期不能为空');
            }
            $GoodsHelp = new GoodsHelp();
            $GoodsHelp->countDevelop($param['date']);
            return json(['message' => 'ok'], 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 获取商品详情页的侵权列表
     * @method get
     * @url :id/goods-tort-description
     * @param $id
     * @author starzhan <397041849@qq.com>
     */
    public function getGoodsTortDescription($id)
    {
        try {
            $param = $this->request->param();
            $page = $param['page'] ?? 1;
            $page_size = $param['page_size'] ?? 50;
            $GoodsHelp = new GoodsHelp();
            $result = $GoodsHelp->getGoodsTortDescription($id, $page, $page_size);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 根据ID获取侵权详情.
     * @method get
     * @url goods-tort-description/:id
     * @param $id
     * @return \think\response\Json
     * @author starzhan <397041849@qq.com>
     */
    public function getGoodsTortDescriptionById($id)
    {
        try {
            $validate = new GoodsTortDescriptionValidate();
            if (!$validate->scene('show')->check(['id' => $id])) {
                throw new Exception($validate->getError());
            }
            $GoodsHelp = new GoodsHelp();
            $result = $GoodsHelp->getGoodsTortDescriptionById($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 商品详情页的侵权新增和修改
     * @method put
     * @url :id/goods-tort-description
     * @author starzhan <397041849@qq.com>
     */
    public function saveGoodsTortDescription($id)
    {
        $userInfo = Common::getUserInfo();
        try {
            $param = $this->request->put();
            if (isset($param['tort_time']) && $param['tort_time']) {
                $param['tort_time'] = strtotime($param['tort_time']);
            }
            $GoodsHelp = new GoodsHelp();
            $result = $GoodsHelp->saveGoodsTortDescription($id, $param, $userInfo['user_id']);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 400);
        }
    }

    /**
     * @title 移除商品侵权详情
     * @method delete
     * @url :id/goods-tort-description
     * @author starzhan <397041849@qq.com>
     */
    public function removeGoodsTortDescription($id)
    {
        try {
            $GoodsHelp = new GoodsHelp();
            $result = $GoodsHelp->removeGoodsTortDescription($id);
            return json($result, 200);
        } catch (Exception $ex) {
            return json([
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ], 200);
        }
    }

    /**
     * @title 获取供应商选择列表
     * @method get
     * @url supplier-select
     * @author starzhan <397041849@qq.com>
     */
    public function getSupplierSelect()
    {
        $param = $this->request->param();
        $page = $param['page'] ?? 1;
        $pageSize = $param['pageSize'] ?? 50;
        $supplierService = new SupplierService();
        $result = $supplierService->getSupplierSelect($page, $pageSize, $param);
        return json($result, 200);
    }

    /**
     * @title 侵权记录的侵权列表
     * @method get
     * @url goods-tort-description-list
     * @apiRelate app\order\controller\Order::channel
     * @apiRelate app\order\controller\Order::account
     * @apiRelate app\publish\controller\JoomCategory::category
     * @return \think\response\Json
     * @throws \think\Exception
     */
    public function getTortList()
    {
        try {
            $request = Request::instance();
            $params = $request->param();
            $page = $request->get('page', 1);
            $pageSize = $request->get('pageSize', 10);
            $goodsHelp = new GoodsHelp();
            //搜索条件
            $where = $goodsHelp->tortWhere($params);
            $count = $goodsHelp->getTortCount($where);
            $fields = "goods_tort_description.*,goods.spu,goods.name,goods.thumb,goods.sales_status,goods.channel_id as goods_channel_id";
            $lists = $goodsHelp->getTortList($where, $fields, $page, $pageSize);
            $result = [
                'data' => $lists,
                'page' => $page,
                'pageSize' => $pageSize,
                'count' => $count,
            ];
            return json($result, 200);
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()],
                500);
        }
    }

    /**
     * @title 侵权记录的侵权列表 新增
     * @url /goods/goods-tort-description$
     * @method POST
     * @return \think\response\Json
     * @throws \think\Exception
     */
    public function createTort()
    {
        try {
            $param = Request::instance()->param();
            if (isset($param['tort_time']) && $param['tort_time']) {
                $param['tort_time'] = strtotime($param['tort_time']);
            }
            $param['create_time'] = time();
            $validate = new GoodsTortDescriptionValidate();
            if (!$validate->scene('create')->check($param)) {
                throw new Exception($validate->getError());
            }
            $server = new TortImport();
            $server->create($param);

            return json(['message' => '新增成功'], 200);
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title 侵权记录的侵权列表 修改
     * @url /goods/goods-tort-description/:id
     * @method PUT
     * @param $id  主键
     * @return \think\response\Json
     */
    public function updateTort($id)
    {
        try {
            $param = Request::instance()->param();
            if (isset($param['tort_time']) && $param['tort_time']) {
                $param['tort_time'] = strtotime($param['tort_time']);
            }
            $validate = new GoodsTortDescriptionValidate();
            if (!$validate->scene('update')->check($param)) {
                throw new Exception($validate->getError());
            }

            $server = new TortImport();
            $server->update($param);
            return json(['message' => '修改成功'], 200);
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title 侵权的邮箱附件文件上传
     * @url /goods/goods-tort-description/email-photo$
     * @method POST
     * @return \think\response\Json
     */
    public function TortEmailUpload()
    {
        $baseData = $this->request->param('content');
        $res = (new TortImport())->uploadAttachment($baseData, 'tort_content_email_photo');
        return json($res, 200);
    }

    /**
     * @title  侵权数据导出
     * @url /goods/tort-export
     * @method POST
     * @apiRelate app\order\controller\Order::channel
     * @apiRelate app\order\controller\Order::account
     * @apiRelate app\publish\controller\JoomCategory::category
     * @return \think\response\Json
     * @throw
     */
    public function exportTort()
    {
        //导出字段  spu、侵权平台、站点、账号、侵权描述、侵权时间、录入时间
        try {
            $param = Request::instance()->param();
            $goodsHelp = new GoodsHelp();
            $where = $goodsHelp->tortWhere($param);
            $count = $goodsHelp->getTortCount($where);
            if ($count > TortImport::ENQUEUE_NUM) {
                //超过500条加入队列
                $userInfo = Common::getUserInfo();
                $cache = Cache::handler();
                $key = 'Goods:exportTort:lastExportTime:' . $userInfo['user_id'];
                $lastApplyTime = $cache->get($key);
                if ($lastApplyTime && time() - $lastApplyTime < 5 * 60) {
                    throw new Exception('5分钟内只能请求一次', 400);
                } else {
                    $cache->set($key, time());
                    $cache->expire($key, 3600);
                }
                $fileName = '侵权记录_' . date('YmdHis') . ".csv";
                $model = new ReportExportFiles();
                $data['applicant_id'] = $userInfo['user_id'];
                $data['apply_time'] = time();
                $data['export_file_name'] = $fileName;
                $data['export_file_name'] = str_replace('.csv', '.zip', $data['export_file_name']);
                $data['status'] = 0;
                $data['applicant_id'] = $userInfo['user_id'];
                $model->allowField(true)->isUpdate(false)->save($data);
                $param['file_name'] = $fileName;
                $param['apply_id'] = $model->id;
                $param['class'] = '\app\\goods\\service\\TortImport';
                $param['fun'] = 'export';
                (new CommonQueuer(ExportDownQueue::class))->push($param);
                return json(['status' => 2, 'message' => '添加队列导出任务成功'], 200);
            } else {
                //直接导出
                $result = TortImport::export($param);
                return json($result, 200);
            }
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title  侵权数据导入
     * @url /goods/tort-import
     * @method POST
     * @return \think\response\Json
     * @throw
     */
    public function importTort()
    {
        $baseData = $this->request->param('content');
        $extension = $this->request->param('extension');
        if (!$baseData) {
            return json(['message' => '请选择上传文件'], 400);
        }
        if (!in_array($extension, TortImport::MIME_TYPE)) {
            return json(['message' => '文件类型错误，请选择excel文件上传'], 400);
        }
        try {
            $result = (new TortImport())->import($baseData);
            return json($result, 200);
        } catch (Exception $e) {
            return json([
                'message' => '导入失败，' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title 品连导入sku
     * @url /goods/brand-link/import
     * @method POST
     * @return json
     */
    public function BrandLinkImport()
    {
        try {
            (new BrandLink())->goCheckImport();
            $res = (new GoodsBrandsLink())->skuImport();
            return json($res, 200);
        } catch (Exception $e) {
            return json([
                'message' => '导入失败，' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title  品连商品推送列表
     * @url /goods/brand-link
     * @method GET
     * @return json
     * @throw
     */
    public function BrandLinkList()
    {
        $goodsBrandsLink = new GoodsBrandsLink();
        if ($this->request->param('ids')) {
            throw new JsonErrorException('非法请求参数ids');
        }
        list($where, $field, $fileName, $page, $pageSize) = $goodsBrandsLink->buildParams();
        unset($fileName);
        $res = $goodsBrandsLink->getPushList($where, $field, $page, $pageSize);
        return json($res, 200);
    }

    /**
     * @title 品连推送数据 导出
     * @url /goods/brand-link/export
     * @method POST
     * @return json
     */
    public function BrandLinkExport()
    {
        try {
            $goodsBrandsLink = new GoodsBrandsLink();
            $param = Request::instance()->param();
            list($where, $field) = $goodsBrandsLink->buildParams();
            if (!$field) {
                throw new JsonErrorException('导出字段不存在');
            }
            foreach ($field as $v) {
                $canExportField = array_column(GoodsBrandsLink::EXPORT_FIELD, 'key');
                if (!in_array($v, $canExportField)) {
                    throw new JsonErrorException('导出字段不匹配');
                }
            }
            if (isset($param['ids']) && $ids = $param['ids']) {
                $ids = json_decode($ids, true);
                if (is_array($ids)) {
                    $res = $goodsBrandsLink->redirectExport($param);
                    return json($res, 200);
                }
            } else {
                $userInfo = Common::getUserInfo();
                $fileName = '品连sku导出_' . date('YmdHis') . ".csv";
                $model = new ReportExportFiles();
                $data['applicant_id'] = $userInfo['user_id'];
                $data['apply_time'] = time();
                $data['export_file_name'] = $fileName;
                $data['export_file_name'] = str_replace('.csv', '.zip', $data['export_file_name']);
                $data['status'] = 0;
                $data['applicant_id'] = $userInfo['user_id'];
                $model->allowField(true)->isUpdate(false)->save($data);
                $param['file_name'] = $fileName;
                $param['apply_id'] = $model->id;
                $param['class'] = '\app\\goods\\service\\GoodsBrandsLink';
                $param['fun'] = 'queueExport';
                //$goodsBrandsLink->queueExport($param);
                (new CommonQueuer(ExportDownQueue::class))->push($param);
                return json(['message' => '添加队列导出任务成功'], 200);
            }
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title 品连分类同步接口
     * @url /goods/brand-link-category/sync
     * @method POST
     * return json
     */
    public function brandLinkCategorySync()
    {
        try {
            $res = (new GoodsBrandsLink())->brandLinksCategorySync();
            if ($res === true) {
                return json(['message' => '同步成功'], 200);
            }
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }


    /**
     * @title 以sku的方式推送商品到品连
     * @url /goods/brand-link/push
     * @method POST
     * @return json
     */
    public function skuPushToBrandLink()
    {
        try {
            $ids = Request::instance()->param('ids');
            if (!$ids) {
                throw new JsonErrorException('请勾选要推送的sku');
            }
            $ids = json_decode($ids, true);
            $goodsBrandsLink = new GoodsBrandsLink();
            $goodsBrandsLink->pushBeforeAction($ids);
            $queue = new UniqueQueuer(GoodsSkuToBrandLinkQueue::class);
            foreach ($ids as $v) {
                //$goodsBrandsLink->skuPush($v);
                $queue->push($v);
            }
            return json(['message' => '加入队列成功'], 200);
        } catch (Exception $e) {
            return json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * @title 品连导出的字段
     * @url /goods/brand-link/export-fields
     * @method GET
     * @return json
     */
    public function getBrandsLinkExportField()
    {
        $goodsBrandsLink = new GoodsBrandsLink();
        $field = $goodsBrandsLink->getExportField();
        return json(['data' => $field], 200);
    }

    /**
     * @title 月统计开发数
     * @url /goods/month-count-develop/restore
     * @method post
     * @return json
     */
    public function restoreMonthCountDevelop()
    {
        $year = $this->request->param('year/d');
        $month = $this->request->param('month/d');
        if (!$year || !$month) {
            throw new JsonErrorException('请求参数有误');
        }
        $goodsHelp = new GoodsHelp();
        $goodsHelp->restoreMonthCountDevelop($year, $month);
        return json(['message' => '操作成功'], 200);
    }
}
