<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2018/8/29
 * Time: 10:50
 */

namespace app\publish\helper\shopee;

use app\common\cache\Cache;
use app\common\model\GoodsSku;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\shopee\ShopeeAttribute;
use app\common\model\shopee\ShopeeCategory;
use app\common\model\shopee\ShopeeLogistic;
use app\common\model\shopee\ShopeeProduct;
use app\common\model\shopee\ShopeeProductInfo;
use app\common\model\shopee\ShopeeVariant;
use app\common\service\ChannelAccountConst;
use app\common\service\CommonQueuer;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsSkuMapService;
use app\goods\service\GoodsPublishMapService;
use app\goods\service\GoodsImage;
use app\publish\queue\ShopeeGetItemDetailQueue;
use service\shopee\ShopeeApi;
use think\Db;
use think\Exception;


class ShopeeHelper
{
    public const PUBLISH_STATUS = [//本地记录的刊登状态
        'fail' => -1,//刊登失败
        'noStatus' => 0,//未刊登
        'inPublishQueue' => 1,//刊登队列中
        'publishing' => 2,//刊登中
        'success' => 3,//刊登成功
        'inUpdateQueue' => 4,//更新队列中
        'updating' => 5,//更新中
        'failUpdate' => 6,//更新失败
        'offLine' => 7,//下架
    ];
    public const ITEM_STATUS = [//线上状态
        'NORMAL' => 1,//正常
        'DELETED' => 2,//已删除
        'BANNED' => 3//禁止
    ];

    public const UPDATE_TYPE = [//在线更新类型
        'updateItem' => 0,
        'addVariations' => 1,
        'addItemImg' => 2,
        'deleteItemImg' => 3,
        'deleteVariation' => 4,
        'insertItemImg' => 5,
        'updatePrice' => 6,
        'updateStock' => 7,
        'updateVariationPrice' => 8,
        'updateVariationStock' => 9
    ];


    /**
     * 根据站点同步分类
     * @param $country
     * @return bool|string
     */
    public function syncCategoriesByCountry($country)
    {
        try {

            //获取认证信息和参数
            $config = $this->getAuthorization(0, $country);
            if (!isset($config['shop_id'])) {
                return $config;
            }
            $siteId = $config['site_id'];
            unset($config['site_id']);
            unset($config['site']);

            $params['country'] = strtoupper($country);
            $params['is_cb'] = 0;
            $country == 'id' && $params['language'] = 'en';


            $response = ShopeeApi::instance($config)->handler('Item')->getCategoryByCountry($params);
            $message = $this->checkResponse($response, 'categories');

            if ($message !== true) {
                return $message;
            }

            //处理数据
            $categories= $response['categories'];
            $newCategories = [];
            $newCategoryIds = [];
            foreach ($categories as $k => $category) {
                $newCategories[$k]['parent_id'] = $category['parent_id'];
                $newCategories[$k]['has_children'] = empty($category['has_children']) ? 0 : 1;
                $newCategories[$k]['category_id'] = $category['category_id'];
                $newCategories[$k]['category_name'] = $category['category_name'];
                $newCategories[$k]['site_id'] = $siteId;
                $newCategoryIds[$k] = intval($category['category_id']);
            }

            //获取旧分类信息
            $oldCategoryIds = ShopeeCategory::where(['site_id'=>$siteId])->column('category_id');
            //更新数据库
            $data = [
                'new_ids' => $newCategoryIds,
                'old_ids' => $oldCategoryIds,
                'new_items' => $newCategories,
                'item' => 'category_id'
            ];
            $where = [
                'del_wh' => ['site_id'=>$siteId],
                'update_wh' => ['site_id'=>$siteId]
            ];
            $res = $this->curdDb($data,ShopeeCategory::class,$where);


            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 同步分类属性
     * @param $country
     * @param $categoryId
     * @return bool|string
     */
    public function syncAttributes($country, $categoryId)
    {
        try {
            //获取认证信息和参数
            $config = $this->getAuthorization(0, $country);

            if (!isset($config['shop_id'])) {
                return $config;
            }
            $siteId = $config['site_id'];
            unset($config['site_id']);
            unset($config['site']);
            $params['category_id'] = intval($categoryId);

            $response = ShopeeApi::instance($config)->handler('Item')->getAttributes($params);

            $message = $this->checkResponse($response, 'attributes');
            if ($message !== true) {
                return $message;
            }
            //处理数据
            $newAttributes = [];
            $newAttributeIds = [];
            $attributes = $response['attributes'];
            foreach ($attributes as $k => $attribute) {
                $attribute['category_id'] = $categoryId;
                $attribute['site_id'] = $siteId;
                $attribute['is_mandatory'] = empty($attribute['is_mandatory']) ? 0 : 1;
                $attribute['options'] = isset($attribute['options']) ? json_encode($attribute['options']) : json_encode([]);
                $newAttributeIds[] = intval($attribute['attribute_id']);
                if(isset($attribute['values'])) {
                    unset($attribute['values']);
                }
                $newAttributes[$k] = $attribute;
            }
            //获取旧属性
            $wh['category_id'] = $categoryId;
            $wh['site_id'] = $siteId;
            $oldAttributeIds = ShopeeAttribute::where($wh)->column('attribute_id');

            //更新数据库
            $data = [
                'new_ids' => $newAttributeIds,
                'old_ids' => $oldAttributeIds,
                'new_items' => $newAttributes,
                'item' => 'attribute_id'
            ];
            $where = [
                'del_wh' => $wh,
                'update_wh' => $wh
            ];

            $res = $this->curdDb($data,ShopeeAttribute::class,$where);

            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 同步物流
     * @param $accountId
     * @param string $country
     * @return bool
     */
    public function syncLogistics($accountId, $country='')
    {
        try {
            $account = ShopeeAccount::get($accountId);
            if (empty($account)) {
                return '获取账号信息失败';
            }

            $config = [
                'shop_id' => $account->shop_id,
                'partner_id' => $account->partner_id,
                'key' => $account->key
            ];
            $response = ShopeeApi::instance($config)->handler('Logistics')->getLogistics();

            $message = $this->checkResponse($response,'logistics');
            if ($message !== true) {
                return $message;
            }
            //处理数据
            $logistics = $response['logistics'];
            $newLogistics = [];
            $newLogisticIds = [];
            foreach ($logistics as $logistic) {
                $logistic['account_id'] = $accountId;
                $logistic['has_cod'] = $logistic['has_cod'] ? 1 : 0;
                $logistic['enabled'] = $logistic['enabled'] ? 1 : 0;
                $logistic['sizes'] = json_encode($logistic['sizes']);
                $logistic['weight_limits'] = json_encode($logistic['weight_limits']);
                $logistic['item_max_dimension'] = json_encode($logistic['item_max_dimension']);

                if(isset($logistic['preferred'])) {
                    unset($logistic['preferred']);
                }
                $newLogistics[] = $logistic;
                $newLogisticIds[] = $logistic['logistic_id'];
            }
            //获取旧物流
            $oldLogisticIds = ShopeeLogistic::where(['account_id'=>$accountId])->column('logistic_id');

            $data = [
                'new_ids' => $newLogisticIds,
                'old_ids' => $oldLogisticIds,
                'new_items' => $newLogistics,
                'item' => 'logistic_id'
            ];

            $where = [
                'del_wh' => ['account_id' => $accountId],
                'update_wh' => ['account_id' => $accountId]
            ];
            $res = $this->curdDb($data, ShopeeLogistic::class, $where);
            return $res;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 拉取item详情
     * @param $itemId
     * @return bool|string
     */
    public function getItemDetail($itemId, $accountId=0)
    {
        try {
            if ($accountId == 0) {
                $product = ShopeeProduct::field('account_id,id')->where(['item_id' => $itemId])->find();
                if (empty($product)) {
                    throw new Exception('拉取item详情时，根据item id获取产品信息失败');
                }
                $accountId = $product['account_id'];
            }
            $field = 'partner_id,shop_id,key';
            $account = ShopeeAccount::field($field)->where(['id'=>$accountId])->find();
            if (empty($account)) {
                throw new Exception('拉取item详情时，获取账号信息失败');
            }
            $config = $account->toArray();
            $response = ShopeeApi::instance($config)->loader('Item')->getItemDetail(['item_id'=>(int)$itemId]);
            //检查结果是否正确
            $message = $this->checkResponse($response, 'item');
            if ($message !== true) {
                throw new Exception($message);
            }

            //更新
            $itemInfo['item'] = $response['item'];
            $itemInfo['item_id'] = $itemId;
            if ($response['item']['status'] == 'NORMAL') {
                ShopeeProduct::update(['publish_status'=>self::PUBLISH_STATUS['success'], 'publish_message'=>''], ['item_id'=>$itemId]);
            }
            $res = $this->updateProductWithItem($itemInfo, $accountId);
            if ($res !== true) {
                throw new Exception($res);
            }
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 更新item
     * @param $data
     * @return string
     */
    public function updateItem($data)
    {
        try {
            $var = $data['var'];
            $account = $this->getAuthorization($var['account_id']);
            if (!is_array($account)) {
                throw new Exception($account);
            }

            $item['item_id'] = $var['item_id'];
            $item['category_id'] = $var['category_id'];
            $item['name'] = $var['name'];
            $item['description'] = $var['description'];
            $item['item_sku'] = $var['item_sku'];
            //变体
            if ($var['variant']) {
                $variations = [];
                foreach ($var['variant'] as $variation) {
                    if (empty($variation['variation_id'])) {
                        continue;
                    }
                    $variations[] = [
                        'variation_id' => $variation['variation_id'],
                        'name' => empty($variation['name']) ? $variation['variation_sku'] : $variation['name'],
                        'variation_sku' => $variation['variation_sku']
                    ];
                }
                $item['variations'] = $variations;
            }
            //属性
            $attributes = json_decode($var['attributes'], true);
            if (!empty($attributes)) {
                $itemAttributes = [];
                foreach ($attributes as $k => $attribute) {
                    if (!isset($attribute['attribute_value'])) {
                        continue;
                    }
                    $itemAttributes[] = [
                        'attributes_id' => $attribute['attribute_id'],
                        'value' => $attribute['attribute_value']
                    ];
                }
                $item['attributes'] = $itemAttributes;
            }
            //备货
            if ($var['days_to_ship']>=7 && $var['days_to_ship']<=30) {
                $item['days_to_ship'] = $var['days_to_ship'];
            }
            //批发
            $wholesales = json_decode($var['wholesales'], true);
            if (!empty($wholesales)) {
                $itemWholesales = [];
                foreach ($wholesales as $k => $wholesale) {
                    $itemWholesales[$k]['min'] = (int)$wholesale['min'];
                    $itemWholesales[$k]['max'] = (int)$wholesale['max'];
                    $itemWholesales[$k]['unit_price'] = (float)$wholesale['unit_price'];
                }
                $item['wholesales'] = $itemWholesales;
            }
            //物流
            $logistics = json_decode($var['logistics'], true);
            $item['logistics'] = $logistics;
            $item['weight'] = (float)$var['weight'];
            !empty($var['package_length']) && $item['package_length'] = (int)$var['package_length'];
            !empty($var['package_width']) && $item['package_width'] = (int)$var['package_width'];
            !empty($var['package_height']) && $item['package_height'] = (int)$var['package_height'];

            unset($account['site']);
            unset($account['site_id']);

            $response = ShopeeApi::instance($account)->handler('Item')->updateItem($item);
            $message = $this->checkResponse($response, 'item');
            if ($message !== true) {
                throw new Exception($message);
            }
            ShopeeProduct::update(['publish_status'=>self::PUBLISH_STATUS['success'],'publish_message'=>''], ['id'=>$data['id']]);//更新状态
            //30s后进行一次同步
            $params = [
                'item_id' => $response['item_id'],
                'account_id' => $var['account_id']
            ];
            (new UniqueQueuer(ShopeeGetItemDetailQueue::class))->push($params, 30);
            $res = $this->updateProductWithItem($response, $var['account_id'], $data['id']);
            if ($res !== true) {
                $message = '刊登成功，但是更新本地信息时出现错误，error:'.$res;
                ShopeeProduct::update(['publish_message'=>$message], ['id'=>$data['id']]);
                throw new Exception($res);
            }
            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 根据线上返回的信息，更新本地信息,可以是本地不存在的
     * @param $itemInfo
     * @param $productId
     * @return bool|string
     */
    public function updateProductWithItem($itemInfo, $accountId, $productId=0)
    {
        $dbFlag = false;
        try {
            $status = [
                'NORMAL' => 1,
                'DELETED' => 2,
                'BANNED' => 3
            ];
            $condition = [
                'NEW' => 0,
                'USED' => 1
            ];
            $product = [];
            $productInfo = [];

            if ($productId == 0) {
                $productId = ShopeeProduct::where(['item_id'=>$itemInfo['item_id']])->value('id', 0);
            }

            $item = $itemInfo['item'];
            $product['item_id'] = intval($itemInfo['item_id']);//item_id必须有
            $product['account_id'] = $accountId;
            isset($item['item_sku']) && $product['item_sku'] = $item['item_sku'];
            isset($item['status']) && $product['status'] = $status[$item['status']];
            isset($item['name']) && $product['name'] = $item['name'];
            isset($item['description']) && $productInfo['description'] = $item['description'];
            isset($item['images']) && $product['images'] = json_encode($item['images']);
            isset($item['currency']) && $product['currency'] = $item['currency'];
            isset($item['has_variation']) && $product['has_variation'] = empty($item['has_variation']) ? 0 : 1;
            isset($item['price']) && $product['price'] = $item['price'];
            isset($item['stock']) && $product['stock'] = $item['stock'];
            isset($item['create_time']) && $product['publish_create_time'] = $item['create_time'];
            isset($item['update_time']) && $product['publish_update_time'] = $item['update_time'];
            isset($item['weight']) && $product['weight'] = $item['weight'];
            isset($item['category_id']) && $product['category_id'] = $item['category_id'];
            isset($item['original_price']) && $product['original_price'] = $item['original_price'];

            if (isset($item['variations'])) {
                $variations = [];
                foreach ($item['variations'] as $k => $variation) {
                    $variation['publish_create_time'] = $variation['create_time'];
                    $variation['publish_update_time'] = $variation['update_time'];
                    if ($productId) {//本地存在，进行更新
                        $variation['update_time'] = time();
                        unset($variation['create_time']);
                    } else {//新增
                        $variation['create_time'] = time();
                        unset($variation['update_time']);
                        $variation['publish_status'] = 1;
                        $variation['status'] = 1;
                    }
                    $variation['item_id'] = intval($itemInfo['item_id']);
                    $variations[$k] = $variation;
                }
            }
            if (isset($item['attributes'])) {
                $productInfo['attributes'] = json_encode($item['attributes']);
            }
            if (isset($item['logistics'])) {
                $productInfo['logistics'] = json_encode($item['logistics']);
            }
            if (isset($item['wholesales'])) {
                $productInfo['wholesales'] = json_encode($item['wholesales']);
            }
            isset($item['sales']) && $product['sales'] = $item['sales'];
            isset($item['views']) && $product['views'] = $item['views'];
            isset($item['likes']) && $product['likes'] = $item['likes'];
            isset($item['package_length']) && $product['package_length'] = $item['package_length'];
            isset($item['package_width']) && $product['package_width'] = $item['package_width'];
            isset($item['package_height']) && $product['package_height'] = $item['package_height'];
            isset($item['days_to_ship']) && $product['days_to_ship'] = $item['days_to_ship'];
            isset($item['rating_star']) && $product['rating_star'] = $item['rating_star'];
            isset($item['cmt_count']) && $product['cmt_count'] = $item['cmt_count'];
            isset($item['condition']) && $product['condition'] = $condition[$item['condition']];
            $message = [];
            !empty($itemInfo['warning']) && $message['warning'] = $itemInfo['warning'];
            !empty($itemInfo['fail_image']) && $message['fail_image'] = $itemInfo['fail_image'];
            !empty($message) && $productInfo['message'] = json_encode($message);

            //加入一些附加信息
            $product['update_time'] = time();
            $productInfo['item_id'] = intval($itemInfo['item_id']);
            /*Cache::handler()->hset('shopee.debug.updateitem', $itemInfo['item_id'].'|'.$accountId,
                json_encode($product).'|'.json_encode($productInfo).'|'.json_encode($variations));*/
            Db::startTrans();
            $dbFlag = true;
            if ($productId) {//更新
                //更新主表
                (new ShopeeProduct())->save($product, ['id'=>$productId]);
                //更新信息表
                (new ShopeeProductInfo())->save($productInfo, ['id'=>$productId]);
                //更新变体表
                if (!empty($variations)) {
                    foreach ($variations as $variation) {
                        (new ShopeeVariant())->save($variation,['pid'=>$productId, 'variation_sku'=>$variation['variation_sku']]);
                    }
                }
            } else {//新增
                $productId = (new ShopeeProduct())->insertGetId($product);
                $productInfo['id'] = $productId;
                ShopeeProductInfo::create($productInfo);
                if (!empty($variations)) {
                    foreach ($variations as &$variation) {
                        $variation['pid'] = $productId;
                    }
                    (new ShopeeVariant())->saveAll($variations);
                }

            }
            Db::commit();
            return true;
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }

    /**
     * 下架item
     * @param $accountId
     * @param $itemId
     * @return bool|string
     */
    public function delItem($accountId, $itemId,$tortId=0)
    {
        $dbFlag = false;
        try {
            $field = 'partner_id,shop_id,key';
            $account = ShopeeAccount::field($field)->where(['id'=>$accountId])->find();
            if (empty($account)) {
                throw new Exception('下架Item时，获取账号信息失败');
            }
            $product = ShopeeProduct::field('goods_id,end_type')->where('item_id',$itemId)->find();
            $config = $account->toArray();
            $response = ShopeeApi::instance($config)->loader('Item')->deleteItem(['item_id'=>$itemId]);
            $message = $this->checkResponse($response, 'item_id');
            if ($message !== true) {
                if ($product['end_type'] == 2) {//侵权下架失败回写
                    $backWriteData = [
                        'goods_id' => $product['goods_id'],
                        'goods_tort_id' => $tortId,
                        'channel_id' => 9,
                        'status' => 2,
                    ];
                    (new UniqueQueuer(\app\goods\queue\GoodsTortListingQueue::class))->push($backWriteData);//回写
                }
                $message = json_encode(['error'=>$message]);
                ShopeeProduct::update(['message'=>$message], ['item_id'=>$itemId]);
                return $message;
            }
            Db::startTrans();
            $dbFlag = true;
            ShopeeProduct::update(['status'=>2,'manual_end_time'=>time()], ['item_id'=>$itemId]);
            ShopeeVariant::update(['status'=>2], ['item_id'=>$itemId]);
            Db::commit();
            if ($product['end_type'] == 2) {//侵权下架失败回写
                $backWriteData = [
                    'goods_id' => $product['goods_id'],
                    'goods_sort_id' => $tortId,
                    'channel_id' => 9,
                    'status' => 1,
                ];
                (new UniqueQueuer(\app\goods\queue\GoodsTortListingQueue::class))->push($backWriteData);//回写
            }
            return true;
        } catch (Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }

    /**
     * 保存产品
     * @param $data
     * @param $userId
     * @return int|string
     */
    public function saveProduct($data, $userId, $isUpdate=false)
    {
        try {
            $var = $data['var'];
            $spu = $data['spu'];
            $goodsId = $data['goods_id'];
            //主表
            if ($isUpdate) {
                $product['id'] = $data['id'];
                $product['update_time'] = time();
                $product['update_id'] = $userId;
            } else {//新增
                //检测该账号下是否已经存在相同产品
                $wh = [
                    'goods_id' => $goodsId,
                    'account_id' => $var['account_id']
                ];
                $existProductId = ShopeeProduct::where($wh)->value('id');
                if (!empty($existProductId)) {
                    throw new Exception('账号'.$var['account_code'].'下已存在相同产品，无法在进行创建');
                }
                $product['create_time'] = time();
                $product['create_id'] = $userId;
            }
            $product['goods_id'] = $goodsId;
            $product['category_id'] = $var['category_id'];
            $product['account_id'] = $var['account_id'];
            $product['name'] = $var['name'];
            $product['images'] = json_encode($var['images']);
            $product['item_sku'] = (new GoodsSkuMapService())->createSku($spu);
            $product['weight'] = $var['weight'];
            $product['spu'] = $spu;
            $product['application'] = 'rondaful';
            $product['days_to_ship'] = $var['days_to_ship'];
            $product['cron_time'] = empty($var['cron_time']) ? 0 : strtotime($var['cron_time']);
            //信息表
            $isUpdate && $productInfo['id'] = $data['id'];
            $productInfo['description'] = $var['description'];
            //属性，仅存储有取值的属性
            $attributes = [];
            foreach ($var['attributes'] as $attribute) {
                if (!isset($attribute['attribute_value'])) {
                    continue;
                }
                $attributes[] = [
                    'attribute_id' => $attribute['attribute_id'],
                    'attribute_name' => $attribute['attribute_name'],
                    'is_mandatory' => $attribute['is_mandatory'],
                    'attribute_type' => $attribute['attribute_type'],
                    'attribute_value' => isset($attribute['attribute_value']) ? $attribute['attribute_value'] : ''
                ];
            }
            $productInfo['attributes'] = json_encode($attributes);
            //物流，仅存储用户勾选的物流
            $logistics = [];
            foreach ($var['logistics'] as $logistic) {
                if (empty($logistic['is_checked'])) {
                    continue;
                }
                $logistics[] = $logistic;
            }
            $productInfo['logistics'] = json_encode($logistics);

            $productInfo['original_images'] = json_encode($var['images']);
            $productInfo['wholesales'] = json_encode($var['wholesales']);
            //变体表
            if (isset($var['variant'])) {
                $variants = [];
                foreach ($var['variant'] as $k => $variant) {
                    if ($isUpdate && isset($variant['vid'])) {//更新,确保不是更新过程新增的变体
                        $variant['update_time'] = time();
                    } else {//新增
                        $skuMap = [
                            'combine_sku' => $variant['sku'].'*1',
                            'sku_code' => $variant['sku'],
                            'account_id' => $var['account_id'],
                            'channel_id' => 9
                        ];
                        if (isset($variant['id'])) {
                            $variant['sku_id'] = $variant['id'];
                            unset($variant['id']);
                        }
                        $res = (new GoodsSkuMapService())->addSkuCodeWithQuantity($skuMap, $userId);
                        $variant['variation_sku'] = $res['result'] ? $res['sku_code'] : (new GoodsSkuMapService())->createSku($variant['sku']);
                        $variant['create_time'] = time();
                        $variant['name'] = empty($variant['name']) ? $variant['variation_sku'] : $variant['name'];
                        $variant['combine_sku'] = $variant['sku'].'*1';
                        $variant['local_sku'] = $variant['sku'];
                        isset($data['id']) && $variant['pid'] = $data['id'];
                    }
                    $variants[] = $variant;
                }
            }
            //写入数据表
            if ($isUpdate) {//更新
                $productId = $data['id'];
                $obj = ShopeeProduct::update($product, ['id'=>$productId]);
                $productInfo['id'] = $data['id'];
                ShopeeProductInfo::update($productInfo);
                (new ShopeeVariant())->allowField(true)->saveAll($variants);
            } else {//新增
                $productId = (new ShopeeProduct())->insertGetId($product);
                $productInfo['id'] = $productId;
                ShopeeProductInfo::create($productInfo);
                if (!empty($variants)) {
                    foreach ($variants as &$variant) {
                        $variant['pid'] = $productId;
                    }
                    (new ShopeeVariant())->allowField(true)->saveAll($variants, false);
                }
                GoodsPublishMapService::update(9, $spu, $var['account_id'],1);
            }
            return (int)$productId;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 刊登
     * @param $productId
     * @return bool|string
     */
    public function addItem($productId)
    {
        try {
            $product = $this->getProduct($productId);
            if (!is_array($product)) {
                throw new \Exception($product);
            }
            $platformStatus = (new \app\goods\service\GoodsHelp())->getPlatformForChannel($product['product']['goods_id'],9);
            if (!$platformStatus) {
                throw new Exception('商品在该平台已禁止上架');
            }
            $config = $this->getAuthorization($product['product']['account_id']);
            if (!is_array($config)) {
                throw new \Exception($config);
            }
            $item = $this->formatItemData($product);
            if (!is_array($item)) {
                throw new \Exception($item);
            }
            unset($config['site_id']);
            unset($config['site']);
            Cache::handler()->hset('shopee.debug.additemsenddata', $productId,
                json_encode($item));
            $response = ShopeeApi::instance($config)->handler('Item')->add($item);
            $message = $this->checkResponse($response,'item');
            if ($message !== true) {
                ShopeeProduct::update(['publish_message'=>$message,'publish_status'=>-1], ['id'=>$productId]);
                throw new \Exception($message);
            }

            ShopeeProduct::update(['publish_status'=>3,'publish_message'=>'', 'item_id'=>$response['item_id']], ['id'=>$productId]);
            //刊登成功后push到"SPU上架实时统计队列"
            $skuCount = ShopeeVariant::where('pid',$productId)->count();
            $param = [
                'channel_id' => ChannelAccountConst::channel_Shopee,
                'account_id' => $product['product']['account_id'],
                'shelf_id' => $product['product']['create_id'],
                'goods_id' => $product['product']['goods_id'],
                'times'    => 1, //实时=1
                'quantity' => empty($skuCount) ? 1 : $skuCount,
                'dateline' => time()
            ];
            (new CommonQueuer(\app\report\queue\StatisticByPublishSpuQueue::class))->push($param);
           //30s后进行一次同步
            $params = [
                'item_id' => $response['item_id'],
                'account_id' => $product['product']['account_id']
            ];
            (new UniqueQueuer(ShopeeGetItemDetailQueue::class))->push($params, 30);


            Cache::handler()->hset('shopee.debug.additemresponse', $response['item_id'].'|'.$productId,
                json_encode($response));
            $res = $this->updateProductWithItem($response, $product['product']['account_id'], $productId);
            if ($res !== true) {
                $message = '刊登成功，但是更新本地信息时出现错误，error:'.$res;
                ShopeeProduct::update(['publish_message'=>$message], ['id'=>$product['product']['id']]);
                throw new \Exception($res);
            }
            return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 批量删除本地产品
     * @param $productIds
     * @return string
     */
    public function delProducts($productIds)
    {
        $dbFlag = false;
        try {
            $enableDelStatus = [
                ShopeeHelper::PUBLISH_STATUS['fail'],
                ShopeeHelper::PUBLISH_STATUS['noStatus'],
                ShopeeHelper::PUBLISH_STATUS['inPublishQueue'],
                ShopeeHelper::PUBLISH_STATUS['offLine'],
            ];
            $wh['publish_status'] = ['in', $enableDelStatus];
            $wh['id'] = ['in', $productIds];
            $ids = ShopeeProduct::where($wh)->column('id');//过滤不允许删除的
            Db::startTrans();
            $dbFlag = true;
            //维护刊登映射表
            $products = ShopeeProduct::field('spu,account_id')->where(['id'=>['in', $ids]])->select();
            foreach ($products as $product) {
                GoodsPublishMapService::update(9, $product['spu'], $product['account_id'], 0);
            }
            ShopeeProduct::destroy($ids);
            ShopeeProductInfo::destroy($ids);
            ShopeeVariant::destroy(function($query) use ($ids){
                $query->where('pid', 'in', $ids);
            });
            Db::commit();
            return count($ids);
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }


/**********************************************************************************************************************/

    /**
     * 同步数据时，批量增删改查数据库
     * @param $data
     *              new_ids     新的目标ids，比如category,attribute
     *              new_items   新的数据,根据目标不同而不同
     *              old_ids     旧的目标ids
     *              item        要操作的目标键名，比如category_id,attribute_id
     * @param $modelClass       要操作的模型类
     * @param $where
     *              del_wh      删除时除了目标id之外的附加条件，比如站点，账号
     *              update_wh   更新查询时除了目标id之外的附加条件，比如站点，账号
     * @param bool $operationTime   是否写入创建时间和更新时间
     * @return bool
     */
    public function curdDb($data, $modelClass, $where, $operationTime=true)
    {
        $dbFlag = false;
        try {
            $newItemIds = $data['new_ids'];
            $newItems = $data['new_items'];

            $insertItemIds = array_diff($newItemIds, $data['old_ids']);//需要插入的
            $delItemIds = array_diff($data['old_ids'], $newItemIds);//需要删除的
            $updateItemIds = array_diff($newItemIds, $insertItemIds);//需要更新的
            unset($data['old_ids']);

            Db::startTrans();
            $dbFlag = true;
            //删除
            if (!empty($delItemIds)) {
                $map = $where['del_wh'];
                $map[$data['item']] = ['in', $delItemIds];
                $modelClass::destroy($map);
            }

            //新增
            if (!empty($insertItemIds)) {
                $insertItems = [];
                $tmpItems = $newItems;
                foreach ($tmpItems as $k => $tmpItem) {
                    if (in_array($tmpItem[$data['item']], $insertItemIds)) {
                        $operationTime && $tmpItem['create_time'] = time();
                        $insertItems[] = $tmpItem;
                        unset($newItems[$k]);//释放掉新增的，剩下的都是需要更新的
                        unset($newItemIds[$k]);//同时释放掉id,与上面的保持索引一致
                    }
                }
                (new $modelClass())->saveAll($insertItems, false);
            }


            //更新
            if (!empty($updateItemIds)) {
                //获取旧信息
                $wh = $where['update_wh'];
                $updateField = 'id,'.$data['item'];
                $wh[$data['item']] = ['in',$updateItemIds];
                $needUpdateItems = $modelClass::field($updateField)->where($wh)->select();


                $newItemIds = array_flip($newItemIds);
                //将主键id组装到更新信息中，以便批量更新
                foreach ($needUpdateItems as $needUpdateItem) {
                    $index = $newItemIds[$needUpdateItem[$data['item']]];
                    $newItems[$index]['id'] = $needUpdateItem['id'];
                    $operationTime && $newItems[$index]['update_time'] = time();

                }
                $updateItems = array_values($newItems);
                
                (new $modelClass())->saveAll($updateItems);
            }
            Db::commit();
            return true;
        } catch (Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            return $e->getMessage();
        }
    }

    /**
     * 检查返回的结果是否正确
     * @param $response
     * @param $key
     * @return bool|string
     */
    public function checkResponse($response, $key)
    {
        $message = '';
        if (isset($response['error'])){
            $message .= 'error_type:'.$response['error'];
            isset($response['msg']) && $message .= '; msg:'.$response['msg'];
        } else if (!isset($response[$key])) {
            $message = 'unknown error';
        }
        return empty($message) ? true : $message;
    }

    /**
     * 获取对应站点或账号的认证信息
     * @param $country
     * @return array|string
     */
    public function getAuthorization($accountId, $country='')
    {
        try {
            $wh = [
                'platform_status' => 1,
                'key' => ['neq', ''],
                'shop_id' => ['neq', 0],
                'status' => 1
            ];
            if ($accountId) {
                $wh['id'] = $accountId;
            } else {
                $wh['site'] = $country;
            }
            $field = 'site,shop_id,partner_id,key';
            $account = ShopeeAccount::field($field)->where($wh)->find();
            if (empty($account)) {
                return '获取账号信息失败';
            }
            $siteId = $account['site_id'];
            $config = $account->toArray();
//            unset($config['site']);
            $config['site_id'] = $siteId;
            return $config;
        } catch (Exception $e) {
            return $e->getMessage();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 获取产品信息
     * @param $productId
     * @param int $itemId
     * @return array|string
     */
    public function getProduct($productId, $itemId=0)
    {
        try {
            if ($productId) {
                $wh = ['id'=>$productId];
            } else {
                $wh = ['item_id'=>$itemId];
            }
            $product = ShopeeProduct::get($wh);
            if (empty($product)) {
                throw new \Exception('获取产品信息失败');
            }
            $productId = $product['id'];
            $productInfo = ShopeeProductInfo::get($productId);
            $variant = ShopeeVariant::where(['pid'=>$productId])->select();
            $data = [
                'product' => $product->toArray(),
                'productInfo' => $productInfo->toArray(),
                'variant' => json_decode(json_encode($variant), true)
            ];
            return $data;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 格式化刊登的数据
     * @param $productData
     * @return string
     */
    public function formatItemData($productData)
    {
        try {
            $product = $productData['product'];
            $productInfo = $productData['productInfo'];
            $variant = $productData['variant'];
            $item['category_id'] = (int)$product['category_id'];
            $item['name'] = $product['name'];
            $item['description'] = $productInfo['description'];
            $item['name'] = $product['name'];
            $item['item_sku'] = $product['item_sku'];

            $variations = [];//变体
            foreach ($variant as $k => $var) {
                $variations[$k]['name'] = $var['name'];
                $variations[$k]['stock'] = (int)$var['stock'];
                $variations[$k]['price'] = (float)$var['price'];
                $variations[$k]['variation_sku'] = $var['variation_sku'];
            }
            $item['variations'] = $variations;
            //图片
            $code = ShopeeAccount::where(['id'=>$product['account_id']])->value('code');
            $images = json_decode($product['images'], true);
            foreach ($images as $k => $image) {
                $item['images'][$k]['url'] = GoodsImage::getThumbPath($image, 0, 0, $code, true);
            }
            //属性
            $attributes = json_decode($productInfo['attributes'], true);
            if (!empty($attributes)) {
                $itemAttributes = [];
                foreach ($attributes as $k => $attribute) {
                    if (!isset($attribute['attribute_value'])) {
                        continue;
                    }
                    $itemAttributes[] = [
                        'attributes_id' => (int)$attribute['attribute_id'],
                        'value' => $attribute['attribute_value']
                    ];
                }
                $item['attributes'] = $itemAttributes;
            }
            //物流
            $logistics = json_decode($productInfo['logistics'], true);
            $formatLogistics = [];
            foreach ($logistics as $k => $logistic) {
                $formatLogistics[$k]['logistic_id'] = (int)$logistic['logistic_id'];
                $formatLogistics[$k]['enabled'] = $logistic['enabled'] == 1 ? true : false;
                if (isset($logistic['fee_type'])) {
                    if ($logistic['fee_type'] == 'CUSTOM_PRICE') {
                        $formatLogistics[$k]['shipping_fee'] = (float)$logistic['shipping_fee'];
                    } else if ($logistic['fee_type'] == 'SIZE_SELECTION') {
                        $formatLogistics[$k]['size_id'] = (int)$logistic['size_id'];
                    }
                }
                $formatLogistics[$k]['is_free'] = $logistic['is_free'] == 1 ? true : false;
            }
            $item['logistics'] = $formatLogistics;
            $item['weight'] = (float)$product['weight'];
            !empty($product['package_length']) && $item['package_length'] = (int)$product['package_length'];
            !empty($product['package_width']) && $item['package_width'] = (int)$product['package_width'];
            !empty($product['package_height']) && $item['package_height'] = (int)$product['package_height'];
            if ($product['days_to_ship']>=7 && $product['days_to_ship']<=30) {
                $item['days_to_ship'] = $product['days_to_ship'];
            }
            //批发
            $wholesales = json_decode($productInfo['wholesales'], true);
            if (!empty($wholesales)) {
                $itemWholesales = [];
                foreach ($wholesales as $k => $wholesale) {
                    $itemWholesales[$k]['min'] = (int)$wholesale['min'];
                    $itemWholesales[$k]['max'] = (int)$wholesale['max'];
                    $itemWholesales[$k]['unit_price'] = (float)$wholesale['unit_price'];
                }
                $item['wholesales'] = $itemWholesales;
            }
            return $item;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 根据SKU获取刊登过该SKU的销售员
     * @param $skuId
     * @return array
     */
    public static function getSalesmenBySkuId($skuId)
    {
        try {
            //根据sku获取对应的goods id
            $goodsIds = GoodsSku::where('id',$skuId)->value('goods_id');
            //根据goods id获取已刊登listing的销售员
            $wh['goods_id'] = $goodsIds;
            $wh['status'] = 1;
            $salesmenIds = ShopeeProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 根据商品id获取刊登过该商品的销售员
     * @param $skuId
     * @return array
     */
    public static function getSalesmenByGoodsId($goodsId)
    {
        try {
            $wh['goods_id'] = $goodsId;
            $wh['status'] = 1;
            $salesmenIds = ShopeeProduct::distinct(true)->where($wh)->column('create_id');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }


}