<?php
/**
 * Created by PhpStorm.
 * User: TOM
 * Date: 2017/8/18
 * Time: 11:02
 */

namespace app\api\help;

use app\api\validate\ProductValidate;
use app\common\cache\Cache;
use app\common\model\AttributeGroup;
use app\common\model\Brand;
use app\common\model\CategoryAttribute;
use app\common\model\Goods;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsLang;
use app\common\model\GoodsSku;
use app\common\model\GoodsSourceUrl;
use app\common\model\Packing;
use app\common\model\SupplierGoodsOffer;
use app\common\service\UniqueQueuer;
use app\goods\queue\SyncGoodsImgQueue;
use app\goods\service\GoodsImport;
use app\goods\service\GoodsHelp;
use app\goods\service\GoodsSku as GoodsSkuService;
use app\goods\service\GoodsSkuAlias as ServiceGoodsSkuAlias;
use app\goods\service\CategoryHelp;
use app\common\model\GoodsSkuAlias;
use app\common\model\Category;
use app\common\model\Attribute;
use app\common\model\AttributeValue;
use app\goods\service\GoodsLog;
use think\Db;
use think\Exception;
use app\goods\service\GoodsNotice;
use app\order\service\OrderRuleExecuteService;
use app\purchase\service\SupplierService;
use app\common\model\SupplierGoodsOfferHistory;
use app\common\model\SupplierStatisticReport;
use app\purchase\service\SupplierOfferService;

class ProductHelp
{
    const PARAM_ERROR = 1;  //参数错误
    private $_validate;
    private $response;
    private $errors;
    private $queue;
    //开发部门

    private $dev_platform = [
        7 => 'AliExpress部',
        1 => 'Amazon部',
        2 => 'eBay部',
        3 => 'Wish部',
        4 => '女装事业部',
        5 => 'LED事业部',
        6 => '品牌事业部'
    ];

    public function __construct()
    {
        $this->_validate = new ProductValidate();
        $this->queue = new UniqueQueuer(SyncGoodsImgQueue::class);
    }

    /**
     * @desc 新增产品
     * @param $params
     */
    public function addProduct(array $params)
    {
        foreach ($params as $product) {
            try {
                //验证产品数据
                $errors = $this->checkProductData($product);
                if ($errors === true) {
                    //数据转换
                    $productData = self::dataConversion($product);
                    //保存产品数据
                    $goods_id = $this->saveProduct($productData);
                    $data = [
                        'goods_id' => $goods_id,
                        'spu' => $productData['spu']
                    ];
                    $this->queue->push($data);
                    $goodsHelp = new GoodsHelp();
                    $goodsHelp->putPushList($goods_id);
                    $this->response[] = [
                        'success' => true,
                    ];
                } else {
                    $this->response[] = [
                        'success' => false,
                        'error_msg' => $errors
                    ];
                }
            } catch (Exception $exception) {
                $this->response[] = [
                    'success' => false,
                    'error_msg' => [$exception->getMessage() . $exception->getFile() . $exception->getLine()]
                ];
            }

        }
        return $this->response;
    }


    private function checkAndBuildSkuData($product)
    {
        if (empty($product['spu'])) {
            throw new Exception('SPU不能为空');
        }
        if (empty($product['skus'])) {
            throw new Exception('skus不能为空');
        }
        $result = ['sku_list' => [], 'supplier' => []];
        $Goods = new Goods();
        $aGoods = $Goods->where('spu', $product['spu'])->whereOr('alias', $product['spu'])->find();
        if (!$aGoods) {
            throw new Exception('查无此商品:' . $product['spu']);
        }
        $aGoods = $aGoods->getData();
        $skuList = [];
        $currency = !empty($product['currency']) ? $product['currency'] : 'CNY';
        $OrderRuleExecuteService = new OrderRuleExecuteService();
        foreach ($product['skus'] as $row) {
            $ret = [];
            $ret['id'] = 0;
            $ret['sku'] = self::returnSku($row['sku']);
            $ret['goods_id'] = $aGoods['id'];
            if (!$this->_validate->scene('sku')->check($row)) {
                throw new Exception($row['sku'] . ":" . $this->_validate->getError());
            } else {
                $ret['attributes'] = $this->_validate->temporaryData['sku_attr'];
            }
            $originalPrice = $row['zuixcgdj'];
            if ($currency != 'CNY') {
                $row['zuixcgdj'] = $OrderRuleExecuteService->convertCurrency($currency, 'CNY', $row['zuixcgdj']);
            }
            $ret['currency'] = $currency;
            $ret['cost_price'] = $row['zuixcgdj'];
            $ret['originalPrice'] = $originalPrice;
            $ret['retail_price'] = '0';
            $ret['market_price'] = '0';
            $ret['weight'] = $row['chanpzl'];
            $ret['status'] = 1;
            $ret['length'] = $row['chanpc'];
            $ret['width'] = $row['chanpk'];
            $ret['height'] = $row['chanpg'];
            $skuList[] = $ret;
        }

        $result['sku_list'] = $skuList;
        if (!empty($product['business_license'])) {
            $supplierId = GoodsImport::getSupplierId(trim($product['business_license']));
            if ($supplierId) {
                $result['supplier']['supplier_id'] = $supplierId;
                $result['supplier']['link'] = $product['purchasing_link'] ?? '';
            }
        }
        $result['goods_info'] = $aGoods;
        return $result;
    }

    public function addSku($product)
    {
        try {
            $product = $this->checkAndBuildSkuData($product);
            $goods_info = $product['goods_info'];
            $goods_id = $goods_info['id'];
            Db::startTrans();
            try {
                $GoodsSkuService = new GoodsSkuService();
                $GoodsSkuService->saveSkuInfo($goods_id, $product['sku_list'], 3066, function ($skuInfo) use ($product) {
                    if ($product['supplier']) {
                        $supplier = $product['supplier'];
                        $offerData = [
                            'supplier_id' => $supplier['supplier_id'],
                            'sku_id' => $skuInfo['id'],
                            'sku' => $skuInfo['sku'],
                            'cycle' => [],
                            'section' => [],
                            'link' => $supplier['link'],
                            'price' => $skuInfo['originalPrice'],
                            'linkSny' => false,
                            'isChange' => true,
                            'linkVerify' => true,
                            'priceSny' => false,
                            'minQtySny' => false,
                            'currencySny' => true,
                            'sectionSny' => false,
                            'cycleSny' => false,
                            'sku_alias' => [],
                            'min_qty' => 1,
                            'is_default' => false,
                            'is_history' => 0,
                            'currency_code' => $skuInfo['currency'],
                            'isEmpty' => 1
                        ];
                        $GoodsImportService = new GoodsImport();
                        $pushToSupplierGoodsOffer[] = $offerData;
                        $GoodsImportService->pushToSupplierGoodsOffer($supplier['supplier_id'], $pushToSupplierGoodsOffer);
                    }
                });
                Db::commit();
                $this->response = [
                    'success' => true,
                ];
                return $this->response;
            } catch (Exception $ex) {
                Db::rollback();
                throw $ex;
            }

            //验证产品数据
        } catch (Exception $exception) {

            throw $exception;
        }

    }

    private function getLangMap()
    {
        $langArr = Cache::store('lang')->getLang();
        $lang = [];
        foreach ($langArr as $v) {
            $lang[$v['code']] = $v;
        }
        return $lang;
    }

    private function checkDescriptionData($product)
    {
        if (empty($product['spu'])) {
            throw new Exception('spu不能为空');
        }
        $result = [];
        $Goods = new Goods();
        $aGoods = $Goods->where('spu', $product['spu'])->find();
        if (!$aGoods) {
            throw new Exception('该spu信息不存在');
        }
        $result['goods_info'] = $aGoods;
        $lang = $this->getLangMap();
        foreach ($product as $key => $v) {
            if (!$v) {
                continue;
            }
            if (isset($lang[$key])) {
                $row = [];
                if (isset($v['title'])) {
                    $row['title'] = strip_tags($v['title']);
                    $row['title'] = html_entity_decode($row['title']);
                }
                if (isset($v['description'])) {
                    $row['description'] = strip_tags($v['description']);
                    $row['description'] = html_entity_decode($row['description']);
                }
                $row['lang_id'] = $lang[$key]['id'];
                $selling_point = [];
                $is_update_point = 0;
                for ($i = 1; $i <= 5; $i++) {
                    $_k = 'amazon_point_' . $i;
                    if (isset($v[$_k])) {
                        $v[$_k] = strip_tags($v[$_k]);
                        $v[$_k] = addslashes($v[$_k]);
                        $v[$_k] = html_entity_decode($v[$_k]);
                        $selling_point[$_k] = $v[$_k] ? $v[$_k] : '';
                        if (!$is_update_point) {
                            $is_update_point = 1;
                        }
                    }
                }
                if ($is_update_point) {
                    $row['selling_point'] = json_encode($selling_point, JSON_FORCE_OBJECT);
                }
                $row['tags'] = $v['tags'] ?? '';
                $result['lang'][] = $row;

            }
        }
        if (empty($result['lang'])) {
            throw new Exception('提交的信息为空');
        }
        return $result;
    }

    public function saveDescription($product)
    {
        try {
            $info = $this->checkDescriptionData($product);
            $goods_info = $info['goods_info'];
            $goods_id = $goods_info['id'];
            $goodsHelp = new GoodsHelp();
            // halt($info);
            $goodsHelp->modifyProductDescription($goods_id, $info['lang'], 3066);
            $this->response = [
                'success' => true,
            ];
            return $this->response;
            //验证产品数据
        } catch (Exception $exception) {
            throw $exception;
        }
    }

    public function updateProductInfo(array $product)
    {
        try {
            $errors = $this->checkProductDataForUpdate($product);
            if ($errors === true) {
                $productData = self::dataConversionUpdate($product);
                $this->updateSaveProduct($productData);
                $this->response['success'] = true;
            } else {
                $this->response = [
                    'success' => false,
                    'error_msg' => $errors
                ];
            }
            //验证产品数据
        } catch (Exception $exception) {
            $this->response = [
                'success' => false,
                'error_msg' => $exception->getMessage()
            ];
        }
        return $this->response;
    }

    /**
     * 更新产品
     * @param array $params
     */
    public function updateProduct(array $params)
    {
        $GoodsSkuService = new GoodsSkuService();
        $GoodsImportService = new GoodsImport();
        foreach ($params as $product) {
            try {
                //验证产品数据
                if (empty($product['spu'])) {
                    $this->errors[] = '产品spu不能为空';
                }
                if (empty($product['skus'])) {
                    $this->errors[] = '产品sku不能为空';
                }
                if (empty($product['supplier_name'])) {
                    $this->errors[] = '产品供应商不能为空';
                }
                $supplier = '';
                if (empty($this->errors)) {
                    $goods = Goods::where(['spu' => $product['spu']])->find();
                    if (empty($goods)) {
                        $this->errors[] = "产品{$product['spu']}不存在";
                    }
                    $supplier = GoodsImport::getsupplierId($product['supplier_name']);
                    if (empty($supplier)) {
                        $this->errors[] = "供应商{$product['supplier_name']}不存在";
                    }
                }
                if (empty($this->errors)) {
                    foreach ($product['skus'] as $sku) {
                        if (empty($sku['sku_code'])) {
                            $this->errors[] = "产品{$product['spu']}中sku不能为空";
                        }
                        if (empty($sku['price'])) {
                            $this->errors[] = "产品{$product['spu']}中sku价格不能为空";
                        }
                        $goodsSku = GoodsSku::where(['goods_id' => $goods['id'], 'sku' => $sku['sku_code']])->find();
                        if (empty($goodsSku)) {
                            $this->errors[] = "系统中{{$product['spu']}}不存在sku：{$sku['sku_code']}";
                        }
                    }
                }
                $currency = $product['currency'] ?? 'CNY';
                if (empty($this->errors)) {
                    $GoodsLog = new GoodsLog();
                    $developer = GoodsImport::getUserId($product['developer']);
                    if (!empty($developer)) {
                        $goodsData['developer_id'] = $developer;
                    }
                    Db::startTrans();
                    try {
                        if ($supplier != $goods['supplier_id']) {
                            $GoodsLog->mdfSpu($goods['spu'], ['supplier_id' => $goods['supplier_id']], ['supplier_id' => $supplier]);
                            SupplierStatisticReport::statisticSpuQty($goods['supplier_id'], -1);
                            SupplierStatisticReport::statisticSpuQty($supplier, 1);
                            $GoodsSkuService->afterUpdateDefSupplier($supplier, $goods['id'], $GoodsLog);
                        }
                        //更新goods信息
                        $goods->supplier_id = $supplier;
                        $goods->save();
                        $pushSupplierOffer = [];
                        //更新goodssku信息和报价信息
                        foreach ($product['skus'] as $sku) {
                            $originalPrice = $sku['price'];
                            if ($currency != 'CNY') {
                                $OrderRuleExecuteService = new OrderRuleExecuteService();
                                $sku['price'] = $OrderRuleExecuteService->convertCurrency($currency, 'CNY', $sku['price']);
                            }
                            //更新sku信息
                            $skuData = ['cost_price' => $sku['price']];
                            $goodsSku = GoodsSku::where(['goods_id' => $goods['id'], 'sku' => $sku['sku_code']])->find();
//                            $GoodsSkuService->afterUpdateCostPrice($goodsSku->id, $goodsSku->cost_price, $sku['price']);
                            $GoodsLog->mdfSku($goodsSku->sku, ['cost_price' => $goodsSku->cost_price], ['cost_price' => $sku['price']]);
                            $goodsSku->save($skuData);
                            Cache::store('goods')->delSkuInfo($goodsSku->id);
                            $GoodsSkuService->afterUpdate($goodsSku, $skuData);
                            $offerData = [
                                'supplier_id' => $supplier,
                                'sku_id' => $goodsSku->id,
                                'sku' => $goodsSku->sku,
                                'cycle' => [],
                                'section' => [],
                                'link' => '',
                                'price' => $originalPrice,
                                'linkSny' => false,
                                'isChange' => true,
                                'linkVerify' => true,
                                'priceSny' => false,
                                'minQtySny' => false,
                                'currencySny' => true,
                                'sectionSny' => false,
                                'cycleSny' => false,
                                'sku_alias' => [],
                                'min_qty' => 1,
                                'is_default' => false,
                                'is_history' => 0,
                                'currency_code' => $currency,
                                'isEmpty' => 1
                            ];
                            if (!empty($product['purchasing_links'])) {
                                $offerData['link'] = $product['purchasing_links'];
                            }
                            $pushSupplierOffer[] = $offerData;
                        }
                        if ($pushSupplierOffer) {
                            $GoodsImportService->pushToSupplierGoodsOffer($supplier, $pushSupplierOffer);
                        }
                        $GoodsLog->save(3066, $goods['id']);
                        Db::commit();
                        Cache::store('goods')->delGoodsInfo($goods['id']);

                    } catch (Exception $exception) {
                        Db::rollback();
                        $this->response[] = [
                            'success' => false,
                            'error_msg' => $exception->getMessage()
                        ];
                    }
                    $this->response[] = [
                        'success' => true,
                    ];
                } else {
                    $this->response[] = [
                        'success' => false,
                        'error_msg' => $this->errors
                    ];
                }

            } catch (Exception $exception) {
                $this->response[] = [
                    'success' => false,
                    'error_msg' => [$exception->getMessage() . $exception->getFile() . $exception->getLine()]
                ];
            }
        }
        return $this->response;
    }

    /**
     * 保存产品数据
     * @param array $product
     * @return bool
     * @throws Exception
     */
    private function saveProduct(array $product)
    {
        Db::startTrans();
        try {
            $goods = new Goods();
            $GoodsLog = new GoodsLog();
            $aCheckGoods = $goods->where('spu', $product['spu'])->find();
            if ($aCheckGoods) {
                return $aCheckGoods->id;
            }
            $product['create_time'] = time();
            $goods->allowField(true)->isUpdate(false)->save($product);
            if (!empty($product['supplier_id'])) {
                SupplierStatisticReport::statisticSpuQty($product['supplier_id'], 1);
            }
            $GoodsLog->addSpu($product['spu']);
            $goods_id = $goods->id;
            $description = [];
            // 添加描述
            $langMap = $this->getLangMap();
            if (!empty($product['lang'])) {
                foreach ($product['lang'] as $lang => $langInfo) {
                    $langRow = [];
                    $langRow['goods_id'] = $goods_id;
                    $langRow['lang_id'] = $langMap[$lang]['id'];
                    $langRow['title'] = strip_tags($langInfo['title']);
                    $langRow['title'] = html_entity_decode($langRow['title']);
                    $langRow['description'] = strip_tags($langInfo['description']);
                    $langRow['description'] = html_entity_decode($langRow['description']);
                    $selling_point = [];
                    if (isset($langInfo['amazon_point_1'])) {
                        $selling_point['amazon_point_1'] = strip_tags($langInfo['amazon_point_1']);
                        $selling_point['amazon_point_1'] = html_entity_decode($selling_point['amazon_point_1']);
                    }
                    if (isset($langInfo['amazon_point_2'])) {
                        $selling_point['amazon_point_2'] = strip_tags($langInfo['amazon_point_2']);
                        $selling_point['amazon_point_2'] = html_entity_decode($selling_point['amazon_point_2']);
                    }
                    if (isset($langInfo['amazon_point_3'])) {
                        $selling_point['amazon_point_3'] = strip_tags($langInfo['amazon_point_3']);
                        $selling_point['amazon_point_3'] = html_entity_decode($selling_point['amazon_point_3']);
                    }
                    if (isset($langInfo['amazon_point_4'])) {
                        $selling_point['amazon_point_4'] = strip_tags($langInfo['amazon_point_4']);
                        $selling_point['amazon_point_4'] = html_entity_decode($selling_point['amazon_point_4']);
                    }
                    if (isset($langInfo['amazon_point_5'])) {
                        $selling_point['amazon_point_5'] = strip_tags($langInfo['amazon_point_5']);
                        $selling_point['amazon_point_5'] = html_entity_decode($selling_point['amazon_point_5']);
                    }
                    $langRow['selling_point'] = json_encode($selling_point, JSON_FORCE_OBJECT);
                    $langRow['tags'] = $langInfo['tags'] ?? '';
                    $description[] = $langRow;
                }
            }
            $goodsLang = new GoodsLang();
            $goodsLang->allowField(true)->saveAll($description);
            // 添加sourceurl
            if (!empty($product['source_url'])) {
                $goodsSourceUrl = new GoodsSourceUrl();
                $goodsSourceUrl->allowField(true)->save(['goods_id' => $goods_id, 'source_url' => $product['source_url'], 'create_time' => time(), 'create_id' => 0]);
            }
            foreach ($product['sku'] as &$skuInfo) {
                $attributes = [];
                if (!empty($skuInfo['sku_attributes']) && is_array($skuInfo['sku_attributes'])) {
                    foreach ($skuInfo['sku_attributes'] as &$attribute) {
                        $attribute['category_id'] = $product['category_id'];
                        $attribute['goods_id'] = $goods_id;
                        if ($attribute['value_id'] == 0) {
                            $value_id = self::addSelfAttribute($attribute);
                            $attributes['attr_' . $attribute['attribute_id']] = $value_id;
                        } else {
                            //添加产品属性
                            GoodsImport::addAttribute($attribute);
                            $attributes['attr_' . $attribute['attribute_id']] = $attribute['value_id'];
                        }
                    }
                }
                $skuInfo['goods_id'] = $goods_id;
                $skuInfo['sku_attributes'] = json_encode($attributes);
            }
            $GoodsSkuService = new GoodsSkuService();
            $pushToSupplierGoodsOffer = [];
            foreach ($product['sku'] as $data) {
                $goodsSku = new GoodsSku();
                $flag = $GoodsSkuService->checkSkuCode($product['spu'], $data['sku']);
                if ($flag === false) {
                    throw new Exception("sku[{$data['sku']}]编码不对！");
                }
                $goodsSku->allowField(true)->isUpdate(false)->save($data);
                $goodsSkuAlias = new ServiceGoodsSkuAlias();
                $goodsSkuAlias->insert($goodsSku->id, $data['sku'], $data['sku'], 1);
                $GoodsLog->addSku($data['sku']);
                //保存采购链接等相关数据到供应商报价表
                if ($product['supplier_id'] && $data['cost_price']) {
                    $offerData = [
                        'supplier_id' => $product['supplier_id'],
                        'sku_id' => $goodsSku->id,
                        'sku' => $goodsSku->sku,
                        'cycle' => [],
                        'section' => [],
                        'link' => $product['purchase_link'],
                        'price' => $data['original_price'],
                        'linkSny' => false,
                        'isChange' => true,
                        'linkVerify' => true,
                        'priceSny' => false,
                        'minQtySny' => false,
                        'currencySny' => true,
                        'sectionSny' => false,
                        'cycleSny' => false,
                        'sku_alias' => [],
                        'min_qty' => 1,
                        'is_default' => false,
                        'is_history' => 0,
                        'currency_code' => $data['currency_code'],
                        'isEmpty' => 1
                    ];
                    $pushToSupplierGoodsOffer[] = $offerData;
                }
            }
            if ($pushToSupplierGoodsOffer) {
                $GoodsImportService = new GoodsImport();
                $GoodsImportService->pushToSupplierGoodsOffer($product['supplier_id'], $pushToSupplierGoodsOffer);
            }
            $GoodsLog->save(3066, $goods_id);
            Db::commit();
            Cache::handler()->del('cache:categoryAttribute');
            return $goods_id;
        } catch (Exception $ex) {
            Db::rollback();
            throw new Exception('产品：' . $product['spu'] . ' ' . $ex->getMessage() . $ex->getFile() . $ex->getLine());
        }
    }

    /**
     * @title 转channel_id
     * @param $platform
     * @return mixed
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    private static function platform2ChannelId($platform)
    {
        $platform = strtolower($platform);
        $channel = cache::store('channel')->getChannel();
        foreach ($channel as $channel_name => $value) {
            $channel_name = strtolower($channel_name);
            if ($channel_name == $platform) {
                return $value['id'];
            }
        }
        return 0;
    }

    /**
     * @title 找出供应商id
     * @param $name
     * @return int
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    public static function supplierToId($name)
    {
        $id = GoodsImport::getsupplierId($name);
        if(!$id){
            throw new Exception("供应商【{$name}】不存在");
        }
        return $id;
    }

    /**
     * 将接收数据转换成系统保存数据字段
     * @param array $product
     * @return array
     */
    private static function dataConversion(array $product)
    {

        $map = [0 => 1, 1 => 1, 2 => 0];
        $platform_sale = [];
        $product['ebay'] != '' && $platform_sale['ebay'] = $map[$product['ebay']];
        $product['amazon'] != '' && $platform_sale['amazon'] = $map[$product['amazon']];
        $product['wish'] != '' && $platform_sale['wish'] = $map[$product['wish']];
        $product['aliexpress'] != '' && $platform_sale['aliExpress'] = $map[$product['aliexpress']];
        $product['joom'] != '' && $platform_sale['joom'] = $map[$product['joom']];
        $product['mymall'] != '' && $platform_sale['pandao'] = $map[$product['mymall']];
        $product['shopee'] != '' && $platform_sale['shopee'] = $map[$product['shopee']];
        $product['paytm'] != '' && $platform_sale['paytm'] = $map[$product['paytm']];
        $product['lazada'] != '' && $platform_sale['lazada'] = $map[$product['lazada']];

        if (!$platform_sale) {
            throw new Exception('平台上架状态是必须的');
        }
        $product['skus'] = array_values($product['skus']);
        $firstSku = empty($product['skus']) ? [] : $product['skus'][0];
        $currency = $product['currency'] ? $product['currency'] : 'CNY';
        $GoodsImport = new GoodsImport();
        $platform = $GoodsImport->getPlatForm($platform_sale);
        //goods表数据
        $productData = [
            'spu' => $product['spu'],
            'category_id' => $product['category'],
            'declare_name' => $product['zhongwbgm'],
            'declare_en_name' => $product['yingwbgm'],
            'keywords' => $product['guanjc'],
            'thumb' => $product['chanpzt'],
            'status' => 1,
            'sales_status' => GoodsImport::getSalesStatus(isset($firstSku['xiaoszt']) ? $firstSku['xiaoszt'] : ''),
            'developer_id' => GoodsImport::getUserIdByJobId($product['kfy_code']),
            'stop_selling_time' => '',
            'weight' => !empty($firstSku) ? $firstSku['chanpzl'] : '',
            'width' => !empty($firstSku) ? $firstSku['chanpk'] * 10 : '',
            'height' => !empty($firstSku) ? $firstSku['chanpg'] * 10 : '',
            'depth' => !empty($firstSku) ? $firstSku['chanpc'] * 10 : '',
            'volume_weight' => '',
            'source_url' => $product['pingtlj'],
            'supplier_id' => self::supplierToId($product['gongysmc']),
            'cost_price' => isset($firstSku['zuixcgdj']) ? $firstSku['zuixcgdj'] : '',
            'retail_price' => '',
            'hs_code' => $product['haigbm'],
            'brand_id' => self::getBrandId($product['pinp']),
            'warehouse_id' => isset($product['cangk']) ? GoodsImport::getWarehouseId($product['cangk']) : '',
            'platform' => $platform,
            'platform_sale' => json_encode($platform_sale),
            'transport_property' => GoodsImport::getTransportProperty(implode(',', $product['wulsx'])),
            'net_weight' => isset($firstSku['chanpjz']) ? $firstSku['chanpjz'] : '',
            'purchaser_id' => GoodsImport::getUserIdByJobId($product['cgy_code']),
            'is_impower' => '',
            'packing_back_id' => isset($firstSku['baozcl']) ? self::getPackingId($firstSku['baozcl']) : 0,
            'from' => 4,
            'dev_platform_id' => $product['kaifbm'] === 0 ? 7 : $product['kaifbm'],//开发部门
            'sku' => $product['skus'],
            'publish_time' => strtotime($product['shangjrq']),
            'purchase_link' => isset($product['caiglj']) ? $product['caiglj'] : '',
            'channel_id' => self::platform2ChannelId($product['platform']),
            'pre_sale' => $product['pre_sale'] ?? 0
        ];
        if (empty($productData['purchaser_id'])) {
            if ($productData['supplier_id']) {
                $productData['purchaser_id'] = SupplierService::getInfoById($productData['supplier_id'], 'purchaser_id');
            }
            if (!$productData['purchaser_id']) {
                $CategoryHelp = new CategoryHelp();
                $productData['purchaser_id'] = $CategoryHelp->getPurchaserIdByCategoryId($productData['category_id']);
            }
        }
        if (!empty($product['lang'])) {
            if (isset($product['lang']['cn'])) {
                $productData['description'] = $product['lang']['cn']['description'];
                $productData['name'] = $product['lang']['cn']['title'];
            }
            if (isset($product['lang']['en'])) {
                $productData['description_en'] = $product['lang']['en']['description'];
                $productData['name_en'] = $product['lang']['en']['title'];
            }
            $productData['lang'] = $product['lang'];
        }
        //goods_sku表数据
        $goods_sku = [];
        if (!empty($product['skus'])) {
            $OrderRuleExecuteService = new OrderRuleExecuteService();
            foreach ($product['skus'] as $sku) {
                $originalPrice = $sku['zuixcgdj'];
                if ($currency != 'CNY') {
                    $sku['zuixcgdj'] = $OrderRuleExecuteService->convertCurrency($currency, 'CNY', $sku['zuixcgdj']);
                }
                $skuCode = self::returnSku($sku['sku']);
                $goods_sku[] = [
                    'sku' => $skuCode,
                    'thumb' => $sku['skutp'],
                    'sku_attributes' => $sku['attr'],
                    'spu_name' => $productData['name'],
                    'cost_price' => $sku['zuixcgdj'],
                    'original_price' => $originalPrice,
                    'currency_code' => $currency,
                    'retail_price' => '',
                    'market_price' => '',
                    'weight' => $sku['chanpzl'],
                    'old_weight' => $sku['chanpzl'],
                    'status' => GoodsImport::getSalesStatus($sku['xiaoszt']),
                    'length' => $sku['chanpc'] * 10,
                    'width' => $sku['chanpk'] * 10,
                    'height' => $sku['chanpg'] * 10
                ];
            }
        }
        $productData['sku'] = $goods_sku;
        return $productData;
    }

    private static function returnSku($sku)
    {
        $f = preg_match('/^[A-Za-z0-9]+$/', $sku);
        if (!$f) {
            throw new Exception('sku[' . $sku . ']格式不正确(只能包含字符和数字)');
        }
        $len = strlen($sku);
        if ($len > 9) {
            $condition = substr($sku, 7);
            $diff = $condition - 100;
            if ($diff < 0) {
                throw new Exception('sku[' . $sku . ']未按照编码规则');
            }
            $map = range('A', 'Z');
            $mod = $diff % 10;
            $modKey = floor($diff / 10);
            if (!isset($map[$modKey])) {
                throw new Exception("sku[{$sku}]超出编码范围");
            }
            return substr($sku, 0, 7) . $map[$modKey] . $mod;
        }
        return $sku;
    }

    private static function dataConversionUpdate(array $product)
    {
        $platform_sale = [];
        $map = [0 => 1, 1 => 1, 2 => 0];
        isset($product['ebay']) && $platform_sale['ebay'] = $map[$product['ebay']];
        isset($product['amazon']) && $platform_sale['amazon'] = $map[$product['amazon']];
        isset($product['wish']) && $platform_sale['wish'] = $map[$product['wish']];
        isset($product['aliexpress']) && $platform_sale['aliExpress'] = $map[$product['aliexpress']];
        isset($product['joom']) && $platform_sale['joom'] = $map[$product['joom']];
        isset($product['mymall']) && $platform_sale['pandao'] = $map[$product['mymall']];
        isset($product['shopee']) && $platform_sale['shopee'] = $map[$product['shopee']];
        isset($product['paytm']) && $platform_sale['paytm'] = $map[$product['paytm']];
        isset($product['lazada']) && $platform_sale['lazada'] = $map[$product['lazada']];
        //goods表数据
        $productData = [];
        if ($platform_sale) {
            $aGoods = Goods::where(['spu' => $product['spu']])->find();
            if (!$aGoods) {
                throw new Exception('该spu不存在');
            }
            $GoodsImport = new GoodsImport();
            $goodHelp = new GoodsHelp();
            $platform_old = $goodHelp->getPlatformSaleJson($aGoods->platform);
            $platform_sale = array_merge($platform_old, $platform_sale);
            $platform = $GoodsImport->getPlatForm($platform_sale);
            $productData['platform'] = $platform;
        }
        $aDescription = [];
        if (isset($product['zhongwms'])) {
            $product['zhongwms'] = strip_tags($product['zhongwms']);
            $product['zhongwms'] = html_entity_decode($product['zhongwms']);
            $aDescription[1]['description'] = $product['zhongwms'];
        }
        isset($product['chanpzwmc']) && $aDescription[1]['title'] = $product['chanpzwmc'];
        isset($product['chanpywbt']) && $aDescription[2]['title'] = $product['chanpywbt'];
        if (isset($product['yingwms'])) {
            $product['yingwms'] = strip_tags($product['yingwms']);
            $product['yingwms'] = html_entity_decode($product['yingwms']);
            $aDescription[2]['description'] = $product['yingwms'];
            $aDescription[2][''] = $product['yingwms'];
        }
        if ($aDescription) {
            $productData['aDescriptions'] = $aDescription;
        }
        isset($product['spu']) && $product['spu'] && $productData['spu'] = $product['spu'];
        isset($product['pre_sale']) && $productData['pre_sale'] = $product['pre_sale'];
        isset($product['gongysmc']) && $product['gongysmc'] && $productData['supplier_id'] = GoodsImport::getsupplierId($product['gongysmc']);
        isset($product['zhongwbgm']) && $product['zhongwbgm'] && $productData['declare_name'] = $product['zhongwbgm'];
        isset($product['yingwbgm']) && $product['yingwbgm'] && $productData['declare_en_name'] = $product['yingwbgm'];
        isset($product['caiglj']) && $product['caiglj'] && $productData['purchase_link'] = $product['caiglj'];
        isset($product['guanjc']) && $product['guanjc'] && $productData['keywords'] = $product['guanjc'];
        isset($product['zhongwms']) && $product['zhongwms'] && $productData['description'] = $productData['description_cn'] = $product['zhongwms'];
        isset($product['yingwms']) && $product['yingwms'] && $productData['description_en'] = $product['yingwms'];
        isset($product['kaifry']) && $product['kaifry'] && $productData['developer_id'] = GoodsImport::getUserIdByJobId($product['kfy_code']);
        isset($product['chanpzwmc']) && $product['chanpzwmc'] && $productData['name'] = $product['chanpzwmc'];
        isset($product['chanpywbt']) && $product['chanpywbt'] && $productData['name_en'] = $product['chanpywbt'];
        isset($product['wulsx']) && $product['wulsx'] && $productData['transport_property'] = self::ConversionTransport($product['wulsx']);
        //goods_sku表数据
        $goods_sku = [];
        if (!empty($product['skus'])) {
            foreach ($product['skus'] as $sku) {
                $row = [];
                $row['sku'] = $sku['sku'];
                isset($sku['skutp']) && $sku['skutp'] && $row['thumb'] = $sku['skutp'];
                isset($sku['chanpzl']) && $sku['chanpzl'] && $row['weight'] = $sku['chanpzl'];//重量
                isset($sku['chanpc']) && $sku['chanpc'] && $row['length'] = $sku['chanpc'] * 10;//长
                isset($sku['chanpk']) && $sku['chanpk'] && $row['width'] = $sku['chanpk'] * 10;//宽
                isset($sku['chanpg']) && $sku['chanpg'] && $row['height'] = $sku['chanpg'] * 10;//高
                isset($sku['attr']) && $sku['attr'] && $row['attributes'] = $sku['attr'];//属性
                $goods_sku[] = $row;
            }
        }
        $productData['sku'] = $goods_sku;
        return $productData;
    }

    public static function ConversionTransport($transport)
    {
        $result = false;
        $GoodHelp = new GoodsHelp();
        $aTransports = $GoodHelp->getTransportProperies();
        $aMaps = [];
        if ($aTransports) {
            foreach ($aTransports as $v) {
                $aMaps[$v['name']] = $v;
            }
            $aTransport = [];
            foreach ($transport as $v) {
                $row = [];
                $row['value'] = $aMaps[$v]['value'];
                $row['field'] = $aMaps[$v]['field'];
                $aTransport[] = $row;
            }
            $result = $GoodHelp->formatTransportProperty($aTransport);
            $GoodHelp->checkTransportProperty($result);
        }
        return $result;

    }

    /**
     * checkParams
     * @param $product
     * @return bool
     * @throws Exception
     * @internal param $params
     */
    private function checkProductData(&$product)
    {
        //验证产品信息
        if ($this->_validate->scene('spu')->check($product) === false) {
            return $this->_validate->getError();
        }

        $product['category'] = $this->_validate->temporaryData['category_id'];
        //验证产品sku信息
        foreach ($product['skus'] as &$sku) {
            if (!$this->_validate->scene('sku')->check($sku)) {
                $this->errors[] = $sku['sku'] . ":" . $this->_validate->getError();
            } else {
                $sku['attr'] = $this->_validate->temporaryData['sku_attr'];
            }
        }
        if (!$errors = $this->getErrors()) {
            return true;
        }
        return $errors;
    }

    /**
     * @param $product
     * @return bool
     * @autor starzhan <397041849@qq.com>
     */
    private function checkProductDataForUpdate(&$product)
    {
        //验证产品sku信息
        foreach ($product['skus'] as &$sku) {
            if (!$this->_validate->scene('update')->check($sku)) {
                $this->errors[] = $this->_validate->getError();
            } else {
                if (isset($sku['shuxlx'])) {
                    $sku['attr'] = $this->_validate->temporaryData['sku_attr'];
                }
            }
        }
        if (!$errors = $this->getErrors()) {
            return true;
        }
        return $errors;
    }

    /**
     * 获取品牌
     * @param $name
     * @return int|mixed
     */
    private static function getBrandId($name)
    {
        $brandInfo = Brand::where(['name' => $name])->field('id')->find();
        if (!$brandInfo) {
            $Brand = new Brand();
            $Brand->allowField(true)->isUpdate(false)->save([
                'name' => $name,
                'description' => '备注：oa接口添加',
                'create_time' => time(),
                'update_time' => time()
            ]);
            Cache::handler()->del('cache:brand');
            return $Brand->id;
        }
        return $brandInfo['id'];
    }

    /**
     * 获取包装材料
     * @param $name
     * @return int|mixed
     */
    private static function getPackingId($name)
    {
        $brandInfo = Packing::where(['title' => $name])->field('id')->find();
        return $brandInfo ? $brandInfo['id'] : 0;
    }

    /**
     * 产品自定义属性值
     * @param array $info
     * @return int
     */
    private static function addSelfAttribute(array $info)
    {
        //添加分类属性
        $valueStartIds = [
            11 => 171,
            15 => 298
        ];
        $categoryAttribute = new CategoryAttribute();
        $categoryAttr = $categoryAttribute->where('category_id', $info['category_id'])->where('attribute_id', $info['attribute_id'])->find();
        if (!$categoryAttr) {
            $categoryAttrArr = [
                'category_id' => $info['category_id'],
                'attribute_id' => $info['attribute_id'],
                'group_id' => 1,
                'sku' => 1,
                'value_range' => '[]'
            ];
            $attributeGroup = new AttributeGroup();
            $groupInfo = $attributeGroup->where(['category_id' => $info['category_id']])->find();
            if (!$groupInfo) {
                $attributeGroup->allowField(true)->save(['category_id' => $info['category_id'], 'name' => '分组1']);
                $group_id = $attributeGroup->id;
            } else {
                $group_id = $groupInfo->id;
            }
            $categoryAttrArr['group_id'] = $group_id;
            $categoryAttribute->allowField(true)->save($categoryAttrArr);
        }
        //添加商品属性
        $goodsAttrAttr = [
            'attribute_id' => $info['attribute_id'],
            'goods_id' => $info['goods_id'],
            'alias' => $info['value']
        ];
        $goodsAttribute = new GoodsAttribute();
        $goodsAttributeInfo = $goodsAttribute->where($goodsAttrAttr)->find();
        if ($goodsAttributeInfo) {
            $value_id = $goodsAttributeInfo->value_id;
        } else {
            $lastAttriubteInfo = $goodsAttribute->where(['attribute_id' => $info['attribute_id'], 'goods_id' => $info['goods_id']])->order('value_id desc')->find();
            if ($lastAttriubteInfo) {
                $value_id = $lastAttriubteInfo->value_id + 1;
            } else {
                $value_id = $valueStartIds[$info['attribute_id']];
            }
            $goodsAttrAttr['value_id'] = $value_id;
            $goodsAttribute->allowField(true)->save($goodsAttrAttr);
        }

        return $value_id;
    }

    /**
     * 验证和生成所需数据
     * @param $params
     * @author starzhan <397041849@qq.com>
     */
    protected function checkAndBuildGoodPlatformSaleData(array $params)
    {
        $aResult = [
            'error' => '',
            'platform_sale' => [],
            'sku' => []
        ];
        if (!$params) {
            $aResult['error'] = '参数为空';
            return $aResult;
        }
        $sSpu = isset($params['spu']) && $params['spu'] ? $params['spu'] : [];
        $aSku = isset($params['skus']) && $params['skus'] ? $params['skus'] : [];
        if ([] === $sSpu && [] === $aSku) {
            $aResult['error'] = 'spu或sku不能同时为空';
            return $aResult;
        }
        $oGoods = new goods();
        $aGoods = $oGoods->where('spu', $sSpu)->find();
        if (!$aGoods) {
            $aResult['error'] = "商品[{$sSpu}]不存在";
            return $aResult;
        }
        $aResult['goods_id'] = $aGoods->id;
        if ($sSpu) {
            $platform_sale = (array)json_decode($aGoods->platform_sale, true);
            isset($params['ebay']) && $params['ebay'] && $platform_sale['ebay'] = $params['ebay'];
            isset($params['amazon']) && $params['amazon'] && $platform_sale['amazon'] = $params['amazon'];
            isset($params['wish']) && $params['wish'] && $platform_sale['wish'] = $params['wish'];
            isset($params['aliexpress']) && $params['aliexpress'] && $platform_sale['aliExpress'] = $params['aliexpress'];
            if (!$platform_sale) {
                $aResult['error'] = "platform_sale为空";
                return $aResult;
            }
            $aResult['platform_sale'] = $platform_sale;
        }
        if ($aSku) {
            $aSkyCodes = [];
            $oGoodsHelp = new GoodsHelp();
            foreach ($aSku as $v) {
                $nSku = $oGoodsHelp->getSkuValueByName($v['sales_status']);
                if (!$nSku) {
                    $aResult['error'] = "找不到该sales_status[{$v['sales_status']}]";
                    return $aResult;
                }
                $aSkyCodes[$v['sku']] = $nSku;
            }
            if ($aSkyCodes) {
                $oGoodsSku = new GoodsSku();
                $aSelectSkuData = $oGoodsSku->whereIn('sku', array_keys($aSkyCodes))->select();
                foreach ($aSelectSkuData as $v) {
                    $aResult['sku'][] = ['id' => $v->id, 'status' => $aSkyCodes[$v->sku]];
                }

            }
        }
        return $aResult;
    }

    /**
     * 保存修改sku、spu平台发布情况
     * @param array $params
     * @author starzhan <397041849@qq.com>
     */
    private function saveGoodPlatformSale(array $params)
    {
        if ($params['platform_sale'] && $params['goods_id']) {
            $goods = new goods();
            $flag = $goods->allowField(['platform_sale'])
                ->isUpdate(true)
                ->save(['platform_sale' => json_encode($params['platform_sale'])], ['id' => $params['goods_id']]);
            Cache::store('goods')->delGoodsInfo($params['goods_id']);
            if (!$flag) {
                return 'platform_sale保存失败!';
            }
        }
        if ($params['sku']) {
            $oGoodsSku = new GoodsSku();
            $oGoodsSku->saveAll($params['sku']);
        }
        return true;

    }

    /**
     *  修改sku上下架情况，修改spu平台发布情况
     * @author starzhan <397041849@qq.com>
     */
    public function updateGoodPlatformSale(array $params)
    {
        try {
            //验证产品数据
            $errors = $this->checkAndBuildGoodPlatformSaleData($params);
            if ($errors['error'] === '') {
                $sResult = $this->saveGoodPlatformSale($errors);
                if ($sResult !== true) {
                    $this->response[] = [
                        'success' => false,
                        'error_msg' => $sResult
                    ];
                } else {
                    $this->response[] = [
                        'success' => true,
                    ];
                }

            } else {
                $this->response[] = [
                    'success' => false,
                    'error_msg' => $errors['error']
                ];
            }
        } catch (Exception $exception) {
            $this->response[] = [
                'success' => false,
                'error_msg' => [$exception->getMessage() . $exception->getFile() . $exception->getLine()]
            ];
        }
        return $this->response;
    }

    /**
     * 获取错误信息
     * @return bool
     */
    private function getErrors()
    {
        $errors = $this->errors;
        $this->errors = [];
        if (empty($errors)) {
            return false;
        }
        return $errors;
    }

    /**
     * 更改产品
     * @param array $row
     * @return boolean
     * @throws Exception
     */
    private function updateSaveProduct(array $row)
    {
        Db::startTrans();
        try {
            $aGoods = Goods::where(['spu' => $row['spu']])->find();
            if (!$aGoods) {
                throw new Exception('产品 ' . $row['spu'] . ' 不存在');
            }
            $goods = new Goods();
            $GoodsLog = new GoodsLog();
            $goods_id = $aGoods->id;
            $aResult['goods_id'] = $goods_id;
            if (isset($row['platform_sale'])) {
                $platform_sale = json_decode($aGoods->platform_sale, true);
                $row['platform_sale'] = array_merge($platform_sale, $row['platform_sale']);
                $row['platform_sale'] = json_encode($row['platform_sale']);
            }
            $goods->allowField(true)->isUpdate(true)->save($row, ['id' => $goods_id]);
            if (!empty($row['supplier_id'])) {
                if ($aGoods['supplier_id'] != $row['supplier_id']) {
                    SupplierStatisticReport::statisticSpuQty($aGoods['supplier_id'], -1);
                    SupplierStatisticReport::statisticSpuQty($row['supplier_id'], 1);
                }
            }
            $GoodsLog->mdfSpu($row['spu'], $aGoods, $row);
            // 添加描述
            if (isset($row['aDescriptions']) && $row['aDescriptions']) {
                foreach ($row['aDescriptions'] as $lang_id => $val) {
                    $goodsLang = new GoodsLang();
                    $oGoodsLang = $goodsLang->where(['goods_id' => $goods_id, 'lang_id' => $lang_id])->find();
                    if ($oGoodsLang) {
                        isset($val['description']) && $val['description'] && $oGoodsLang->description = $val['description'];
                        isset($val['title']) && $val['title'] && $oGoodsLang->title = $val['title'];
                        isset($val['tags']) && $val['tags'] && $goodsLang->tags = $val['tags'];
                        $oGoodsLang->save();
                    }
                }
            }
            $row['sales_status'] = isset($row['sales_status']) && $row['sales_status'] ? $row['sales_status'] : $aGoods->sales_status;
            $row['category_id'] = isset($row['category_id']) && $row['category_id'] ? $row['category_id'] : $aGoods->category_id;
            if (isset($row['sku']) && $row['sku']) {
                $GoodsSkuService = new GoodsSkuService();
                foreach ($row['sku'] as $skuInfo) {
                    $goodsSku = new GoodsSku();
                    $aGoodsSku = $goodsSku->where(['goods_id' => $goods_id, 'sku' => $skuInfo['sku']])->find();
                    if (!$aGoodsSku) {
                        $aAlias = GoodsSkuAlias::where('alias', $skuInfo['sku'])->find();
                        if (!$aAlias) {
                            throw new Exception('sku: ' . $skuInfo['sku'] . ' 不存在,修改失败');
                        }
                        $nSkuId = $aAlias->sku_id;

                    } else {
                        $nSkuId = $aGoodsSku->id;
                    }
                    if (!$nSkuId) {
                        throw new Exception('sku: ' . $skuInfo['sku'] . ' 不存在,修改失败');
                    }
                    if (isset($skuInfo['status'])) {
                        $skuInfo['status'] = $skuInfo['status'] == 0 ? $row['sales_status'] : $skuInfo['status'];
                    }
                    if (isset($skuInfo['attributes'])) {
                        $attributes = [];
                        foreach ($skuInfo['attributes'] as &$attribute) {
                            $attribute['category_id'] = $row['category_id'];
                            $attribute['goods_id'] = $goods_id;
                            if ($attribute['value_id'] == 0) {
                                $value_id = GoodsImport::addSelfAttribute($attribute);
                                $attributes['attr_' . $attribute['attribute_id']] = $value_id;
                            } else {
                                GoodsImport::addAttribute($attribute);
                                $attributes['attr_' . $attribute['attribute_id']] = $attribute['value_id'];
                            }
                        }
                        if ($attributes) {
                            $skuInfo['sku_attributes'] = json_encode($attributes);
                            if ($GoodsSkuService->isSameSkuAttributes($goods_id, $skuInfo['sku_attributes'])) {
                                throw new Exception($skuInfo['sku'] . '已存在这个属性,请修改为其他属性');
                                continue;
                            }
                        }
                    }
                    if (isset($skuInfo['weight'])) {
                        if ($aGoodsSku['weight'] != $skuInfo['weight']) {
                            $skuInfo['old_weight'] = $aGoodsSku['weight'];
                        }
                    }
                    $skuInfo['goods_id'] = $goods_id;
                    $GoodsLog->mdfSku($skuInfo['sku'], $aGoodsSku, $skuInfo);
                    unset($skuInfo['sku']);
                    $goodsSku->allowField(true)->save($skuInfo, ['id' => $nSkuId]);
                    $GoodsSkuService->afterUpdate($aGoodsSku, $skuInfo);
                    Cache::store('goods')->delSkuInfo($nSkuId);
                }
            }
            if (isset($row['name'])) {
                $allsku = GoodsSku::where('goods_id', $goods_id)->select();
                foreach ($allsku as $skuInfo) {
                    $skuInfo->spu_name = $row['name'];
                    $skuInfo->save();
                    Cache::store('goods')->delSkuInfo($skuInfo->id);
                }
            }

            $GoodsLog->save(3066, $goods_id);
            GoodsNotice::sendDown();
            Db::commit();
            Cache::store('goods')->delGoodsInfo($goods_id);
            Cache::handler()->del('cache:categoryAttribute');
            return $aResult;
        } catch (Exception $ex) {
            Db::rollback();
            throw  new Exception($row['spu'] . ' ' . $ex->getMessage());
        }
    }

    public function updateSalesStatus($data)
    {
        try {
            $sku_list = $data['sku_list'] ?? [];
            if (!$sku_list) {
                throw new Exception('sku_list不能都为空');
            }
            Db::startTrans();
            try {
                $goods_id = 0;
                $aMap = ['在售' => 1, '缺货' => 5, '卖完下架' => 4, '停售' => 2];
                $GoodsHelp = new GoodsHelp();
                foreach ($sku_list as $skuInfo) {
                    if (empty($skuInfo['sku'])) {
                        throw  new Exception("sku不能为空");
                    }
                    if (!isset($aMap[$skuInfo['status']])) {
                        throw  new Exception("状态值：‘{$skuInfo['status']}’不存在!");
                    }
                    $status = $aMap[$skuInfo['status']];
                    if (!in_array($status, [1, 2, 3, 4, 5])) {
                        throw  new Exception("sku[{$skuInfo['sku']}]状态的取值范围不对");
                    }
                    $goodsSku = new GoodsSku();
                    $aGoodsSku = $goodsSku->where('sku', $skuInfo['sku'])->find();
                    if (!$aGoodsSku) {
                        throw  new Exception("sku[{$skuInfo['sku']}}]不存在");
                    }
                    $GoodsHelp->changeSkuStatus($aGoodsSku->id, $status, 3066);
                    if ($goods_id == 0) {
                        $goods_id = $aGoodsSku->goods_id;
                    }
                }
                $Goods = new Goods();
                $aGoods = $Goods->where('id', $goods_id)->find();
                if (!$aGoods) {
                    throw  new Exception("商品不存在");
                }

                if (isset($data['channel']) && $data['channel']) {
                    if (!in_array($data['channel'], ['ebay', 'joom', 'wish', 'amazon', 'aliExpress'])) {
                        throw  new Exception("渠道不正确");
                    }
                    $json = json_decode($aGoods->platform_sale, true);
                    $json[$data['channel']] = 1;
                    $aGoods->platform_sale = json_encode($json);
                    $aGoods->save();
                }
                if (isset($data['down_channel']) && $data['down_channel']) {
                    if (!in_array($data['down_channel'], ['ebay', 'joom', 'wish', 'amazon', 'aliExpress'])) {
                        throw  new Exception("渠道不正确");
                    }
                    $json = json_decode($aGoods->platform_sale, true);
                    $json[$data['down_channel']] = 0;
                    $aGoods->platform_sale = json_encode($json);
                    $aGoods->save();
                }
                Db::commit();
                Cache::store('goods')->delGoodsInfo($goods_id);
            } catch (Exception $exception) {
                Db::rollback();
                throw new Exception($exception->getMessage());
            }
            $this->response[] = [
                'success' => true,
            ];

        } catch (Exception $exception) {
            $this->response[] = [
                'success' => false,
                'error_msg' => [$exception->getMessage()]
            ];
        }
        return $this->response;
    }


    public function getGoodsLang($spu, $lang_id = 0)
    {
        $aGoods = Goods::where('spu', $spu)->find();
        if (!$aGoods) {
            throw new Exception('查无此sku');
        }
        $result = [];
        if ($lang_id == 0) {
            $ret = GoodsLang::where('goods_id', $aGoods->id)->select();
        } else {
            $ret = GoodsLang::where('goods_id', $aGoods->id)->where('lang_id', $lang_id)->select();
        }
        $lanArr = [];
        $lanTmp = Cache::store('lang')->getLang();
        foreach ($lanTmp as $l) {
            $lanArr[$l['id']] = $l;
        }
        foreach ($ret as $v) {
            $v['lang'] = $lanArr[$v['lang_id']]['code'];
            $result[] = $v;
        }
        return $result;
    }

    public function getCategory()
    {
        $Category = new Category();
        return $Category->select();
    }

    public function getAttr()
    {
        $result['attribute'] = Attribute::select();
        $result['attribute_value'] = AttributeValue::select();
        return $result;
    }
}