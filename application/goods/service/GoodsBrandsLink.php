<?php


namespace app\goods\service;

use app\common\cache\Cache;
use app\common\exception\BrandLinkException;
use app\common\exception\JsonErrorException;
use app\common\model\CategoryMap;
use app\common\model\Goods;
use app\common\model\GoodsBrandLinkSku;
use app\common\model\GoodsGallery;
use app\common\model\GoodsLang;
use app\common\service\Common;
use app\common\service\ImportExport;
use app\index\service\DownloadFileService;
use app\report\model\ReportExportFiles;
use phpzip\PHPZip;
use service\brandslink\BrandslinkApi;
use app\common\model\brandslink\BrandslinkCategory;
use app\common\model\brandslink\BrandslinkCategoryMap;
use app\goods\service\GoodsImage;
use think\Exception;
use app\common\model\Category;
use app\common\model\GoodsSku;
use think\Db;
use think\Request;

class GoodsBrandsLink
{

    private $api = null;

    /**
     * 导入的文件格式
     * @var array
     */
    public const MIME_TYPE = ['xls', 'xlsx'];

    /**
     * 一次导入的最大值
     * @var int
     */
    public const MAX_IMPORT_NUM = 200;

    /**
     *  一次导出最大值
     * @var int
     */
    public const MAX_EXPORT_NUM = 500;

    public const EXPORT_FIELD = [
        ['title' => '更新时间', 'key' => 'update_time'],
        ['title' => 'SKU', 'key' => 'sku'],
        ['title' => '产品中文名', 'key' => 'goods_name'],
        ['title' => 'SKU状态', 'key' => 'status'],
        ['title' => '分类', 'key' => 'goods_category'],
        ['title' => '推送结果', 'key' => 'push_status'],
        ['title' => '日志', 'key' => 'log'],
        ['title' => '操作人', 'key' => 'creator'],
        ['title' => '执行时间', 'key' => 'push_time']
    ];

    /**
     * 表头
     * @var array
     */
    protected const EXCEL_HEADER = ['sku'];

    /**
     * 推送状态
     * @var array
     */
    public const PUSH_STATUS = [
        1 => '未推送',
        2 => '成功',
        3 => '失败',
        4 => '更新失败'
    ];

    public const PUSH_STATUS_NO = 1;

    public const PUSH_STATUS_SUCCESS = 2;

    public const PUSH_STATUS_FAIL = 3;

    public const PUSH_STATUS_UPDATE_FAIL = 4;

    public function __construct()
    {
        $this->api = BrandslinkApi::instance([]);
    }

    public function getAllCategory()
    {
        $page = 1;
        $pageSize = 100;
        $result = [];
        do {
            $category = $this->api->loader('category')->lists($page, $pageSize);
            if (!$category) {
                throw new Exception('返回为空');
            }
            if ($category['errorCode'] != 100200) {
                throw  new Exception($category['msg']);
            }
            if (isset($category['data'])) {
                $pageInfo = $category['data']['pageInfo'] ?? [];
                if ($pageInfo) {
                    foreach ($pageInfo['list'] as $row) {
                        $newRow = $row;
                        unset($newRow['children']);
                        $newRow['path'] = $row['id'];
                        $result[] = $newRow;
                        $data2 = $this->pageInfo($row, [], $row['id']);
                        $result = array_merge($result, $data2);
                    }
                }
            } else {
                throw  new Exception('返回格式有误');
            }
            $page++;
        } while ($pageInfo['hasNextPage'] == true);
        return $result;
    }

    public function ref()
    {
        $result = $this->getAllCategory();
        if ($result) {
            $time = time();
            Db::startTrans();
            try {
                $BrandslinkCategory = new BrandslinkCategory();
                $BrandslinkCategory->where('id', '>', 0)->delete();
                foreach ($result as $info) {
                    $info['create_time'] = $time;
                    $BrandslinkCategory = new BrandslinkCategory();
                    $BrandslinkCategory->allowField(true)
                        ->isUpdate(false)
                        ->save($info);
                }
                Db::commit();
            } catch (Exception $ex) {
                Db::rollback();
                throw $ex;
            }

        }
    }

    public function buildMap()
    {
        $Category = new Category();
        $aCategory = $Category->field('id,title')->select();
        foreach ($aCategory as $categoryInfo) {
            $title = $categoryInfo['title'];
            $mapId = $this->mapCategoryTitle($title);
            if ($mapId) {
                $data = [
                    'category_id' => $categoryInfo['id'],
                    'title' => $categoryInfo['title'],
                    'brandslink_category_id' => $mapId,
                ];
                $BrandslinkCategoryMap = new BrandslinkCategoryMap();
                $BrandslinkCategoryMap
                    ->allowField(true)
                    ->isUpdate(false)
                    ->save($data);
            }
        }
    }

    public function mapCategoryTitle($title)
    {
        $BrandslinkCategory = new BrandslinkCategory();
        $ret = $BrandslinkCategory
            ->where('categoryName', $title)
            ->field('id')
            ->find();
        if ($ret) {
            return $ret['id'];
        }
        return 0;
    }

    private function pageInfo($row, $data = [], $path = '')
    {

        if ($row['children']) {
            foreach ($row['children'] as $v) {
                $new = $v;
                unset($new['children']);
                if ($path) {
                    $new['path'] = $path . "-" . $v['id'];
                } else {
                    $new['path'] = $path;
                }
                $data[] = $new;
                $data = $this->pageInfo($v, $data, $new['path']);
            }
        }
        return $data;

    }

    public function attr()
    {
        $category = $this->api->loader('Attribute')->lists(1, 500);
    }

    public function brand()
    {
        $category = $this->api->loader('Brand')->lists(1, 500);
    }

    public function createGoods($id)
    {
        $goodsModel = new Goods();
        $goodsHelp = new GoodsHelp();
        $goodsInfo = $goodsModel->where('id', $id)->find($id);
        if (!$goodsInfo) {
            throw new Exception('商品不存在');
        }
        if (!$goodsInfo['category_id']) {
            throw new Exception('当前商品没有分类推送失败');
        }
        $CategoryModel = new Category();
        $categoryInfo = $CategoryModel->where('id', $goodsInfo['category_id'])->find();
        if (!$categoryInfo) {
            throw new Exception('该分类不存在');
        }
        $data = [];
        $categoryId = $goodsInfo['category_id'];
        $categoryData = $this->mapBrandsLinkCategoryId($categoryInfo['title']);
        $data['brandName'] = '';
        if ($goodsInfo['brand_id']) {
            $data['brandName'] = $goodsModel->getBrandAttr(null, ['brand_id' => $goodsInfo['brand_id']]);
        }
        if (!$data['brandName']) {
            $data['brandName'] = 'OEM';
        }
        $data = array_merge($data, $categoryData);
        $data['defaultRepository'] = $goodsModel->getWarehouseNameAttr(null, ['warehouse_id' => $goodsInfo['warehouse_id']]);
        $data['isPrivateModel'] = false;
        //$data['masterPicture'] = GoodsImage::getThumbPath($goodsInfo['thumb'], 0, 0);
        $data['producer'] = $goodsModel->getSupplierAttr(null, ['supplier_id' => $goodsInfo['supplier_id']]);
        $data['productLogisticsAttributes'] = $goodsHelp->getProTransPropertiesTxt($goodsInfo['transport_property']);
        $data['productMarketTime'] = $goodsInfo['publish_time'] ? date('Y-m-d', $goodsInfo['publish_time']) : '';
        $data['supplierId'] = 100;
        $data['supplierName'] = '利朗达';
        $data['title'] = $goodsInfo['name'];
        $aPlatform = $goodsHelp->getPlatformSale($goodsInfo['platform']);
        if (!$aPlatform) {
            throw new Exception('当前商品可用平台为空，无法推送');
        }
        $canPush = true;
        $channelId = [1 => 'eBay', 2 => 'Amazon', 3 => 'wish', 4 => 'AliExpress'];
        $can = [];
        foreach ($aPlatform as $v) {
            if (in_array($v['id'], array_keys($channelId))) {
                if ($v['value_id']) {
                    $can[] = $v['id'];
                }
            }
        }

        if (empty($can)) {
            throw new Exception('当前商品无可售平台，无法推送');
        }
        $aVendibilityPlatform = [];
        foreach ($can as $canId) {
            $aVendibilityPlatform[] = $channelId[$canId];
        }
        $aSku = $this->getSku($id);
        if (!$aSku) {
            throw new Exception('当前产品sku为空');
        }
        $skuMap = [];
        foreach ($aSku as $skuInfo) {
            $skuMap[$skuInfo['id']] = $skuInfo['sku'];
        }
        $data['vendibilityPlatform'] = implode(',', $aVendibilityPlatform);
        $commodityDetails = [];
        //$image = $this->getAllPicByGoodsId($id, $goodsInfo['spu'], $skuMap);
        $image = $this->getGoodsImages($id);
        if (empty($image['main'])) {
            throw new Exception('商品： ' . $goodsInfo['spu'] . '的主图不能为空，不能推送');
        }
        $commodityDetails['masterPicture'] = implode("|", $image['main']);
        $commodityDetails['additionalPicture'] = implode("|", $image['detail']);

        $aLang = $this->getLang($id);
        $getSpecifiedFormatData = $this->getSpecifiedFormatData($aLang, $goodsInfo);

        $commodityDetails['searchKeyWords'] = $getSpecifiedFormatData['searchKeyWords'];
        $commodityDetails['commodityDesc'] = $getSpecifiedFormatData['commodityDesc'];
        $commodityDetails['strength1'] = $getSpecifiedFormatData['strength1'];
        $commodityDetails['strength2'] = $getSpecifiedFormatData['strength2'];
        $commodityDetails['strength3'] = $getSpecifiedFormatData['strength3'];
        $commodityDetails['strength4'] = $getSpecifiedFormatData['strength4'];
        $commodityDetails['strength5'] = $getSpecifiedFormatData['strength5'];
        $commodityDetails['provePicture'] = '';
        $data['commodityDetails'] = $commodityDetails;
        $CommoditySpec = [];
        foreach ($aSku as $skuInfo) {
            $row = [];
            $row['commodityPrice'] = number_format($skuInfo['cost_price'], 2, '.', '');
            $row['retailPrice'] = number_format($skuInfo['retail_price'], 2, '.', '');
            $row['commodityNameCn'] = $skuInfo['spu_name'];
            $row['commodityNameEn'] = isset($aLang[2]) ? $aLang[2]['title'] : '';
            $row['supplierSku'] = $skuInfo['sku'];
            $row['commodityHeight'] = number_format($skuInfo['height'] / 10, 2, '.', '');
            $row['commodityLength'] = number_format($skuInfo['length'] / 10, 2, '.', '');
            $row['commodityWeight'] = number_format($skuInfo['weight'], 2, '.', '');
            $row['commodityWidth'] = number_format($skuInfo['width'] / 10, 2, '.', '');
            $row['packingHeight'] = 0;
            $row['packingLength'] = 0;
            $row['packingWeight'] = 0;
            $row['packingWidth'] = 0;
            $sku_id = $skuInfo['id'];
            $image = $this->getGoodsImages($id, $sku_id, 'sku');
            if (empty($image['main'])) {
                throw new Exception('商品： ' . $goodsInfo['spu'] . '的sku：'
                    . $skuInfo['sku'] . ' 的主图为空，无法推送');
            }
            $row['masterPicture'] = join('|', $image['main']);
            $row['additionalPicture'] = join('|', $image['detail']);
            $row['customsNameCn'] = isset($aLang[1]) ? $aLang[1]['declare_name'] : $goodsInfo['declare_name'];
            $row['customsNameEn'] = isset($aLang[2]) ? $aLang[2]['declare_name'] : $goodsInfo['declare_en_name'];
            $row['customsPrice'] = '';
            $row['customsWeight'] = '';
            $row['customsCode'] = $goodsInfo['hs_code'];
            $attr = json_decode($skuInfo['sku_attributes'], true);
            $json = GoodsHelp::getAttrbuteInfoBySkuAttributes($attr, $id);
            $newJson = [];
            foreach ($json as $k => $attr_value) {
                $_key = explode('|', $attr_value['name']);
                $newKey = '';
                if (count($_key) == 2) {
                    $newKey = $_key[0] . "({$_key[1]})";
                } else {
                    $newKey = $_key[0] . "({$_key[0]})";
                }
                $_value = explode('|', $attr_value['value']);
                $newValue = '';
                if (count($_value) == 2) {
                    $newValue = $_value[0] . "({$_value[1]})";
                } else {
                    $newValue = $_value[0] . "({$_value[0]})";
                }
                $newJson[] = $newKey . ":" . $newValue;
            }
            if (empty($newJson)) {
                $row['commoditySpec'] = '无属性(NoAttributes):无属性(NoAttributes)';
            } else {
                $row['commoditySpec'] = implode('|', $newJson);
            }

            $CommoditySpec[] = $row;
        }
        $data['commoditySpecList'] = $CommoditySpec;
        $result = $this->api->loader('goods')->push($data);
        if ($result['errorCode'] == 100200) {
            return ['message' => '请求成功'];
        } else {
            throw new Exception($result['msg']);
        }
    }

    public function getSku($goods_id)
    {
        $GoodsSku = new GoodsSku();
        return $GoodsSku->where('goods_id', $goods_id)->where('status', '<>', 2)->select();
    }

    public function getLang($goods_id)
    {
        $GoodsLang = new GoodsLang();
        $result = [];
        $aLang = $GoodsLang->where('goods_id', $goods_id)->select();
        foreach ($aLang as $langInfo) {
            $result[$langInfo->lang_id] = $langInfo;
        }
        return $result;
    }

    protected function getSpecifiedFormatData($goodsLang, $goodsInfo)
    {
        $searchKeyWords = ''; //搜索关键字
        $commodityDesc = ''; //商品描述
        $packingLists = ''; //包装清单暂为空
        $strength1 = ''; //亮点1
        $strength2 = ''; //亮点2
        $strength3 = ''; //亮点3
        $strength4 = ''; //亮点4
        $strength5 = ''; //亮点5
        foreach ($goodsLang as $k => $v) {
            $sellingPoint = json_decode($v['selling_point'], true);
            $tmp1 = array_key_exists('amazon_point_1', $sellingPoint) ? $sellingPoint['amazon_point_1'] : '';
            $tmp2 = array_key_exists('amazon_point_2', $sellingPoint) ? $sellingPoint['amazon_point_2'] : '';
            $tmp3 = array_key_exists('amazon_point_3', $sellingPoint) ? $sellingPoint['amazon_point_3'] : '';
            $tmp4 = array_key_exists('amazon_point_4', $sellingPoint) ? $sellingPoint['amazon_point_4'] : '';
            $tmp5 = array_key_exists('amazon_point_5', $sellingPoint) ? $sellingPoint['amazon_point_5'] : '';
            switch ($k) {
                case 1:
                    $searchKeyWords .= ':::CN===' . join(',', explode('\n', $v['tags']));
                    $commodityDesc .= ':::CN===' . ($v['description'] ?? $goodsInfo['description']);
                    $strength1 .= ':::CN===' . $tmp1;
                    $strength2 .= ':::CN===' . $tmp2;
                    $strength3 .= ':::CN===' . $tmp3;
                    $strength4 .= ':::CN===' . $tmp4;
                    $strength5 .= ':::CN===' . $tmp5;
                    break;
                case 2:
                    $searchKeyWords .= ':::EN===' . join(',', explode('\n', $v['tags']));
                    $commodityDesc .= ':::EN===' . ($v['description'] ?? '');
                    $strength1 .= ':::EN===' . $tmp1;
                    $strength2 .= ':::EN===' . $tmp2;
                    $strength3 .= ':::EN===' . $tmp3;
                    $strength4 .= ':::EN===' . $tmp4;
                    $strength5 .= ':::EN===' . $tmp5;
                    break;
                case 3:
                    $searchKeyWords .= ':::DE===' . join(',', explode('\n', $v['tags']));
                    $commodityDesc .= ':::DE===' . ($v['description'] ?? '');
                    $strength1 .= ':::DE===' . $tmp1;
                    $strength2 .= ':::DE===' . $tmp2;
                    $strength3 .= ':::DE===' . $tmp3;
                    $strength4 .= ':::DE===' . $tmp4;
                    $strength5 .= ':::DE===' . $tmp5;
                    break;
                case 4:
                    $searchKeyWords .= ':::FR===' . join(',', explode('\n', $v['tags']));
                    $commodityDesc .= ':::FR===' . ($v['description'] ?? '');
                    $strength1 .= ':::FR===' . $tmp1;
                    $strength2 .= ':::FR===' . $tmp2;
                    $strength3 .= ':::FR===' . $tmp3;
                    $strength4 .= ':::FR===' . $tmp4;
                    $strength5 .= ':::FR===' . $tmp5;
                    break;
                case 6:
                    $searchKeyWords .= ':::IT===' . join(',', explode('\n', $v['tags']));
                    $commodityDesc .= ':::IT===' . ($v['description'] ?? '');
                    $strength1 .= ':::IT===' . $tmp1;
                    $strength2 .= ':::IT===' . $tmp2;
                    $strength3 .= ':::IT===' . $tmp3;
                    $strength4 .= ':::IT===' . $tmp4;
                    $strength5 .= ':::IT===' . $tmp5;
                    break;
            }
        }
        $searchKeyWords = trim($searchKeyWords, ':::');
        $commodityDesc = trim($commodityDesc, ':::');
        $strength1 = trim($strength1, ':::');
        $strength2 = trim($strength2, ':::');
        $strength3 = trim($strength3, ':::');
        $strength4 = trim($strength4, ':::');
        $strength5 = trim($strength5, ':::');
        return [
            'searchKeyWords' => $searchKeyWords,
            'commodityDesc' => $commodityDesc,
            'packingLists' => $packingLists,
            'strength1' => $strength1,
            'strength2' => $strength2,
            'strength3' => $strength3,
            'strength4' => $strength4,
            'strength5' => $strength5
        ];
    }

    public function getAllPicByGoodsId($goods_id, $spu, $skuMap)
    {
        $GoodsGallery = new GoodsGallery();
        $ret = $GoodsGallery
            ->field('id,goods_id,path,sku_id,is_default')
            ->where('goods_id', $goods_id)
            ->select();

        $trueMain = [];
        $detail = [];
        foreach ($ret as $v) {
            if ($v['is_default'] == 1) {
                if (!$v['sku_id']) {
                    if (empty($trueMain)) {
                        $trueMain[] = GoodsImage::getThumbPath($v['path'], 0, 0) . "?spu=" . $spu;
                    }
                } else {
                    if (!isset($skuMap[$v['sku_id']])) {
                        continue;
                    }
                    $sku = $skuMap[$v['sku_id']];
                    if (empty($trueMain)) {
                        $trueMain[] = GoodsImage::getThumbPath($v['path'], 0, 0) . "?sku=" . $sku;
                    } else {
                        $detail[] = GoodsImage::getThumbPath($v['path'], 0, 0) . "?sku=" . $sku;
                    }
                }
            } else {
                if (!$v['sku_id']) {
                } else {
                    if (!isset($skuMap[$v['sku_id']])) {
                        continue;
                    }
                    $sku = $skuMap[$v['sku_id']];
                    $detail[] = GoodsImage::getThumbPath($v['path'], 0, 0) . "?sku=" . $sku;
                }
            }
        }
        return ['main' => $trueMain, 'detail' => $detail];
    }

    public function getGoodsImages($goods_id, $sku_id = 0, $type = 'spu')
    {
        $GoodsGallery = new GoodsGallery();
        $main = [];
        $detail = [];
        if ($type == 'spu') {
            //先处理单sku的商品  spu和sku的所有图片都一样
            $skuList = GoodsSku::all(['goods_id' => $goods_id]);
            if (count($skuList) == 1) {
                $sku_id = ($skuList[0])->id;
                $list = $GoodsGallery
                    ->field('id,goods_id,path,sku_id,is_default')
                    ->where('goods_id', '=', $goods_id)
                    ->where('sku_id', '=', $sku_id)
                    ->select();
                foreach ($list as $v) {
                    if ($v['is_default'] == 1) {
                        $main[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                    } else {
                        $detail[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                    }
                }
            } else {
                $list = $GoodsGallery
                    ->field('id,goods_id,path,sku_id,is_default')
                    ->where('goods_id', '=', $goods_id)
                    ->where('sku_id', '=', 0)
                    ->select();
                if (!empty($list)) {
                    foreach ($list as $v) {
                        if ($v['is_default'] == 1) {
                            $main[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                        } else {
                            $detail[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                        }
                    }
                }
            }
        } elseif ($type == 'sku') {
            if (!$sku_id) {
                throw new JsonErrorException('sku_id参数有误');
            }
            $list = $GoodsGallery
                ->field('id,goods_id,path,sku_id,is_default')
                ->where('goods_id', '=', $goods_id)
                ->where('sku_id', '=', $sku_id)
                ->select();
            if (!empty($list)) {
                foreach ($list as $v) {
                    if ($v['is_default'] == 1) {
                        $main[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                    } else {
                        $detail[] = GoodsImage::getThumbPath($v['path'], 0, 0);
                    }
                }
            }
        }
        return [
            'main' => $main,
            'detail' => $detail
        ];
    }

    private function mapCategoryId($categoryId)
    {

        $brandslinkCategoryMap = new BrandslinkCategoryMap();
        $mapInfo = $brandslinkCategoryMap->where('category_id', $categoryId)->find();
        if (!$mapInfo) {
            throw new Exception("当前分类[{$categoryId}]未找到匹配的分销分类id");
        }
        $mapId = $mapInfo['brandslink_category_id'];
        $brandslinkCategory = new BrandslinkCategory();
        $brandslinkInfo = $brandslinkCategory->where('id', $mapId)->find();
        if (!$brandslinkInfo) {
            throw new Exception("当前分类[{$categoryId}]对应分销分类不存在");
        }
        $path = explode('-', $brandslinkInfo['path']);
        if (count($path) != 3) {
            throw new Exception("当前分类[{$categoryId}]对应分销分类不是三级分类，无法推送");
        }
        $data = [];
        foreach ($path as $k => $id) {
            $j = $k + 1;
            $key = 'categoryLevel' . $j;
            $data[$key] = $id;
        }
        return $data;
    }

    /**
     * @title 与詹先生商议后改版匹配分类
     * @author starzhan <397041849@qq.com>
     */
    public function mapBrandsLinkCategoryId($categoryName)
    {
        $aCategory = $this->getAllCategory();
        if (!$aCategory) {
            throw new Exception('获取的品连分类为空');
        }
        foreach ($aCategory as $categoryInfo) {
            if ($categoryInfo['categoryLevel'] != 3) {
                continue;
            }
            if ($categoryName == $categoryInfo['categoryName']) {
                $categoryIds = explode('-', $categoryInfo['path']);
                $data = [];
                foreach ($categoryIds as $k => $id) {
                    $j = $k + 1;
                    $key = 'categoryLevel' . $j;
                    $data[$key] = $id;
                }
                return $data;
            }
        }
        throw new Exception('品类分类匹配失败');
    }

    /**
     * sku导入主方法
     * @return array
     * @throws Exception
     */
    public function skuImport()
    {
        set_time_limit(0);
        $params = Request::instance()->param();
        $importService = new ImportExport();
        $path = $importService->uploadFile($params['content'], 'brand_link_import');
        $importData = $importService->excelImport($path);
        if (count($importData) === 0) {
            throw new JsonErrorException('没有数据，不允许导入');
        }
        if (count($importData) > static::MAX_IMPORT_NUM) { //除去表头
            throw new JsonErrorException('导入数据每次最多200条！');
        }
        $this->checkHeader($importData);
        $result = $this->checkAndCombine($importData);

        $data = $result['data'];
        $errorMsg = $result['errorMsg'];
        $failCount = $result['failCount'];
        $msgArr = $this->combineError($errorMsg);
        //没有数据导入
        if (empty($data) || count($data) == 0) {
            return [
                'result' => -1,
                'message' => $msgArr,
                'success_count' => 0,
                'error_count' => $failCount
            ];
        }
        Db::startTrans();
        try {
            $successCount = count((new GoodsBrandLinkSku())->saveAll($data));
            Db::commit();
            //过滤过一部分
            if (!empty($msgArr)) {
                return [
                    'result' => 0,
                    'message' => $msgArr,
                    'success_count' => $successCount,
                    'error_count' => $failCount
                ];
            }
            //全部导入成功
            return [
                'result' => 1,
                'message' => '导入成功！',
                'success_count' => $successCount,
                'error_count' => $failCount
            ];
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage(), 400);
        }

    }

    /**
     * sku导入 检查表头
     * @param array $data
     * @$throws JsonErrorException
     */
    protected function checkHeader($data)
    {
        $headers = static::EXCEL_HEADER;
        $row = reset($data);
        $aRowFiles = array_keys($row);
        $aDiffRowField = array_diff($headers, $aRowFiles);
        if (!empty($aDiffRowField)) {
            throw new JsonErrorException("缺少列名[" . implode(';', $aDiffRowField) . "]");
        }
    }

    /**
     * sku导入 验证excel数据 并组装插入数据表数据
     * @param array $data
     * @return array
     */
    protected function checkAndCombine($data)
    {
        $i = 1;
        $failCount = 0;
        $errorMsg = [];
        $resultData = [];
        $userInfo = Common::getUserInfo();
        foreach ($data as $key => $vo) {
            $i++;
            $data[$key]['sku'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, 'sku')));
            //过滤空行
            $filterAfterData = array_filter($data[$key]);
            if (empty($filterAfterData)) {
                unset($data[$key]);
                $failCount++;
                continue;
            }
            $sku = param($vo, 'sku');
            if (!$sku) {
                $errorString = "第 {$i} 行 [ sku ]： 数据不能为空（注：格式正确才能导入）";
                throw new JsonErrorException($errorString, 400);
            }
            $skuInfo = GoodsSku::get(['sku' => $sku]);
            //sku不存在的情况
            if (is_null($skuInfo)) {
                $tmp = [
                    'row' => $i,
                    'sku' => $sku
                ];
                $failCount++;
                $errorMsg['sku'][] = $tmp;
                continue;
            }
            //已导入过的情况
            $brandLinkSkuInfo = GoodsBrandLinkSku::get(['sku' => $sku]);
            if (!is_null($brandLinkSkuInfo)) {
                //sku重复导入的情况
                $tmp = [
                    'row' => $i,
                    'sku' => $sku
                ];
                $failCount++;
                $errorMsg['repeat'][] = $tmp;
                continue;
            }
            //将推送时的数据验证放到导入
            $skuId = $skuInfo->id;
            $goodsHelp = new GoodsHelp();
            $goodsInfo = Cache::store('goods')->getGoodsInfo($skuInfo['goods_id']);
            $goodsId = $goodsInfo['id'];
            $sku = $skuInfo['sku'];
            $categoryId = $goodsInfo['category_id'];
            if ($skuInfo->getData('status') != 1) {
                //sku只有在售状态可以导入
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}必须“在售”状态才能导入"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            if (!$categoryId) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品没有分类"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $categoryInfo = Cache::store('category')->getCategory($categoryId);
            if (!$categoryInfo) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品的分类在erp中不存在"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            if (!isset($categoryInfo['platform']) || !$categoryInfo['platform']) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品没有平台分类"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $platFormCategoryIds = array_column($categoryInfo['platform'], 'channel_id');
            if (!in_array(31, $platFormCategoryIds)) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品没有 品连平台分类"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $aPlatform = $goodsHelp->getPlatformSale($goodsInfo['platform']);
            if (!$aPlatform) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品可用平台为空，无法推送"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $channelId = [1 => 'eBay', 2 => 'Amazon', 3 => 'wish', 4 => 'AliExpress'];
            $can = [];
            foreach ($aPlatform as $v) {
                if (in_array($v['id'], array_keys($channelId))) {
                    if ($v['value_id']) {
                        $can[] = $v['id'];
                    }
                }
            }
            if (empty($can)) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的商品无可售平台，无法推送"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $image = $this->getGoodsImages($goodsId);
            if (empty($image['main'])) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => '商品spu： ' . $goodsInfo['spu'] . '的主图不能为空，不能推送'
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            $image = $this->getGoodsImages($goodsId, $skuId, 'sku');
            if (empty($image['main'])) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "sku：{$sku}的无主图，无法推送"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }
            //语言
            $aLang = $this->getLang($goodsId);
            if (!isset($aLang[2])) {
                $tmp = [
                    'row' => $i,
                    'pl_sku' => "spu：{$goodsInfo['spu']}无英文名，不能推送"
                ];
                $failCount++;
                $errorMsg['pl_sku'][] = $tmp;
                continue;
            }

            $tmpOne['sku'] = $sku;
            $tmpOne['sku_id'] = $skuId;
            $tmpOne['goods_id'] = $goodsId;
            $tmpOne['create_time'] = time();
            $tmpOne['update_time'] = time();
            $tmpOne['log'] = '';
            $tmpOne['creator_id'] = $userInfo['user_id'];
            $resultData[] = $tmpOne;
        }
        return [
            'data' => $resultData,
            'errorMsg' => $errorMsg,
            'failCount' => $failCount
        ];
    }

    /**
     * sku导入 组装错误信息 返回前端指定格式
     * @param array $errorMsg
     * @return array
     */
    protected function combineError($errorMsg)
    {
        $returnErrMsg = [];
        if (isset($errorMsg['sku'])) {
            foreach ($errorMsg['sku'] as $v) {
                $tmp['row'] = $v['row'];
                $tmp['item_id'] = 'erp，sku: 【' . $v['sku'] . '】 不存在';
                $returnErrMsg[] = $tmp;
            }
        }

        if (isset($errorMsg['repeat'])) {
            foreach ($errorMsg['repeat'] as $v) {
                $tmp['row'] = $v['row'];
                $tmp['item_id'] = '【' . $v['sku'] . '】 在商品推送列表己存在';
                $returnErrMsg[] = $tmp;
            }
        }

        if (isset($errorMsg['pl_sku'])) {
            foreach ($errorMsg['pl_sku'] as $v) {
                $tmp['row'] = $v['row'];
                $tmp['item_id'] = $v['pl_sku'];
                $returnErrMsg[] = $tmp;
            }
        }
        return $returnErrMsg;
    }

    /**
     * 品连分类数据同步
     */
    public function brandLinksCategorySync()
    {
        $apiBrandCategory = $this->getAllCategory();
        foreach ($apiBrandCategory as &$v) {
            unset($v['categoryNameEn']); //删除数据库没有的字段
        }
        $brandCategory = BrandslinkCategory::all();
        if ($apiBrandCategory) {
            $apiBrandCategory = array_column($apiBrandCategory, null, 'id');
        } else {
            throw new JsonErrorException('品连没有分类！');
        }
        if ($brandCategory) {
            $brandCategory = array_column($brandCategory, null, 'id');
        }
        $insertData = [];
        $updateData = [];
        foreach ($apiBrandCategory as $k => $v) {
            if (isset($brandCategory[$k])) {
                $diffItemArr = array_diff_assoc($v, ($brandCategory[$k])->toArray());
                if ($diffItemArr) {
                    $updateData[] = $v;
                }
            } else {
                $insertData[] = $v;
            }
        }
        Db::startTrans();
        try {
            $BrandsLinkCategory = new BrandslinkCategory();
            if (!empty($insertData)) {
                $BrandsLinkCategory->saveAll($insertData, false);
            }
            if (!empty($updateData)) {
                $BrandsLinkCategory->saveAll($updateData);
            }
            Db::commit();
            return true;
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 前端分类/分类映射（平台分类）/distribution(品连)
     * 组装成前端能解析的json
     * @param array $where
     */
    public function combineCategory($where)
    {
        $condition = [];
        if (isset($where['category_level'])) {
            $condition['categoryLevel'] = $where['category_level']; //为了对应数据库字段
        }
        if (isset($where['category_pid'])) {
            $condition['categoryParentId'] = $where['category_pid'];
        }
        $list = BrandslinkCategory::all(function ($query) use ($condition) {
            $field = 'id as category_id,categoryLevel as category_level,categoryName as category_name,categoryParentId as category_parent_id';
            $query->field($field)->where($condition);
        });
        foreach ($list as &$v) {
            //查看是否有下级
            $row = BrandslinkCategory::get(['categoryParentId' => $v['category_id']]);
            if (is_null($row)) {
                $v['is_leaf'] = 1;
            } else {
                $v['is_leaf'] = 0;
            }
        }
        return $list;
    }

    /**
     * 获取品连分类树 递归的方式
     * @param mixed data
     * @param int pid
     */
    public function getBrandLinkCategoryTree($data, $pid = 0)
    {
        $tree = [];
        foreach ($data as $k => $v) {
            if ($v['categoryParentId'] == $pid) {
                $v['child'] = $this->getBrandLinkCategoryTree($data, $v['id']);
                if (!$v['child']) {
                    unset($v['child']);
                }
                $tree[] = $v;
            }
        }
        return $tree;
    }

    /**
     * 获取品连分类 在sku推送列表展示的数据格式   分类1>分类2>分类3
     * @param categoryId int 分类id
     */
    public function getBrandLinkCategory($categoryId)
    {
        $category = array_column(BrandslinkCategory::all(), null, 'id');
        if (isset($category[$categoryId])) {
            $namePath = '';
            $loopCategoryId = $categoryId;
            while ($loopCategoryId) {
                if (!isset($category[$loopCategoryId])) {
                    break;
                }
                $namePath = $namePath ? $category[$loopCategoryId]['categoryName'] . '>' . $namePath : $category[$loopCategoryId]['categoryName'];
                $parentId = $category[$loopCategoryId]['categoryParentId'];
                $loopCategoryId = empty($parentId) ? 0 : $parentId;
            }
            return $namePath;
        }
        return '';
    }

    /**
     * 针对搜索 构建查询
     */
    public function buildParams()
    {
        $param = Request::instance()->param();
        $where = [];
        $field = '';
        $fileName = '';

        //针对导出 推送 删除的 复选框勾选 勾选优先级最高
        if (isset($param['ids']) && $param['ids']) {
            $ids = json_decode($param['ids'], true);
            $where[] = ['sku_id', 'in', $ids];
        } else {
            /* 关闭了sku状态的搜索
             * if (isset($param['status']) && $status = $param['status']) {
                $where[] = ['b.status', '=', $status];
            }*/
            if (isset($param['push_status']) && $pushStatus = $param['push_status']) {
                $where[] = ['push_status', '=', $pushStatus];
            }
            if (isset($param['snType']) && isset($param['snText']) && !empty($param['snText'])) {
                $snType = $param['snType'];
                $snText = $param['snText'];
                if ($snType == 'sku') {
                    $sku = json_decode($snText, true);
                    $where[] = ['b.sku', 'in', $sku];
                } elseif ($snType == 'goods_name') {
                    $where[] = ['b.spu_name', 'like', "%{$snText}%"];
                }
            }

            if ($param['time_type'] == 1) {
                //更新时间
                if (isset($param['start_time']) && isset($param['end_time'])) {
                    $importStartTime = strtotime($param['start_time']);
                    $importEndTime = strtotime($param['end_time']);
                    if ($importStartTime && $importEndTime) {
                        $where[] = ['a.update_time', 'between', [$importStartTime, $importEndTime + 86399]];
                    } elseif ($importStartTime && !$importEndTime) {
                        $where[] = ['a.update_time', 'egt', $importStartTime];
                    } elseif (!$importStartTime && $importEndTime) {
                        $where[] = ['a.update_time', 'elt', $importEndTime];
                    }
                }
            } elseif ($param['time_type'] == 2) {
                //执行时间 推送时间
                if (isset($param['start_time']) && isset($param['end_time'])) {
                    $importStartTime = strtotime($param['start_time']);
                    $importEndTime = strtotime($param['end_time']);
                    if ($importStartTime && $importEndTime) {
                        $where[] = ['push_time', 'between', [$importStartTime, $importEndTime + 86399]];
                    } elseif ($importStartTime && !$importEndTime) {
                        $where[] = ['push_time', 'egt', $importStartTime];
                    } elseif (!$importStartTime && $importEndTime) {
                        $where[] = ['push_time', 'elt', $importEndTime];
                    }
                }
            }
        }

        $page = $param['page'] ?? 1;
        $pageSize = $param['pageSize'] ?? 50;

        if (isset($param['field']) && $param['field']) {
            $field = json_decode($param['field'], true);
        }

        if (isset($param['file_name'])) {
            $fileName = $param['file_name'];
        }

        $where = function ($query) use ($where) {
            foreach ($where as $k => $v) {
                if (is_array($v)) {
                    call_user_func_array([$query, 'where'], $v);
                } else {
                    $query->where($v);
                }
            }
        };
        return [$where, $field, $fileName, $page, $pageSize];
    }

    /**
     * 查询品连sku列表的数据
     * @param $where
     * @param string $field
     * @param $page
     * @param $pageSize
     * @return GoodsBrandLinkSku[]|false
     * @throws
     */
    public function getPushList($where, $field = '', $page = null, $pageSize = null)
    {
        $baseField = 'b.spu_name as goods_name,b.status,a.*';
        if (empty($field)) {
            $field = $baseField;
        }
        $list = GoodsBrandLinkSku::all(function ($query) use ($where, $field, $page, $pageSize, $baseField) {
            $query->alias('a')
                ->field($field)
                ->join('goods_sku b', 'a.sku_id = b.id', 'left')
                ->where($where);
            if ($page && $pageSize) {
                $query->page($page, $pageSize);
            }
        });
        return [
            'data' => $list,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => count($list)
        ];
    }

    /**
     * 记录数 暂时队列导出时有用
     * @param $where
     * @return int|string
     */
    public function getRecordCount($where)
    {
        $count = GoodsBrandLinkSku::alias('a')
            ->join('goods_sku b', 'a.sku_id = b.id', 'left')
            ->where($where)->count();
        return $count;
    }

    /**
     * 导出品连sku数据  直接导出
     * @param $param
     * @return array
     */
    public function redirectExport($param)
    {
        list($where, $field, $fileName) = $this->buildParams();
        $allField = static::EXPORT_FIELD;
        $excelExport = new DownloadFileService();
        $file = [
            'name' => $fileName ? : '品连推送_',
            'path' => 'brand_link'
        ];
        $header = [];
        foreach ($allField as $k => $v) {
            if (in_array($v['key'], $field)) {
                $header[] = $v;
            }
        }
        $result = $this->getPushList($where);
        return $excelExport->export($result['data'], $header, $file);
    }

    /**
     * 用于队列导出 条件筛选
     * @param $param
     * @return array
     */
    protected function getWhere($param)
    {
        $where = [];
        if (isset($param['push_status']) && $pushStatus = $param['push_status']) {
            $where[] = ['push_status', '=', $pushStatus];
        }
        if (isset($param['snType']) && isset($param['snText']) && !empty($param['snText'])) {
            $snType = $param['snType'];
            $snText = $param['snText'];
            if ($snType == 'sku') {
                $sku = json_decode($snText, true);
                $where[] = ['b.sku', 'in', $sku];
            } elseif ($snType == 'goods_name') {
                $where[] = ['b.spu_name', 'like', "%{$snText}%"];
            }
        }

        if ($param['time_type'] == 1) {
            //更新时间
            if (isset($param['start_time']) && isset($param['end_time'])) {
                $importStartTime = strtotime($param['start_time']);
                $importEndTime = strtotime($param['end_time']);
                if ($importStartTime && $importEndTime) {
                    $where[] = ['a.update_time', 'between', [$importStartTime, $importEndTime + 86399]];
                } elseif ($importStartTime && !$importEndTime) {
                    $where[] = ['a.update_time', 'egt', $importStartTime];
                } elseif (!$importStartTime && $importEndTime) {
                    $where[] = ['a.update_time', 'elt', $importEndTime];
                }
            }
        } elseif ($param['time_type'] == 2) {
            //执行时间 推送时间
            if (isset($param['start_time']) && isset($param['end_time'])) {
                $importStartTime = strtotime($param['start_time']);
                $importEndTime = strtotime($param['end_time']);
                if ($importStartTime && $importEndTime) {
                    $where[] = ['push_time', 'between', [$importStartTime, $importEndTime + 86399]];
                } elseif ($importStartTime && !$importEndTime) {
                    $where[] = ['push_time', 'egt', $importStartTime];
                } elseif (!$importStartTime && $importEndTime) {
                    $where[] = ['push_time', 'elt', $importEndTime];
                }
            }
        }

        $where = function ($query) use ($where) {
            foreach ($where as $k => $v) {
                if (is_array($v)) {
                    call_user_func_array([$query, 'where'], $v);
                } else {
                    $query->where($v);
                }
            }
        };
        return $where;
    }

    /**
     * 品连导出字段
     * @return array
     */
    public function getExportField()
    {
        $field = self::EXPORT_FIELD;
        $newField = [];
        foreach ($field as $v) {
            $tmp['field_key'] = $v['key'];
            $tmp['field_name'] = $v['title'];
            $newField[] = $tmp;
        }
        return $newField;
    }

    /**
     * 队列导出
     * @param $param
     */
    public function queueExport($param)
    {
        $where = $this->getWhere($param);
        $field = json_decode($param['field'], true);
        $userInfo = Common::getUserInfo();
        $downFileName = $param['file_name'] ? : '品连sku_' . date('YmdHis');
        $downFileName .= "({$userInfo['realname']}).csv";
        $header = [];
        $allField = static::EXPORT_FIELD;
        foreach ($allField as $k => $v) {
            if (in_array($v['key'], $field)) {
                $header[] = $v;
            }
        }

        $count = $this->getRecordCount($where);
        //队列操作
        $page = 1;
        $pageSize = 10000;
        $pageTotal = ceil($count / $pageSize);
        $fileName = str_replace('.csv', '', $downFileName);
        $fileDirPath = ROOT_PATH . 'public' . DS . 'download' . DS . 'brand_link';
        $filePath = $fileDirPath . DS . $downFileName;

        $aHeader = array_column($header, 'title');
        $fp = fopen($filePath, 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $aHeader);
        fclose($fp);

        do {
            $offset = ($page - 1) * $pageSize;
            $fp = fopen($filePath, 'a');
            $list = GoodsBrandLinkSku::all(function ($query) use ($header, $where, $offset, $pageSize) {
                $baseField = 'b.spu_name as goods_name,b.status,a.*';
                $query->alias('a')
                    ->field($baseField)
                    ->join('goods_sku b', 'a.sku_id = b.id', 'left')
                    ->where($where)
                    ->limit($offset, $pageSize);
            });

            foreach ($list as &$v) {
                $v = $v->toArray(); //转为获取器的值
                $keys = array_keys($list[0]);
                $rowContent = [];
                foreach ($header as $h) {
                    $field = $h['key'];
                    $value = in_array($field, $keys) ? $v[$field] : '';
                    $rowContent[] = $value;
                }
                fputcsv($fp, $rowContent);
            }
            fclose($fp);
            $page++;
        } while ($page <= $pageTotal);

        $zipPath = $fileDirPath . DS . $fileName . ".zip";
        $phpZip = new PHPZip();
        $zipData = [
            [
                'name' => $fileName,
                'path' => $filePath
            ]
        ];
        $phpZip->saveZip($zipData, $zipPath);
        @unlink($filePath);
        $applyRecord = ReportExportFiles::get($param['apply_id']);
        $applyRecord['exported_time'] = time();
        $applyRecord['download_url'] = '/download/brand_link/' . $fileName . ".zip";
        $applyRecord['status'] = 1;
        $applyRecord->isUpdate()->save();
    }

    /**
     * 推送前置操作 防止队列异常情况
     * @param array $ids
     */
    public function pushBeforeAction($ids)
    {
        foreach ($ids as $id) {
            $bSku = GoodsBrandLinkSku::get(['sku_id' => $id]);
            $pushStatus = $bSku->getData('push_status');
            if ($pushStatus != self::PUSH_STATUS_FAIL) {
                continue;
            }
            $bSku->save(['push_status' => self::PUSH_STATUS_NO]);
        }
    }

    /**
     * 按sku来推送商品到品连
     * @param $skuId
     * @return array
     * @throws Exception
     */
    public function skuPush($skuId)
    {
        $skuInfo = Cache::store('goods')->getSkuInfo($skuId);
        $sku = $skuInfo['sku'];
        $bSku = GoodsBrandLinkSku::get(['sku_id' => $skuId]);
        $pushStatus = $bSku->getData('push_status');
        $skuStatus = $skuInfo['status'];
        if ($pushStatus == self::PUSH_STATUS_UPDATE_FAIL) {
            $res = $this->onOffLine($skuId, $sku, $skuStatus);
            return $res === false || $res === null ? false : true;
        }

        $goodsHelp = new GoodsHelp();
        $goodsInfo = Cache::store('goods')->getGoodsInfo($skuInfo['goods_id']);
        $goodsId = $goodsInfo['id'];
        $categoryId = $goodsInfo['category_id'];
        if (!$categoryId) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品没有分类"
            ]);
        }
        $categoryInfo = Cache::store('category')->getCategory($categoryId);
        if (!$categoryInfo) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品的分类在erp中不存在"
            ]);
        }
        if (!isset($categoryInfo['platform']) || !$categoryInfo['platform']) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品没有平台分类"
            ]);
        }
        $platFormCategoryIds = array_column($categoryInfo['platform'], 'channel_id');
        if (!in_array(31, $platFormCategoryIds)) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品没有品连平台分类"
            ]);
        }
        $bCategoryMap = CategoryMap::get([
            'category_id' => $categoryId,
            'channel_id' => 31,
        ]);
        $brandLinkCategoryId = $bCategoryMap->channel_category_id;
        $brandLinkCategory = BrandslinkCategory::get($brandLinkCategoryId);
        if (is_null($brandLinkCategory)) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品的品连平台分类映射失败，请维护！"
            ]);
        }

        $data = [];
        //分类
        $bCategoryIds = explode('-', $brandLinkCategory['path']);
        foreach ($bCategoryIds as $k => $id) {
            $j = $k + 1;
            $key = 'categoryLevel' . $j;
            $data[$key] = $id;
        }
        //品牌
        $goodsModel = new Goods();
        $data['brandName'] = '';
        if ($goodsInfo['brand_id']) {
            $data['brandName'] = $goodsModel->getBrandAttr(null, ['brand_id' => $goodsInfo['brand_id']]);
        }
        if (!$data['brandName']) {
            $data['brandName'] = 'OEM';
        }
        $data['defaultRepository'] = $goodsModel->getWarehouseNameAttr(null, ['warehouse_id' => $goodsInfo['warehouse_id']]);
        $data['isPrivateModel'] = false;
        $data['producer'] = $goodsModel->getSupplierAttr(null, ['supplier_id' => $goodsInfo['supplier_id']]);
        $data['productLogisticsAttributes'] = $goodsHelp->getProTransPropertiesTxt($goodsInfo['transport_property']);
        $data['productMarketTime'] = $goodsInfo['publish_time'] ? date('Y-m-d', $goodsInfo['publish_time']) : '';
        $data['supplierId'] = 100;
        $data['supplierName'] = '利朗达';
        $data['title'] = $goodsInfo['name'];
        $data['supplierSpu'] = $goodsInfo['spu']; //新增spu值

        $aPlatform = $goodsHelp->getPlatformSale($goodsInfo['platform']);
        if (!$aPlatform) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品的可用平台为空，无法推送"
            ]);
        }
        $channelId = [1 => 'eBay', 2 => 'Amazon', 3 => 'wish', 4 => 'AliExpress'];
        $can = [];
        foreach ($aPlatform as $v) {
            if (in_array($v['id'], array_keys($channelId))) {
                if ($v['value_id']) {
                    $can[] = $v['id'];
                }
            }
        }
        if (empty($can)) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的商品无可售平台，无法推送"
            ]);
        }

        $aVendibilityPlatform = [];
        foreach ($can as $canId) {
            $aVendibilityPlatform[] = $channelId[$canId];
        }
        $data['vendibilityPlatform'] = implode(',', $aVendibilityPlatform);

        $commodityDetails = [];
        $image = $this->getGoodsImages($goodsId);
        if (empty($image['main'])) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => '商品spu： ' . $goodsInfo['spu'] . '的主图不能为空，不能推送'
            ]);
        }
        $commodityDetails['masterPicture'] = implode("|", $image['main']);
        $commodityDetails['additionalPicture'] = implode("|", $image['detail']);

        $aLang = $this->getLang($goodsId);
        $getSpecifiedFormatData = $this->getSpecifiedFormatData($aLang, $goodsInfo);

        $commodityDetails['searchKeyWords'] = $getSpecifiedFormatData['searchKeyWords'];
        $commodityDetails['commodityDesc'] = $getSpecifiedFormatData['commodityDesc'];
        $commodityDetails['strength1'] = $getSpecifiedFormatData['strength1'];
        $commodityDetails['strength2'] = $getSpecifiedFormatData['strength2'];
        $commodityDetails['strength3'] = $getSpecifiedFormatData['strength3'];
        $commodityDetails['strength4'] = $getSpecifiedFormatData['strength4'];
        $commodityDetails['strength5'] = $getSpecifiedFormatData['strength5'];
        $commodityDetails['provePicture'] = '';
        $data['commodityDetails'] = $commodityDetails;

        $CommoditySpec = [];
        $row = [];
        $row['commodityPrice'] = number_format($skuInfo['cost_price'], 2, '.', '');
        $row['retailPrice'] = number_format($skuInfo['retail_price'], 2, '.', '');
        $row['commodityNameCn'] = $skuInfo['spu_name'];
        $row['commodityNameEn'] = isset($aLang[2]) ? $aLang[2]['title'] : '';
        $row['supplierSku'] = $sku;
        $row['commodityHeight'] = number_format($skuInfo['height'] / 10, 2, '.', '');
        $row['commodityLength'] = number_format($skuInfo['length'] / 10, 2, '.', '');
        $row['commodityWeight'] = number_format($skuInfo['weight'], 2, '.', '');
        $row['commodityWidth'] = number_format($skuInfo['width'] / 10, 2, '.', '');
        $row['packingHeight'] = 0;
        $row['packingLength'] = 0;
        $row['packingWeight'] = 0;
        $row['packingWidth'] = 0;

        $image = $this->getGoodsImages($goodsId, $skuId, 'sku');
        if (empty($image['main'])) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => "sku：{$sku}的无主图，无法推送"
            ]);
        }
        $row['masterPicture'] = join('|', $image['main']);
        $row['additionalPicture'] = join('|', $image['detail']);
        $row['customsNameCn'] = isset($aLang[1]) ? $aLang[1]['declare_name'] : $goodsInfo['declare_name'];
        $row['customsNameEn'] = isset($aLang[2]) ? $aLang[2]['declare_name'] : $goodsInfo['declare_en_name'];
        $row['customsPrice'] = '';
        $row['customsWeight'] = '';
        $row['customsCode'] = $goodsInfo['hs_code'];
        $attr = json_decode($skuInfo['sku_attributes'], true);

        $json = GoodsHelp::getAttrbuteInfoBySkuAttributes($attr, $goodsId);
        $newJson = [];
        foreach ($json as $k => $attr_value) {
            $_key = explode('|', $attr_value['name']);
            if (count($_key) == 2) {
                $newKey = $_key[0] . "({$_key[1]})";
            } else {
                $newKey = $_key[0] . "({$_key[0]})";
            }
            $_value = explode('|', $attr_value['value']);
            if (count($_value) == 2) {
                $newValue = $_value[0] . "({$_value[1]})";
            } else {
                $newValue = $_value[0] . "({$_value[0]})";
            }
            $newJson[] = $newKey . ":" . $newValue;
        }
        if (empty($newJson)) {
            $row['commoditySpec'] = '无属性(NoAttributes):无属性(NoAttributes)';
        } else {
            $row['commoditySpec'] = implode('|', $newJson);
        }
        $CommoditySpec[] = $row;
        $data['commoditySpecList'] = $CommoditySpec;
        $result = $this->api->loader('goods')->push($data);
        if ($result['errorCode'] != 100200) {
            throw new BrandLinkException([
                'sku_id' => $skuId,
                'push_status' => self::PUSH_STATUS_FAIL,
                'log' => $result['msg']
            ]);
        }
        Db::startTrans();
        try {
            if ($bSku->getData('first_push_time') > 0) {
                $bSku->save([
                    'push_status' => self::PUSH_STATUS_SUCCESS,
                    'push_time' => time(),
                    'log' => "sku：{$sku}推送成功"
                ]);
            } else {
                $bSku->save([
                    'push_status' => self::PUSH_STATUS_SUCCESS,
                    'push_time' => time(),
                    'first_push_time' => time(),
                    'log' => "sku：{$sku}第一次推送成功"
                ]);
            }
            Db::commit();
            return ['message' => '请求成功'];
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage());
        }
    }

    /**
     * sku状态（在售、停售） 同步品连
     * @param $skuId
     * @param $sku
     * @param $newStatus
     * @param $isValidate
     * @throws
     */
    public function onOffLine($skuId, $sku, $newStatus, $isValidate = false)
    {
        if ($isValidate === true) {
            $row = GoodsBrandLinkSku::get(['sku_id' => $skuId]);
            if (is_null($row)) {
                return; // 这种情况不做日志 原因找不到
            }
            if ($row->first_push_time <= 0) {
                return; // 这种情况不做日志 原因找不到
            }
        }
        $data = [];
        if ($newStatus == 2 || $newStatus == 4) {
            //停售和卖完下架
            $data = [
                'optType' => '-1',
                'supplierId' => 100,
                'supplierSkuList' => [$sku]
            ];
        } elseif ($newStatus == 1 || $newStatus == 5) {
            //在售和缺货
            $data = [
                'optType' => '1',
                'supplierId' => 100,
                'supplierSkuList' => [$sku]
            ];
        }
        if (!$data) {
            return false;
        }
        Db::startTrans();
        try {
            $res = $this->api->loader('goods')->onOffLineSync($data);
            if ($res['errorCode'] != 100200) {
                throw new BrandLinkException([
                    'sku_id' => $skuId,
                    'push_status' => self::PUSH_STATUS_UPDATE_FAIL,
                    'log' => "sku：{$sku}状态更新失败： {$res['msg']}"
                ]);
            }
            $row = GoodsBrandLinkSku::get(['sku_id' => $skuId]);
            $updateData = [
                'push_status' => self::PUSH_STATUS_SUCCESS,
                'sku_status' => $newStatus,
                'log' => "sku：{$sku}状态更新成功",
                'update_time' => time()
            ];
            if ($isValidate === false) {
                $updateData['push_time'] = time();
            }
            $row->allowField(true)->save($updateData);
            Db::commit();
            return true;
        } catch (Exception $e) {
            Db::rollback();
            return false;
        }
    }
}