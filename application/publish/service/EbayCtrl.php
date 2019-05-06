<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2018/8/1
 * Time: 16:59
 */

namespace app\publish\service;

use app\common\cache\Cache;
use app\common\cache\driver\EbayListingReponseCache;
use app\common\model\ChannelUserAccountMap;
use app\common\model\ebay\EbayAccount;
use app\common\model\ebay\EbayActionLog;
use app\common\model\ebay\EbayCategory;
use app\common\model\ebay\EbayCategorySpecific;
use app\common\model\ebay\EbayCategorySpecificDetail;
use app\common\model\ebay\EbayDraft;
use app\common\model\ebay\EbayListing;
use app\common\model\ebay\EbayListingImage;
use app\common\model\ebay\EbayListingMappingSpecifics;
use app\common\model\ebay\EbayListingSetting;
use app\common\model\GoodsTortDescription;
use app\common\model\mongodb\ebay\EbayListingSetting as ELSMongo;
use app\common\model\ebay\EbayListingTiming;
use app\common\model\ebay\EbayListingVariation;
use app\common\model\ebay\EbaySite;
use app\common\model\ebay\EbayTitle;
use app\common\model\ebay\EbayTrans;
use app\common\model\Goods;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsGallery;
use app\common\model\GoodsLang;
use app\common\model\GoodsPublishMap;
use app\common\model\GoodsSku;
use app\common\model\GoodsSkuMap;
use app\common\model\GoodsTitleKeyMap;
use app\common\model\Lang;
use app\common\model\LogExportDownloadFiles;
use app\common\model\User;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\common\service\GoogleTranslate;
use app\common\model\TitleKey;
use app\common\service\ImportExport;
use app\common\service\UniqueQueuer;
use app\index\service\Role;
use app\listing\queue\EbayEndItemQueue;
use app\publish\queue\EbayListingExportQueue;
use app\index\service\DownloadFileService;
use app\publish\helper\ebay\EbayPublish as EbayPublishHelper;
use app\publish\helper\ebay\EbayPublish;
use app\publish\queue\EbayPublishItemQueuer;
use app\publish\queue\EbayUpdateOnlineListing;
use app\publish\validate\EbayListingValidate;
use app\report\model\ReportExportFiles;
use think\Db;
use think\Exception;
use app\common\traits\User as UserTraits;

class EbayCtrl
{
    use UserTraits;
    private $userId;
    private $helper;

    public function __construct(int $userId=0)
    {
        if (!$userId) {
            $userInfo = Common::getUserInfo();
            $this->userId = $userInfo['user_id']??0;
        } else {
            $this->userId = $userId;
        }
        $this->helper = new EbayPublishHelper();
    }


    /**
     * 获取推荐的分类
     * @param $accountId
     * @param $site_id
     * @param $queryString
     * @return array
     * @throws Exception
     */
    public function getSuggestedCategories($accountId, $site_id, $query)
    {
        try {
            $verb = 'GetSuggestedCategories';
            $q = trim($query['keywords']);
            if (preg_match('/^[0-9]+$/',$q)) {
                $categoryIds = [$q];
            } else {
                $accountInfo = EbayAccount::get($accountId);
                if (empty($accountInfo)) {
                    return ['result'=>false, 'message'=>'获取账号信息失败'];
//                    throw new Exception();
                }
                $accountInfo = $accountInfo->toArray();
                $packApi = new EbayPackApi();
                $api = $packApi->createApi($accountInfo, $verb, ($site_id==100) ? 0 : $site_id);
                $xml = $packApi->createXml(['query'=>$query['keywords']]);
                $response = $api->createHeaders()->__set('requesBody', $xml)->sendHttpRequest2();
                $res = (new EbayDealApiInformation())->dealWithApiResponse($verb,$response);
                if ($res === false) {
                    return ['result'=>false, 'message'=>'获取推荐分类失败'];
//                    throw new Exception('获取失败');
                }
                $categoryIds = $res;
            }
            $map['category_id'] = ['in', $categoryIds];
            $map['site'] = $site_id;
            $categoryInfo = (new EbayCategory())->field('category_id,variations_enabled,item_compatibility_enabled')
                ->where($map)->order('category_id')->page($query['page'], $query['size'])->select();
            if (empty($categoryInfo)) {
                return ['result'=>false, 'message'=>'无法获取到对应分类'];
            }
            $existCategoryIds = EbayCategory::where($map)->order('category_id')->column('category_id');
            $combineCategories = array_combine($existCategoryIds, $categoryInfo);
            $count = (new EbayCategory())->where($map)->count();

            $categories = [];
            $i = 0;
            foreach ($categoryIds as $categoryId) {
                if (in_array($categoryId, $existCategoryIds)) {
                    $categories[$i] = $combineCategories[$categoryId]->toArray();
                    $categories[$i]['category_name'] = $this->helper->getEbayCategoryChain($categoryId,$site_id);
                    $i++;
                }
            }
//            foreach ($categoryInfo as $info) {
//                $categories[$i] = $info->toArray();
//                $categories[$i]['category_name'] = $this->helper->getEbayCategoryChain($info['category_id'],$site_id);
//                $i++;
//            }
            return ['result'=>true, 'data'=>$categories, 'count'=>$count];
        } catch (Exception $e) {
            return ['result'=>false,'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 获取范本或listing的店铺分类
     * @param $ids
     * @throws Exception
     */
    public function getDLStoreCategory($ids)
    {
        try {
            $field = 'id,item_id,account_id,spu,store_category_id,store_category2_id,site,listing_sku';
            $storeCategories = (new EbayListing())->field($field)->where(['id'=>['in', $ids]])->select();
            $data = [];
            foreach ($storeCategories as $storeCategory) {
                $storeCategory = $storeCategory->toArray();
                $storeCategory['store_category_chain'] = empty($storeCategory['store_category_id']) ? '' :
                    $this->helper->getStoreCategoryChain($storeCategory['store_category_id'], $storeCategory['account_id']);
                $storeCategory['store_category2_chain'] = empty($storeCategory['store_category2_id']) ? '' :
                    $this->helper->getStoreCategoryChain($storeCategory['store_category2_id'], $storeCategory['account_id']);
                $data[] = $storeCategory;
            }
            return ['result'=>true, 'data'=>$data];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取指定账号，指定分类的分类链
     * @param $categoryId
     * @param $accountId
     * @return string
     * @throws Exception
     */
    public function getStoreCategoryChain($categoryId, $accountId)
    {
        try {
            $chain = $this->helper->getStoreCategoryChain($categoryId, $accountId);
            $data = [
              'name_chain' => $chain,
              'account_id' => $accountId,
              'store_category_id' => $categoryId
            ];
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 更新listing店铺分类
     * @param $data
     * @throws Exception
     */
    public function updateListingStoreCategory($data)
    {
        try {
            foreach ($data as &$datum) {
                $datum['api_type'] = 2;
                $datum['create_id'] = $this->userId;
                $datum['cron_time'] = empty($datum['cron_time']) ? 0 : strtotime($datum['cron_time']);
                $logId = $this->helper->writeUpdateLog($datum);
                $this->helper->pushQueue(EbayUpdateOnlineListing::class, $logId, $datum['cron_time']);
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 批量修改范本店铺分类
     * @param $data
     * @throws Exception
     */
    public function changeDraftStoreCategory($data)
    {
        try {
            foreach ($data as &$datum) {
                $datum['user_id'] = $this->userId;
                $datum['update_date'] = time();
            }
            (new EbayListing())->saveAll($data);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 批量获取范本/listing主图
     * @param $ids
     * @throws Exception
     */
    public function getDLMainImgs($ids)
    {
        try {
            $field = 'id,item_id,spu,account_id,site,listing_sku';
            $imgField = 'path,base_url,ser_path,eps_path,sort,de_sort,main,detail';
            $listings = (new EbayListing())->field($field)->where(['id'=>['in', $ids]])->select();
            foreach ($listings as &$listing) {
                $listing['imgs'] = (new EbayListingImage())->field($imgField)->where(['listing_id'=>$listing['id'],
                    'main'=>1])->order('sort')->select();
            }
            return $listings;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 在线更新listing主图
     * @param $data
     * @throws Exception
     */
    public function updateListingMainImgs($data)
    {
        try {
            $data['api_type'] = 2;
            $data['create_id'] = $this->userId;
            $data['cron_time'] = empty($data['cron_time']) ? 0 : strtotime($data['cron_time']);
            //描述里面可能会使用到主图，更新主图时，更新描述
            $list = EbayListing::get(['item_id'=>$data['item_id']]);
            if (empty($list)) {
                throw new Exception('获取listing信息失败');
            }
            $list = $list->toArray();
            $set = EbayListingSetting::get($list['id'])->toArray();

            $desc['title'] = $list['title'];
            $desc['description'] = $set['description'];
            $desc['mod_style'] = $list['mod_style'];
            $desc['mod_sale'] = $list['mod_sale'];
            $desc['detail_imgs'] = (new EbayListingImage())->where(['listing_id'=>$list['id'], 'main'=>1])->select();
            $desc['mainImgs'] = $data['new_val']['imgs'];
            $data['new_val']['picture_gallery'] = $list['picture_gallery'];
            $data['new_val']['mainImgs'] = $data['new_val']['imgs'];
            unset($data['new_val']['imgs']);
            $data['new_val']['style'] = $desc;
            $logId = $this->helper->writeUpdateLog($data);
            $this->helper->pushQueue(EbayUpdateOnlineListing::class, $logId, $data['cron_time']);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 批量转站点设置账号
     * @param $ids
     * @param $site
     * @param $templates
     * @return array
     * @throws Exception
     */
    public function changeSite($ids, $site, $templates,$copy=0,$accountId=0,$fromDraft=0)
    {
        $errMsg = '';
        if ($copy) {//需要复制
            $res = $this->helper->packageCopyListing($ids,$this->userId,$accountId,$fromDraft);
            if ($res['result']===false) {
                return $res;
            }
            $listings = $res['data'];
            $lists = array_column($listings,'list');//主表
            $sets = array_column($listings,'set');
            $variants = array_column($listings,'variants');
        } else {//不需要复制,需要检测状态
            $ids = $this->helper->filterReadOnlyListingId($ids);
            if (empty($ids) || (!$accountId && (!is_numeric($site) || empty($templates)))) {
                return ['result'=>true,'message'=>'成功操作0条，自动忽略了不可修改的listing'];
            }
            $field = 'id,spu,site,primary_categoryid,second_categoryid,title,account_id,variation,min_price';
            $lists = (new EbayListing())->field($field)->where(['id'=>['in', $ids]])->select();
            if ($lists) {
                $lists = collection($lists)->toArray();
            } else {
                return ['result'=>true,'message'=>'没有需要操作的数据'];
            }
        }
        if ($accountId) {//修改了账号，匹配大小额账户
            foreach ($lists as &$list) {
                $list['account_id'] = $accountId;
                $list['paypal_emailaddress'] = $this->helper->autoAdaptPaypal($accountId,is_numeric($site)?$site:$list['site'],
                    $list['min_price'],$this->userId);
                if (empty($list['paypal_emailaddress'])) {
                    throw new Exception('获取目的账号paypal账号失败，请先确认账号已绑定了对应仓库的paypal账号后再进行操作');
                }
            }
        }
        if (is_numeric($site)) {//修改了站点
            $currency = EbaySite::where('siteid',$site)->value('currency');
            //处理分类
            $messages = '';
//            $categoryUpdate = [];//要更新的分类信息
            $specificUpdate = [];//要更新的属性信息
            foreach ($lists as $k => &$list) {
                $list['currency'] = $currency;//切换站点需要切换货币类型
                $categoryWh = [
                    'site' => $site,
                    'category_id' => $list['primary_categoryid']
                ];
                $categoryField = 'category_id,variations_enabled';
                $newSiteCategory = (new EbayCategory())->field($categoryField)->where($categoryWh)->find();//新站点分类
                if (empty($newSiteCategory)) {//新站点中没有对应的分类，用title获取推荐分类
                    $query = [
                        'keywords' => $list['title'],
                        'page' => 1,
                        'size' => 20
                    ];
                    $suggestCategory = $this->getSuggestedCategories($list['account_id'], $site, $query);
//                    $suggestCategory = $suggestCategory['data'];
                    if ($suggestCategory['result'] == false || !isset($suggestCategory['data'][0])) {
                        $messages .= 'SPU:' . $list['spu'] . ',原站点：' . EbaySite::where('siteid', $list['site'])->value('name') .
                            ' 的listing转到新站点后获取新站点分类失败，请手动选择；';
                        continue;
                    }
//                    $categoryUpdate[] = [
//                        'id' => $list['id'],
//                        'primary_categoryid' => $suggestCategory['data'][0]['category_id']
//                    ];
                    $list['primary_categoryid'] = $suggestCategory['data'][0]['category_id'];
                    //查询是否支持多属性
                    $categoryWh['category_id'] = $suggestCategory['data'][0]['category_id'];
                    $newSiteCategory['variations_enabled'] = EbayCategory::where($categoryWh)->value('variations_enabled');
//                    $category->save();
                }

                if ($list['variation'] && !$newSiteCategory['variations_enabled']) {
                    $errMsg .= 'SPU:' . $list['spu'] . ',原站点：' . EbaySite::where('siteid', $list['site'])->value('name') .
                        ' 的listing转到新站点后分类属性不支持多属性，请取消勾选此条后再操作；';
                }

                //处理分类属性
                $wh['site'] = $site;
                $wh['category_id'] = $list['primary_categoryid'];
                $specificField = 'id,category_specific_name,min_values,max_values';
                $specifics = (new EbayCategorySpecific())->field($specificField)->where($wh)->select();
                //统计旧属性中有值的
                if ($copy) {
                    $oldSpecifics = $sets[$k]['specifics'];//如果是复制的，不用再次获取
                } else {
                    $oldSpecifics = EbayListingSetting::where(['id' => $list['id']])->value('specifics');
                }
                $oldSpecifics = json_decode($oldSpecifics, true);
                $oldSpecificNameValueList = [];
                $oldSpecificNames = [];
                foreach ($oldSpecifics as $oldSpecific) {
                    if (!empty($oldSpecific['attr_value'])) {
                        $oldSpecificNames[] = $oldSpecific['attr_name'];
                        $oldSpecificNameValueList[$oldSpecific['attr_name']] = $oldSpecific['attr_value'];
                    }
                }

                $setSpecifics = [];
                $specificNames = [];
                $map['site'] = $site;
                $map['category_id'] = $list['primary_categoryid'];
                $i = 0;
                foreach ($specifics as $specific) {//新分类属性
                    $specificNames[] = $specific['category_specific_name'];
                    $setSpecifics[$i]['custom'] = 0;
                    $setSpecifics[$i]['attr_name'] = $specific['category_specific_name'];
                    if (in_array($specific['category_specific_name'], $oldSpecificNames)) {//属性名与旧属性相同，使用旧属性值
                        $tmpValue = $oldSpecificNameValueList[$specific['category_specific_name']];
                        if ($specific['max_values'] == 1 && is_array($tmpValue)) {
                            //新属性只能单选，但是旧属性是多选
                            $setSpecifics[$i]['attr_value'] = $tmpValue[0];
                        } elseif ($specific['max_values'] > 1 && !empty($tmpValue) && !is_array($tmpValue)) {
                            //新属性是多选，就属性是单选
                            $setSpecifics[$i]['attr_value'] = [$tmpValue];
                        } else {
                            $setSpecifics[$i]['attr_value'] = $tmpValue;
                        }

                        unset($oldSpecificNameValueList[$specific['category_specific_name']]);
                    } else if ($specific['min_values'] == 1) {//必填项获取第一个值
                        if ($specific['category_specific_name'] == 'Brand') {//品牌特殊处理
                            $setSpecifics[$i]['attr_value'] = 'Unbranded';
                        } else {
                            $map['ebay_specific_id'] = $specific['id'];
                            $attrVal = EbayCategorySpecificDetail::where($map)->value('category_specific_value');
                            $setSpecifics[$i]['attr_value'] = empty($attrVal) ? 'Does not apply' : $attrVal;
                        }
                    } else {//非必填项置空
                        $setSpecifics[$i]['attr_value'] = '';
                    }
                    $i++;
                }
                //将旧属性中有值的属性转为新站点自定义属性
                foreach ($oldSpecificNameValueList as $name => $value) {
                    $setSpecifics[$i]['custom'] = 1;
                    $setSpecifics[$i]['attr_name'] = $name;
                    $setSpecifics[$i]['attr_value'] = $value;
                    $specificNames[] = $name;
                    $i++;
                }

                if ($list['variation'] && $newSiteCategory['variations_enabled']) {//多属性
                    if ($copy) {
                        $variation = $variants[$k][0]['variation'];//变体属性
                    } else {
                        $variation = EbayListingVariation::where(['listing_id' => $list['id']])->value('variation');
                    }
                    $variation = json_decode($variation, true);
                    if (is_array($variation)) {
                        $varSpecifics = array_keys($variation);
                        foreach ($varSpecifics as $varSpecific) {
                            if (!in_array($varSpecific, $specificNames)) {//如果多属性不在新站点中,转为自定义属性
                                $setSpecifics[$i]['custom'] = 1;
                                $setSpecifics[$i]['attr_name'] = $varSpecific;
                                $setSpecifics[$i]['attr_value'] = '';
                                $i++;
                            }
                        }
                    }
                }
                if ($copy) {
                    $listings[$k]['set']['specifics'] = json_encode($setSpecifics);
                } else {
                    $specificUpdate[] = [
                        'specifics' => json_encode($setSpecifics),
                        'id' => $list['id']
                    ];
                }
            }
            if ($errMsg) {
                throw new Exception($errMsg);
            }
        }
        if ($templates) {
            try {
                $details = $this->helper->applyCommonTemplate($templates);
            } catch (\Exception $e) {
                return ['result' => false, 'message' => $e->getMessage()];
            }
            $details['list']['site'] = $site;
            $details['list']['update_date'] = time();
            $details['list']['user_id'] = $this->userId;
            $details['list']['is_translated'] = 0;//转站点后复位翻译标志
        }
        try {
            Db::startTrans();
            $mongoSet = [];
            if ($copy) {//复制
                foreach ($listings as $key => $listing) {
                    $listing['list'] = $lists[$key];//上面可能更新了账号或分类
                    //上面如果修改了站点，已经设置了主分类和属性，还要再合并一下修改了站点后的信息
                    $listing['list'] = array_merge($listing['list'], $details['list']??[]);
                    $listingId = (new EbayListing())->insertGetId($listing['list']);
                    $listing['set']['id'] = $listingId;
                    $listing['set'] = array_merge($listing['set'], $details['set']??[]);
                    //处理描述
                    $tmpSet['description'] = $listing['set']['description'];
                    $tmpSet['id'] = (int)$listingId;
                    $mongoSet[] = $tmpSet;
                    unset($listing['set']['description']);

                    EbayListingSetting::create($listing['set']);
                    //图片
                    if ($listing['imgs']) {
                        foreach ($listing['imgs'] as &$img) {
                            $img['listing_id'] = $listingId;
                        }
                        (new EbayListingImage())->saveAll($listing['imgs']);
                    }
                    if (!empty($listing['variants'])) {//变体
                        foreach ($listing['variants'] as &$variant) {
                            if (!$listing['list']['variation']) {
                                $variant['v_qty'] = $listing['list']['quantity'];
                            }
                            $variant['listing_id'] = $listingId;
                        }
                        (new EbayListingVariation())->saveAll($listing['variants']);
                    }
                    if ($listing['mappingsepc']??'') {
                        foreach ($listing['mappingsepc'] as &$mapspec) {
                            $mapspec['listing_id'] = $listingId;
                        }
                        (new EbayListingMappingSpecifics())->saveAll($listing['mappingsepc']);
                    }
                    //维护映射表
                    if ($listing['list']['variation']) {
                        foreach ($listing['variants'] as $variant) {
                            $variant['is_virtual_send'] = $listing['list']['is_virtual_send'];
                            $this->helper->maintainTableGoodsSkuMap($variant, $listing['list']['account_id'], $this->userId, $listing['list']['assoc_order'] ? false : true);
                        }
                    } else {
                        $this->helper->maintainTableGoodsSkuMap($listing['list'], $listing['list']['account_id'], $this->userId, $listing['list']['assoc_order'] ? false : true);
                    }
                }
            } else {//没有复制
                foreach ($lists as &$list) {
                    $list = array_merge($list, $details['list']??[]);
                }
                (new EbayListing())->saveAll($lists);
                if (is_numeric($site)) {//转了站点，同时更新范本里面的站点信息
                    EbayDraft::update(['site_id'=>$site],['listing_id'=>['in',$ids]]);
                }

                if (is_numeric($site)) {
                    (new EbayListingSetting())->saveAll($specificUpdate);
                }
                if ($templates && isset($details)) {
                    EbayListingSetting::update($details['set'], ['id' => ['in', $ids]]);
                }

            }
            Db::commit();
            if ($mongoSet) {
                ELSMongo::insertAll($mongoSet);
            }
            return ['result'=>true,'message'=>'成功操作'.count($ids).'条，自动过滤了不可修改的listing。'.(empty($messages) ? '' : '警告：'.$messages)];
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     *
     * @param $data
     * @throws Exception
     */
    public function costPriceToAdjustedPrice($data)
    {
        try {
            $listPrice = [];
            $varPrice = [];
            $varListIds = [];
            $j = 0;
            foreach ($data as $datum) {
                if ($datum['variation']) {//多属性
                    foreach ($datum['prices'] as $price) {
                        $varPrice[$datum['id']][$price['channel_map_code']]['cost_price'] = $price['cost_price'];
                        $varPrice[$datum['id']][$price['channel_map_code']]['adjusted_cost_price'] = $price['adjusted_cost_price'];
//                        $varPrice[$datum['id']][$price['channel_map_code']]['channel_map_code'] = $price['channel_map_code'];
//                        $varPrice[$datum['id']][$price['channel_map_code']]['listing_id'] = $datum['id'];
                        $varListIds[] = $datum['id'];
                    }
                } else if ($datum['prices']){
                    $listPrice[$j]['cost_price'] = $datum['prices']['adjusted_cost_price'];
                    $listPrice[$j]['adjusted_cost_price'] = $datum['prices']['adjusted_cost_price'];
                    $listPrice[$j]['id'] = $datum['id'];
                    $j++;
                }
            }
            //先批量更新单属性
            (new EbayListing())->saveAll($listPrice);
            //更新多属性
            $field = 'id,listing_id,channel_map_code';
            $varians = (new EbayListingVariation())->field($field)->where(['listing_id'=>['in', $varListIds]])->select();
            $varIdPrices = [];
            $i = 0;
            foreach ($varians as $varian) {
                if (isset($varPrice[$varian['listing_id']][$varian['channel_map_code']])) {
                    $varIdPrices[$i] = $varPrice[$varian['listing_id']][$varian['channel_map_code']];
                    $varIdPrices[$i]['id'] = $varian['id'];
                    $i++;
                }
            }
            (new EbayListingVariation())->saveAll($varIdPrices);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 批量修改范本拍卖刊登天数
     * @param $data
     * @throws Exception
     */
    public function DChineseListingDuration($data)
    {
        try {
            foreach ($data as &$datum) {
                $datum['user_id'] = $this->userId;
                $datum['update_date'] = time();
            }
            (new EbayListing())->saveAll($data);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 翻译
     * @param $data
     * @return mixed
     * @throws Exception
     */
    public function translate($data)
    {
        $siteLang = [
            0 => 'en',//美国
            2 => 'en',//加拿大
            3 => 'en',//英国
            15 => 'en',//澳大利亚
            16 => 'de',//奥地利
            23 => 'fr',//比利时法语
            71 => 'fr',//法国
            77 => 'de',//德国
            100 => 'en',//eBay汽车
            101 => 'it',//意大利
            123 => 'nl',//比利时荷兰
            146 => 'nl',//荷兰
            186 => 'es',//西班牙
            193 => 'de',//瑞士
            201 => 'zh',//香港
            203 => 'in',//印度
            205 => 'en',//爱尔兰
            207 => 'en',//马来西亚
            210 => 'fr',//加拿大法语
            211 => 'en',//菲律宾
            212 => 'pl',//波兰
            215 => 'ru',//俄罗斯
            216 => 'en'//新加坡
        ];
        try {
            foreach ($data as &$datum) {
                $target = $siteLang[$datum['site']];
                if ($target == 'en') continue;//英文不翻译
                //过滤不需要翻译的字符串
                $strings = [];
                foreach ($datum['strings'] as $k => $string) {
                    if (!is_string($string)) {
                        continue;
                    }
                    if ((preg_match('/\d+#/', $string) !==0 && strlen($string) < 5)) continue;
                    $strings[$k] = $string;
                }
                //翻译
                $strVals = array_values($strings);
                $translates = (new GoogleTranslate())->translateBatch($strVals, ['target'=>$target], $this->userId, 1);
                $strVals = [];
                foreach ($translates as $translate) {
                    $strVals[] = $translate['text'];
                }
                //用翻译后的结果替换原字符串
                $strings = array_combine(array_keys($strings), $strVals);
                foreach ($strings as $k => $string) {
                    $datum['strings'][$k] = $string;
                }
            }
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取标题库列表
     * @param $params
     * @return array
     */
    public function titles($params)
    {
        try {
            $wh['channel'] = 1;
            if (!empty($params['searchType']) && !empty($params['searchContent'])) {
                switch ($params['searchType']) {
                    case 'spu':
                        $spus = json_decode($params['searchContent'],true);
                        if (empty($spus)) {
                            $wh['m.spu'] = 'is null';
                        } else {
                            $wh['m.spu'] = ['in', $spus];
                        }
                        break;
                    case 'name':
                        $wh['g.name'] = ['like','%'.$params['searchContent'].'%'];
                        break;
                    case 'sku':
                        $goodsIds = GoodsSku::distinct(true)->whereIn('sku',$params['searchContent'])->column('goods_id');
                        $wh['m.goods_id'] = ['in',$goodsIds];
                        break;
                }
            }
            if (!empty($params['localCategoryId'])) {
                $categoryIds = CommonService::getSelfAndChilds($params['localCategoryId']);
                $wh['m.category_id'] = ['in',$categoryIds];
            }
            if (isset($params['isset']) && $params['isset'] !== '') {
                $wh['et.title'] = ($params['isset']==0) ? ['exp','is null'] : ['exp','is not null'];
            }



            if (!empty($params['startDate']) || !empty($params['endDate'])) {
                $startTime = empty($params['startDate']) ? 0 : strtotime($params['startDate']);
                $endTime = empty($params['endDate']) ? time() : strtotime($params['endDate'] . ' 23:59:59');
                $wh['g.publish_time'] = ['between',[$startTime,$endTime]];
            }


            $page = $params['page'] ?? 1;
            $pageSize = $params['pageSize'] ?? 50;

            $model = new GoodsPublishMap();
            $count = $model->alias('m')
                ->join('goods g','m.goods_id=g.id','LEFT')
                ->join('ebay_title et','m.goods_id=et.goods_id','LEFT')
                ->where($wh)->count();

            $field = 'm.spu,m.goods_id,g.thumb,g.name,g.publish_time,g.category_id,et.title,et.create_id,et.update_id,
                et.create_time,et.update_time';
            $lists = $model->alias('m')->field($field)
                ->join('goods g','m.goods_id=g.id','LEFT')
                ->join('ebay_title et','m.goods_id=et.goods_id','LEFT')
                ->where($wh)->order('m.id desc')->page($page,$pageSize)->select();
            if (empty($lists)) {
                return ['result'=>true, 'data'=>[]];
            }

            //处理图片，英文标题，平台销售状态，时间日期，创建人，更新人
            $userIds = [];
            $goodsIds = [];

            foreach ($lists as $list) {
                $userIds[] = $list['create_id'];
                $userIds[] = $list['update_id'];
                $goodsIds[] = $list['goods_id'];
            }
            $langs = GoodsLang::where('lang_id',2)->whereIn('goods_id',$goodsIds)->column('title','goods_id');
            $usernames = User::whereIn('id',$userIds)->column('realname','id');

            $titleStores = EbayTitle::whereIn('goods_id',$goodsIds)->column('title','goods_id');

            foreach ($titleStores as &$titleStore) {
                $titleStore = json_decode($titleStore,true);
                if (!isset($titleStore['en'])) {
                    continue;
                }
                $titleStore = $this->helper->createTitle('en',$titleStore);
            }

            foreach ($lists as &$list) {
                $list['thumb'] = \app\goods\service\GoodsImage::getThumbPath($list['thumb']);
                $list['name'] .= "|".($langs[$list['goods_id']] ?? '');
                $list['title_store'] = $titleStores[$list['goods_id']] ?? '';
                $list['category_name'] = (new \app\goods\service\GoodsHelp())->mapCategory($list['category_id']);
                $list['create_name'] = $usernames[$list['create_id']] ?? '';
                $list['update_name'] = $usernames[$list['update_id']] ?? '';
                $list['platform_sale_status'] = (new \app\goods\service\GoodsHelp())->getPlatformForChannel($list['goods_id'],1) ? '可选上架' : '禁止上架';
                $list['publish_time'] = empty($list['publish_time'])? '' : date('Y-m-d',$list['publish_time']);
                $list['create_time'] = empty($list['create_time'])? '' : date('Y-m-d',$list['create_time']);
                $list['update_time'] = empty($list['update_time']) ? '' : date('Y-m-d',$list['update_time']);
            }

            return ['result'=>true, 'data'=>['list'=>$lists,'count'=>$count]];

        } catch (Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 获取单个商品标题库详情
     * @param $goodsId
     * @return array
     */
    public function titleDetail($goodsId)
    {
        try {
            //先查标题库
            $titleStore = EbayTitle::field('id,title')->where('goods_id',$goodsId)->find();
            $id = $titleStore['id'];
            $titleStore = $titleStore['title'];
            $titleStore = json_decode($titleStore,true);
            $this->helper->titleKeyIdToName($titleStore);
            //查原标题
            $wh['goods_id'] = $goodsId;
            $wh['lang_id'] = ['neq',1];
            $originTitles = GoodsLang::where($wh)->column('title','lang_id');
            $langIds = array_keys($originTitles);
            $langs = Lang::whereIn('id',$langIds)->column('title,name','id');
            $goods = Goods::get($goodsId);
            if (empty($goods)) {
                throw new Exception('获取产品信息失败');
            }
            $langNameTitles = [];
            foreach ($originTitles as $langId => $title) {
                $langNameTitles[$langs[$langId]['name']] = $title;
            }
            //标题库与原标题合并
            $titles = [];
            foreach ($langNameTitles as $k => $langNameTitle) {
//                list($name,$title) = explode('|',$k);
                $titles[$k]['originTitle'] = $langNameTitle;
                $titles[$k]['titleStore'] = $titleStore[$k]??'';
            }
            $data['id'] = $id;
            $data['spu'] = $goods->spu;
            $data['title_zh'] = $goods->name;
            $data['titles'] = $titles;
            return ['result'=>true,'data'=>$data];
        } catch (Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量获取标题详情
     * @param $goodsIds
     * @return array
     */
    public function titleDetails($goodsIds)
    {
        try {
            //先查标题库
            $titleStores = EbayTitle::whereIn('goods_id',$goodsIds)->column('id,title','goods_id');
            if (!empty($titleStores)) {
                foreach ($titleStores as &$titleStore) {
                    $tmpTitle = $titleStore['title'];
                    $tmpTitle = json_decode($tmpTitle, true);
                    if (empty($tmpTitle)) {
                        $titleStore['title'] = '';
                    } else {
                        $this->helper->titleKeyIdToName($tmpTitle);
                        $titleStore['title'] = $tmpTitle;
                    }
                }
            }
            //查原标题
            $originTitles = GoodsLang::field('title,lang_id,goods_id')->where('lang_id','neq',1)
                ->whereIn('goods_id',$goodsIds)->select();
            $goodsTitles = [];
            $langs = Lang::column('title,name','id');
            foreach ($originTitles as $originTitle) {
                $goodsTitles[$originTitle['goods_id']][$langs[$originTitle['lang_id']]['name']] = $originTitle['title'];
            }
            //查缩略图,spu,中文标题
            $goods = Goods::whereIn('id',$goodsIds)->column('thumb,spu,name','id');

            $titleDetails = [];
            foreach ($goodsTitles as $goodsId => $goodsTitle) {
                $titleDetails[$goodsId]['id'] = $titleStores[$goodsId]['id'] ?? null;
                $titleDetails[$goodsId]['goods_id'] = $goodsId;
                $titleDetails[$goodsId]['thumb'] = 'https://img.rondaful.com/'.$goods[$goodsId]['thumb'];
                $titleDetails[$goodsId]['spu'] = $goods[$goodsId]['spu'];
                $titleDetails[$goodsId]['title_zh'] = $goods[$goodsId]['name'];
                foreach ($goodsTitle as $langName => $gt) {
                    $titleDetails[$goodsId]['titles'][$langName]['originTitle'] = $gt;
                    $titleDetails[$goodsId]['titles'][$langName]['titleStore'] = $titleStores[$goodsId]['title'][$langName] ?? '';
                }
            }
            return ['result'=>true,'data'=>array_values($titleDetails)];

        } catch (Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 保存标题库
     * @param $titles
     * @param $goodsId
     * @param $id
     * @return array
     */
    public function saveTitleDetail($titles, $goodsId, $id)
    {
        try {
            $data = [['titles'=>$titles,'goods_id'=>$goodsId,'id'=>$id]];
            return $this->saveTitleDetails($data);
//            $langCodes = array_keys($titles);
//            $langCodeIds = Lang::whereIn('name',$langCodes)->column('id','name');
//            //整合关键词
//            foreach ($titles as $langName => &$title) {
//                foreach ($title as &$tl) {
//                    if (empty($tl)) {
//                        continue;
//                    }
//                    $tl = array_map(function($a) {
//                        return strtolower(trim($a));//统一大小写
//                    },$tl);
//                }
//                if (array_intersect($title['front'],$title['middle'],$title['back']) || array_intersect($title['middle'],$title['back'])) {
//                    throw new Exception('语言简码:'.$langName.',标题前中后部分有重复');
//                }
//                $keys[$langName] = array_merge($title['front'],$title['middle'],$title['back']);
//            }
//            //查关键词表是否存在，不存在的新增
//            $keyNameIds = [];
//            foreach ($keys as $k => $key) {
//                $nameIds = TitleKey::whereIn('name',$key)->where('lang_id',$langCodeIds[$k])->column('id','name');
//                $nameIds = array_change_key_case($nameIds,CASE_LOWER);
//                $names = array_keys($nameIds);
//                $insertKeys = array_diff($key,$names);
//                if (empty($insertKeys)) {//全部存在
//                    $keyNameIds[$k] = $nameIds;
//                    continue;
//                }
//                //打包插入
//                $newKeys = [];
//                $i = 0;
//                foreach ($insertKeys as $insertKey) {
//                    $newKeys[$i]['name'] = $insertKey;
//                    $newKeys[$i]['lang_id'] = $langCodeIds[$k];
//                    $newKeys[$i]['channel_id'] = 1;
//                    $i++;
//                }
//                (new TitleKey())->saveAll($newKeys,false);
//                //重新获取name id对
//                $nameIds = TitleKey::whereIn('name',$key)->where('lang_id',$langCodeIds[$k])->column('id','name');
//                $keyNameIds[$k] = $nameIds;
//            }
//
//            //将标题中的关键词替换为对应的id
//            $titleKeyMap = [];
//            $i = 0;
//            $fmb = ['front'=>0,'middle'=>1,'back'=>2];
//            foreach ($titles as $k => &$title) {//循环语种
//                foreach ($title as $p => &$t) {//循环位置
//                    foreach ($t as &$tt) {//循环关键词
//                        $tt = $keyNameIds[$k][$tt];
//                        $titleKeyMap[$i]['goods_id'] = $goodsId;
//                        $titleKeyMap[$i]['key_id'] = $tt;
//                        $titleKeyMap[$i]['position'] = $fmb[$p];
//                        $i++;
//                    }
//                }
//            }
//            //维护商品标题关键词映射表
//            $mapIds = GoodsTitleKeyMap::where('goods_id',$goodsId)->column('id');
//            $existCount = count($mapIds);
//            foreach ($mapIds as $index => $mapId) {
//                if (!isset($titleKeyMap[$index])) {
//                    break;
//                }
//                $titleKeyMap[$index]['id'] = $mapId;
//            }
//            if ($existCount !==0 && $index+1<$existCount) {
//                $delMapIds = array_slice($mapIds,$index);
//            }
//            try {
//                Db::startTrans();
//                if (empty(intval($id))) {//新增
//                    EbayTitle::create(['goods_id' => $goodsId, 'title' => json_encode($titles)]);
//                } else {
//                    EbayTitle::update(['title' => json_encode($titles)], ['id' => $id]);
//                }
//                (new GoodsTitleKeyMap())->saveAll($titleKeyMap);
//                !empty($delMapIds) && GoodsTitleKeyMap::destroy($delMapIds);
//                Db::commit();
//            } catch (\Exception $e) {
//                Db::rollback();
//                return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
//            }
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量保存标题详情
     * @param $data
     * @return array
     * @throws \Exception
     */
    public function saveTitleDetails($data)
    {
        $langCodeIds = Lang::column('id','name');
        //整合关键词
        $keys = [];
        $goodsIds = [];
        foreach ($data as $k => &$dt) {
            $goodsIds[] = $dt['goods_id'];
            if (isset($dt['id']) && empty($dt['id'])) {
                unset($dt['id']);
            }
            foreach ($dt['titles'] as $langName => &$title) {
//                $tmpTitle = $title;
                foreach ($title as &$tl) {
                    if (empty($tl)) {
                        continue;
                    }
                    $tl = array_map(function($a) {
                        return ucwords(trim($a));//去除空白字符并将首字母大写
                    },$tl);
                }

                if (array_intersect($title['front'],$title['middle'],$title['back']) || array_intersect($title['middle'],$title['back'])) {
                    return ['result'=>false,'message'=>'商品id:'.$dt['goods_id'].'语言简码:'.$langName.',标题前中后部分有重复,成功保存0条'];
                }
                $keys[$langName] = array_merge($keys[$langName]??[],$title['front'],$title['middle'],$title['back']);
            }
        }

        //查关键词表是否存在，不存在的新增
        $keyNameIds = [];
        foreach ($keys as $langName => &$key) {
            $key = array_unique($key);
            $nameIds = TitleKey::whereIn('name',$key)->where('lang_id',$langCodeIds[$langName])->column('id','name');
//            $nameIds = array_change_key_case($nameIds,CASE_LOWER);
            $names = array_keys($nameIds);
            $insertKeys = array_diff($key,$names);
            if (empty($insertKeys)) {//全部存在
                $keyNameIds[$langName] = $nameIds;
                continue;
            }
            //打包插入
            $newKeys = [];
            $i = 0;
            foreach ($insertKeys as $insertKey) {
                $newKeys[$i]['name'] = $insertKey;
                $newKeys[$i]['lang_id'] = $langCodeIds[$langName];
                $newKeys[$i]['channel_id'] = 1;
                $i++;
            }
            (new TitleKey())->saveAll($newKeys,false);
            //重新获取name id对
            $nameIds = TitleKey::whereIn('name',$key)->where('lang_id',$langCodeIds[$langName])->column('id','name');
            $keyNameIds[$langName] = $nameIds;
        }
        //将标题中的关键词替换为对应的id
        $titleKeyMap = [];
        $i = 0;
        $fmb = ['front'=>0,'middle'=>1,'back'=>2];
        foreach ($data as &$dt) {
            foreach ($dt['titles'] as $langName => &$title) {//循环语种
                foreach ($title as $p => &$t) {//循环位置
                    foreach ($t as &$tt) {//循环关键词
                        $tt = $keyNameIds[$langName][$tt];
                        $titleKeyMap[$i]['goods_id'] = $dt['goods_id'];
                        $titleKeyMap[$i]['key_id'] = $tt;
                        $titleKeyMap[$i]['position'] = $fmb[$p];
                        $i++;
                    }
                }
            }
            $dt['title'] = json_encode($dt['titles']);
        }

        //维护商品标题关键词映射表
        $mapIds = GoodsTitleKeyMap::whereIn('goods_id',$goodsIds)->column('id');
        $existCount = count($mapIds);
        foreach ($mapIds as $index => $mapId) {
            if (!isset($titleKeyMap[$index])) {
                break;
            }
            $titleKeyMap[$index]['id'] = $mapId;
        }
        if ($existCount !==0 && $index+1<$existCount) {
            $delMapIds = array_slice($mapIds,$index);
        }

        try {
            Db::startTrans();
            (new EbayTitle())->allowField(true)->saveAll($data);
            (new GoodsTitleKeyMap())->saveAll($titleKeyMap);
            !empty($delMapIds) && GoodsTitleKeyMap::destroy($delMapIds);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
        return ['result'=>true,'message'=>'成功保存'.count($data).'条'];
    }

    /**
     * 对范本标题随机排序
     * @param $data
     * @return array
     */
    public function randomDraftTitle($data)
    {
        try {
            $goodsIds = array_column($data,'goods_id');
            //获取标题库数据
            $titleStores = EbayTitle::whereIn('goods_id',$goodsIds)->column('title','goods_id');
            foreach ($titleStores as $goodsId => &$titleStore) {
                $titleStore = json_decode($titleStore,true);
                if (empty($titleStore)) {
                    unset($titleStores[$goodsId]);
                    continue;
                }
                $this->helper->titleKeyIdToName($titleStore);
            }
            //标题库没有数据的不做处理
//            $titleStoreGoodsIds = array_keys($titleStores);
//            $noTitleStoreGoodsIds = array_diff($goodsIds,$titleStoreGoodsIds);
//            $originTitles = GoodsLang::where('lang_id',2)->whereIn('goods_id',$noTitleStoreGoodsIds)->column('title','goods_id');
            foreach ($data as &$dt) {
                if (isset($titleStores[$dt['goods_id']]) && isset($titleStores[$dt['goods_id']]['en'])) {
                    $title = $titleStores[$dt['goods_id']]['en'];
                    $title = array_merge($title['front']??[],$title['middle']??[],$title['back']??[]);
                    shuffle($title);//随机排序
                    $title = implode(' ',$title);
                    $dt['title'] = $title;
                    $dt['user_id'] = $this->userId;
                    $dt['update_date'] = time();
                }

//                else if (isset($originTitles[$dt['goods_id']])) {
//                    $title = $originTitles[$dt['goods_id']];
//                } else {
//                    $title = '';
//                }

            }
            (new EbayListing())->saveAll($data);
            return ['result'=>true,'message'=>'成功操作'.count($data).'条数据'];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    public function getSuggestWord($query)
    {
        try {
            $titleKeys = TitleKey::where('name','like',trim($query).'%')->select();
            if ($titleKeys) {
                $titleKeys = collection($titleKeys)->toArray();
                $name = array_column($titleKeys,'name');
            }
            return ['result'=>true,'data'=>$name];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量下架
     * @param $itemIds
     * @return array
     */
    public function endItems($itemIds)
    {
        try {
            $itemIds = array_filter($itemIds,function($a) {
                if (intval($a) > 0) {
                    return true;
                }
                return false;
            });
            if (!$itemIds) {
                throw new Exception('没有需要下架的listing，请清缓存重载后重试');
            }
            $wh = [
                'item_id' => ['in',$itemIds],
                'draft' => 0,
                'listing_status' => ['in',EbayPublish::OL_PUBLISH_STATUS],
            ];
            $itemIds = EbayListing::where($wh)->column('account_id','item_id');
            $log = [
                'remark' => '手动下架',
                'new_val' => json_encode(['end_type'=>1]),
                'old_val' => '',
            ];
            $logs = [];
            foreach ($itemIds as $itemId => $accountId) {
                $tmpLog = $log;
                $tmpLog['item_id'] = $itemId;
                $tmpLog['account_id'] = $accountId;
                $logs[] = $tmpLog;
            }
            $models = (new EbayActionLog())->saveAll($logs);
            sleep(1);//防止数据同步延迟
            foreach ($models as $model) {
                try {
                    EbayApiApply::endItem($model);
                    $message[$model['item_id']] = '下架成功';
                } catch (\Throwable $e) {
                    $message[$model['item_id']] = $e->getMessage();
                }
            }
            return ['result'=>true,'message'=>$message];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getMessage()];
        }
    }

    /**
     * 获取listing列表
     * @param $params
     * @return array
     */
    public function listings($params)
    {
        $page = empty($params['page']) ? 1 : $params['page'];
        $size = empty($params['size']) ? 20 : $params['size'];

        $condition = $this->packCondition($params);
        $condition['page'] = $page;
        $condition['pageSize'] = $size;

        $field = 'l.id,item_id,l.goods_id,listing_type,application,l.spu,l.local_sku,l.sku,listing_sku,l.account_id,site,
            l.currency,l.variation,l.title,max_price,min_price,sold_quantity,watch_count,hit_count,start_date,end_date,
            listing_status,rule_id,listing_cate,create_date,update_date,listing_fee,timing,user_id,realname,img,
            quantity,listing_duration,l.start_price,l.buy_it_nowprice,l.reserve_price,l.draft_name,l.end_user_id';
        $listings = $this->doSearch($condition,$field);

        if (empty($listings)) {
            return ['result'=>true,'data'=>['count'=>0,'listings'=>[]]];
        }
        $count = $this->doCount($condition);


        $listings = collection($listings)->toArray();

        //spu物流属性
        $goodsIds = array_column($listings,'goods_id');
        $goodsIds = array_values(array_unique($goodsIds));
        $transportProperties = Goods::whereIn('id',$goodsIds)->column('transport_property','id');
        //创建人，更新人，下架人信息
        $createIds = array_column($listings,'realname');
        $updateIds = array_column($listings,'user_id');
        $endUserIds = array_column($listings,'end_user_id');
        $userIds = array_unique(array_merge($createIds,$updateIds,$endUserIds));
        $userIdRealname = User::whereIn('id',$userIds)->column('realname','id');
        //定时规则名称
        $ruleIds = array_unique(array_column($listings,'rule_id'));
        $ruleIdNames = EbayListingTiming::whereIn('id',$ruleIds)->column('timing_rule_name','id');
        //账号简称
        $accountIds = array_unique(array_column($listings,'account_id'));
        $accountIdCodes = EbayAccount::whereIn('id',$accountIds)->column('code','id');
        //错误信息
        $listingIds = array_column($listings,'id');
        $messages = EbayListingSetting::whereIn('id',$listingIds)->column('message','id');
        //站点信息
        $siteSymbol = EbaySite::column('symbol,time_zone','siteid');
        //侵权信息
        if (!isset($whGoodsTort)) {
            $tortGoodsIds = GoodsTortDescription::whereIn('goods_id',$goodsIds)->distinct(true)->column('goods_id');
        }

        foreach ($listings as &$listing) {
            $listing['account_code'] = isset($accountIdCodes[$listing['account_id']]) ? $accountIdCodes[$listing['account_id']] : '';
            $listing['create_name'] = isset($userIdRealname[$listing['realname']]) ? $userIdRealname[$listing['realname']] : '';
            $listing['update_name'] = isset($userIdRealname[$listing['user_id']]) ? $userIdRealname[$listing['user_id']] : '';
            $listing['end_user_name'] = isset($userIdRealname[$listing['end_user_id']]) ? $userIdRealname[$listing['end_user_id']] : '';
            $listing['rval_price'] = ($listing['min_price']==$listing['max_price'])?
                $listing['min_price']:($listing['min_price'].'-'.$listing['max_price']);
            $listing['listing_status_txt'] = EbayPublish::PUBLISH_STATUS_TXT[$listing['listing_status']];
            $listing['symbol'] = $siteSymbol[$listing['site']]['symbol'];
            $listing['timing_rule_name'] = isset($ruleIdNames[$listing['rule_id']]) ? $ruleIdNames[$listing['rule_id']] : '';
            $listing['site_timing'] = $listing['timing'] == 0 ? 0 : date('Y-m-d H:i:s',($listing['timing']+$siteSymbol[$listing['site']]['time_zone']));
            $listing['create_date'] = empty($listing['create_date']) ? '' : date('Y-m-d',$listing['create_date']);
            $listing['update_date'] = empty($listing['update_date']) ? '' : date('Y-m-d',$listing['update_date']);
            $listing['start_date'] = empty($listing['start_date']) ? '' : date('Y-m-d H:i:s',$listing['start_date']);
            $listing['end_date'] = empty($listing['end_date']) ? '' : date('Y-m-d H:i:s',$listing['end_date']);
            $listing['message'] = $messages[$listing['id']] ?? '';
            $listing['img'] = strpos($listing['img'],'http')===false ? 'https://img.rondaful.com/'.$listing['img'] : $listing['img'];
            $listing['transport_property_txt'] = isset($transportProperties[$listing['goods_id']]) ?
                (new \app\goods\service\GoodsHelp())->getProTransPropertiesTxt($transportProperties[$listing['goods_id']]) : '';
            $listing['tort_flag'] = (isset($whGoodsTort) || in_array($listing['goods_id'],$tortGoodsIds)) ? 1 : 0;
        }
        $res['listings'] = $listings;
        $res['count'] = $count;
        return ['result'=>true,'data'=>$res];
    }

    /**
     * 保存listing
     * @return array
     */
    public function saveListing($data)
    {
        $listings = [];
        !isset($data[0]) && $data = [$data];
        $errMsgs = [];
        $validate = new EbayListingValidate();
        foreach ($data as $k => $dt) {
            if (!($validate->checkListing($dt))) {
                $errMsgs[$k] = $validate->getError();
                continue;
            }
            $list = $dt['list'];
            $imgs = $dt['imgs'];
            $detail_imgs = $dt['detail_imgs'];
            $set = $dt['set'];

            //list
            if (empty($list['account_id']) || !is_numeric($list['site']) || empty($list['goods_id']) || empty($list['spu'])
                || empty($list['primary_categoryid']) || empty($list['paypal_emailaddress'])) {
                $errMsgs[] = '第'.$k.'条缺少必要字段';
                continue;
            }
            $siteName = EbaySite::where('siteid',$list['site'])->value('name');//站点名称
            $accountCode = EbayAccount::where('id',$list['account_id'])->value('code');//账号简称
            $errMsg = 'spu:'.$list['spu'].',站点：'.$siteName.',账号：'.$accountCode.',';//错误初始信息

            $isUpdate = 1;//默认更新

            //判断是否可以保存
            if (empty($list['id'])) {//新增
//                $whExist = [
//                    'account_id' => $list['account_id'],
//                    'goods_id' => $list['goods_id'],
//                    'title' => $list['title'],
//                    'listing_type' => $list['listing_type'],
//                    'site' => $list['site'],
//                    'location' => $list['location']
//                ];
//                try {
//                    $isExist = EbayListing::field('id')->where($whExist)->find();
//                } catch (\Exception $e) {
//                    $errMsgs[] = $errMsg.$e->getMessage();
//                    continue;
//                }
//                if ($isExist) {
//                    $errMsgs[] = $errMsg.'已存在一条相同listing,无法进行创建';
//                    continue;
//                }
                $isUpdate = 0;
            } else {
                $list['listing_status'] = EbayListing::where('id', $list['id'])->value('listing_status');
                if (is_null($list['listing_status'])) {
                    sleep(2);//避免数据库延迟出错
                    $list['listing_status'] = EbayListing::where('id', $list['id'])->value('listing_status');
                }
                if (in_array($list['listing_status'],EbayPublish::RO_PUBLISH_STATUS)) {
                    $errMsgs[] = $errMsg.'listing处于无法直接保存的状态。如果是导入，请检查是否带了范本id进行了更新操作';
                    continue;
                }
            }

            //可以保存

            $this->helper->formatList($list,$isUpdate,$this->userId);


            //set部分
            try {
                $set = $this->helper->formatDLSetToStore($set, []);
                isset($list['id']) && $set['id'] = $list['id'];
            } catch (\Exception $e) {
                $errMsgs[] = $errMsg.$e->getMessage();
                continue;
            }

            //变体部分
            if ($list['variation']) {
                $isUpdate && $idInfo['oldListId'] = $list['id'];
                $idInfo['account_id'] = $list['account_id'];
                $idInfo['goods_id'] = $list['goods_id'];
                $idInfo['assoc_order'] = $list['assoc_order'];
                $idInfo['userId'] = $this->userId;
                $idInfo['variationImg'] = $set['variation_image'];
                $variants = $dt['varians'];
                try {
                    $variants = $this->helper->formatDLVarToStore($variants, $isUpdate, $idInfo);
                } catch (\Exception $e) {
                    $errMsgs[] = $errMsg.$e->getMessage();
                    continue;
                }
                $list['max_price'] = $variants['maxPrice'];
                $list['min_price'] = $variants['minPrice'];
            } else {//单属性
                $tmpVar = [
                    'goods_id' => $list['goods_id'],
                    'v_sku' => $list['local_sku'],
                    'sku_id' => $list['sku_id']??0,
                    'v_price' => $list['start_price'],
                    'v_qty' => $list['quantity'],
                    'combine_sku' => $list['sku'],
                    'channel_map_code' => $list['listing_sku'],
                    'cost_price' => $list['cost_price']??0,
                    'adjusted_cost_price' => $list['adjusted_cost_price']??0,
                    'reserve_price' => $list['reserve_price']??0,
                    'buy_it_nowprice' => $list['buy_it_nowprice']??0,
                    'variation' => json_encode([]),
                ];
                if ($isUpdate) {//更新
                    $varId = EbayListingVariation::where('listing_id',$list['id'])->value('id');
                    !empty($varId) && $tmpVar['id'] = $varId;
                    $tmpVar['listing_id'] = $list['id'];
                }
                $variants = [
                    'update'=>[$tmpVar],
                    'del'=>[],
                ];
            }
            $needClear = 0;
            //图片
            EbayPublish::optionImgHost($imgs,'del');
            EbayPublish::optionImgHost($detail_imgs,'del');
            $accountCode = EbayAccount::where('id',$list['account_id'])->value('code');
            $allImgs = [
                'publishImgs' => $imgs,
                'detailImgs' => $detail_imgs,
                'skuImgs' => $variants['skuImgs']??[],
            ];

            $res = EbayPublish::formatImgs($allImgs,$accountCode,$list['id']??0,$set['variation_image']);
            if ($res['result'] === false) {
                $errMsgs[] = $errMsg.$res['message'];
                continue;
            }
            $listings[] = [
                'isUpdate' => $isUpdate,
                'list' => $list,
                'newImgs' => $res['data'],
                'set' => $set,
                'mappingspec' => $data['mappingspec']??[],
                'variants' => $variants??[],
            ];
        }
        if (!empty($errMsgs)) {
            return ['result'=>false,'message'=>json_encode($errMsgs,JSON_UNESCAPED_UNICODE)];
        }


        try {
            $mongoUpSet = [];
            $mongoInsertSet = [];
            Db::startTrans();
            foreach ($listings as $listing) {
                try {
                    $isUpdate = $listing['isUpdate'];
                    $list = $listing['list'];
                    $set = $listing['set'];
                    $newImgs = $listing['newImgs'];
                    $mappingspec = $listing['mappingspec'];
                    $variants = $listing['variants'];
                    $saveDraftFlag = 1;
                    if ($list['variation']) {//多属性
                        foreach ($variants['update'] as $variant) {
                            $combineSku = explode(',',$variant['combine_sku']);
                            if (count($combineSku)>1) {//组合
                                $saveDraftFlag = 0;
                                break;
                            }
                            $combineSku = explode(',',$combineSku[0]);
                            if (!isset($combineSku[1]) || $combineSku[1]>1) {//捆绑
                                $saveDraftFlag = 0;
                                break;
                            }
                        }
                    } else {//单属性
                        $combineSku = explode(',',$list['sku']);
                        if (count($combineSku)>1) {//组合
                            $saveDraftFlag = 0;
                        }
                        $combineSku = explode(',',$combineSku[0]);
                        if (!isset($combineSku[1]) || $combineSku[1]>1) {//捆绑
                            $saveDraftFlag = 0;
                        }
                    }
                    if (!in_array($list['location'],['HK','CN'])) {
                        $saveDraftFlag = 0;
                    }


                    $siteName = EbaySite::where('siteid',$list['site'])->value('name');//站点名称
                    $accountCode = EbayAccount::where('id',$list['account_id'])->value('code');//账号简称
                    $errMsg = 'spu:'.$list['spu'].',站点：'.$siteName.',账号：'.$accountCode.',';//错误初始信息

                    //处理mongoDb
                    $tmpSet = [
                        'description' => $set['description']??'',
                    ];
                    $set['description'] = '';


                    if ($isUpdate) {
                        $listingId = $list['id'];
                        (new EbayListing())->allowField(true)->isUpdate(true)->save($list);
                        $set['id'] = $listingId;
                        EbayListingSetting::update($set);
                        $tmpSet['id'] = (int)$listingId;
                        $mongoUpSet[] = $tmpSet;
                        //图片
                        if ($newImgs['del']) {
                            EbayListingImage::destroy($newImgs['del']);
                        }
                        $newImgs = $newImgs['update'];
                        foreach ($newImgs as &$newImg) {
                            $newImg['listing_id'] = $listingId;
                        }
                        (new EbayListingImage())->saveAll($newImgs);
                        //平台属性与本地属性的映射
                        if (!empty($mappingspec)) {
                            $oldMapIds = EbayListingMappingSpecifics::where('listing_id', $listingId)->column('id');
                            $reserveIds = [];
                            $mapSpec = [];
                            $i = 0;
                            foreach ($mappingspec as $dtMap) {
                                if (isset($dtMap['detail'])) {
                                    unset($dtMap['detail']);
                                }
                                if ($dtMap['is_check']) {//勾选了
                                    $mapSpec[$i]['listing_id'] = $listingId;
                                    $mapSpec[$i]['is_check'] = 1;
                                    $mapSpec[$i]['channel_spec'] = $dtMap['channel_spec'];
                                    $mapSpec[$i]['combine_spec'] = $dtMap['combine_spec'];
                                    if (isset($oldMapIds[$i])) {
                                        $mapSpec[$i]['id'] = $oldMapIds[$i];
                                        $reserveIds[] = $oldMapIds[$i];
                                    }
                                    $i++;
                                }
                            }
                            $delMapIds = array_diff($oldMapIds, $reserveIds);
                            if ($delMapIds) {
                                EbayListingMappingSpecifics::destroy($delMapIds);
                            }
                            (new EbayListingMappingSpecifics())->saveAll($mapSpec);
                        }
                        //变体
                        if (!empty($variants['update'])) {
                            (new EbayListingVariation())->saveAll($variants['update']);
                        }
                        if (!empty($variants['del'])) {
                            EbayListingVariation::destroy($variants['del']);
                        }
                    } else {
                        $ebayListingModel = new EbayListing();
                        $ebayListingModel->allowField(true)->save($list);
                        $listingId = $ebayListingModel->getLastInsID();
                        $set['id'] = $listingId;
                        EbayListingSetting::create($set);
                        $tmpSet['id'] = (int)$listingId;
                        $mongoInsertSet[] = $tmpSet;
                        $newImgs = $newImgs['update'];
                        foreach ($newImgs as &$newImg) {
                            $newImg['listing_id'] = $listingId;
                        }
                        (new EbayListingImage())->saveAll($newImgs);
                        if ($list['variation']) {
                            if (!empty($mappingspec)) {
                                $mapSpec = [];
                                $i = 0;
                                foreach ($mappingspec as $dtMap) {
                                    if ($dtMap['is_check']) {
                                        $mapSpec[$i]['listing_id'] = $listingId;
                                        $mapSpec[$i]['is_check'] = 1;
                                        $mapSpec[$i]['channel_spec'] = $dtMap['channel_spec'];
                                        $mapSpec[$i]['combine_spec'] = $dtMap['combine_spec'];
                                        $i++;
                                    }
                                }
                                (new EbayListingMappingSpecifics())->allowField(true)->saveAll($mapSpec);
                            }
                        }
                        $vars = $variants['update'];
                        foreach ($vars as &$var) {
                            $var['listing_id'] = $listingId;
                        }
                        (new EbayListingVariation())->saveAll($vars);
                    }

                    //维护映射表
                    if ($list['variation']) {
                        foreach ($variants['update'] as $variant) {
                            $variant['is_virtual_send'] = $list['is_virtual_send'];
                            $this->helper->maintainTableGoodsSkuMap($variant, $list['account_id'], $this->userId, $list['assoc_order'] ? false : true);
                        }
                    } else {
                        $this->helper->maintainTableGoodsSkuMap($list, $list['account_id'], $this->userId, $list['assoc_order'] ? false : true);
                    }
                    $lists[] = [
                        'id' => $listingId,
                        'account_id' => $list['account_id']
                    ];
                    //查询是否有对应范本存在
                    if ($saveDraftFlag) {
                        $map = [
                            'goods_id' => $list['goods_id'],
                            'site_id' => $list['site']
                        ];
                        if (!EbayDraft::where($map)->find()) {
                            $map['listing_id'] = $listingId;
                            EbayDraft::create($map);//第一条创建的默认作为范本
                        }
                    }
                } catch (\Exception $e) {
                    $errMsgs[] = $errMsg.$e->getMessage();
                }
            }
            if (!empty($errMsgs)) {
                Db::rollback();
                return ['result'=>false,'message'=>json_encode($errMsgs,JSON_UNESCAPED_UNICODE)];
            }
            Db::commit();
            if ($mongoInsertSet) {
                ELSMongo::insertAll($mongoInsertSet);
            }
            if ($mongoUpSet) {
                $collection = mongo('ebay_listing_setting');
                foreach ($mongoUpSet as $mus) {
                    $collection->updateOne(['id'=>$mus['id']],['$set'=>$mus],['upsert'=>true]);
                }
            }
            return ['result'=>true,'data'=>$lists??[]];
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false,'message'=>$errMsg.$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 复制listing并切换账号
     * @param $ids
     * @param $accountId
     * @param $paypal
     * @return array
     */
    public function cpListings($ids, $accountId=0, $paypal='')
    {
        try {
            //过滤掉账号未改变的
//            $validIds = EbayListing::where('account_id','neq',$accountId)->whereIn('id',$ids)->column('id');
            $validIds = $ids;
            $res = $this->helper->packageCopyListing($validIds,$this->userId,$accountId,$paypal);
            if ($res['result'] === false) {
                throw new Exception($res['message']);
            }
            $data = $res['data'];

            //执行保存
            try {
                $newListingIds = [];
                $mongoSet = [];
                Db::startTrans();
                foreach ($data as $dt) {
                    $listingId = (new EbayListing())->insertGetId($dt['list']);
                    $dt['set']['id'] = $listingId;
                    //处理描述
                    $tmpSet['description'] = $dt['set']['description'];
                    $tmpSet['id'] = (int)$listingId;
                    $mongoSet[] = $tmpSet;
                    unset($dt['set']['description']);
                    EbayListingSetting::create($dt['set']);

                    foreach ($dt['imgs'] as &$img) {
                        $img['listing_id'] = $listingId;
                    }
                    (new EbayListingImage())->saveAll($dt['imgs']);
                    if (!empty($dt['variants'])) {
                        foreach ($dt['variants'] as &$variant) {
                            $variant['listing_id'] = $listingId;
                        }
                        (new EbayListingVariation())->saveAll($dt['variants']);
                        if (!empty($dt['mappingspec'])) {
                            foreach ($dt['mappingspec'] as &$ms) {
                                $ms['listing_id'] = $listingId;
                            }
                            (new EbayListingMappingSpecifics())->saveAll($dt['mappingspec']);
                        }
                     }
                    //维护映射表
                    if ($dt['list']['variation']) {
                        foreach ($dt['variants'] as $variant) {
                            $variant['is_virtual_send'] = $dt['list']['is_virtual_send'];
                            $this->helper->maintainTableGoodsSkuMap($variant,$dt['list']['account_id'],$this->userId,$dt['list']['assoc_order']?false:true);
                        }
                    } else {
                        $this->helper->maintainTableGoodsSkuMap($dt['list'],$dt['list']['account_id'],$this->userId,$dt['list']['assoc_order']?false:true);
                    }
                    $newListingIds[] = $listingId;
                }
                Db::commit();
                if ($mongoSet) {
                    ELSMongo::insertAll($mongoSet);
                }
                $message = '成功复制'.count($data).'条,自动忽略了账号未改变的listing';
                return ['result'=>true,'message'=>$message,'ids'=>$newListingIds];
            } catch (\Exception $e) {
                Db::rollback();
                return ['result'=>false,'message'=>$e->getMessage()];
            }
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量检测刊登费用
     * @param $ids
     * @return array
     */
    public function checkPublishFee($ids,$data=[])
    {
        try {
            $fees = [];
            if ($ids) {
                foreach ($ids as $id) {
                    return EbayApiApply::verifyAddItem($id,[]);
                }
            } else {
                $validate = new EbayListingValidate();
//                $errMsg = [];
                foreach ($data as $dt) {
                    if (!($validate->checkListing($dt))) {
                        return ['result'=>false,'message'=> $validate->getError()];
                    }

                    $dt['variants'] = $dt['varians'] ?? [];
                    unset($dt['varians']);
                    return EbayApiApply::verifyAddItem(0,$dt);
                }
            }


//            if (empty($ids)) {
//                $listing = isset($data[0]) ? $data[0] : $data;
//                $ids = [$listing['list']['id']??'none'];
//                $single = true;
//            }
//            foreach ($ids as $id) {
//                if (empty($single)) {
//                    $res = $this->helper->getListing($id);
//                    if ($res['result'] === false) {
//                        $message = 'id:' . $id . ' 的listing,' . $res['message'] . '；';
//                        $fees[$id] = ['result' => false, 'message' => $message];
//                        continue;
//                    }
//                    $listing = $res['data'];
//                } else {
//                    $list = $listing['list'];
//                    $this->helper->formatList($list,false,$this->userId);
//                    $listing['list'] = $list;
//                    $set = $listing['set'];
//                    $listing['set'] = $this->helper->formatDLSetToStore($set,[]);
//                    //变体部分
//                    if ($list['variation']) {
//                        $idInfo['account_id'] = $list['account_id'];
//                        $idInfo['assoc_order'] = $list['assoc_order'];
//                        $idInfo['userId'] = $this->userId;
//                        !empty($list['id']) && $idInfo['oldListId'] = $list['id'];
//                        $idInfo['variationImg'] = $listing['set']['variation_image'];
//                        $variants = $listing['varians'];
//                        try {
//                            $variants = $this->helper->formatDLVarToStore($variants, empty($list['id'])?0:1, $idInfo);
//                        } catch (\Exception $e) {
//                            return ['result'=>false,'message'=>$e->getMessage()];
//                        }
//                        $listing['varians'] = $variants['update'];
//                        foreach ($listing['varians'] as &$varian) {//清空变体图片
//                            $varian['path'] = null;
//                        }
//                    }
//                    //将图片重新组合成数据库存储格式
//                    $publishImgs = $listing['imgs'];
//                    $detailImgs = $listing['detail_imgs'];
//                    EbayPublish::optionImgHost($publishImgs,'del');
//                    EbayPublish::optionImgHost($detailImgs,'del');
//                    $accountCode = EbayAccount::where('id',$list['account_id'])->value('code');
//                    $imgs = [
//                        'publishImgs' => [$publishImgs[0]],
//                        'detailImgs' => [$detailImgs[0]??''],
//                    ];
//                    $res = EbayPublish::formatImgs($imgs,$accountCode,0);
//                    if ($res['result'] === false) {
//                        $fees[$id] = ['result'=>false,'message'=>$res['message']];
//                        continue;
//                    }
//                    $listing['imgs'] = $res['data']['update'];
//                }
//
//                $accountInfo = EbayAccount::get($listing['list']['account_id']);
//                if (empty($accountInfo)) {
//                    $message = 'id:'.$id.' 的listing,获取账号信息失败；';
//                    $fees[$id] = ['result'=>false,'message'=>$message];
//                    continue;
//                }
//                $accountInfo = $accountInfo->toArray();
//                $verb = $listing['list']['listing_type'] == 1 ? 'VerifyAddFixedPriceItem' : 'VerifyAddItem';
//                //处理图片
//
//                //检测刊登仅上传一张图片
//                $res = (new EbayPackApi())->uploadImgsToEps($listing['imgs'],$accountInfo,$listing['list']['site']);
//                if ($res['result'] === false) {
//                    $fees[$id] = ['result'=>false,'message'=>$res['message']];
//                    continue;
//                }
//                //上传
//                $packApi = new EbayPackApi();
//                $api = $packApi->createApi($accountInfo,$verb,$listing['list']['site']);
//                $xml = $packApi->createXml($listing);
//                $response = $api->createHeaders()->__set('requesBody', $xml)->sendHttpRequest2();
//                $res = (new EbayDealApiInformation())->dealWithApiResponse($verb,$response,$listing);
//                if ($res['result'] === false) {
//                    $fees[$id] = ['result'=>false,'message'=>$res['message']];
//                    continue;
//                }
//                $fees[$id] = ['result'=>true,'data'=>$res['data']];
//            }
//            if (count($fees) == 1) {
//                $fees = array_values($fees);
//                $fees = $fees[0];
//                if ($fees['result'] === true) {
//                    $fees['data'] = [
//                        'insertion_fee' => $fees['data']['InsertionFee']??0,
//                        'listing_fee' => $fees['data']['ListingFee']??0
//                    ];
//                }
//                return $fees;
//            }
//            return ['result'=>true,'data'=>$fees];
        } catch (\Throwable $e) {
            return ['result'=>false, 'message'=>$e->getMessage()];
        }
    }

    /**
     * 批量删除
     * @param $ids
     * @return array
     */
    public function delListings($ids)
    {
        $enableDelListingStatus = [
            EbayPublish::PUBLISH_STATUS['noStatus'],
            EbayPublish::PUBLISH_STATUS['inPublishQueue'],
            EbayPublish::PUBLISH_STATUS['publishFail'],
            EbayPublish::PUBLISH_STATUS['ended'],
        ];
        $validIds = EbayListing::where(['id'=>['in',$ids],'listing_status'=>['in',$enableDelListingStatus]])->column('id');
        if (empty($validIds)) {
            return ['result'=>true,'message'=>'成功删除0条，自动过滤了无法进行删除的'];
        }
        $validIds = array_map(function($a) {
            return (int)$a;
        },$validIds);
        try {
            Db::startTrans();
            EbayListing::destroy(['id'=>['in',$validIds]]);
            EbayListingSetting::destroy(['id'=>['in',$validIds]]);
            EbayListingImage::destroy(['listing_id'=>['in',$validIds]]);
            EbayListingMappingSpecifics::destroy(['listing_id'=>['in',$validIds]]);
            EbayListingVariation::destroy(['listing_id'=>['in',$validIds]]);
            EbayDraft::destroy(['listing_id'=>['in',$validIds]]);
            Db::commit();
//            ELSMongo::destroy((['id'=>['in',$validIds]]));
            mongo('ebay_listing_setting')->deleteMany(['id'=>['$in'=>$validIds]]);
            return ['result'=>true,'message'=>'成功删除'.count($validIds).'条，自动过滤了无法进行删除的'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false,'message'=>$e->getMessage().';出现异常，未删除'];
        }


    }

    /**
     * 一键展开变体
     * @param $ids
     * @return array
     */
    public function spreadVariants($ids)
    {
        $statusTxt = ['未上架','上架','下架',];
        try {
            $idVariants = [];
            $validIds = EbayListing::whereIn('id',$ids)->where('variation',1)->column('id');
            if (!$validIds) {
                return['result'=>true,'data'=>[]];
            }
            $field = 'id,listing_id,path,thumb,channel_map_code,v_sku,combine_sku,variation,adjusted_cost_price,v_price,v_qty,sku_status';
            $variants = EbayListingVariation::field($field)->whereIn('listing_id',$validIds)->select();
            foreach ($variants as $variant) {

                $variant['path'] = empty($variant['path']) ? $variant['thumb'] : $variant['path'];
                $path = json_decode($variant['path'], true) ?? [];
                if (is_array($path)) {
                    foreach ($path as &$p) {
                        if (isset($p['base_url']) && isset($p['path']) && strpos($p['path'], 'http') === false) {
                            $p = $p['base_url'] . $p['path'];
                        } else {
                            if (is_array($p)) {
                                $p = array_values($p)[0];
                            }
                            if (strpos($p, 'http') === false) {
                                $p = 'https://img.rondaful.com/' . $p;
                            }
                        }
                    }
                }
                $variant['path'] = empty($path) ? [] : $path;
                $variant['img'] = $path[0]??'';
                $variant['sku_status_txt'] = $statusTxt[$variant['sku_status']]??'未知';
                $idVariants[$variant['listing_id']][] = $variant;
            }
            return ['result'=>true,'data'=>$idVariants];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 加入刊登队列
     * @param $ids
     */
    public function addPublishQueue($ids,$data)
    {
        $enableStatus = [EbayPublish::PUBLISH_STATUS['noStatus'],
            EbayPublish::PUBLISH_STATUS['publishFail'],
            EbayPublish::PUBLISH_STATUS['ended']
            ];
        if (empty($ids)) {
            $res = $this->saveListing($data);
            if ($res['result'] === false) {
                return $res;
            }
            $ids = [$res['data']];
        }
        $validIds = EbayListing::where(['id'=>['in',$ids],'listing_status'=>['in',$enableStatus]])->column('id');
        if (empty($validIds)) {
            return ['result'=>true,'message'=>'操作成功0条，自动过滤了不可加入队列的listing'];
        }
        $successIds = [];
        foreach ($validIds as $validId) {
            if ((new UniqueQueuer(EbayPublishItemQueuer::class))->push($validId)) {
                $successIds[] = $validId;
            }
        }
        if ($successIds) {
            EbayListing::update(['listing_status' => EbayPublish::PUBLISH_STATUS['inPublishQueue']], ['id' => ['in', $successIds]]);
        }
        return ['result'=>true,'message'=>'操作成功'.count($successIds).'条，未成功的请刷新后根据状态重新添加，如果提示成功的条数与实际操作的条数相同而状态未改变，可能是数据库同步延迟导致的，无需重新操作'];
    }

    /**
     * 批量设置账号
     * @param $ids
     * @param $accountId
     * @param $paypal
     * @return array
     */
    public function setAccount($ids, $accountId, $paypal,$copy)
    {
        try {
            if ($copy) {
                $res = $this->cpListings($ids,$accountId,$paypal);
                if ($res['result'] === true) {
                    sleep(2);//延迟2s,避免数据库同步延迟造成获取数据失败
                    $ids = $res['ids'];
                    $tmpRes = $this->listings(['id'=>$ids]);
                    $tmpRes['message'] = $res['message'];
                    $res = $tmpRes;
                }
                return $res;
            }
            $validIds = $this->helper->filterReadOnlyListingId($ids);
            if (empty($validIds)) {
                return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
            }
            $update = [
                'account_id' => $accountId,
                'paypal_emailaddress' => $paypal,
                'update_date' => time(),
                'user_id' => $this->userId
            ];
            EbayListing::update($update,['id'=>['in',$validIds]]);
            return ['result'=>true,'message'=>'成功更新了'.count($validIds).'条，自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 修改一口价及可售量
     * @param $data
     * @return array
     */
    public function setFixedPriceQty($data)
    {
        $ids = array_keys($data);
        $validIds = $this->helper->filterReadOnlyListingId($ids);
        if (empty($validIds)) {
            return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
        }
        $update['update_date'] = time();
        $update['user_id'] = $this->userId;
        try {
            Db::startTrans();
            $i = 0;
            foreach ($data as $id => $dt) {
                if (!in_array($id,$validIds)) {
                    continue;
                }
                if (count($dt) == 1) {//单属性
                    $update['min_price'] = $dt[0]['start_price'];
                    $update['max_price'] = $dt[0]['start_price'];
                    (new EbayListing())->allowField(true)->save(array_merge($dt[0],$update),['id'=>$id]);
                } else {//多属性
//                    $channelMapCodes = array_column($dt,'listing_sku');
//                    $varIds = EbayListingVariation::where('listing_id',$id)->whereIn('channel_map_code',$channelMapCodes)
//                        ->column('id','channel_map_code');
                    $variants = [];
                    $maxPrice = 0;
                    $minPrice = 0;
                    foreach ($dt as $d) {
                        $minPrice = empty($minPrice) ? $d['start_price'] : min($minPrice,$d['start_price']);
                        $maxPrice = empty($maxPrice) ? $d['start_price'] : max($maxPrice,$d['start_price']);
                        $variants[] = [
                            'v_price' => $d['start_price'],
                            'v_qty' => $d['quantity'],
                            'id' => $d['id'],
                        ];
                    }
                    $update['min_price'] = $minPrice;
                    $update['max_price'] = $maxPrice;
                    (new EbayListingVariation())->saveAll($variants);
                    EbayListing::update($update,['id'=>$id]);
                }
                $i++;
            }
            Db::commit();
            return ['result'=>true,'message'=>'成功操作'.$i.'条，自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量修改拍卖价
     * @param $data
     * @return array
     */
    public function setChinesePrice($data)
    {
        try {
            $ids = array_column($data,'id');
            $validIds = $this->helper->filterReadOnlyListingId($ids);
            if (empty($validIds)) {
                return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
            }
            $lists = [];
            foreach ($data as $dt) {
                if (!in_array($dt['id'],$validIds)) {
                    continue;
                }
                $dt['update_date'] = time();
                $dt['user_id'] = $this->userId;
                $lists[] = $dt;
            }
            (new EbayListing())->saveAll($lists);
            return ['result'=>true,'message'=>'成功更新了'.count($lists).'条,自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量修改标题
     * @param $data
     * @return array
     */
    public function setTitle($data)
    {
        //和修改拍卖价一样，不再重写
        return $this->setChinesePrice($data);
    }


    /**
     * 批量修改商铺分类
     * @param $data
     * @return array
     */
    public function setStoreCategory($data)
    {
        //和修改拍卖价一样，不再重写
        return $this->setChinesePrice($data);
    }


    /**
     * 获取listing刊登图
     * @param $ids
     * @return array
     */
    public function getPublishImgs($ids)
    {
        $data = [];
        try {
//            $lists = EbayListing::where('id',$ids)->column('spu,item_id','id');
//            foreach ($ids as $id) {
//                $res = $this->helper->listingImgVersionO2N($id);
//                if ($res['result'] === false) {
//                    throw new Exception('id:'.$id.',spu:'.($lists[$id]['spu']??'').' 的listing获取刊登图片失败');
//                }
//                $imgs = $res['data'];
//                $res = EbayPublish::seperateImgs($imgs);
//                if (isset($res['result'])) {
//                    throw new Exception('id:'.$id.',spu:'.($lists[$id]['spu']??'').' 的listing获取刊登图片失败');
//                }
//                $publishImgs = $res['publishImgs']??[];
//                $data[] = [
//                    'id' => $id,
//                    'item_id' => $lists[$id]['item_id']
//                ];
////                    $publishImgs;
//            }
//            return ['result'=>true,'data'=>$data];
        } catch (\Exception $e) {
            return ['result'=>false,'message'=>$e->getMessage()];
        }
    }

    /**
     * 批量设置刊登图片
     * @param $data
     * @return array
     */
    public function setPublishImgs($data)
    {
        try {
            $ids = array_column($data,'id');
            $validIds = $this->helper->filterReadOnlyListingId($ids);
            if (empty($validIds)) {
                return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
            }
            $accountIds = EbayListing::whereIn('id',$validIds)->column('account_id','id');
            $accountCodes = EbayAccount::whereIn('id',$accountIds)->column('code','id');
            $imgs = array_column($data,'imgs');

            $newImgs = [];
            $i = 0;
            $mainImgs = [];
            $usedIds = [];
            foreach ($imgs as $k => $img) {
                if (!in_array($ids[$k],$validIds)) {
                    continue;
                }
                $mainImgs[] = ['id'=>$ids[$k],'img'=>$img[0],'update_date'=>time(),'user_id'=>$this->userId];
                EbayPublish::optionImgHost($img,'del');
                $res = $this->helper->listingImgVersionO2N($ids[$k]);
                if ($res['result'] === false) {
                    throw new Exception($res['message']);
                }
                $res = EbayPublish::seperateImgs($res['data']);
                if (isset($res['result'])) {
                    throw new Exception($res['message']);
                }
                $oldPublishImgs = [];
                $oldPaths = [];
                foreach ($res['publishImgs'] as $k1 => $publishImg) {
                    $index = $publishImg['path']?:$publishImg['thumb'];
                    $oldPublishImgs[$index] = $publishImg;
                    $oldPaths[] = $index;
                }

                foreach ($img as $k2 => $path) {
                    $tmpImg = [];
                    if (in_array($path,$oldPaths)) {//旧图中有
                            $tmpImg = $oldPublishImgs[$path];
                            if (isset($tmpImg['id'])) {
                                $usedIds[] = $tmpImg['id'];
                            }
                            $tmpImg['path'] = $path;
                            $tmpImg['thumb'] = $path;
                            $tmpImg['sort'] = $k2;
                            $newImgs[$i] = $tmpImg;
                            $i++;
                    } else {//旧图中没有
                        $tmpImg['listing_id'] = $ids[$k];
                        $tmpImg['path'] = $path;
                        $tmpImg['thumb'] = $path;
                        $tmpImg['ser_path'] = \app\goods\service\GoodsImage::getThumbPath($path,0,0,
                            $accountCodes[$accountIds[$ids[$k]]]??'ahkies',true);
                        $tmpImg['sort'] = $k2;
                        $tmpImg['main'] = 1;
                        $newImgs[$i] = $tmpImg;
                        $i++;
                    }
                }
            }
            //执行保存
            try {
                Db::startTrans();
                //删除旧的
                EbayListingImage::destroy(['listing_id'=>['in',$validIds],'main'=>1,'id'=>['not in',$usedIds]]);
                (new EbayListingImage())->saveAll($newImgs);
                (new EbayListing())->saveAll($mainImgs);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                throw new Exception($e->getMessage());
            }
            return ['result'=>true,'message'=>'成功更新了'.count($validIds).'条,自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量修改分类属性
     * @param $data
     * @return array
     */
    public function setSpecifics($data)
    {
        try {
            $ids = array_column($data,'id');
            $validIds = $this->helper->filterReadOnlyListingId($ids);
            if (empty($validIds)) {
                return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
            }
            foreach ($data as &$dt) {
                if (!in_array($dt['id'],$validIds)) {
                    continue;
                }
                $dt['specifics'] = json_encode($dt['specifics']);
            }
            (new EbayListingSetting())->saveAll($data);
            $update = [
                'update_date' => time(),
                'user_id' => $this->userId
            ];
            EbayListing::update($update,['id'=>['in',$validIds]]);
            return ['result'=>true,'message'=>'成功更新了'.count($validIds).'条,自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 批量修改刊登天数
     * @param $data
     * @return array
     */
    public function setListingDuration($data)
    {
        //和修改拍卖价一样，不再重写
        return $this->setChinesePrice($data);
    }

//    /**
//     * 批量修改拍卖价
//     * @param $data
//     * @return array
//     */
//    public function setChineseListingDuration($data)
//    {
//        //和修改拍卖价一样，不再重写
//        return $this->setChinesePrice($data);
//    }

    /**
     * 批量应用公共模块
     * @param $ids
     * @param $modules
     * @return array
     */
    public function applyCommonModule($ids, $modules)
    {
        $validIds = $this->helper->filterReadOnlyListingId($ids);
        if (empty($validIds)) {
            return ['result'=>true,'message'=>'成功更新了0条,自动过滤了不可修改的listing'];
        }
        try {
            $data = $this->helper->applyCommonTemplate($modules);
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
        $list = $data['list'];
        $set = $data['set'];
        $list['update_date'] = time();
        $list['user_id'] = $this->userId;
        try {
            Db::startTrans();
            EbayListing::update($list,['id'=>['in',$validIds]]);
            EbayListingSetting::update($set,['id'=>['in',$validIds]]);
            Db::commit();
            return ['result'=>true,'message'=>'成功更新了'.count($validIds).'条,自动过滤了不可修改的listing'];
        } catch (\Exception $e) {
            Db::rollback();
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }


    /**
     * @param $id
     * @param $data
     * @return array
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public function publishImmediately($id)
    {
        $reponseCache = new EbayListingReponseCache();
        $cache = $reponseCache->getReponseCache($id);
        empty($cache) && $cache = [];

        $res = EbayApiApply::addItem($id,$this->userId);
        if (is_array($res)) {
            $cache['listing_status'] = EbayPublishHelper::PUBLISH_STATUS['publishSuccess'];
            $cache = array_merge($cache,$res);
        } else {
            $cache['listing_status'] = EbayPublishHelper::PUBLISH_STATUS['publishFail'];
            $cache['message'] = $res;
        }
        Cache::store('EbayListingReponseCache')->setReponseCache($id,$cache);

//        $res = $this->helper->getListing($id);
//        if ($res['result'] === false) {
//            sleep(2);//等待2s,避免数据库未同步出错
//            $res = $this->helper->getListing($id);
//            if ($res['result'] === false) {
//                $cache['listing_status'] = EbayPublishHelper::PUBLISH_STATUS['publishFail'];
//                $cache['message'] = $res['message'];
//                $reponseCache->setReponseCache($id,$cache);
//                try {
//                    EbayPublish::setListingStatus($id, 'publishFail', $res['message']);
//                } catch (\Exception $e) {
//                    //不处理
//                }
//                return $res;
//            }
//        }
//        $listing = $res['data'];
//        $list = $listing['list'];
//        //状态更新为在刊登中
//        EbayPublish::setListingStatus($list['id'],'publishing');
//        //写缓存
////        $cache['id'] = $list['id'];
////        Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//        $cache['listing_status'] = EbayPublish::PUBLISH_STATUS['publishFail'];
//
//        $accountInfo = EbayAccount::get($listing['list']['account_id']);
//        if (empty($accountInfo)) {
//            $cache['message'] = '获取账号信息失败';
//            Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//            EbayPublish::setListingStatus($list['id'],'publishFail','获取账号信息失败');
//            return ['result'=>false,'message'=>'获取账号信息失败'];
//        }
//        $accountInfo = $accountInfo->toArray();
//        $verb = $listing['list']['listing_type'] == 2 ? 'AddItem' : 'AddFixedPriceItem';
//        //处理图片
//        $imgs = $listing['imgs'];
//        $res = (new EbayPackApi())->uploadImgsToEps($imgs,$accountInfo,$listing['list']['site']);
//        try {
//            (new EbayListingImage())->saveAll($imgs);
//        } catch (\Exception $e) {
//            //不处理
//        }
//        if ($res['result'] === false) {
//            $cache['message'] = $res['message'];
//            Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//            EbayPublish::setListingStatus($list['id'],'publishFail',$res['message']);
//            return $res;
//        }
//        $listing['imgs'] = $imgs;
//        //上传数据
//        try {
//            $packApi = new EbayPackApi();
//            $api = $packApi->createApi($accountInfo, $verb, $listing['list']['site']);
//            $xml = $packApi->createXml($listing);
//            $response = $api->createHeaders()->__set('requesBody', $xml)->sendHttpRequest2();
//        } catch (\Exception $e) {
//            EbayPublish::setListingStatus($list['id'],'publishFail',$e->getMessage());
//            $cache['message'] = $e->getMessage();
//            Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//            return ['result'=>false,'message'=>$e->getMessage()];
//        }
//        $res = (new EbayDealApiInformation())->dealWithApiResponse($verb,$response, $listing['list']);
//        if ($res['result'] === false) {
//            EbayPublish::setListingStatus($list['id'],'publishFail',$res['message']);
//            //更新缓存
//            $cache['message'] = $res['message'];
//            Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//            return $res;
//        }
//        $update = $res['data'];
//        $update['listing_status'] = EbayPublish::PUBLISH_STATUS['publishSuccess'];
//        EbayListing::update($update,['id'=>$list['id']]);
//        $cache = array_merge($cache,$update);
//        Cache::store('EbayListingReponseCache')->setReponseCache($list['id'],$cache);
//        return ['result'=>true];
    }

    /**
     * 查询立即刊登结果
     * @param $ids
     * @return array
     */
    public function publishImmediatelyResult($ids)
    {
        try {
            $cacheObj = Cache::store('EbayListingReponseCache');
            $resultCache = [];
            foreach ($ids as $id) {
                $cache = $cacheObj->getReponseCache('id');
                if ($cache) {
                    $resultCache[$id] = $cache;
                } else {
                    $noCacheIds[] = $id;
                }
            }
            if (!empty($noCacheIds)) {
                $siteInfo = EbaySite::column('symbol,name','siteid');
                $field = 'id,listing_status,insertion_fee,listing_fee,item_id,spu,site,account_id,title';
                $lists = EbayListing::whereIn('id',$noCacheIds)->field($field)->select();
                if (!$lists) {
                    return ['result'=>true,'data'=>$resultCache];
                }
                $lists = collection($lists)->toArray();
                $accountIds = array_unique(array_column($lists,'account_id'));
                $accountCodes = EbayAccount::whereIn('id',$accountIds)->column('code','id');
                foreach ($lists as $list) {
                    $list['account_code'] = $accountCodes[$list['account_id']];
                    $list['site_name'] = $siteInfo[$list['site']]['name'];
                    $list['symbol'] = $siteInfo[$list['site']]['symbol'];
                    $resultCache[$list['id']] = $list;
                }
            }
            return ['result'=>true,'data'=>$resultCache];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }
    /**
     * 拉取指定item id的listing
     * @param $itemId
     * @return array
     */
    public function pullListingByItemId($itemId)
    {
        try {
            $itemId = trim($itemId);
            $res = $this->helper->getItem($itemId);
            if ($res['result'] === false) {
                return $res;
            }
            $res = $res['data'];
            if ($res['Ack'] == 'Failure') {
                $errMsg = $this->helper->dealEbayApiError($res);
                if (isset($errMsg[17])) {
                    return ['result'=>true,'message'=>'指定item id:'.$itemId.'的listing在平台上不存在，请检查'];
                }
                throw new Exception(json_encode($errMsg));
            }
            $listingHelper = new EbayListingCommonHelper();
            $listingData = $listingHelper->syncEbayListing($res['Item']);
            $listingHelper->syncListingData($listingData);
            $res = $this->listings(['item_id'=>$itemId]);
            if ($res['result'] === false) {
                throw new Exception($res['message']);
            }
            $data = $res['data']['listings'][0]??[];
            return ['result'=>true,'data'=>$data];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 自动补货设置
     * @param $ids
     * @param $replenish
     */
    public function replenish($ids, $replenish)
    {
        EbayListing::update(['replen'=>$replenish],['id'=>['in',$ids]]);
        return ['result'=>true,'message'=>'操作成功'.count($ids).'条'];
    }

    /**
     * 通过导入在线更新listing
     * @param $filepath
     * @return array
     */
    public function updateListingImport($filepath)
    {
        try {
            $fileContent = ImportExport::csvImport($filepath);
            $title = ['item_id','exloca', 'ss1','sc1','sac1','sextra1','ss2','sc2','sac2','sextra2','ss3','sc3','sac3','sextra3','iss1','isc1','isac1',
                'iss2','isc2','isac2','iss3','isc3','isac3','sku','quantity','price'];
            $assocFileContent = [];
            $titleLen = count($title);
            //为方便处理，转成关联数组
            foreach ($fileContent as $k => $fc) {
                if ($k == 0) {
                    continue;//第一行是标题，不处理
                }
                if (count($fc) < $titleLen) {
                    continue;
                }
                $assocFileContent[] = array_combine($title,$fc);
            }
            if (!$assocFileContent) {
                return ['message' => '文件格式不对，无法正确解析或文件是空的'];
            }
            $itemIdAr = array_unique(array_column($assocFileContent,'item_id'));
            $itemIdSite = EbayListing::whereIn('item_id',$itemIdAr)->column('site','item_id');
            //处理数据
            $shippingLog = [];//物流记录
            $priceQtyLog = [];//价格数量记录
            $common = [
                'listing_sku' => '',
                'account_id' => 0,
                'remark' => ''
            ];
            $lastItemId = '';//记录上次的item id
            $errItemId = 0;//记录错误的Item Id
            $message = [];
            $errFlag = 0;
            foreach ($assocFileContent as $row => $afc) {
                $afc = array_map(function ($a) {
                    return trim($a);
                },$afc);
                if ($afc['item_id']) {//item id不为空
                    $lastItemId = $afc['item_id'];
                    $shippingLog[$lastItemId] = $common;
                    $shippingLog[$lastItemId]['item_id'] = $lastItemId;
                    if (!isset($itemIdSite[(string)$lastItemId])) {
                        $message[] = [
                            'row' => $row+2,
                            'errMsg' => 'item_id:'.$lastItemId.' 的listing不存在',
                        ];
                        $errItemId = $lastItemId;
                        continue;
                    }
                    $shippingLog[$lastItemId]['site'] = $itemIdSite[(string)$lastItemId];
                    //组装物流
                    $shipping = [];
                    $internationalShipping = [];
                    $excludeLocation = [];

                    if ($afc['exloca']) {
                        $excludeLocation = explode('，', $afc['exloca']);
                    }

                    for ($i=0;$i<3;$i++) {
                        if ($afc['ss'.($i+1)]) {
                            $shipping[$i]['shipping_service'] = $afc['ss'.($i+1)];
                            $shipping[$i]['shipping_service_cost'] = (string)$afc['sc'.($i+1)];
                            $shipping[$i]['shipping_service_additional_cost'] = (string)$afc['sac'.($i+1)];
                            $shipping[$i]['extra_cost'] = $afc['sextra'.($i+1)]??0;
                        }
                        if ($afc['iss'.($i+1)]) {
                            $internationalShipping[$i]['shipping_service'] = $afc['iss'.($i+1)];
                            $internationalShipping[$i]['shipping_service_cost'] = (string)$afc['isc'.($i+1)];
                            $internationalShipping[$i]['shipping_service_additional_cost'] = (string)$afc['isac'.($i+1)];

                        }
                    }
                    $errFlag = 0;
                    //检测国内物流合法性
                    $validShippings = EbayTrans::where(['site'=>$itemIdSite[(string)$lastItemId], 'international_service'=>0])->column('shipping_service');
                    foreach ($shipping as $v) {
                        if (!in_array($v['shipping_service'], $validShippings)) {
                            $message[] = [
                                'row' => $row+2,
                                'errMsg' => '国内物流['.$v['shipping_service'].']与站点不匹配;',
                            ];
                            $errFlag = 1;
                        }
                    }
                    //检测国际物流合法性
                    $validInterShippings = EbayTrans::where(['site'=>$itemIdSite[(string)$lastItemId], 'international_service'=>1])->column('shipping_service');
                    foreach ($internationalShipping as $v) {
                        if (!in_array($v['shipping_service'], $validInterShippings)) {
                            $message[] = [
                                'row' => $row+2,
                                'errMsg' => '国际物流['.$v['shipping_service'].']与站点不匹配;',
                            ];
                            $errFlag = 1;
                        }
                    }
                    if ($shipping || $internationalShipping || $excludeLocation) {
                        $shipping && $tmpShipping['shipping'] = $shipping;
                        $excludeLocation && $tmpShipping['exclude_location'] = $excludeLocation;
                        $internationalShipping && $tmpShipping['international_shipping'] = $internationalShipping;
                        $shippingLog[$lastItemId]['new_val'] = json_encode($tmpShipping);
                        $shippingLog[$lastItemId]['old_val'] = '';
                        $shippingLog[$lastItemId]['cron_time'] = time() + 10;//延迟十秒避免此方法还未执行完毕就触发队列执行
                    } else {
                        unset($shippingLog[$lastItemId]);//不需要更新物流
                    }
                }
                if ($errFlag || $errItemId == $lastItemId) {
                    continue;
                }
                //组装价格数量
                if ($afc['sku'] && (is_numeric($afc['price']) || is_numeric($afc['quantity']))) {
                    $priceQtyLog[$row] = $common;
                    $priceQtyLog[$row]['item_id'] = $lastItemId;
                    $priceQtyLog[$row]['site'] = $itemIdSite[(string)$lastItemId];
                    $priceQtyLog[$row]['cron_time'] = date('Y-m-d H:i:s',time()+10);
                    $priceQtyLog[$row]['listing_sku'] = $afc['sku'];
                    is_numeric($afc['price']) && $priceQtyLog[$row]['start_price'] = $afc['price'];
                    is_numeric($afc['quantity']) && $priceQtyLog[$row]['quantity'] = $afc['quantity'];
                }
            }
            if (!empty($message)) {
                return ['result'=>false,'message'=>$message];
            }
            try {
                Db::startTrans();
                $m = new EbayListingService($this->userId);
                foreach ($shippingLog as $sl) {
                    $m->insertUpdata($sl);
                }
                $m->updatePriceQty($priceQtyLog);
                Db::commit();
                return ['result'=>true,'message'=>'操作成功，已加入更新队列'];
            } catch (\Exception $e) {
                Db::rollback();
                return ['result'=>false,'message'=>['row'=>0,'errMsg'=>$e->getMessage()]];
            }
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>['row'=>0,'errMsg'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()]];
        }
    }


    /**
     * 设置是否虚拟仓发货
     * @param $ids
     * @param $isVirtualSend
     * @throws Exception
     */
    public function setIsVirtualSend($ids, $isVirtualSend)
    {
        //获取单属性的平台SKU
        $listingSku = EbayListing::whereIn('id', $ids)->where('variation', 0)->column('listing_sku');
        //获取多属性的平台SKU
        $channelMapCode = EbayListingVariation::whereIn('listing_id', $ids)->column('channel_map_code');

        $skus = array_merge($listingSku, $channelMapCode);
        try {
            Db::startTrans();
            EbayListing::update(['is_virtual_send'=>$isVirtualSend,'user_id'=>$this->userId],['id'=>['in',$ids]]);
            GoodsSkuMap::update(['is_virtual_send'=>$isVirtualSend,'updater_id'=>$this->userId], ['channel_sku'=>['in',$skus],'channel_id'=>1]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new Exception($e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }
    /*
     * 取消队列/定时刊登
     * @param $ids
     * @return int
     */
    public function cancelQueuePublish($ids)
    {
        //过滤掉状态不是在队列中的
        $validIds = EbayListing::whereIn('id',$ids)->where('listing_status',1)->column('id');
        //先更新状态为未刊登，去除定时规则
        $update = [
            'listing_status' => 0,
            'rule_id' => 0,
            'timing' => 0
        ];
        EbayListing::update($update,['id'=>['in',$validIds]]);
        foreach ($validIds as $validId) {
            (new UniqueQueuer(EbayPublishItemQueuer::class))->remove((string)$validId);
        }
        //返回实际执行的条目数
        return count($validIds);
    }
    /**
     * 在线数据导出
     * @param array $params  搜索条件
     * @param $type int 导出类型，0，正常导出；1，队列导出
     * @throws Exception
     */
    public function onlineExport($params, $type=0)
    {
        $header = [
            ['title' => 'ItemID', 'width' => 20, 'key' => 'item_id'],
            ['title' => 'eBay账号', 'width' => 20, 'key' => 'account_name'],
            ['title' => '平台', 'width' => 20, 'key' => 'site_name'],
            ['title' => '主SKU', 'width' => 20, 'key' => 'listing_sku'],
            ['title' => 'SKU', 'width' => 20, 'key' => 'channel_map_code'],
            ['title' => '商品状态', 'width' => 20, 'key' => 'sale_status_txt'],
            ['title' => '刊登分类1', 'width' => 20, 'key' => 'primary_category_chain'],
            ['title' => '分类编号1', 'width' => 20, 'key' => 'primary_categoryid'],
            ['title' => '刊登分类2', 'width' => 20, 'key' => 'second_category_chain'],
            ['title' => '分类编号2', 'width' => 20, 'key' => 'second_categoryid'],
            ['title' => 'Listing分类', 'width' => 20, 'key' => 'listing_cate'],
            ['title' => '商品所在地', 'width' => 20, 'key' => 'location'],
            ['title' => '售价', 'width' => 20, 'key' => 'start_price'],
            ['title' => '刊登方式(天)', 'width' => 20, 'key' => 'listing_type_duration'],
            ['title' => '浏览量', 'width' => 20, 'key' => 'hit_count'],
            ['title' => '收藏量', 'width' => 20, 'key' => 'watch_count'],
            ['title' => '刊登日期', 'width' => 20, 'key' => 'start_date'],
            ['title' => '结束时间', 'width' => 20, 'key' => 'end_date'],
            ['title' => '标题', 'width' => 20, 'key' => 'title'],
            ['title' => '副标题', 'width' => 20, 'key' => 'sub_title'],
            ['title' => '收款PayPal帐号', 'width' => 20, 'key' => 'paypal_emailaddress'],
            ['title' => '在线数量', 'width' => 20, 'key' => 'quantity'],
            ['title' => '售出数量', 'width' => 20, 'key' => 'sold_quantity'],
            ['title' => '店铺分类1', 'width' => 20, 'key' => 'store_category_id'],
            ['title' => '店铺分类2', 'width' => 20, 'key' => 'store_category2_id'],
            ['title' => 'ebay促销', 'width' => 20, 'key' => 'promotion'],
            ['title' => '物品描述', 'width' => 20, 'key' => 'condition_description'],
            ['title' => '境内物流1', 'width' => 20, 'key' => 'shipping_service1'],
            ['title' => '境内运费1', 'width' => 20, 'key' => 'shipping_service_cost1'],
            ['title' => '境内运费1续', 'width' => 20, 'key' => 'shipping_service_additional_cost1'],
            ['title' => '境内物流2', 'width' => 20, 'key' => 'shipping_service2'],
            ['title' => '境内运费2', 'width' => 20, 'key' => 'shipping_service_cost2'],
            ['title' => '境内运费2续', 'width' => 20, 'key' => 'shipping_service_additional_cost2'],
            ['title' => '境内物流3', 'width' => 20, 'key' => 'shipping_service3'],
            ['title' => '境内运费3', 'width' => 20, 'key' => 'shipping_service_cost3'],
            ['title' => '境内运费3续', 'width' => 20, 'key' => 'shipping_service_additional_cost3'],
            ['title' => '境内物流4', 'width' => 20, 'key' => 'shipping_service4'],
            ['title' => '境内运费4', 'width' => 20, 'key' => 'shipping_service_cost4'],
            ['title' => '境内运费4续', 'width' => 20, 'key' => 'shipping_service_additional_cost4'],
            ['title' => '境内物流5', 'width' => 20, 'key' => 'shipping_service5'],
            ['title' => '境内运费5', 'width' => 20, 'key' => 'shipping_service_cost5'],
            ['title' => '境内运费5续', 'width' => 20, 'key' => 'shipping_service_additional_cost5'],
            ['title' => '境外物流1', 'width' => 20, 'key' => 'international_shipping_service1'],
            ['title' => '境外运费1', 'width' => 20, 'key' => 'international_shipping_service_cost1'],
            ['title' => '境外运费1续', 'width' => 20, 'key' => 'international_shipping_service_additional_cost1'],
            ['title' => '境外物流2', 'width' => 20, 'key' => 'international_shipping_service2'],
            ['title' => '境外运费2', 'width' => 20, 'key' => 'international_shipping_service_cost2'],
            ['title' => '境外运费2续', 'width' => 20, 'key' => 'international_shipping_service_additional_cost2'],
            ['title' => '境外物流3', 'width' => 20, 'key' => 'international_shipping_service3'],
            ['title' => '境外运费3', 'width' => 20, 'key' => 'international_shipping_service_cost3'],
            ['title' => '境外运费3续', 'width' => 20, 'key' => 'international_shipping_service_additional_cost3'],
            ['title' => '境外物流4', 'width' => 20, 'key' => 'international_shipping_service4'],
            ['title' => '境外运费4', 'width' => 20, 'key' => 'international_shipping_service_cost4'],
            ['title' => '境外运费4续', 'width' => 20, 'key' => 'international_shipping_service_additional_cost4'],
            ['title' => '境外物流5', 'width' => 20, 'key' => 'international_shipping_service5'],
            ['title' => '境外运费5', 'width' => 20, 'key' => 'international_shipping_service_cost5'],
            ['title' => '境外运费5续', 'width' => 20, 'key' => 'international_shipping_service_additional_cost5'],
            ['title' => '备货时间', 'width' => 20, 'key' => 'dispatch_max_time'],
            ['title' => '主图链接', 'width' => 20, 'key' => 'img'],
            ['title' => '退货时间', 'width' => 20, 'key' => 'return_time'],
            ['title' => '橱窗类型', 'width' => 20, 'key' => 'picture_gallery'],
            ['title' => '国家', 'width' => 20, 'key' => 'country'],
            ['title' => 'UPC', 'width' => 20, 'key' => 'upc'],
            ['title' => 'MPN', 'width' => 20, 'key' => 'mpn'],
            ['title' => 'Brand', 'width' => 20, 'key' => 'brand'],
        ];
        try {
            //组装搜索条件
            $condition = $this->packCondition($params);

            //查询字段(主表)，根据需求变动
            $field = 'l.id,l.item_id,l.application,l.spu,l.listing_sku,l.account_id,l.site,l.currency,l.variation,l.title,l.sub_title,l.
                sold_quantity,l.watch_count,l.hit_count,l.start_date,l.end_date,l.listing_cate,l.img,l.quantity,l.listing_duration,l.
                start_price,l.sale_status,l.primary_categoryid,l.second_categoryid,l.location,l.paypal_emailaddress,l.store_category_id,l.
                store_category2_id,l.is_promotion,l.dispatch_max_time,l.return_time,l.picture_gallery,l.country,l.listing_type,l.goods_id';

            //先查询数量
            $count = $this->doCount($condition);
            if ($count == 0) {
                return ['message'=>'没有查询到需要导出的数据'];
            }

            if (!$type && $count>500) {//正常导出时，如果总数量大于500条，走队列
                $model = new ReportExportFiles();
                $data['applicant_id'] = $this->userId;
                $data['apply_time'] = time();
                $data['export_file_name'] = '在线Listing数据导出-'.date('YmdHis');
                $data['status'] = 0;
                $data['applicant_id'] = $this->userId;
                $model->allowField(true)->isUpdate(false)->save($data);
                $condition['file_name'] = $data['export_file_name'];
                $condition['apply_id'] = $model->id;
                $condition['export_type'] = 0;//与修改在线数据导出进行区分，因为二者用的是同一个队列
                (new CommonQueuer(EbayListingExportQueue::class))->push($condition);
                $message = '导出任务添加成功，请到报表导出管理处下载csv';
                return ['message'=>$message];
            }

            $listings = [];
            if (!$type) {//正常导出，数据不大于500条，直接获取全部数据
                $listings = $this->doSearch($condition,$field);
                $condition['file_name'] = '在线Listing数据导出-'.date('YmdHis');
            } else {//加入队列后触发了队列执行导出
                set_time_limit(0);
                //分批导出
                $pageSize = 500;
                $loop = ceil($count/$pageSize);
                $condition['pageSize'] = $pageSize;
                for ($i=0; $i<$loop;$i++) {
                    $condition['page'] = $i+1;
                    $tmpListing = $this->doSearch($condition,$field);
                    $tmpListing = $tmpListing ?: [];
                    $listings = array_merge($listings,$tmpListing);
                }
            }
            if (!$listings) {
                return ['message' => '没有查询到需要导出的数据'];
            }
            $listings = collection($listings)->toArray();

            $accountIds = [];
            $variationListingIds = [];
            $goodIds = [];
            foreach ($listings as $listing) {
                $accountIds[] = $listing['account_id'];
                $goodIds[] = $listing['goods_id'];
                if ($listing['variation']) {
                    $variationListingIds[] = $listing['id'];
                }
            }
            //批量查询账号简称
            $accounts = EbayAccount::whereIn('id',$accountIds)->column('account_name,code','id');
            //商品本地状态
            $goods = Goods::whereIn('id',$goodIds)->column('sales_status,id','id');
            //站点信息
            $sites = EbaySite::column('country,name','siteid');
            //查询setting表数据
            $setField = 'id,brand,mpn,upc,international_shipping,shipping,condition_description';
            $settings = EbayListingSetting::whereIn('id',array_column($listings,'id'))->column($setField,'id');
            //查询变体表数据
            $varField = 'id,listing_id,v_price,v_qty,v_sold,upc,channel_map_code';
            $variants = EbayListingVariation::whereIn('listing_id',$variationListingIds)->field($varField)->select();
            $vars = [];
            if ($variants) {
                $variants = collection($variants)->toArray();
                foreach ($variants as $variant) {
                    $vars[$variant['listing_id']][] = $variant;
                }
            }
            //组装表格数据
            $data = [];
            $j = 0;
            $listVarCn = EbayConstants::LISTVAR_CN;
            foreach ($listings as $listing) {
                if (!isset($settings[$listing['id']])) {
                    continue;
                }
                $shipping = json_decode($settings[$listing['id']]['shipping'], true) ?? [];
                $internationalShipping = json_decode($settings[$listing['id']]['international_shipping'], true) ?? [];
                $goodsLocalStatus = $goods[$listing['goods_id']] ?? '';
                $goodsLocalStatus = empty($goodsLocalStatus) ? '' :  (new \app\goods\service\GoodsHelp())->sales_status[$goods[$listing['goods_id']]];
                $common = [
                    'item_id' => $listing['item_id'],
                    'account_name' => $accounts[$listing['account_id']]['account_name'],
                    'site_name' => $sites[$listing['site']]['country'],
                    'listing_sku' => $listing['listing_sku'],
                    'sale_status_txt' => $goodsLocalStatus,
                    'primary_category_chain' =>  $this->helper->getEbayCategoryChain($listing['primary_categoryid'], $listing['site']),
                    'primary_categoryid' => $listing['primary_categoryid'],
                    'second_category_chain' => $listing['second_categoryid'] ? $this->helper->getEbayCategoryChain($listing['second_categoryid'],$listing['site']) : '',
                    'second_categoryid' => $listing['second_categoryid'],
                    'listing_cate' => $listing['listing_cate'],
                    'location' => $listing['location'],
                    'listing_type_duration' => $listVarCn['listingType'][$listing['listing_type']].'('.
                        $listVarCn['listingDuration'][$listing['listing_duration']].')',
                    'hit_count' => $listing['hit_count'],
                    'watch_count' => $listing['watch_count'],
                    'start_date' => date('Y-m-d H:i:s', $listing['start_date']),
                    'end_date' => date('Y-m-d H:i:s', $listing['end_date']),
                    'title' => $listing['title'],
                    'sub_title' => $listing['sub_title'],
                    'paypal_emailaddress' => $listing['paypal_emailaddress'],
                    'store_category_id' => $listing['store_category_id'],
                    'store_category2_id' => $listing['store_category2_id'],
                    'promotion' => '',
                    'condition_description' => $settings[$listing['id']]['condition_description'],
                    'dispatch_max_time' => $listing['dispatch_max_time'].'天',
                    'img' => $listing['img'],
                    'return_time' => EbayConstants::LISTVAR_EN['returnTime'][$listing['return_time']]??'未知',
                    'picture_gallery' => $listVarCn['pictureGallery'][$listing['picture_gallery']],
                    'country' => $listing['country'],
                    'brand' => $settings[$listing['id']]['brand'],
                    'mpn' => $settings[$listing['id']]['mpn'],
                ];
                //境内物流
                for ($i=0; $i<5; $i++) {
                    $common['shipping_service'.($i+1)] = $shipping[$i]['shipping_service'] ?? '';
                    $common['shipping_service_cost'.($i+1)] = $shipping[$i]['shipping_service_cost'] ?? '';
                    $common['shipping_service_additional_cost'.($i+1)] = $shipping[$i]['shipping_service_additional_cost'] ?? '';
                }
                //境外物流
                for ($i=0; $i<5; $i++) {
                    $common['international_shipping_service'.($i+1)] = $internationalShipping[$i]['shipping_service'] ?? '';
                    $common['international_shipping_service_cost'.($i+1)] = $internationalShipping[$i]['shipping_service_cost'] ?? '';
                    $common['international_shipping_service_additional_cost'.($i+1)] = $internationalShipping[$i]['shipping_service_additional_cost'] ?? '';
                }
                //多属性
                if ($listing['variation'] && isset($vars[$listing['id']])) {
                    foreach ($vars[$listing['id']] as $var) {
                        $data[$j] = $common;
                        $data[$j]['channel_map_code'] = $var['channel_map_code'];
                        $data[$j]['start_price'] = $var['v_price'];
                        $data[$j]['quantity'] = $var['v_qty'];
                        $data[$j]['sold_quantity'] = $var['v_sold'];
                        $data[$j]['upc'] = $var['upc'];
                        $j++;
                    }
                } else {
                    $data[$j] = $common;
                    $data[$j]['channel_map_code'] = $listing['listing_sku'];
                    $data[$j]['start_price'] = $listing['start_price'];
                    $data[$j]['quantity'] = $listing['quantity'];
                    $data[$j]['sold_quantity'] = $listing['sold_quantity'];
                    $data[$j]['upc'] = $settings[$listing['id']]['upc'];
                    $j++;
                }
            }

            //数据打包完毕，进行导出
            $file = [
                'file_name' => $condition['file_name'],
                'file_extension' => 'csv',
                'file_code' => date('YmdHis').rand(100000,999999),
                'path' => 'ebay',
                'type' => 'ebay_publish_export',
            ];

            $res = CommonService::exportCsv($header, $data, $file,$type,1,$condition['apply_id']??0);
            if ($res === true) {
                return [
                    'status' => 1,
                    'message' => 'OK',
                    'file_code' => $file['file_code'],
                    'file_name' => $file['file_name'].'.'.$file['file_extension'],
                ];
            } else {
                throw new Exception($res);
            }

        } catch (\Exception $e) {
           throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 修改在线数据导出
     * @param $params
     * @param int $type
     * @throws Exception
     */
    public function onlineExportModify($params, $type=0)
    {
        $header = [
            ['title' => 'ItemID', 'width' => 20, 'key' => 'item_id'],
            ['title' => '屏蔽目的地', 'width' => 20, 'key' => 'exclude_location'],
            ['title' => '国内运输方式1', 'width' => 20, 'key' => 'shipping_service1'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'shipping_service_cost1'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'shipping_service_additional_cost1'],
            ['title' => 'AK,HI,PR额外收费', 'width' => 20, 'key' => 'extra_cost1'],
            ['title' => '国内运输方式2', 'width' => 20, 'key' => 'shipping_service2'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'shipping_service_cost2'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'shipping_service_additional_cost2'],
            ['title' => 'AK,HI,PR额外收费', 'width' => 20, 'key' => 'extra_cost2'],
            ['title' => '国内运输方式3', 'width' => 20, 'key' => 'shipping_service3'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'shipping_service_cost3'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'shipping_service_additional_cost3'],
            ['title' => 'AK,HI,PR额外收费', 'width' => 20, 'key' => 'extra_cost3'],
            ['title' => '国际运输方式1', 'width' => 20, 'key' => 'international_shipping_service1'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'international_shipping_service_cost1'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'international_shipping_service_additional_cost1'],
            ['title' => '国际运输方式2', 'width' => 20, 'key' => 'international_shipping_service2'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'international_shipping_service_cost2'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'international_shipping_service_additional_cost2'],
            ['title' => '国际运输方式3', 'width' => 20, 'key' => 'international_shipping_service3'],
            ['title' => '首件运费', 'width' => 20, 'key' => 'international_shipping_service_cost3'],
            ['title' => '续件运费', 'width' => 20, 'key' => 'international_shipping_service_additional_cost3'],
            ['title' => 'SKU', 'width' => 20, 'key' => 'channel_map_code'],
            ['title' => 'Quantity', 'width' => 20, 'key' => 'quantity'],
            ['title' => 'Price', 'width' => 20, 'key' => 'start_price'],
        ];
        try {

            //组装搜索条件
            $condition = $this->packCondition($params);

            //查询字段(主表)，根据需求变动
            $field = 'l.id,item_id,l.application,l.listing_sku,l.variation,l.quantity,l.start_price';

            $count = $this->doCount($condition);
            if ($count == 0) {
                return ['message'=>'没有查询到需要导出的数据'];
            }

            if (!$type && $count>500) {//正常导出时，如果总数量大于500条，走队列
                $model = new ReportExportFiles();
                $data['applicant_id'] = $this->userId;
                $data['apply_time'] = time();
                $data['export_file_name'] = '修改在线数据导出-'.date('YmdHis');
                $data['status'] = 0;
                $data['applicant_id'] = $this->userId;
                $model->allowField(true)->isUpdate(false)->save($data);
                $condition['file_name'] = $data['export_file_name'];
                $condition['apply_id'] = $model->id;
                $condition['export_type'] = 1;//与在线listing数据导出进行区分，因为二者用的是同一个队列
                (new CommonQueuer(EbayListingExportQueue::class))->push($params);
                $message = '导出任务添加成功，请到报表导出管理处下载csv';
                return ['message'=>$message];
            }

            $listings = [];
            if (!$type) {//正常导出，数据不大于500条，直接获取全部数据
                $listings = $this->doSearch($condition,$field);
                $condition['file_name'] = '修改在线数据导出-'.date('YmdHis');
            } else {//加入队列后触发了队列执行导出
                set_time_limit(0);
                //分批导出
                $pageSize = 500;
                $loop = ceil($count/$pageSize);
                $condition['pageSize'] = $pageSize;
                for ($i=0; $i<$loop;$i++) {
                    $condition['page'] = $i+1;
                    $tmpListing = $this->doSearch($condition,$field);
                    $tmpListing = $tmpListing ?: [];
                    $listings = array_merge($listings,$tmpListing);
                }
            }
            if (!$listings) {
                return ['message' => '没有查询到需要导出的数据'];
            }
            $listings = collection($listings)->toArray();

            $variationListingIds = [];
            foreach ($listings as $listing) {
                if ($listing['variation']) {
                    $variationListingIds[] = $listing['id'];
                }
            }
            //查询setting表数据
            $setField = 'id,international_shipping,shipping,exclude_location';
            $settings = EbayListingSetting::whereIn('id',array_column($listings,'id'))->column($setField,'id');
            //查询变体表数据
            $varField = 'id,listing_id,v_price,v_qty,channel_map_code';
            $variants = EbayListingVariation::whereIn('listing_id',$variationListingIds)->field($varField)->select();
            $vars = [];
            if ($variants) {
                $variants = collection($variants)->toArray();
                foreach ($variants as $variant) {
                    $vars[$variant['listing_id']][] = $variant;
                }
            }
            //组装表格数据
            $data = [];
            $j = 0;
            foreach ($listings as $listing) {
                if (!isset($settings[$listing['id']])) {
                    continue;
                }
                $shipping = json_decode($settings[$listing['id']]['shipping'], true) ?? [];
                $internationalShipping = json_decode($settings[$listing['id']]['international_shipping'], true) ?? [];
                $excludeLocation = json_decode($settings[$listing['id']]['exclude_location'],true) ?? [];
                $excludeLocation = empty($excludeLocation) ? '' : implode('，', $excludeLocation);

                $common = [
                    'item_id' => $listing['item_id'],
                    'exclude_location' => $excludeLocation,
                ];
                $subCommon = [
                    'item_id' => '',
                    'exclude_location' => '',
                ];
                //境内物流
                for ($i=0; $i<3; $i++) {
                    $common['shipping_service'.($i+1)] = $shipping[$i]['shipping_service'] ?? '';
                    $common['shipping_service_cost'.($i+1)] = $shipping[$i]['shipping_service_cost'] ?? '';
                    $common['shipping_service_additional_cost'.($i+1)] = $shipping[$i]['shipping_service_additional_cost'] ?? '';
                    $common['extra_cost'.($i+1)] = $shipping[$i]['extra_cost'] ?? '';
                    $subCommon['shipping_service'.($i+1)] = '';
                    $subCommon['shipping_service_cost'.($i+1)] = '';
                    $subCommon['shipping_service_additional_cost'.($i+1)] = '';
                    $subCommon['extra_cost'.($i+1)] = '';
                }
                //境外物流
                for ($i=0; $i<3; $i++) {
                    $common['international_shipping_service'.($i+1)] = $internationalShipping[$i]['shipping_service'] ?? '';
                    $common['international_shipping_service_cost'.($i+1)] = $internationalShipping[$i]['shipping_service_cost'] ?? '';
                    $common['international_shipping_service_additional_cost'.($i+1)] = $internationalShipping[$i]['shipping_service_additional_cost'] ?? '';
                    $subCommon['international_shipping_service'.($i+1)] = '';
                    $subCommon['international_shipping_service_cost'.($i+1)] = '';
                    $subCommon['international_shipping_service_additional_cost'.($i+1)] = '';
                }
                //多属性
                if ($listing['variation'] && isset($vars[$listing['id']])) {
                    foreach ($vars[$listing['id']] as $k => $var) {
                        $data[$j] = $k==0 ? $common : $subCommon;
                        $data[$j]['channel_map_code'] = $var['channel_map_code'];
                        $data[$j]['start_price'] = $var['v_price'];
                        $data[$j]['quantity'] = $var['v_qty'];
                        $j++;
                    }
                } else {
                    $data[$j] = $common;
                    $data[$j]['channel_map_code'] = $listing['listing_sku'];
                    $data[$j]['start_price'] = $listing['start_price'];
                    $data[$j]['quantity'] = $listing['quantity'];
                    $j++;
                }
            }

            //数据打包完毕，进行导出
            $file = [
                'file_name' => $condition['file_name'],
                'file_extension' => 'csv',
                'file_code' => date('YmdHis').rand(100000,999999),
                'path' => 'ebay',
                'type' => 'ebay_publish_export',
            ];

            $res = CommonService::exportCsv($header, $data, $file,$type,1,$condition['apply_id']??0);
            if ($res === true) {
                return [
                    'status' => 1,
                    'message' => 'OK',
                    'file_code' => $file['file_code'],
                    'file_name' => $file['file_name'].'.'.$file['file_extension'],
                ];
            } else {
                throw new Exception($res);
            }
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包搜索条件
     * @param $params
     * @return array
     */
    public function packCondition($params)
    {
        $order = '';
        $whVar = [];
        $wh = [];
        $whGoods = [];
        foreach ($params as $key => $value) {
            if (trim($value) == '') {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            switch ($key) {
                case 'id':
                    $wh['l.id'] = ['in',$value];
                    break;
                case 'ids':
                    $ids = json_decode($value,true);
                    $wh['l.id'] = ['in',$ids];
                    break;
                case 'item_id':
                    $itemIds = json_decode($value,true);
                    if (is_null($itemIds)) {
                        $wh['item_id'] = ['exp','is null'];
                    } elseif (is_string($itemIds)|| is_numeric($itemIds) && !empty($itemIds)) {
                        $wh['item_id'] = $itemIds;
                    } elseif (is_array($itemIds) && count($itemIds)==1 && !empty($itemIds)) {
                        $wh['item_id'] = $itemIds[0];
                    } elseif (is_array($itemIds) && count($itemIds)>1) {
                        foreach ($itemIds as $k => $itemId) {
                            if (empty($itemId)) {
                                unset($itemIds[$k]);
                            }
                        }
                        $wh['item_id'] = ['in',array_values($itemIds)];
                    }
                    break;
                case 'spu'://本地spu
                    $spus = json_decode($value,true);
                    if (is_null($spus)) {//不是json格式，但是有值，直接进行模糊搜索
                        $tmpWh['spu'] = ['like',$value.'%'];
                    } else if (is_array($spus) && count($spus)==1) {//仅有一个值时，支持模糊
                        $tmpWh['spu'] = ['like',$spus[0].'%'];
                    } else if (is_array($spus) && count($spus)>1) {//多个值时，支持批量
                        $tmpWh['spu'] = ['in',$spus];
                    }
                    if (isset($tmpWh['spu'])) {
                        $goodsIds = Goods::where($tmpWh)->column('id');
                        $wh['l.goods_id'] = empty($goodsIds)?['exp','is null']:['in', $goodsIds];
                    }
                    break;
                case 'listing_sku'://平台SKU,支持批量，模糊
                    $listingSkus = json_decode($value,true);
                    if (is_null($listingSkus)) {//不是json格式，但是有值，直接进行模糊搜索
//                        $whSub['listing_sku'] = ['like',$value.'%'];
                        $whVar['v.channel_map_code'] = ['like',$value.'%'];
                    } else if (is_array($listingSkus) && count($listingSkus)==1) {//仅有一个值时，支持模糊
//                        $whSub['listing_sku'] = ['like',$listingSkus[0].'%'];
                        $whVar['v.channel_map_code'] = ['like',$listingSkus[0].'%'];
                    } else if (is_array($listingSkus) && count($listingSkus)>1) {//多个值时，支持批量
//                        $whSub['listing_sku'] = ['in',$listingSkus];
                        $whVar['v.channel_map_code'] = ['in',$listingSkus];
                    }
                    break;
                case 'sku'://本地SKU
                    $skus = json_decode($value,true);
                    if (is_null($skus)) {//不是json格式，但是有值，直接进行模糊搜索
                        $tmpWh['sku'] = ['like',$value.'%'];
                    } else if (is_array($skus) && count($skus)==1) {//仅有一个值时，支持模糊
                        $tmpWh['sku'] = ['like',$skus[0].'%'];
                    } else if (is_array($skus) && count($skus)>1) {//多个值时，支持批量
                        $tmpWh['sku'] = ['in',$skus];
                    }
                    if (isset($tmpWh['sku'])) {
                        $goodsIds = GoodsSku::distinct(true)->where($tmpWh)->column('goods_id');
                        $wh['l.goods_id'] = empty($goodsIds)?['exp','is null']:['in', $goodsIds];
                    }
                    break;
                case 'title'://标题
                    $titles = json_decode($value,true);
                    if (is_null($titles)) {//不是json格式，但是有值，直接进行模糊搜索
                        $wh['l.title'] = ['like',$value.'%'];
                    } else if (is_array($titles) && count($titles)==1) {//仅有一个值时，支持模糊
                        $wh['l.title'] = ['like',$titles[0].'%'];
                    } else if (is_array($titles) && count($titles)>1) {//多个值时，支持批量
                        $wh['l.title'] = ['in',$titles];
                    }
                    break;
                case 'account_id'://账号id
                case 'site'://站点
                case 'listing_type'://出售方式
                case 'listing_duration'://上架时间
                case 'replen'://自动补货
                case 'goods_type'://是否捆绑产品

                case 'restart'://是否重上
                case 'paypal_emailaddress'://收款paypal账号
                case 'location'://所在地
                case 'best_offer'://讨价还价
                case 'return_time'://退货周期
                case 'dispatch_max_time'://备货周期
                case 'listing_cate'://listing分类
                case 'application'://是否erp刊登
                case 'variation'://是否多属性
                case 'rule_id'://定时规则
                case 'is_virtual_send'://是否虚拟仓发货
                    $wh['l.'.$key] = $value;
                    break;
                case 'sales_status'://本地销售状态
                    $wh['l.sale_status'] = $value;
                    break;
                case 'promotion_id'://促销折扣
                    if ($value == 0) {//无折扣
                        $wh['l.is_promotion'] = 0;
                    } else {
                        $wh['l.promotion_id'] = $value;
                    }
                    break;
                case 'create_name'://创建人
                    $createId = User::where('realname','like',$value.'%')->value('id');
                    $wh['l.realname'] = $createId;
                    break;
                case 'account_code'://账号简称
                    $codes = json_decode($value,true);
                    if (is_null($codes)) {//不是json格式，但是有值，直接进行模糊搜索
                        $tmpWh['code'] = ['like',$value.'%'];
                    } else if (is_array($codes) && count($codes)==1) {//仅有一个值时，支持模糊
                        $tmpWh['code'] = ['like',$codes[0].'%'];
                    } else if (is_array($codes) && count($codes)>1) {//多个值时，支持批量
                        $tmpWh['code'] = ['in',$codes];
                    }
                    if (isset($tmpWh['code'])) {
                        $accountIds = EbayAccount::where($tmpWh)->column('id');
                        $wh['l.account_id'] = ['in', $accountIds];
                    }
                    break;
                case 'category'://主分类
                    $wh['l.primary_categoryid'] = $value;
                    break;
                case 'work_off'://是否售出过
                    $whVar['v.v_sold'] = [($value==1?'eq':'neq'),0];
                    break;
                case 'picture_gallery'://橱窗图片类型
                    $wh['l.picture_gallery'] = EbayPublish::PICTURE_GALLERY[$value];
                    break;
                case 'quantity'://0库存在线
                    if ($value) {
//                        $whSub['quantity'] = 0;
                        $whVar['v.v_qty'] = 0;
                    } else {
//                        $wh['quantity'] = ['>',0];
                        $whVar['v.v_qty'] = ['>',0];
                    }
                    break;
                case 'sub_title'://是否有副标题
                    $wh['l.sub_title'] = [($value==0?'=':'neq'),''];
                    break;
                case 'adjust_range'://价格调整幅度
                    $adjustedType = $params['adjusted_price'];
                    if ($adjustedType == 2) {//涨价
//                        $whSub['adjusted_cost_price'] = ['exp', '>cost_price+'.$value];
                        $whVar['v.adjusted_cost_price'] = ['exp', '>cost_price+'.$value];
                    } elseif ($adjustedType == 3) {//降价
//                        $whSub['adjusted_cost_price'] = ['exp', '>cost_price-'.$value];
                        $whVar['v.adjusted_cost_price'] = ['exp', '>cost_price-'.$value];
                    } elseif ($adjustedType == 4) {//未变动
//                        $whSub['adjusted_cost_price'] = ['exp', '=cost_price'];
                        $whVar['v.adjusted_cost_price'] = ['exp', '=cost_price'];
                    }
                    break;
                case 'listing_status'://刊登状态
                    $listingStatus = explode(',',$value);
                    if (empty($listingStatus)) {
                        break;
                    }
                    $wh['l.listing_status'] = count($listingStatus)>1?['in',$listingStatus]:$listingStatus[0];
                    break;
                case 'realname':
                    $userIds = User::where('realname','like',$value.'%')->column('id');
                    if ($userIds) {
                        $wh['l.realname'] = ['in',$userIds];
                    } else {
                        $wh['l.realname'] = ['exp', 'is null'];
                    }
                    break;
                case 'pub_time'://刊登时间/结束时间
                    $startDate = $params['pub_start'];
                    $endDate = $params["pub_end"];
                    if (empty($startDate) && empty($endDate)) break;

                    $startDate = explode(' ', $startDate);
                    $endDate = explode(' ', $endDate);

                    $startDate = empty($startDate[0]) ? 0 : strtotime($startDate[0].' '.'00:00:00');
                    $endDate = empty($endDate[0]) ? time() : strtotime($endDate[0].' '.'23:59:59');
                    if ($startDate !== false || $endDate !== false) {
                        if ($startDate == $endDate) {
                            $endDate = $startDate + 86400;
                        }
                        if ($value == 'create') {
                            $wh['l.create_date'] = array("between",[(string)$startDate,(string)$endDate]);
                        } else if ($value == 'update') {
                            $wh['l.update_date'] = array("between",[(string)$startDate,(string)$endDate]);
                        } else if ($value == 'local') {
                            $wh['l.timing'] = ['between',[(string)$startDate,(string)$endDate]];
                        } else if ($value == 'site') {
                            //先转化为本地时间
                            if ($params['site'] == '') {
                                break;
                            }
                            $timezone = EbaySite::where('siteid',$params['site'])->value('time_zone');
                            $wh['l.timing'] = ['between',[(string)($startDate-$timezone),(string)($endDate-$timezone)]];
                        }
                    }
                    break;
                case 'name':
                    $startDate = $params['start_date'];
                    $endDate = $params["end_date"];
                    if (empty($startDate) && empty($endDate)) break;

                    $startDate = explode(' ', $startDate);
                    $endDate = explode(' ', $endDate);

                    $startDate = empty($startDate[0]) ? 0 : strtotime($startDate[0].' '.'00:00:00');
                    $endDate = empty($endDate[0]) ? time() : strtotime($endDate[0].' '.'23:59:59');
                    if ($startDate !== false || $endDate !== false) {
                        if ($startDate == $endDate) {
                            $endDate = $startDate + 86400;
                        }
                        if ($value == 'start') {//
                            $wh['l.start_date'] = array("between",[(string)$startDate,(string)$endDate]);
                        } else if ($value == 'end') {
                            $wh['l.end_date'] = array("between",[(string)$startDate,(string)$endDate]);
                        }
                    }
                    break;
                case 'price':
                    $exp = explode(',', $value);
                    if (count($exp)!=2 || !$exp[1]) {
                        break;
                    }
                    if ($exp[0] == '>') {
                        $wh['l.max_price'] = $exp;
                    } elseif ($exp[0] == '<') {
                        $wh['l.min_price'] = $exp;
                    } elseif ($exp[0] == '=') {
                        $whVar['v.v_price'] = $exp;
                    }
                    break;
                case 'transport_property':
                    if ($value == '普货') {
                        $whGoods['g.transport_property'] = 1;
                    } else {
                        $whGoods['g.transport_property'] = ['neq',1];
                    }
                    break;
                case 'tort_channel_id'://侵權平臺
                    if ($value) {
                        $whGoodsTort['gtd.channel_id'] = $value;
                    } elseif ($value==0) {//0表示全部
                        $whGoodsTort['gtd.goods_id'] = ['<>',0];
                    }
                    break;
                case 'order_sold_quantity'://按销售量排序
                    $order .= 'sold_quantity '.$value.',';
                    break;
                case 'order_price'://按价格排序
                    $order .= 'min_price '.$value.',';
                    break;
                case 'order_publish_date'://按刊登时间排序
                    $order .= 'start_date '.$value.',';
                    break;
                case 'order_create_date'://按创建时间排序
                    $order .= 'create_date '.$value.',';
                    break;
            }
        }
        if (empty($order)) {
            $order = 'l.id desc,';
        }
        $order = substr($order,0,-1);
        $wh['draft'] = 0;
        return [
            'wh' => $wh,
            'whVar' => $whVar,
            'whGoodsTort' => $whGoodsTort??[],
            'whGoods' => $whGoods,
            'order' => $order,
        ];
    }

    /**
    * 获取范本信息
    * @param $goodsId
    * @param $siteId
    * @return array|mixed
    * @throws Exception
    */
    public function getSiteDraftInfo($params)
    {
        try {
            $wh['goods_id'] = $params['goodsId'];
            $wh['site_id'] = $params['siteId'];
            $listingId = EbayDraft::where($wh)->value('listing_id');
            if (!$listingId) {
                throw new Exception('范本对应的listing不存在');
            }
            $draft = (new EbayListingService())->getListingInfo($listingId);
            return $draft;
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 设置范本
     * @param $params
     * @return array
     * @throws Exception
     */
    public function setDraft($params)
    {
        try {
            if (isset($params['listing_id'])) {
                $wh['site_id'] = $params['site_id'];
                $wh['goods_id'] = $params['goods_id'];
                $draft = EbayDraft::get($wh);
                if ($draft) {
                    $draft->listing_id = $params['listing_id'];
                    $draft->isUpdate(true)->save();
                } else {
                    EbayDraft::create($params);
                }
                return ['message'=>'操作成功'];
            } else {//如果没有id,需要保存后获取
                $params =json_decode($params['data'], true);
                $res = $this->saveListing($params);
                if ($res['result'] === false) {
                    throw new Exception($res['message']);
                }
                $wh['site_id'] = $params[0]['list']['site'];
                $wh['goods_id'] = $params[0]['list']['goods_id'];
                $draft = EbayDraft::get($wh);

                $data['listing_id'] = $res['data'][0]['id'];
                $data['goods_id'] = $params[0]['list']['goods_id'];
                $data['site_id'] = $params[0]['list']['site'];
                if ($draft) {//已存在
                    EbayDraft::update($data,['id'=>$draft['id']]);
                } else {
                    EbayDraft::create($data);
                }
                return $res;
            }

        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取范本列表
     * @param $params
     * @throws Exception
     */
    public function drafts($params)
    {
        try {
            //解析搜索条件
            $wh = [];
            if (isset($params['spu']) && $params['spu']) {//spu
                $spus = explode(',', $params['spu']);
                if (count($spus) == 1) {//只有一个时，使用模糊搜索
                    $goodsIds = Goods::where('spu','like',$spus[0].'%')->column('id');
                } else {
                    $goodsIds = Goods::whereIn('spu',$spus)->column('id');
                }
                $wh['d.goods_id'] = ['in',$goodsIds];
            } else if (isset($params['sku']) && $params['sku']) {//sku
                $skus = explode(',',$params['sku']);
                if (count($skus) == 1) {//只有一个时，使用模糊搜索
                    $goodsIds = GoodsSku::where('sku','like',$skus[0].'%')->column('goods_id');
                } else {
                    $goodsIds = GoodsSku::whereIn('sku',$skus)->column('goods_id');
                }
                $wh['d.goods_id'] = ['in',$goodsIds];
            }
            if (isset($params['site_id']) && is_numeric($params['site_id'])) {//站点
                $wh['d.site_id'] = $params['site_id'];
            }
            if (isset($params['category_id']) && $params['category_id']) {//本地分类
                $wh['g.category_id'] = $params['category_id'];
            }
            if (!empty($params['start_time']) || !empty($params['end_time'])) {
                $startTime = empty($params['start_time']) ? 0 : strtotime($params['start_time']);
                $endTime = empty($params['end_time']) ? time() : strtotime($params['end_time'].' 23:59:59');
                $wh['d.create_time'] = ['between',[$startTime,$endTime]];
            }
            $page = is_numeric($params['page']) ? $params['page'] : 1;
            $pageSize = is_numeric($params['pageSize']) ? $params['pageSize'] : 50;
            //查询
            $field = 'd.id,d.listing_id,d.site_id,d.create_time,g.thumb,g.spu,g.name,g.category_id';
            $data = EbayDraft::alias('d')->field($field)->where($wh)->join('goods g','g.id=d.goods_id','LEFT')
                ->page($page,$pageSize)->select();
            if (!$data) {
                return [
                    'data' => [],
                    'count' => 0,
                    'page' => $page,
                    'pageSize' => $pageSize
                ];
            }
            $count = EbayDraft::alias('d')->field($field)->where($wh)->join('goods g','g.id=d.goods_id','LEFT')
                ->count();
            $data = collection($data)->toArray();
            $listingIds = array_column($data,'listing_id');
            $listings = EbayListing::whereIn('id',$listingIds)->column('listing_status,title,variation','id');
            foreach ($data as &$dt) {
                $dt['thumb'] = \app\goods\service\GoodsImage::getThumbPath($dt['thumb'],60,60);
                $dt['title'] = $listings[$dt['listing_id']]['title'] ?? '';
                $dt['create_time'] = date('Y-m-d H:i:s',$dt['create_time']);
                $dt['category_name'] = (new Goods())->getCategoryAttr([],['category_id'=>$dt['category_id']]);
                $dt['variation'] = $listings[$dt['listing_id']]['variation'] ?? 0;
            }
            return [
                'data' => $data,
                'count' => $count,
                'page' => $page,
                'pageSize' => $pageSize,
            ];
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 应急测试方法
     * @param $param
     * @return string
     */
    public function test($param)
    {
        try {
            switch ($param['action_name']) {
                case 'updateTable'://更新表数据
                    $model = $param['model'];
                    $field = json_decode($param['field'],true);
                    $wh = json_decode($param['wh'],true);
                    $curd = $param['curd'];
                    switch ($curd) {
                        case 'update':
                            foreach ($field as &$fd) {
                                if (is_array($fd)) {
                                    $fd = json_encode($fd);
                                }
                            }
                            $model::update($field,$wh);
                            break;
                        case 'insert':
                            $model::create($field);
                            break;
                        case 'delete':
                            $model::destroy($wh);
                            break;
                        default:
                            throw new Exception('未定义的数据库操作方法');
                            break;
                    }

                    break;
                case 'excuteMethod':
                    $class = $param['class'];
                    $method = $param['method'];
                    $init = $param['init'];
                    $argv = json_decode($param['argv'],true);
                    $paramRepeat = $param['param_repeat'];
                    $instance = $init ? (new $class($init)) : (new $class);
                    if ($paramRepeat) {
                        foreach ($argv as $arg) {
                            call_user_func_array([$instance,$method], $arg);
                        }
                    } else {
                        call_user_func_array([$instance, $method], $argv);
                    }
                    break;
                default:
                    throw new Exception('不存在的方法');
            }
            return '执行成功';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 从范本复制转站点
     * @param $listingIds
     * @param $siteId
     * @param $templates
     * @param $accountId
     * @return array
     * @throws Exception
     */
    public function changeSiteFromDraft($listingIds, $siteId, $templates, $accountId)
    {
        try {
            $res = $this->changeSite($listingIds,$siteId,$templates,1,$accountId,1);
            if ($res['result'] === false) {
                throw new Exception($res['message']);
            }
            $msg = $res['message'];
            $msg = str_replace('自动过滤了不可修改的listing。','',$msg);
            return ['message'=>$msg];
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 在线spu统计导出
     * @param $params
     * @throws Exception
     */
    public function onlineSpuStatisticExport($params)
    {
        try {
            //组装搜索条件
            $condition = $this->packCondition($params);

            $condition['goods_id'] = $condition['goods_id'] ?? ['<>',0];

            //先统计数量以决定是否需要走队列
            $condition['group'] = 'account_id,goods_id';
            $count = $this->doCount($condition);

            if (!$count) {
                return ['message'=>'没有需要导出的数据'];
            }
            $fileName = 'SPU刊登统计导出-'.date('YmdHis').'.xlsx';
            $condition['file_name'] = $fileName;
            $condition['count'] = $count;//记录总数，避免实际执行时再次查询
            if ($count > 500) {//走队列
                $model = new ReportExportFiles();
                $data['applicant_id'] = $this->userId;
                $data['apply_time'] = time();
                $data['export_file_name'] = $fileName;
                $data['status'] = 0;
                $data['applicant_id'] = $this->userId;
                $model->allowField(true)->isUpdate(false)->save($data);
                $condition['apply_id'] = $model->id;
                $condition['export_type'] = 2;//与其他导出进行区分，因为用的是同一个队列
                (new CommonQueuer(EbayListingExportQueue::class))->push($condition);
                $message = '导出任务添加成功，请到报表导出管理处下载xlsx';
                return ['message'=>$message];
            }
            return $this->doOnlineSpuStatisticExport($condition);
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 执行导出
     * @param $wh
     * @param $count
     * @throws Exception
     */
    public function doOnlineSpuStatisticExport($condition)
    {
        $header = [
            'spu' => 'string',
            '上架时间(开发时间)' => 'string',
            '所属平台' => 'string',
            '分类' => 'string',
            '开发员' => 'string',
            '产品状态' => 'string',
            'eBay平台刊登总数' => 'integer',
            '账号简称' => 'string',
            '销售员' => 'string',
            '次数' => 'integer',
        ];
        $fileName = $condition['file_name'];
        $count = $condition['count'];
        $applyId = $condition['apply_id']??0;
        unset($condition['apply_id']);
        unset($condition['export_type']);
        unset($condition['file_name']);
        unset($condition['count']);
        try {
            $field1 = 'l.goods_id,l.account_id,count(*) cnt';
            $pageSize = 1000;
            $condition['pageSize'] = 1000;
            $loop = ceil($count/$pageSize);

            //获取账号信息
            $accountField = 'a.id,a.code,realname';
            $whAccount = [
                'u.status' => 1,
                'u.job' => 'sales',
                'a.is_invalid' => 1,
                'a.account_status' => 1,
                'c.channel_id' => 1,
                'c.warehouse_type' => ['in',[0,1]],
            ];
            $accountInfo = ChannelUserAccountMap::alias('c')->where($whAccount)
                ->join('user u','u.id=c.seller_id','LEFT')
                ->join('ebay_account a','a.id=c.account_id','LEFT')
                ->group('c.account_id')->column($accountField,'c.account_id');
            $channelMap = EbayConstants::CHANNEL_NAME;

            include_once(APP_PATH.'/../extend/XLSXWriter/xlsxwriter.class.php');
            $writer = new \XLSXWriter();
            $writer->writeSheetHeader('Sheet1', $header);

            for ($i=0; $i<$loop; $i++) {
                $condition['page'] = $i+1;
                $rows = $this->doSearch($condition,$field1);
                if (!$rows) {
                    break;
                }
                $goodsIds = [];
                foreach ($rows as $row) {
                    $goodsIds = array_merge($goodsIds, [$row['goods_id']]);
                    $spuCount[$row['goods_id']][$row['account_id']] = $row['cnt'];
                    $spuCount[$row['goods_id']]['total'] = ($spuCount[$row['goods_id']]['total'] ?? 0) + $row['cnt'];
                }
                unset($rows);//及时释放内存
                unset($row);

                //获取商品信息
                $goodsField = 'spu,publish_time,channel_id,category_id,realname developer_name,sales_status';
                $goodsInfo = Goods::alias('g')->whereIn('g.id', $goodsIds)
                    ->join('user u', 'u.id=g.developer_id', 'LEFT')->column($goodsField, 'g.id');
                unset($goodsIds);
                foreach ($spuCount as $gId => $sc) {
                    $spuChangeFlag = 1;
                    foreach ($sc as $aid => $s) {
                        if ($aid == 'total') {
                            continue;
                        }
                        $tmp = [
                            'spu' => $spuChangeFlag ? $goodsInfo[$gId]['spu'] : '',
                            'publish_time' => $spuChangeFlag ? date('Y/m/d', $goodsInfo[$gId]['publish_time']) : '',
                            'develop_platform' => $spuChangeFlag ? $channelMap[$goodsInfo[$gId]['channel_id']] ?? '' : '',
                            'category_name' => $spuChangeFlag ? (new Goods())->getCategoryAttr([], ['category_id' => $goodsInfo[$gId]['category_id']]) : '',
                            'developer_name' => $spuChangeFlag ? $goodsInfo[$gId]['developer_name'] : '',
                            'sales_status' => $spuChangeFlag ? (new \app\goods\service\GoodsHelp())->sales_status[$goodsInfo[$gId]['sales_status']] : '',
                            'total' => $spuChangeFlag ? $sc['total'] : '',
                            'account_code' => $accountInfo[$aid]['code'] ?? '未知',
                            'sales_name' => $accountInfo[$aid]['realname'] ?? '未知',
                            'count' => $s
                        ];
                        $writer->writeSheetRow('Sheet1', $tmp);
                        $spuChangeFlag = 0;
                    }
                }
                unset($tmp);
                unset($goodsInfo);
                unset($spuCount);
            }
            $downLoadDir = '/download/ebay_listing/';
            $saveDir = ROOT_PATH . 'public' . $downLoadDir;
            if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir . $fileName;
            $writer->writeToFile($fullName);
            if (is_file($fullName)) {
                if ($loop > 1) {
                    $applyRecord = ReportExportFiles::get($applyId);
                    $applyRecord['exported_time'] = time();
                    $applyRecord['download_url'] = $downLoadDir . $fileName;
                    $applyRecord['status'] = 1;
                    $applyRecord->isUpdate()->save();
                } else {
                    try {
                        $logExportDownloadFiles = new LogExportDownloadFiles();
                        $data = [];
                        $data['file_extionsion'] = 'xlsx';
                        $data['saved_path'] = $fullName;
                        $data['download_file_name'] = $fileName;
                        $data['type'] = 'ebay_listing_export';
                        $data['created_time'] = time();
                        $data['updated_time'] = time();
                        $logExportDownloadFiles->allowField(true)->isUpdate(false)->save($data);
                        $udata = [];
                        $udata['id'] = $logExportDownloadFiles->id;
                        $udata['file_code'] = date('YmdHis') . $logExportDownloadFiles->id;
                        $logExportDownloadFiles->allowField(true)->isUpdate(true)->save($udata);
                    } catch (\Exception $e) {
                        $result['message'] = '创建导出文件日志失败。' . $e->getMessage();
                        @unlink($fullName);
                        return $result;
                    }
                    $result['message'] = 'OK';
                    $result['file_code'] = $udata['file_code'];
                    $result['file_name'] = $fileName;
                    return $result;
                }
            } else {
                throw new Exception('文件写入失败');
            }
        } catch (\Exception $e) {
            $applyRecord = ReportExportFiles::get($applyId);
            $applyRecord['status'] = 2;
            $applyRecord['error_message'] = $e->getMessage();
            $applyRecord->isUpdate(true)->save();
            Cache::handler()->hset(
                'hash:report_export',
                'error_' . time(),
                '申请id: ' . $applyId . ',导出失败:' . $e->getMessage());
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @title 执行查询
     * @param $condition
     * @param string $field
     * @return EbayListing|false|\PDOStatement|string|\think\Collection
     */
    public function doSearch($condition,$field)
    {
        $order = $condition['order'];
        $whVar = $condition['whVar'];
        $wh = $condition['wh'];
        $whGoods = $condition['whGoods'];
        $whGoodsTort = $condition['whGoodsTort'];
        $group = $condition['group'] ?? '';
        $page = $condition['page'] ?? 1;
        $pageSize = $condition['pageSize'] ?? 50;

        $listings = EbayListing::alias('l')->field($field)->where($wh);

        if ($whVar) {//变体表
            $listings = $listings->join('ebay_listing_variation v','l.id=v.listing_id','LEFT')
                ->where($whVar);
            $group .= $group ? ',l.id' : 'l.id';
        }
        if ($whGoods) {
            $listings = $listings->join('goods g','l.goods_id=g.id','left')->where($whGoods);

        }
        if ($whGoodsTort) {
            $listings =$listings->join('goods_tort_description gtd','l.goods_id=gtd.goods_id','left')
                ->where($whGoodsTort);
            if (strpos($group,'l.id') === false) {
                $group .= $group ? ',l.id' : 'l.id';
            }

        }
        if ($group) {
            $listings = $listings->group($group);
        }
        $listings = $listings->order($order)->page($page, $pageSize)->select();
        return $listings;
    }

    /**
     * @title 计算总数
     *
     */
    public function doCount($condition)
    {
        $whVar = $condition['whVar'];
        $wh = $condition['wh'];
        $whGoods = $condition['whGoods'];
        $whGoodsTort = $condition['whGoodsTort'];
        $group = $condition['group']??'';
        //总数
        $count = EbayListing::alias('l')->where($wh);
        if ($whVar) {//变体表
            $count = $count->join('ebay_listing_variation v','l.id=v.listing_id','LEFT')
                ->where($whVar);
            $group .= $group ? ',l.id' : 'l.id';
        }
        if ($whGoods) {
            $count = $count->join('goods g','l.goods_id=g.id','left')->where($whGoods);

        }
        if ($whGoodsTort) {
            $count =$count->join('goods_tort_description gtd','l.goods_id=gtd.goods_id','left')
                ->where($whGoodsTort);
            if (strpos($group,'l.id') === false) {
                $group .= $group ? ',l.id' : 'l.id';
            }
        }
        if ($group) {
            $count = $count->group($group);
        }
        $count = $count->count();
        return $count;
    }


}
