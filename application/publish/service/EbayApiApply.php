<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2019/3/5
 * Time: 10:28
 */

namespace app\publish\service;

use app\common\model\ebay\EbayAccount;
use app\common\model\ebay\EbayActionLog;
use app\common\model\ebay\EbayListing;
use app\common\model\ebay\EbayListingImage;
use app\common\model\ebay\EbayListingSetting;
use app\common\model\ebay\EbayListingVariation;
use app\common\model\ebay\EbayModelSale;
use app\common\model\ebay\EbayModelStyle;
use app\common\model\ebay\EbaySite;
use app\common\model\mongodb\ebay\EbayLog;
use app\common\service\ChannelAccountConst;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsSkuMapService;
use app\publish\helper\ebay\EbayPublish;
use app\goods\service\GoodsImage;
use app\common\model\mongodb\ebay\EbayListingSetting as ELSMongo;
use app\publish\queue\EbayGetItemQueue;
use app\publish\queue\EbaySettingMove2Mongo;
use app\publish\service\EbayConstants as Constants;
use app\report\service\StatisticShelf;
use ebay\EbaySDK;
use think\Exception;

/**
 * ebay api 统一封装类，牵涉到API调用的以后均使用此类中的方法
 * 方法命名与ebayApi保持一致，只是首字母小写
 * Class EbayApiApply
 * @package app\publish\service
 */
class EbayApiApply
{



    /*******************************************************************************************************************
     *                              Trading API
     ******************************************************************************************************************/

    /**
     *  下架 item
     * @param array $data 只接受id或itemId
     * @param array $config
     * @param int $accountId
     * @return void
     */
    public static function endItem(&$log)
    {
        EbayActionLog::update(['run_time'=>time()],['id'=>$log['id']]);
        $newVal = json_decode($log['new_val'],true);
        $endType = $newVal['end_type'] ?? 0;

        $itemId = $log['item_id'];
        if (!$itemId) {
            return;
        }
        $listing = EbayListing::where('item_id',$itemId)->field('id,item_id,account_id,site')->find();
        if (!$listing) {
            $message = '无法获取到对应的listing信息';
            EbayActionLog::update(['status'=>3,'message'=>$message],['id'=>$log['id']]);
            throw new Exception($message);
        }

        try {
            EbayPublish::updateListingStatusWithErrMsg('ending',$listing['id']);

            $account = EbayAccount::field(EbayPublish::ACCOUNT_FIELD_TOKEN)->where('id', $listing['account_id'])->find();
            $config = $account->toArray();
            $config['siteId'] = $listing['site'];
            //打包数据
            $params['ItemID'] = (string)$listing['item_id'];
            $params['EndingReason'] = 'OtherListingError';

            //发送请求
            $response = EbaySDK::sendRequest('Trading', $config, 'endItem', $params);
        } catch (\Exception $e) {
            EbayPublish::updateListingStatusAndLog('endFail',['id'=>$listing['id']],$log['id'],$e->getMessage());
            throw new Exception($e->getMessage());
        }
        $successUpdate = [
            'listing_status' => EbayPublish::PUBLISH_STATUS['ended'],
            'end_type' => $endType,
            'end_user_id' => $log['create_id'],
        ];
        //处理结果
        if ($response['result'] === false) {
            $errorMsg = json_decode($response['message'], true);
            if (isset($errorMsg[1047])) {//此错误说明listing已经下架了,如果没有就是真的下架失败了
                EbayListing::update($successUpdate,['id'=>$listing['id']]);
                EbayActionLog::update(['status'=>2],['id'=>$log['id']]);
            } else {
                EbayPublish::updateListingStatusAndLog('endFail',['id'=>$listing['id']],$log['id'],$response['message']);
                throw new Exception($response['message']);
            }
        } else {
            EbayListing::update($successUpdate,['id'=>$listing['id']]);
            EbayActionLog::update(['status'=>2],['id'=>$log['id']]);
        }
    }

    /**
     * 同步账号listing列表
     * @param int $accountId
     * @param $userId
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getSellerList(int $accountId, $userId=0)
    {
        $account = EbayAccount::field(EbayPublish::ACCOUNT_FIELD_TOKEN)->where('id',$accountId)->find();
        if (!$account) {
            throw new Exception('账号信息获取失败');
        }
        $config = $account->toArray();

        //获取本地listing
        $whSD = [
            'start_date' => ['<>',0],
            'account_id' => $accountId,
        ];
        $startDate = EbayListing::where($whSD)->order('start_date')->value('start_date');//始终取最早的那条为获取起始日期
        $startDate -= 8*3600;//去除时区
        $endDate = time()-3600;//只同步到1h前的
        $wh = [
            'draft' => 0,
            'listing_status' => ['in',[3,5,6,7,8,9,10]],
            'account_id' => $accountId,
            'item_id' => ['neq',0],//避免获取到异常数据
        ];
        $localItemIds = EbayListing::where($wh)->column('item_id');
        $activeItemIds = [];
        while(1) {//循环上架时间区间
            if (($startDate + 8*3600) >= $endDate) {//已经到了最新时间
                break;
            }
            $startTimeFrom = date('Y-m-d\TH:i:s.000\Z',$startDate);//上架时间区间开始
            $timeTo = $startDate+120*86400;//时间跨度最多120天
            $startTimeTo = date('Y-m-d\TH:i:s.000\Z',$timeTo);//上架时间区间结束
            $pageNumber = 1;
            while (1) {//固定上架区间内，循环页码
                $params = [
                    'Pagination' => [
                        'EntriesPerPage' => 200,
                        'PageNumber' => $pageNumber,
                    ],
                    'StartTimeFrom' => $startTimeFrom,
                    'StartTimeTo' => $startTimeTo,
                ];
                $response = EbaySDK::sendRequest('Trading',$config,'getSellerList',$params);
                if ($response['result'] === false) {
                    $startDate = $timeTo;//重新定义起始时间
                    break;
                }
                $res = $response['data'];
                //解析数据
                $totalPage = $res['PaginationResult']['TotalNumberOfPages']??0;//总页数
                $items = $res['ItemArray']['Item']??[];
                if (empty($totalPage) || empty($items)) {//这一时间段内没有上架
                    $startDate = $timeTo;//重新定义起始时间
                    break;
                }
                !isset($items[0]) && $items = [$items];
                foreach ($items as $item) {
                    $endTime = strtotime($item['ListingDetails']['EndTime']);
                    if ($endTime > time()) {//只记录在线的
                        $activeItemIds[] = $item['ItemID'];
                    }
                }
                if ($pageNumber == $totalPage) {
                    $startDate = $timeTo;//重新定义起始时间
                    break;
                }
                $pageNumber++;
            }
        }
        if (!$activeItemIds) {
            return;
        }
        //循环结束，更新数据表
        $endItemIds = array_diff($localItemIds,$activeItemIds);
        if ($endItemIds) {//需要更改为下架状态的
            $endItemIds = array_values($endItemIds);
            EbayListing::update(['listing_status'=>11],['item_id'=>['in',$endItemIds]]);
            //记录日志
            $log = [
                'itemIds' => $endItemIds,
                'accountId' => $accountId,
                'userId' => $userId,
                'remark' => '同步列表更为下架状态',
            ];
            EbayLog::create($log);
        }
        $oLItemIds = array_diff($activeItemIds,$localItemIds);
        if ($oLItemIds) {//需要更改为在线状态的
            $whOl = [
                'draft' => 0,
                'listing_status' => ['not in',['3,5,6,7,8,9,10']],
                'item_id' => ['in',$oLItemIds],
            ];
            $localItIds = EbayListing::where($whOl)->column('item_id');//本地存在的非在线的
            if ($localItIds) {
                EbayListing::update(['listing_status'=>3],['item_id'=>['in',$localItIds]]);
                $log = [
                    'itemIds' => $localItIds,
                    'accountId' => $accountId,
                    'userId' => $userId,
                    'remark' => '同步列表更为在线状态',
                ];
                EbayLog::create($log);
            }
            $noExistItemIds = array_diff($oLItemIds,$localItIds);//本地不存在的，加入拉取同步队列
            foreach ($noExistItemIds as $noExistItemId) {
                $pd = $accountId.','.$noExistItemId;
                (new UniqueQueuer(EbayGetItemQueue::class))->push($pd);
            }
        }
    }

    /**
     * 刊登
     * @param $id
     * @param int $userId
     * @return mixed
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function addItem($id, $userId=0)
    {
//        $fieldList = 'id,listing_type,listing_sku,account_id,site,currency,variation,paypal_emailaddress,primary_categoryid,
//            second_categoryid,private_listing,picture_gallery,location,country,dispatch_max_time,listing_duration,autopay,
//            quantity,best_offer,buy_it_nowprice,reserve_price,start_price,minimum_to_bid,sales_tax,sales_tax_state,
//            shipping_tax,vat_percent,title,sub_title,store_category_id,store_category2_id,listing_enhancement,disable_buyer,
//            mod_style,mod_sale,return_time,assoc_order,sku,local_sku,is_virtual_send,realname';
//        $list = EbayListing::field($fieldList)->where('id',$id)->find();
//        if (!$list) {
//            throw new Exception('获取对应listing信息失败，请重试');
//        }
        try {
//            EbayListing::update(['listing_status'=>EbayPublish::PUBLISH_STATUS['publishing']],['id'=>$list['id']]);//维护状态

//            $fieldSet = 'id,application_data,postal_code,local_pickup,auto_accept_price,minimum_accept_price,upc,ean,isbn,mpn,
//            brand,custom_exclude,exclude_location,ship_location,international_shipping,shipping,payment_method,
//            payment_instructions,return_policy,return_type,return_shipping_option,return_description,buyer_requirment_details,
//            variation_image,compatibility_count,compatibility,specifics,description,condition_id,condition_description';
//            $set = EbayListingSetting::field($fieldSet)->where('id', $id)->find();
//            $mongoSet = ELSMongo::get(['id'=>(int)$id]);
//            if ($mongoSet) {
//                $set['description'] = $mongoSet['description'];
//            } else {
//                (new UniqueQueuer(EbaySettingMove2Mongo::class))->push($id);
//            }
//
//            $fieldImg = 'id,path,thumb,eps_path,ser_path,name,value,sort,de_sort,main,main_de,detail,message';
//            $imgs = EbayListingImage::where('listing_id', $id)->field($fieldImg)->select();
//            if ($list['variation']) {
//                $fieldVariant = 'id,v_price,v_qty,variation,upc,ean,isbn,channel_map_code,v_sku,combine_sku';
//                $variants = EbayListingVariation::where('listing_id', $id)->field($fieldVariant)->select();
//                //处理平台SKU
//                foreach ($variants as $variant) {
//                    if ($variant['channel_map_code']) {
//                        continue;
//                    }
//                    //重新生成并维护映射表
//                    $sku = (new GoodsSkuMapService())->createSkuNotInTable($variant['v_sku']);
//                    $variant['channel_map_code'] = $list['assoc_order'] ? $sku : 'ebay' . $sku;
//                    $variant['is_virtual_send'] = $list['is_virtual_send'];
//                    if ($list['assoc_order']) {
//                        (new EbayPublish())->maintainTableGoodsSkuMap($variant, $list['account_id'], $userId);
//                    }
//                    $variant->allowField(true)->save();
//                }
//            } elseif (!$list['listing_sku']) {//单属性
//                $sku = (new GoodsSkuMapService())->createSkuNotInTable($list['local_sku']);
//                $list['listing_sku'] = $list['assoc_order'] ? $sku : 'ebay' . $sku;
//                if ($list['assoc_order']) {
//                    (new EbayPublish())->maintainTableGoodsSkuMap($list, $list['account_id'], $userId);
//                }
//                $list->save();
//            }
            $listing = self::getPublishListing($id,0,$userId);
            $list = $listing['list'];
            $set = $listing['set'];
            $imgs = $listing['imgs'];
            $variants = $listing['variants'];

            $account = EbayAccount::where('id', $list['account_id'])->field(EbayPublish::ACCOUNT_FIELD_TOKEN)
                ->find();
            $config = $account->toArray();
            $config['site_id'] = $list['site'];

            //处理图片
            $imgs = collection($imgs)->toArray();
            self::UploadSiteHostedPictures($imgs, $config);
            //保存图片
            (new EbayListingImage())->saveAll($imgs);
            //检查图片是否完全上传成功
            $imgErrMsg = '';

            $imgTypeTxt = [
                '100' => ['刊登图', 'publish_imgs'],
                '010' => ['详情图', 'detail_imgs'],
                '001' => ['变体图', 'sku_imgs'],
            ];
            $typeImgs = [];
            foreach ($imgs as $img) {
                //判断图片类型，组装错误信息
                $imgType = $img['main'] . $img['detail'] . $img['main_de'];
                $msg = $imgTypeTxt[$imgType][0];
                if ($imgType == '001') {
                    $msg .= '属性值为' . $img['value'] . '的图片';
                } else {
                    $msg .= '第' . ($img['sort'] + 1) . '张';
                }
                $failImg = 0;
                if (empty($img['eps_path'])) {
                    $failImg = 1;
                    $msg .= '上传失败，失败信息:' . $img['message'] ?? '' . ',图片链接为https://img.rondaful.com/' . $img['path'] . ';';
                } elseif (strpos($img['eps_path'], '/$_3.') === false) {//图片像素过低
                    $imgSize = getimagesize($img['eps_path']);
                    if (($imgSize[0]??0)<500 || ($imgSize[1]??0)<500) {
                        $failImg = 1;
                        $msg .= '像素过低,图片链接为https://img.rondaful.com/' . $img['path'] . ';';
                    }
                }
                if ($failImg) {
                    $imgErrMsg .= $msg;
                }
                if ($imgType != '001') {
                    $typeImgs[$imgTypeTxt[$imgType][1]][$img['sort']] = $img;
                } else {
                    $typeImgs[$imgTypeTxt[$imgType][1]][] = $img;
                }
            }
            if ($imgErrMsg) {
                EbayListing::update(['listing_status' => EbayPublish::PUBLISH_STATUS['publishFail']], ['id' => $list['id']]);
                EbayListingSetting::update(['message' => $imgErrMsg], ['id' => $list['id']]);
                return $imgErrMsg;
            }


            $data = [
                'list' => $list,
                'set' => $set,
                'imgs' => $typeImgs,
                'variants' => $variants ?? [],
            ];
            $item = self::formatItem($data);
            $params['Item'] = $item;
            $verb = $list['listing_type'] == 1 ? 'addFixedPriceItem' : 'addItem';
        } catch (\Throwable $e) {
            EbayPublish::updateListingStatusWithErrMsg('publishFail',$list['id'],[],$e->getMessage());
            return $e->getMessage();
        }
        $response = EbaySDK::sendRequest('Trading',$config,$verb,$params);
        if ($response['result'] === false) {//刊登失败
            EbayPublish::updateListingStatusWithErrMsg('publishFail',$list['id'],[],$response['message']);
            return $response['message'];
        }
        $res = $response['data'];
        $up['listing_status'] = EbayPublish::PUBLISH_STATUS['publishSuccess'];
        $up['item_id'] = $res['ItemID'];
        EbayListing::update($up,['id'=>$list['id']]);
        //写入统计表
        try {
            StatisticShelf::addReportShelfNow(ChannelAccountConst::channel_ebay,
                $list['account_id'], $list['realname'], $list['goods_id'], 1);
        } catch (\Throwable $e) {
            //不处理
        }
        try {
            $up['end_date'] = strtotime($res['EndTime']);
            $up['start_date'] = strtotime($res['StartTime']);
            $fees = $res['Fees'];
            $fee = self::parseFees($fees, true);
            $up['listing_fee'] = $fee['ListingFee'] ?? 0;
            $up['insertion_fee'] = $fee['InsertionFee'] ?? 0;
            EbayListing::update($up, ['id' => $list['id']]);
            return $up;
        } catch (\Throwable $e) {
            return $up;
        }
    }

    /**
     * 获取刊登数据
     * @param $id
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private static function getPublishListing($id,$verify=0,$userId=0)
    {
        $fieldList = 'id,listing_type,listing_sku,account_id,site,currency,variation,paypal_emailaddress,primary_categoryid,
            second_categoryid,private_listing,picture_gallery,location,country,dispatch_max_time,listing_duration,autopay,
            quantity,best_offer,buy_it_nowprice,reserve_price,start_price,minimum_to_bid,sales_tax,sales_tax_state,
            shipping_tax,vat_percent,title,sub_title,store_category_id,store_category2_id,listing_enhancement,disable_buyer,
            mod_style,mod_sale,return_time,assoc_order,sku,local_sku,is_virtual_send,realname';
        $list = EbayListing::field($fieldList)->where('id',$id)->find();
//        if (!$list) {
//            throw new Exception('获取对应listing信息失败，请重试');
//        }
        $fieldSet = 'id,application_data,postal_code,local_pickup,auto_accept_price,minimum_accept_price,upc,ean,isbn,mpn,
            brand,custom_exclude,exclude_location,ship_location,international_shipping,shipping,payment_method,
            payment_instructions,return_policy,return_type,return_shipping_option,return_description,buyer_requirment_details,
            variation_image,compatibility_count,compatibility,specifics,description,condition_id,condition_description';
        $set = EbayListingSetting::field($fieldSet)->where('id', $id)->find();
        $mongoSet = ELSMongo::get(['id'=>(int)$id]);
        if ($mongoSet) {
            $set['description'] = $mongoSet['description'];
        } elseif (!$verify) {
            (new UniqueQueuer(EbaySettingMove2Mongo::class))->push($id);
        }

        $fieldImg = 'id,path,thumb,eps_path,ser_path,name,value,sort,de_sort,main,main_de,detail,message';
        $imgs = EbayListingImage::where('listing_id', $id)->field($fieldImg)->select();
        if ($list['variation']) {
            $fieldVariant = 'id,v_price,v_qty,variation,upc,ean,isbn,channel_map_code,v_sku,combine_sku';
            $variants = EbayListingVariation::where('listing_id', $id)->field($fieldVariant)->select();
            //处理平台SKU
            foreach ($variants as $variant) {
                if ($variant['channel_map_code']) {
                    continue;
                }
                //重新生成并维护映射表
                $sku = (new GoodsSkuMapService())->createSkuNotInTable($variant['v_sku'],'|',mb_strlen($variant['v_sku'])+11);
                $variant['channel_map_code'] = $list['assoc_order'] ? $sku : 'ebay' . $sku;
                $variant['is_virtual_send'] = $list['is_virtual_send'];
                if (!$verify) {
                    if ($list['assoc_order']) {
                        (new EbayPublish())->maintainTableGoodsSkuMap($variant, $list['account_id'], $userId);
                    }
                    $variant->allowField(true)->save();
                }
            }
        } elseif (!$list['listing_sku']) {//单属性
            $sku = (new GoodsSkuMapService())->createSkuNotInTable($list['local_sku'],'|',mb_strlen($list['local_sku'])+11);
            $list['listing_sku'] = $list['assoc_order'] ? $sku : 'ebay' . $sku;
            if (!$verify) {
                if ($list['assoc_order']) {
                    (new EbayPublish())->maintainTableGoodsSkuMap($list, $list['account_id'], $userId);
                }
                $list->save();
            }
        }
        return [
            'list' => $list,
            'set' => $set,
            'imgs' => $imgs,
            'variants' => $variants??[],
        ];
    }


    public static function verifyAddItem($id,$listing)
    {
        try {
            $typeImgs = [];
            if ($id) {
                $listing = self::getPublishListing($id, 1);
            }
            $account = EbayAccount::where('id', $listing['list']['account_id'])->field(EbayPublish::ACCOUNT_FIELD_TOKEN)
                ->find();
            $config = $account->toArray();
            $config['site_id'] = $listing['list']['site'];


            if ($id) {
                //处理图片
                foreach ($listing['imgs'] as $img) {
                    if ($img['main']) {//刊登图
                        $typeImgs['publish_imgs'][$img['sort']] = $img;
                    } elseif ($img['detail']) {//详情图
                        $typeImgs['detail_imgs'][$img['sort']] = $img;
                    } elseif ($img['main_de']) {//变体图
                        $typeImgs['sku_imgs'][] = $img;
                    }
                }
                $listing['imgs'] = $typeImgs;
            } else {
                if (!$listing['list']['variation'] && empty($listing['list']['listing_sku'])) {//单属性
                    $listing['list']['listing_sku'] = (new GoodsSkuMapService())->createSkuNotInTable($listing['list']['local_sku']);
                }

                //处理图片
                foreach ($listing['imgs'] as $lmk => $img) {
                    $mPath = preg_replace('/http(s):\/\/[0-9a-z\.]*\//','',$img);
                    $typeImgs['publish_imgs'][] = [
                        'eps_path'=> GoodsImage::getThumbPath($mPath,0,0,$config['code']),
                        'sort'=> $lmk,
                    ];
                }
                foreach ($listing['detail_imgs'] as $ldmk => $detail_img) {
                    $dmPath = preg_replace('/http(s):\/\/[0-9a-z\.]*\//','',$detail_img);
                    $typeImgs['detail_imgs'][] = [
                        'eps_path'=> GoodsImage::getThumbPath($dmPath,0,0,$config['code']),
                        'sort'=> $ldmk,
                    ];
                }
                if ($listing['list']['variation'] && $listing['variants']) {
                    foreach ($listing['variants'] as &$variant) {
                        if (!is_array($variant['variation'])) {
                            $variation = json_decode($variant['variation'],true);
                        } else {
                            $variation = $variant['variation'];
                        }

                        foreach ($variant['path'] as &$vp) {
                            $vPath = preg_replace('/http(s):\/\/[0-9a-z\.]*\//','',$vp);
                            $tmpImg['eps_path'] = GoodsImage::getThumbPath($vPath,0,0,$config['code']);
                            $tmpImg['name'] = $listing['set']['variation_image'];
                            $tmpImg['value'] = $variation[$listing['set']['variation_image']];
                            $vp = $tmpImg;
                        }
                        $typeImgs['sku_imgs'] = array_merge($typeImgs['sku_imgs']??[],$variant['path']);
                        if (empty($variant['channel_map_code'])) {
                            $variant['channel_map_code'] = (new GoodsSkuMapService())->createSkuNotInTable($variant['v_sku']);
                        }
                    }   
                }
                $listing['imgs'] = $typeImgs;
            }

            $item = self::formatItem($listing,$id ? 'json':'array');
            $params['Item'] = $item;
            $verb = $listing['list']['listing_type'] == 1 ? 'verifyAddFixedPriceItem' : 'verifyAddItem';

            $response = EbaySDK::sendRequest('Trading',$config,$verb,$params);
            if ($response['result'] === false) {
                return $response;
            }
            $res = $response['data'];
            $fees = $res['Fees'];
            $fee = self::parseFees($fees, true);
            if (isset($fee['ListingFee'])) {
                $up['listing_fee'] = ($fee['ListingFee']['value']??0).($fee['ListingFee']['currencyID']??'');
            } else {
                $up['listing_fee'] = 0;
            }
            if (isset($fee['InsertionFee'])) {
                $up['insertion_fee'] = ($fee['InsertionFee']['value']??0).($fee['InsertionFee']['currencyID']??'');
            } else {
                $up['insertion_fee'] = 0;
            }
            return ['result'=>true,'data'=>$up];
        } catch (\Throwable $e) {
            return ['result'=>false,'message'=>$e->getMessage()];
        }

    }

    /**
     * 解析刊登费用
     * @param $feesInfo
     * @param bool $simple
     * @return array
     */
    public static function parseFees($feesInfo, $simple=false)
    {
        $detailFee = [];
        //feesinfo格式为Fees = [ 'Fee'=>
        //                        0=>[
        //                               'Name'=>'SubtitleFee',
        //                              'Fee@Atts'=>['currencyID'=>USD]
        //                              'Fee'=>0.0
        //                           ]
        //                       1=>[
        //                              'Name'=>'InsertionFee',
        //                              'Fee@Atts'=>['currencyID'=>USD],
        //                              'Fee'=>0.05
        //                          ]
        //                      3=>[
        //                              'Name'=>'ListingFee',//总费用
        //                              'Fee@Atts'=>['currencyID'=>USD],
        //                              'Fee'=>0.05,
        //                              'PromotionalDiscount@Atts'=>['currencyID'=>USD],
        //                              'PromotionalDiscount'=>0.05
        //                          ]
        //                       ...

        foreach ($feesInfo as $fees) {
            foreach ($fees as $fee) {
                if ($fee['Fee'] != '0.0') {
                    $detailFee[$fee['Name']] = $simple ? $fee['Fee'] : $fee['Fee'].' '.$fee['Fee@Atts']['currencyID'];
                    if (isset($fee['PromotionalDiscount']) && !$simple) {
                        $detailFee[$fee['Name']] .= ','.$fee['PromotionalDiscount'].' '.$fee['PromotionalDiscount@Atts']['currencyID'];
                    }
                }
            }
        }
        if (isset($detailFee['ListingFee'])) {
            $tmp = $detailFee['ListingFee'];
            unset($detailFee['ListingFee']);
            $detailFee['ListingFee'] = $tmp;//把总费用ListingFee排在最后
        }
        return $detailFee;
    }

    /**
     * 打包item类型数据
     * @param $data
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public static function formatItem($data,$dataType='json')
    {
        $item = [];
        $list = $data['list'];
        $set = $data['set'];
        $imgs = $data['imgs'];
        $hasVariant = $list['variation'];
        if ($hasVariant) {
            $variants = $data['variants'];
        }
        $list['currency'] = EbaySite::where('siteid',$list['site'])->value('currency');
        isset($set['application_data']) && $item['ApplicationData'] = $set['application_data'];
        //立即付款
        $item['AutoPay'] = $list['autopay'] ? true : false;
        //议价
        $item['BestOfferDetails']['BestOfferEnabled'] = $list['best_offer'] ? true : false;
        //买家限制
        if ($list['disable_buyer']) {//开启了买家限制
            $brd = $dataType=='json' ? json_decode($set['buyer_requirment_details'],true) : $set['buyer_requirment_details'];
            isset($brd[0]) && $brd = $brd[0];
            $tmpBrd = [];
            if ($brd['requirements']) {
                $tmpBrd['MaximumItemRequirements']['MaximumItemCount'] = (int)$brd['requirements_max_count'];
                $tmpBrd['MaximumItemRequirements']['MinimumFeedbackScore'] = $brd['requirements_feedback_score'];
            }
            if ($brd['strikes']) {
                $tmpBrd['MaximumUnpaidItemStrikesInfo']['Count'] = (int)$brd['strikes_count'];
                $tmpBrd['MaximumUnpaidItemStrikesInfo']['Period'] = $brd['strikes_period'];
            }
            if ($brd['registration']) {
                $tmpBrd['ShipToRegistrationCountry'] = true;
            }
            $item['DisableBuyerRequirements'] = false;
            $item['BuyerRequirementDetails'] = $tmpBrd;
        } else {
            $item['DisableBuyerRequirements'] = true;
        }
        $item['CategoryMappingAllowed'] = true;

        //物品状态
        if ($set['condition_description']) {
            $item['ConditionDescription'] = $set['condition_description'];
        }
        $set['condition_id'] && $item['ConditionID'] = $set['condition_id'];

        //国家
        $item['Country'] = $list['country'];

        //货币单位
        $item['Currency'] = $list['currency'];

        //描述
        $description = self::createDescription($set['description'],$list['mod_style'],$list['mod_sale'],$imgs,$list['title']);
        $item['Description'] = $description;
        //备货时间
        $item['DispatchTimeMax'] = (int)$list['dispatch_max_time'];
        //兼容性
        if ($set['compatibility_count'] > 0) {
            if ($dataType == 'json') {
                $compatibility = (new EbayPublish())->compatibilityJsonToArray($set['compatibility']);
            } else {
                $compatibility = $set['compatibility'];
            }
            $compList = [];
            foreach ($compatibility as $compK => $comp) {
                unset($comp['id']);
                unset($comp['isCheck']);
                $compList['Compatibility'][$compK]['CompatibilityNotes'] = $comp['notes'];
                unset($comp['notes']);
                $compI = 0;
                foreach ($comp as $cK => $c) {
                    $compList['Compatibility'][$compK]['NameValueList'][$compI]['Name'] = ucfirst($cK);
                    if (is_array($c)) {
                        foreach ($c as $ccv) {
                            $compList['Compatibility'][$compK]['NameValueList'][$compI]['Value'][] = $ccv;
                        }
                    } else {
                        $compList['Compatibility'][$compK]['NameValueList'][$compI]['Value'] = [$c];
                    }
                    $compI++;
                }

            }
            $item['ItemCompatibilityList'] = $compList;
        }
        //属性
        $specifics = $dataType=='json' ? json_decode($set['specifics'],true) : $set['specifics'];
        if ($hasVariant) {//多属性需要过滤掉变体的属性
            $varKey  = array_keys($dataType=='json' ? json_decode($variants[0]['variation'],true) : $variants[0]['variation']);
            foreach ($specifics as $specK => $specific) {
                if (in_array($specific['attr_name'],$varKey)) {
                    unset($specifics[$specK]);
                }
            }
            unset($specific);
        }
        if ($specifics) {
            $itemSpec = [];
            $specI = 0;
            foreach ($specifics as $specific) {
                $itemSpec['NameValueList'][$specI]['Name'] = $specific['attr_name'];
                if (is_array($specific['attr_value'])) {
                    foreach ($specific['attr_value'] as $sav) {
                        $itemSpec['NameValueList'][$specI]['Value'][] = $sav;
                    }
                } else {
                    $itemSpec['NameValueList'][$specI]['Value'] = [$specific['attr_value']];
                }
                $specI++;
            }
            $item['ItemSpecifics'] = $itemSpec;
        }
        //listing详情
        if ($list['best_offer']) {
            if ($set['auto_accept_price'] != '0.00') {
                $item['ListingDetails']['BestOfferAutoAcceptPrice'] = [
                    'value' => (float)$set['auto_accept_price'],
                    'currencyID' => $list['currency'],
                ];
            }
            if ($set['minimum_accept_price'] != '0.00') {
                $item['ListingDetails']['MinimumBestOfferPrice'] = [
                    'value' => (float)$set['minimum_accept_price'],
                    'currencyID' => $list['currency'],
                ];
            }
        }
        //刊登周期
        $item['ListingDuration'] = Constants::LISTVAR_EN['listingDuration'][$list['listing_duration']];
        //增强样式
        if ($list['listing_enhancement']) {
            $item['ListingEnhancement'] = ['BoldTitle'];
        }
        //刊登类型
        $item['ListingType'] = $list['listing_type']==1 ? 'FixedPriceItem' : 'Chinese';
        //所在地
        $item['Location'] = $list['location'];
        //付款方式
        $paymentMethods = $dataType=='json' ? json_decode($set['payment_method'],true) : $set['payment_method'];
        if (!$paymentMethods) {
            $item['PaymentMethods'] = ['PayPal'];
        } elseif (is_array($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                $item['PaymentMethods'][] = $paymentMethod;
            }
        } else {
            $item['PaymentMethods'] = [$paymentMethods];
        }
        if ($list['paypal_emailaddress']) {
            $item['PayPalEmailAddress'] = $list['paypal_emailaddress'];
        }
        //刊登图
        $pictureDetails = [];
        $pictureDetails['GalleryType'] = Constants::LISTVAR_EN['pictureGallery'][$list['picture_gallery']];
        foreach ($imgs['publish_imgs'] as $publish_img) {
            $pictureDetails['PictureURL'][$publish_img['sort']] = $publish_img['eps_path'];
        }
        ksort($pictureDetails['PictureURL']);
        $item['PictureDetails'] = $pictureDetails;
        //邮编
        if ($set['postal_code']) {
            $item['PostalCode'] = $set['postal_code'];
        }
        //第一分类
        $item['PrimaryCategory']['CategoryID'] = (string)$list['primary_categoryid'];
        //私人listing
//        $item['PrivateListing'] = $list['private_listing'];
        //产品详情
        $productListingDetails = [];
        $productListingDetails['BrandMPN']['Brand'] = $set['brand']??'Unbranded';
        $productListingDetails['BrandMPN']['MPN'] = $set['mpn']??'Does not apply';
        if (!$hasVariant) {//单属性
            $productListingDetails['EAN'] = $set['ean']??'Does not apply';
            $productListingDetails['ISBN'] = $set['isbn']??'Does not apply';
            $productListingDetails['UPC'] = $set['upc']??'Does not apply';
        }
        $item['ProductListingDetails'] = $productListingDetails;
        //数量
        if (!$hasVariant) {
            $item['Quantity'] = (int)trim($list['quantity']);
        }
        //退货政策
        $returnPolicy = [];
        $returnPolicy['ReturnsAcceptedOption'] = Constants::LISTVAR_EN['returnPolicy'][$set['return_policy']];
        if ($set['return_policy']) {//接受退货
            if ($set['return_description']) {
                $returnPolicy['Description'] = $set['return_description'];
            }
            $returnPolicy['RefundOption'] = $set['return_type'];
            $returnPolicy['ReturnsWithinOption'] = Constants::LISTVAR_EN['returnTime'][$list['return_time']];
            $returnPolicy['ShippingCostPaidByOption'] = Constants::LISTVAR_EN['returnShippingOption'][$set['return_shipping_option']]??'Buyer';
        }
        $item['ReturnPolicy'] = $returnPolicy;
        //第二分类
        if ($list['second_categoryid']) {
            $item['SecondaryCategory']['CategoryID'] = (string)$list['second_categoryid'];
        }
        //物流
        $shippingDetails = [];
        //不运送地区
        if ($set['custom_exclude'] == 1) {
            $shippingDetails['ExcludeShipToLocation'] = ['none'];
        } elseif ($set['custom_exclude'] == 3) {
            $excludeLocation = $dataType=='json' ? json_decode($set['exclude_location'],true) : explode('，',$set['exclude_location']);
            foreach ($excludeLocation as $exLoc) {
                $shippingDetails['ExcludeShipToLocation'][] = $exLoc;
            }
        }
        //国际物流
        $internationalShippings = $dataType=='json' ? json_decode($set['international_shipping'],true) : $set['international_shipping'];
        if ($internationalShippings) {
            $is = [];
            foreach ($internationalShippings as $isK => $internationalShipping) {
                $is[$isK]['ShippingService'] = $internationalShipping['shipping_service'];
                $is[$isK]['ShippingServiceCost'] = [
                    'value' => floatval($internationalShipping['shipping_service_cost']),
                    'currencyID' => $list['currency'],
                ];
                $is[$isK]['ShippingServiceAdditionalCost'] = [
                    'value' => floatval($internationalShipping['shipping_service_additional_cost']),
                    'currencyID' => $list['currency'],
                ];
                $is[$isK]['ShippingServicePriority'] = $isK+1;
                if (is_array($internationalShipping['shiptolocation'])) {
                    foreach ($internationalShipping['shiptolocation'] as $shipLocation) {
                        $is[$isK]['ShipToLocation'][] = $shipLocation;
                    }
                } else if (!empty($internationalShipping['shiptolocation'])) {
                    $is[$isK]['ShipToLocation'] = [$internationalShipping['shiptolocation']];
                }
            }
            $shippingDetails['InternationalShippingServiceOption'] = $is;
        }
        //国内物流
        $shippings = $dataType=='json' ? json_decode($set['shipping'],true) : $set['shipping'];
        if ($shippings) {
            $s = [];
            foreach ($shippings as $sK => $shipping) {
                $s[$sK]['ShippingService'] = $shipping['shipping_service'];
                $s[$sK]['ShippingServiceCost'] = [
                    'value' => floatval($shipping['shipping_service_cost']),
                    'currencyID' => $list['currency'],
                ];
                $s[$sK]['ShippingServiceAdditionalCost'] = [
                    'value' => floatval($shipping['shipping_service_additional_cost']),
                    'currencyID' => $list['currency'],
                ];
                $s[$sK]['ShippingServicePriority'] = $sK+1;
                if ((float)$shipping['extra_cost'] > 0) {
                    $s[$sK]['ShippingSurcharge'] = [
                        'value' => floatval($shipping['extra_cost']),
                        'currencyID' => $list['currency'],
                    ];
                }
            }
            $shippingDetails['ShippingServiceOptions'] = $s;
        }
        //付款说明
        if ($set['payment_instructions']) {
            $shippingDetails['PaymentInstructions'] = $set['payment_instructions'];
        }
        //销售税
        if ($list['sales_tax'] && $list['sales_tax_state']) {
            $shippingDetails['SalesTax']['SalesTaxPercent'] = (float)$list['sales_tax'];
            $shippingDetails['SalesTax']['SalesTaxState'] = $list['sales_tax_state'];
        }

        $shippingDetails['SalesTax']['ShippingIncludedInTax'] = $list['shipping_tax'] ? true : false;
        $item['ShippingDetails'] = $shippingDetails;
        //送达地区
        $shipLocations = $dataType=='json' ? json_decode($set['ship_location'], true) : ($set['ship_location']??[]);
        if (is_array($shipLocations)) {
            foreach ($shipLocations as $shipLocation) {
                $item['ShipToLocations'][] = $shipLocation;
            }
        } elseif (!empty($shipLocations)) {
            $item['ShipToLocations'] = [$shipLocations];
        }
        //站点
        $siteInfo = EbaySite::where('siteid',$list['site'])->field('country')->find();
        $item['Site'] = $siteInfo['country'];
        //平台SKU
        $item['SKU'] = $list['listing_sku'];
        //单属性价格
        if (!$hasVariant) {
            $item['StartPrice'] = [
                'value' => floatval($list['start_price']),
                'currencyID' => $list['currency'],
            ];
            if ((float)$list['reserve_price'] > 0) {
                $item['ReservePrice'] = [
                    'value' => floatval($list['reserve_price']),
                    'currencyID' => $list['currency'],
                ];
            }
            if ((float)$list['buy_it_nowprice'] > 0) {
                $item['BuyItNowPrice'] = [
                    'value' => floatval($list['buy_it_nowprice']),
                    'currencyID' => $list['currency'],
                ];
            }
        }
        //店铺分类
        if ($list['store_category_id']) {
            $item['Storefront']['StoreCategoryID'] = (int)$list['store_category_id'];
        }
        if ($list['store_category2_id']) {
            $item['Storefront']['StoreCategory2ID'] = (int)$list['store_category2_id'];
        }
        //副标题
        if ($list['sub_title']) {
            $item['SubTitle'] = $list['sub_title'];
        }
        //标题
        $item['Title'] = $list['title'];

        //变体
        if ($hasVariant) {
            $variations = [];
            //变体图片
            $variations['Pictures']['VariationSpecificName'] = $set['variation_image'];
            $pictureSet = [];
            if (isset($imgs['sku_imgs'])) {
                foreach ($imgs['sku_imgs'] as $sku_img) {
                    $pictureSet[$sku_img['value']]['VariationSpecificValue'] = $sku_img['value'];
                    $pictureSet[$sku_img['value']]['PictureURL'][] = $sku_img['eps_path'];
                }
                $pictureSet = array_values($pictureSet);
            }
            if ($pictureSet) {
                $variations['Pictures']['VariationSpecificPictureSet'] = $pictureSet;
            }
            $variations['Pictures'] = [$variations['Pictures']];
            $vsSet = [];
            foreach ($variants as $variant) {
                $variation['Quantity'] = (int)trim($variant['v_qty']);
                $variation['SKU'] = $variant['channel_map_code'];
                $variation['StartPrice'] = [
                    'value' => floatval($variant['v_price']),
                    'currencyID' => $list['currency'],
                ];
                $variation['VariationProductListingDetails'] = [
                    'EAN' => $variant['ean']??'Does not apply',
                    'ISBN' => $variant['isbn']??'Does not apply',
                    'UPC' => $variant['upc']??'Does not apply',
                ];
                //变体属性
                $vs = $dataType=='json' ? json_decode($variant['variation'],true) : $variant['variation'];
                $varSpec = [];
                $varNameValueListI = 0;
                foreach ($vs as $vsName => $vsValue) {
                    $varSpec[0]['NameValueList'][$varNameValueListI]['Name'] = $vsName;
                    if (is_array($vsValue)) {
                        foreach ($vsValue as $vv) {
                            $varSpec[0]['NameValueList'][$varNameValueListI]['Value'][] = trim($vv);
                        }
                    } else {
                        $varSpec[0]['NameValueList'][$varNameValueListI]['Value'] = [trim($vsValue)];
                    }
                    $varNameValueListI++;
                    $vsValue = is_array($vsValue) ? $vsValue : [trim($vsValue)];
                    if (isset($vsSet[$vsName])) {//已存在
                        $vsSet[$vsName]['value'] = array_merge($vsSet[$vsName]['value'],$vsValue);
                    } else {//不存在
                        $vsSet[$vsName]['value'] = $vsValue;
                    }
                }
                $variation['VariationSpecifics'] = $varSpec;
                $variations['Variation'][] = $variation;
            }
            $vssNVL = [];
            $vssnvlI = 0;
            foreach ($vsSet as $vssName => $vss) {
                $vssNVL[$vssnvlI]['Name'] = $vssName;
                $tmpValue = [];
                foreach ($vss['value'] as $v) {
                    if (in_array(trim($v),$tmpValue)) {
                        continue;
                    }
                    $tmpValue[] = trim($v);
                }
                $vssNVL[$vssnvlI]['Value'] = $tmpValue;
                $vssnvlI++;
            }
            $variations['VariationSpecificsSet']['NameValueList'] = $vssNVL;
            $item['Variations'] = $variations;
        }
        //增值税
        if ((float)$list['vat_percent'] > 0) {
            $item['VATDetails']['VATPercent'] = (float)$list['vat_percent'];
        }
        return $item;
    }

    /**
     * 生成描述
     * @param $desc
     * @param $styleId
     * @param $saleId
     * @param $imgs
     * @param $title
     * @return mixed|string|string[]|null
     * @throws \think\exception\DbException
     */
    public static function createDescription($desc, $styleId, $saleId, $imgs, $title)
    {
        $style = EbayModelStyle::get($styleId);
        $sale = EbayModelSale::get($saleId);
        $pdImgs = array_merge($imgs['publish_imgs'],$imgs['detail_imgs']??[]);
        //将描述中图片链接替换为ebay图库地址
        $desc = preg_replace_callback('/http(s)?:\/\/((14\.118\.130\.19)|(img\.rondaful\.com))(:\d+)?(\/\w+)+\.((jpg)|(gif)|(bmp)|(png))/',
            function($matches) use ($pdImgs){
                foreach ($pdImgs as $pdImg) {
                    if ('http://img.rondaful.com/'.$pdImg['path'] == $matches[0]
                        || 'https://img.rondaful.com/'.$pdImg['path'] == $matches[0]) {
                        return $pdImg['eps_path'];//将图片地址替换为eps地址
                    }
                }
            }, $desc);
        if ($style) {
            $desc = str_replace('[DESCRIBE]',$desc, $style['style_detail']);
            $desc = str_replace('[TITLE]',$title, $desc);
        }
        if($sale){
             $desc = str_replace('[Payment]',$sale['payment']??'', $desc);//付款
            $desc = str_replace('[Shipping]',$sale['delivery_detail']??'', $desc);//提货
            $desc = str_replace('[Terms of Sale]',$sale['terms_of_sales']??'', $desc);//销售条款
            $desc = str_replace('[About Me]',$sale['about_us']??'', $desc);//关于我们
            $desc = str_replace('[Contact Us]',$sale['contact_us']??'', $desc);//联系我们
        }
        $imgsStr = '';
        $i=1;
        $k=1;
        foreach ($imgs['publish_imgs'] as $publishImg) {//橱窗图
            $imgsStrNum = '<img src='.$publishImg['eps_path'].'>';
            $desc = str_replace("[IMG".$i."]", $imgsStrNum, $desc);
            $i++;
        }
        foreach ($imgs['detail_imgs']??[] as $detailImg) {//详情图
            $imgsStr .= '<img src='.$detailImg['eps_path'].'>';
            $imgDetail = '<img src='.$detailImg['eps_path'].'>';
            $desc = str_replace("[TIMG".$k."]",$imgDetail,$desc);
            $k++;
        }
        //如果实际图片数量比占位符数量少，把未使用的占位符清除掉
        while ($i <= (Constants::MAX_MAIN_IMG_NUM) || $k <= (Constants::MAX_DETAIL_IMG_NUM)){
            if ($i <= (Constants::MAX_MAIN_IMG_NUM)) {
                $desc = str_replace("[IMG".$i++."]","",$desc);
            }
            if ($k <= (Constants::MAX_DETAIL_IMG_NUM)) {
                $desc = str_replace("[TIMG".$k++."]","",$desc);
            }
        }
        //快捷替换。直接把PICTURE占位符替换成所有的详情描述图。PICTURE与TIMG占位符不应该共存
        $desc = str_replace("[PICTURE]", $imgsStr, $desc);
        return $desc;
    }


    /**
     * 上传图片到ebay图库
     * @param $imgs
     * @param $config
     * @param bool $multi 是否使用多进程上传
     */
    public static function UploadSiteHostedPictures(&$imgs, $config, $multi=false)
    {
        $needUpload = [];
        foreach ($imgs as &$img) {
            if ($img['eps_path']) {
                continue;
            }
            if (strpos($img['path'],'https://i.ebayimg.com') !== false) {//本身是ebay图库地址
                $img['eps_path'] = $img['path'];
            } else {
                $path = preg_replace('/http(s)?:\/\/img.rondaful.com\//','',$img['path']);
                $img['path'] = $path;
                $needUpload[$path]['ser_path'] = GoodsImage::getThumbPath($path,0,0,$config['code'],true);
            }
        }
        if (!$needUpload) {
            return;
        }
        $packApi = (new EbayPackApi());
        $api = $packApi->createApi($config,'UploadSiteHostedPictures',0);//$config['site_id']);

        //最多上传5次
        for ($i=0; $i<5; $i++) {
            $unCompleteFlag = 0;
            if ($multi) {//并发上传
                $count = count($needUpload);
                $loop = $count/10;

                for ($j=0;$j<$loop;$j++) {//10个一组上传
                    $imgNum = 0;
                    $xml = [];
                    foreach ($needUpload as $k => $nu) {
                        if (empty($nu['eps_path'])) {
                            $xml[$k] = $packApi->createXml($nu['ser_path']);
                            $imgNum++;
                        }
                        if ($imgNum === 10) {
                            break;
                        }
                    }
                    if (!$xml) {//上传完毕
                        $unCompleteFlag = 0;
                        break;
                    }
                    $unCompleteFlag = 1;
                    $response = $api->createHeaders()->sendHttpRequestMulti($xml);
                    if (!$response) {
                        foreach ($xml as $xk => $xv) {
                            $needUpload[$xk]['message'] = '网络错误，请重试';
                        }
                        continue;
                    }
                    foreach ($response as $rk => $rv) {
                        $res = $rv['UploadSiteHostedPicturesResponse'];
                        if ($res['Ack'] == 'Failure') {
                            $msg = EbaySDK::dealApiError($res);
                            $needUpload[$rk]['message'] = $msg;
                            continue;
                        }
                        $siteHostedPictureDetails = $res['SiteHostedPictureDetails'];
                        $url = $siteHostedPictureDetails['PictureSetMember'][3]['MemberURL'] ?? $siteHostedPictureDetails['FullURL'];
                        $needUpload[$rk]['eps_path'] = $url;
                        unset($needUpload[$rk]['message']);//清除错误信息
                    }
                }
            } else {//一张张上传
                foreach ($needUpload as &$nu) {
                    if (!empty($nu['eps_path'])) {
                        continue;
                    }
                    $unCompleteFlag = 1;
                    $xml = $packApi->createXml($nu['ser_path']);
                    $response = $api->createHeaders()->__set('requesBody', $xml)->sendHttpRequest2();

                    if (!$response) {
                        $nu['message'] = '网络错误，请重试';
                        continue;
                    }
                    $response = $response['UploadSiteHostedPicturesResponse'];
                    if ($response['Ack'] == 'Failure') {
                        $msg = EbaySDK::dealApiError($response);
                        $nu['message'] = $msg;
                        continue;
                    }
                    $siteHostedPictureDetails = $response['SiteHostedPictureDetails'];
                    $url = $siteHostedPictureDetails['PictureSetMember'][3]['MemberURL'] ?? $siteHostedPictureDetails['FullURL'];
                    $nu['eps_path'] = $url;
                    unset($nu['message']);//清除错误信息
                }
            }
            if (!$unCompleteFlag) {
                break;
            }
        }
        foreach ($imgs as &$img) {
            if (isset($needUpload[$img['path']])) {
                $img['eps_path'] = $needUpload[$img['path']]['eps_path'] ?? '';
                $img['message'] = $needUpload[$img['path']]['message'] ?? '';
            }
        }
    }


    /**
     * 获取ebay详情
     * @param $detailName
     * @param int $accountId
     * @param int $siteId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
//    public static function geteBayDetails($detailName, $accountId=0, $siteId=0)
//    {
//        if ($accountId) {
//            $wh['id'] = $accountId;
//        } else {
//            $wh = [
//                'is_invalid' => 1,
//                'account_status' => 1,
//                'token' => ['<>',''],
//            ];
//        }
//        $account = EbayAccount::where($wh)->field(EbayPublish::ACCOUNT_FIELD_TOKEN)->find();
//        $config = $account->toArray();
//        $config['site_id'] = $siteId;
//        $param['DetailName'] = [$detailName];
//        $response = EbaySDK::sendRequest('Trading',$config,'geteBayDetails',$param);
//        if ($response['result'] === false) {
//            throw new Exception($response['message']);
//        }
//        $data = $response['data'];
//
//    }




    /**
     * 获取指定item
     * @param array $data itemId,id必须存在一个
     * @param int $userId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
//    public static function getItem($data, $userId=0)
//    {
//        $itemId = $data['itemId'];
//
//        //获取token
//        $wh['item_id'] = $itemId;
//        $field = 'id,item_id,account_id,site';
//        $listing = EbayListing::where($wh)->field($field)->find();
//        if ($listing) {//如果有对应的listing,取对应的listing店铺id
//            $account = EbayAccount::field(EbayPublish::ACCOUNT_FIELD_TOKEN)->where('id',$listing['account_id'])->find();
//            $account->site = $listing['site'];
//        } else {//如果没有对应的listing,任意取一个有效的
//            $whAccount = [
//                'is_invalid' => 1,
//                'account_status' => 1,
//                'token' => ['<>',''],
//            ];
//            $account = EbayAccount::where($whAccount)->field(EbayPublish::ACCOUNT_FIELD_TOKEN)->find();
//        }
//
//        $config = $account->toArray();
//        $param = [
//            'ItemId' => $itemId,
//            'DetailLevel' => ['ReturnAll'],
//        ];
//        $response = EbaySDK::sendRequest('Trading',$config,'getItem',$param);
//        if ($response['result'] === false) {
//            throw new Exception($response['message']);
//        }
//        $response = $response['data'];
//
//
//    }
//
//
//    public static function dealRecItemData($data)
//    {
//        $item = $data['Item'];
//    }

}
