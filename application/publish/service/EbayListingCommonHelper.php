<?php
namespace app\publish\service;

use app\common\model\ebay\EbayCategory;
use app\common\model\ebay\EbayCommonBargaining;
use app\common\model\ebay\EbayCommonChoice;
use app\common\model\ebay\EbayCommonExclude;
use app\common\model\ebay\EbayCommonGallery;
use app\common\model\ebay\EbayCommonIndividual;
use app\common\model\ebay\EbayCommonLocation;
use app\common\model\ebay\EbayCommonPickup;
use app\common\model\ebay\EbayCommonQuantity;
use app\common\model\ebay\EbayCommonReceivables;
use app\common\model\ebay\EbayCommonRefuseBuyer;
use app\common\model\ebay\EbayCommonReturn;
use app\common\model\ebay\EbayCommonTrans;
use app\common\model\ebay\EbayCommonTransDetail;
use app\common\model\ebay\EbayModelPromotion;
use app\common\model\ebay\EbayModelSale;
use app\common\model\ebay\EbayModelStyle;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsCategoryMap;
use app\common\model\GoodsLang;
use app\common\model\GoodsSku;
use app\common\model\GoodsSkuMap;
use app\common\model\Currency;
use app\common\model\ebay\EbayCustomCategory;
use app\common\model\ebay\EbayListing;
use app\common\model\ebay\EbayListingImage;
use app\common\model\ebay\EbayListingSetting;
use app\common\model\ebay\EbayListingVariation;
use app\common\model\ebay\EbayListingMappingSpecifics;
use app\common\model\ebay\EbayListingSerialNumber;
use app\common\model\ebay\EbayCategorySpecific;

use app\common\service\Common;
use app\common\model\ebay\EbayAccount;
use app\publish\controller\EbayCommon;
use app\publish\helper\ebay\EbayPublish;
use service\ebay\EbayApi;
use app\common\service\SwooleQueueJob;
use think\Db;
use app\common\cache\Cache;
use think\Exception;
use app\goods\service\GoodsImage;
use app\goods\service\GoodsSkuMapService;
use app\index\service\MemberShipService;
use app\common\traits\User;
use app\publish\service\EbayConstants as Constants;
use app\common\model\mongodb\ebay\EbayListingSetting as ELSMongo;


/**
 * Rondaful
 * 17-12-19
 */

class EbayListingCommonHelper
{
    use User;
    private $accountId = null;
    private $cache = null;
    private $sites = [];
    public $listingId  = 0;
    private $token = '';

    public function __construct($accountId = 0)
    {
        $accountId && $this->accountId = $accountId;
        $this->cache = Cache::store('ebayRsyncListing');
    }

    /**
     * @param int $listId
     * @return array
     * @throws Exception
     */
    public function getListImgs(int $listId) : array
    {
        try {
            $imgModel = new EbayListingImage();
            $allImgs = $imgModel->field(true)->where(['listing_id'=>$listId])->select();
            $imgs = $imgModel->field(true)->where(['listing_id'=>$listId, 'main'=>1])->order('sort')->select();
            $detailImgs = $imgModel->field(true)->where(['listing_id'=>$listId, 'detail'=>1])->order('de_sort')->select();
            $skuImgs = $imgModel->field(true)->where(['listing_id'=>$listId, 'main_de'=>1])->order('sku_sort')->select();
            $uniqueImgs = $imgModel->distinct('ser_path')->field(true)->where(['listing_id'=>$listId])->select();
//            $imgs =
//            $imgs = [];
//            $detailImgs = [];
//            $skuImgs = [];
//            foreach ($allImgs as $k => $allImg) {
//                if (empty($allImg['path'])) {
//                    $allImgs[$k]['path'] = $allImg['thumb'];
//                }
//                $allImg['main'] == 1 && $imgs[] = $allImg;
//                $allImg['detail'] == 1 && $detailImgs[] = $allImg;
//                $allImg['main_de'] == 1 && $skuImgs[] = $allImg;
//            }
//            uasort($imgs, function($a, $b){
//               if ($a['sort'] == $b['sort']) return 0;
//               return $a['sort'] > $b['sort'] ? 1 : -1;
//            });
//            uasort($detailImgs, function($a, $b){
//                if ($a['de_sort'] == $b['de_sort']) return 0;
//                return $a['de_sort'] > $b['de_sort'] ? 1 : -1;
//            });
//            uasort($skuImgs, function($a, $b){
//                if ($a['sku_sort'] == $b['sku_sort']) return 0;
//                return $a['sku_sort'] > $b['sku_sort'] ? 1 : -1;
//            });
            $row['all_imgs'] = $allImgs;
            $row['imgs'] = $imgs;//主图
            $row['detail_imgs'] = $detailImgs;//详情图
            $row['sku_imgs'] = $skuImgs;//子产品图
            $row['unique_imgs'] = $uniqueImgs;
            return $row;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取分类链
     * @param int $categoryId
     * @return string
     * @throws Exception
     */
    public function getCategoryChain(int $categoryId, int $site)
    {
        try {
            $name = '';
            $tmpId = $categoryId;
            while (1) {
                $categoryInfo = (new EbayCategory())->field('category_id, category_name, category_parent_id')
                    ->where(['category_id'=>$tmpId, 'site'=>$site])->find();
                $name = $categoryInfo['category_name'].(empty($name) ? '' : '>>'.$name);
                if ($categoryInfo['category_parent_id'] == $tmpId) break;
                $tmpId = $categoryInfo['category_parent_id'];

            }
            return $categoryId.'-'.$name;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 打包list
     * @param array $data
     * @param int $userId
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array
     * @throws Exception
     */
    public function packList(array $data, int $userId, int $dealType) : array
    {
        try {
            $list = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $list = $data;
//                $accountInfo = Cache::store('EbayAccount')->getTableRecord($data['account_id']);
                $accountInfo = EbayAccount::get($data['account_id'])->toArray();
                $list['account_name'] = $accountInfo['code'];
                $site = Cache::store('EbaySite')->getSiteInfoBySiteId($data['site']);
                if ($site) {
                    $list['time_zone'] = $site['time_zone'];
                }
//                $ebayCategoryCache = Cache::store('EbayCategoryCache');
                $list['primary_category_pahtname'] = $this->getCategoryChain($data['primary_categoryid'], $list['site']);
                if (intval($data['second_categoryid'])) {
                    $list['second_category_name'] = $this->getCategoryChain($data['second_categoryid'], $list['site']);
                }
                if (intval($data['store_category_id'])) {
                    $cateTree = $this->getCustomCateTree($data['account_id'], intval($data['store_category_id']),'');
                    $list['store_name'] = $data['store_category_id'].'-'.substr($cateTree,1);
                }
                if (intval($data['store_category2_id'])) {
                    $cateTree = $this->getCustomCateTree($data['account_id'],$data['store_category2_id'],'');
                    $list['second_store_name'] = $data['store_category2_id'].'-'.substr($cateTree,1);
                }
            } else if ($dealType == Constants::DATA_TO_STORE) {
                $list['id'] = isset($data['id']) ? $data['id'] : 0;
                isset($data['item_id']) && $list['item_id'] = $data['item_id'];
                isset($data['draft']) && $list['draft'] = $data['draft'];//是否为范本
                isset($data['goods_id']) && $list['goods_id'] = intval($data['goods_id']);//未关联的情况下，可以为空
                if (!empty($list['goods_id'])) {
                    $goodsInfo = Cache::store('Goods')->getGoodsInfo($list['goods_id']);
                    $list['sale_status'] = $goodsInfo['sales_status'];//销售状态1-在售 2-停售 3-待发布 4-卖完下架 5-缺货
                }
                isset($data['listing_type']) && $list['listing_type'] = $data['listing_type'];//销售类型(1:一口价FixedPriceItem,2:Chinese)
                isset($data['goods_type']) && $list['goods_type'] = $data['goods_type'];//是否捆绑产品(1:捆绑产品，0:不是捆绑)
                isset($data['draft_name']) && $list['draft_name'] = $data['draft_name'];
                isset($data['account_id']) && $list['account_id'] = $data['account_id'];//平台账号
                if (empty($list['account_id'])) {
                    throw new Exception('账号不能为空');
                }
                $list['spu'] = empty($data['spu']) ? '' : $data['spu'];//本地spu，未关联可以为空
                //                    if (empty($list['spu'])) {
                //                        throw new Exception('spu不能为空');
                //                    }
                $list['assoc_order'] = isset($data['assoc_order']) ? intval($data['assoc_order']) : 1;//是否关联订单，0：不关联，1：关联
                $list['variation'] = isset($data['variation']) ? intval($data['variation']) : 0;//是否多属性
                if ($list['spu']) {
                    if ($list['variation']) { //多属性
                        isset($data['local_sku']) && $list['local_sku'] = $data['local_sku'];
                        $list['sku'] = empty($data['sku']) ? $list['spu'] : $data['sku'];
                    } else { //单属性
                        $list['local_sku'] = empty($data['local_sku']) ? $list['spu'].'00' : $data['local_sku'];
                        $list['sku'] = empty($data['sku']) ? $list['local_sku']*1 : $data['sku'];
                    }
                }
                //                    if ($data['listing_sku']) {
                //                        $list['listing_sku'] = $data['listing_sku'];
                //                    } else {
                //                        目前先按下面的方式写订单关联，后面此方法里只生成listing_sku,不再写订单关联，订单关联放到刊登的时候处理
                //                    }
                $list['listing_sku'] = empty($data['listing_sku']) ? $this->createSku($list, $userId, $list['assoc_order']) : $data['listing_sku'];
                //                    isset($data['sku']) && $list['sku'] = $data['sku'];//组合sku
                //                    $list['local_sku'] = isset($data['local_sku'])?$data['local_sku']:'';//本地sku

                if ($list['id'] == 0) {//新增
                    $list['application'] = 1;//本系统刊登标识
                    $list['create_date'] = time();//创建时间
                    $list['realname'] = $userId;//创建人id
                    $list['shared_userid'] = 0;//共享者id
                    $list['listing_status'] = 0;//listing状态,0提交1待刊登2刊登中3在线4失败5待更新6更新失败7待下架8下架中9已下架11已结束，12伪删除，13重上,14重上失败,21等待图片上传
                }

                $list['site'] = isset($data['site'])?intval($data['site']):0;//站点id
                $siteInfo = Cache::store('EbaySite')->getSiteInfoBySiteId($list['site']);
                $list['currency'] = $siteInfo['currency'];//币种
                isset($data['paypal_emailaddress']) && $list['paypal_emailaddress'] = $data['paypal_emailaddress'];//收款账号
                isset($data['primary_categoryid']) && $list['primary_categoryid'] = $data['primary_categoryid'];//一级分类
                if(empty($list['primary_categoryid'])){
                    throw new Exception('一级分类不能为空');
                }
                isset($data['second_categoryid']) && $list['second_categoryid'] = $data['second_categoryid'];//二级分类
                isset($data['private_listing']) && $list['private_listing'] = $data['private_listing'];//是否为私人物品
                isset($data['picture_gallery']) && $list['picture_gallery'] = $data['picture_gallery'];//橱窗展示0:None，1:Gallery，2:Featured，3:Plus
                isset($data['location']) && $list['location'] = $data['location'];//物品所在地
                isset($data['country']) && $list['country'] = $data['country'];//发货国家代码
                isset($data['dispatch_max_time']) && $list['dispatch_max_time'] = $data['dispatch_max_time'];//最大备货时间0:当天发货，1，2，3，4，5，10，15，20，30
                isset($data['listing_duration']) && $list['listing_duration'] = $data['listing_duration'];//刊登天数1:GTC，2:1，3:3，4:5，5:7，6:10，7:30
                isset($data['autopay']) && $list['autopay'] = $data['autopay'];//是否开启立即付款
                isset($data['quantity']) && $list['quantity'] = intval($data['quantity']);//上架数
                isset($data['preset_quantity']) && $list['preset_quantity'] = $data['preset_quantity'];//预设库存
                isset($data['sold_quantity']) && $list['sold_quantity'] = intval($data['sold_quantity']);//售出数量
                isset($data['best_offer']) && $list['best_offer'] = $data['best_offer'];//是否接受买家还价
                isset($data['buy_it_nowprice']) && $list['buy_it_nowprice'] = $data['buy_it_nowprice'];//拍卖价(拍卖立即成交价)
                isset($data['reserve_price']) && $list['reserve_price'] = $data['reserve_price'];//拍卖最低成交价(拍卖保留价)
                isset($data['start_price']) && $list['start_price'] = $data['start_price'];//一口价(固定价格一口价，拍卖价格起始价)
                isset($data['minimum_to_bid']) && $list['minimum_to_bid'] = number_format(floatval($data['minimum_to_bid']),2);//每拍一次增加的价格
                isset($data['sales_tax']) && $list['sales_tax'] = $data['sales_tax'];//销售税率
                isset($data['sales_tax_state']) && $list['sales_tax_state'] = $data['sales_tax_state'];//销售税国家
                isset($data['shipping_tax']) && $list['shipping_tax'] = $data['shipping_tax'];//运费是否含税
                isset($data['vat_percent']) && $list['vat_percent'] = $data['vat_percent'];//增值税
                isset($data['img']) && $list['img'] = $data['img'];//主图
                isset($data['title']) && $list['title'] = $data['title'];//刊登标题
                isset($data['sub_title']) && $list['sub_title'] = $data['sub_title'];//刊登副标题
                isset($data['hit_count']) && $list['hit_count'] = intval($data['hit_count']);//点击量
                isset($data['hit_counter']) && $list['hit_counter'] = intval($data['hit_counter']);//hit_count显示类型0：NoHitCounter,1:BasicStyle,2:hiddenStyle,3:RetroStyle,4:GreedLED,5:Hidden,6:HonestyStyle
                isset($data['watch_count']) && $list['watch_count'] = intval($data['watch_count']);//收藏量
                isset($data['store_category_id']) && $list['store_category_id'] = intval($data['store_category_id']);//店铺分类1
                isset($data['store_category2_id']) && $list['store_category2_id'] = intval($data['store_category2_id']);//店铺分类2
                isset($data['listing_enhancement']) && $list['listing_enhancement'] = intval($data['listing_enhancement']);//标题加粗显示0否，1是
                isset($data['disable_buyer']) && $list['disable_buyer'] = $data['disable_buyer'];//是否开启买家限制0否，1是
                isset($data['start_date']) && $list['start_date'] = intval($data['start_date']);//刊登时间
                isset($data['end_date']) && $list['end_date'] = intval($data['end_date']);//下架时间
                $list['update_date'] = time();//更新时间
                isset($data['insertion_fee']) && $list['insertion_fee'] = number_format(floatval($data['insertion_fee']),2);//刊登费用
                isset($data['listing_fee']) && $list['listing_fee'] = number_format(floatval($data['listing_fee']),2);//上市费用
                isset($data['listing_cate']) && $list['listing_cate'] = $data['listing_cate'];//listing分类
                isset($data['model_cate']) && $list['model_cate'] = $data['model_cate'];//范本分类
                isset($data['timing']) && $list['timing'] = strtotime($data['timing']);//定时刊登时间
//                isset($data['comb_model_id']) && $list['comb_model_id'] = $data['comb_model_id'];//引用模板组合ID
//                isset($data['promotion_id']) && $list['promotion_id'] = $data['promotion_id'];//促销策略id
                $list['user_id'] = $userId;//更新人id
                isset($data['mod_style']) && $list['mod_style'] = intval($data['mod_style']);//风格模板id
                isset($data['mod_sale']) && $list['mod_sale'] = intval($data['mod_sale']);//销售说明模板id
//                isset($data['mod_trans']) && $list['mod_trans'] = intval($data['mod_trans']);//物流模板id
//                isset($data['mod_exclude']) && $list['mod_exclude'] = intval($data['mod_exclude']);//不运送地区模板id
//                isset($data['mod_location']) && $list['mod_location'] = intval($data['mod_location']);//物品所在地模板id
//                isset($data['mod_return']) && $list['mod_return'] = intval($data['mod_return']);//退货模板id
//                isset($data['mod_refuse']) && $list['mod_refuse'] = intval($data['mod_refuse']);//买家限制模板id
//                isset($data['mod_receivables']) && $list['mod_receivables'] = intval($data['mod_receivables']);//收款模板id
//                isset($data['mod_promotion']) && $list['mod_promotion'] = intval($data['mod_promotion']);//促销模板id
//                isset($data['mod_choice']) && $list['mod_choice'] = intval($data['mod_choice']);//备货模板id
//                isset($data['mod_pickup']) && $list['mod_pickup'] = intval($data['mod_pickup']);//自提模板id
//                isset($data['mod_gallery']) && $list['mod_gallery'] = intval($data['mod_gallery']);//橱窗展示模板id
//                isset($data['mod_individual']) && $list['mod_individual'] = intval($data['mod_individual']);//私人物品模板id
//                isset($data['mod_bargaining']) && $list['mod_bargaining'] = intval($data['mod_bargaining']);//议价模板id
//                isset($data['mod_quantity']) && $list['mod_quantity'] = intval($data['mod_quantity']);//物品数量模板id
                isset($data['draft_id']) && $list['draft_id'] = intval($data['draft_id']);//范本id(0为没设范本)
                isset($data['rule_id']) && $list['rule_id'] = intval($data['rule_id']);//定时规则id
                isset($data['replen']) && $list['replen'] = intval($data['replen']);//是否自动补货
                isset($data['restart']) && $list['restart'] = intval($data['restart']);//是否重新刊登
                isset($data['return_time']) && $list['return_time'] = intval($data['return_time']);//退货周期1：14Days,2:30Days,3:60Days
            }
            return $list;
        }catch (Exception $e){
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包set
     * @param array $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array
     * @throws Exception
     */
    public function packSet(array $data, int $dealType) : array
    {
        try{
            $set = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $set = $data;
                $set['exclude_location'] = $this->packExclude($data['exclude_location'], $dealType);
                $set['ship_location'] = json_decode($data['ship_location'],true);
                $set['international_shipping'] = $this->packInternalShipping($data['international_shipping'],$dealType);
                $set['shipping'] = $this->packShipping($data['shipping'],$dealType);
                $set['payment_method'] = $this->packPaymethod($data['payment_method'], $dealType);
                //买家限制 兼容旧数据格式
                $set['buyer_requirment_details'] = $this->packBuyerRequirementDetail($set['buyer_requirment_details'], $dealType);
                $compatibility = json_decode($data['compatibility'],true);
                $set['compatibility'] = $compatibility ?? [];
                $packCompatibility = [];
                if (!empty($compatibility)) {
                    foreach ($compatibility as $key => $value) {
                        foreach ($value['name_value_list'] as $k => $v) {
                            $packCompatibility[$key][$v['name']] = $v['value'];
                        }
                        $packCompatibility[$key]['id'] = $key;
                        $packCompatibility[$key]['notes'] = isset($value['compatibility_notes']) ? $value['compatibility_notes'] : '';
                    }
                    $set['compatibility'] = $packCompatibility;
                }
                $set['specifics'] = json_decode($data['specifics'],true);
            } else if ($dealType == Constants::DATA_TO_STORE) {
                isset($data['application_data']) && $set['application_data'] = $data['application_data'];
                isset($data['postal_code']) && $set['postal_code'] = $data['postal_code'];//邮编
                isset($data['local_pickup']) && $set['local_pickup'] = $data['local_pickup'];//自提
                isset($data['auto_accept_price']) && $set['auto_accept_price'] = $data['auto_accept_price'];//自动交易价格
                isset($data['minimum_accept_price']) && $set['minimum_accept_price'] = $data['minimum_accept_price'];//自动拒绝价格
                isset($data['bid_count']) && $set['bid_count'] = $data['bid_count'];//投标次数
                isset($data['bid_increment']) && $set['bid_increment'] = $data['bid_increment'];//投标金额
                isset($data['current_price']) && $set['current_price'] = $data['current_price'];//当前价格
                isset($data['upc']) && $set['upc'] = $data['upc'];//upc
                isset($data['ean']) && $set['ean'] = $data['ean'];//ean
                isset($data['isbn']) && $set['isbn'] = $data['isbn'];//isbn
                isset($data['mpn']) && $set['mpn'] = $data['mpn'];//mpn
                isset($data['brand']) && $set['brand'] = $data['brand'];//brand
                $set['custom_exclude'] = isset($data['custom_exclude']) ? $data['custom_exclude'] : 2;//不运送方式，默认选择使用eBay站点设置
//                isset($data['custom_exclude']) && $set['custom_exclude'] = $data['custom_exclude'];//不运送方式
                if ($set['custom_exclude'] == 1) {
                    $set['exclude_location'] = json_encode('');//运送至所有国家时，清空不运送地区
                }
                //                    $set['exclude_location'] = '';//不运送地区
                if ($set['custom_exclude'] == 3 && !empty($data['exclude_location'])) {
                    $excludeLocation = [];
                    if (is_array($data['exclude_location'])) {
                        $excludeLocation = $data['exclude_location'];
                    } else if (is_array(explode('，', $data['exclude_location']))) {
                        $excludeLocation = explode('，', $data['exclude_location']);
                    }
                    $set['exclude_location'] = json_encode($excludeLocation);//不运送地区
                }
                isset($data['ship_location']) && $set['ship_location'] = json_encode($data['ship_location']);//送达地区
                isset($data['international_shipping']) && $set['international_shipping'] = json_encode($data['international_shipping']);//国际运输方式
                isset($data['shipping']) && $set['shipping'] = json_encode($data['shipping']);//国内运输方式
                isset($data['payment_method']) && $set['payment_method'] = json_encode($data['payment_method']);//支付方式
                isset($data['payment_instructions']) && $set['payment_instructions'] = $data['payment_instructions'];//支付说明

                //退货
                isset($data['return_policy']) && $set['return_policy'] = $data['return_policy'];//是否支持退货
                isset($data['return_type']) && $set['return_type'] = $data['return_type'];//退货方式
                isset($data['extended_holiday']) && $set['extended_holiday'] = $data['extended_holiday'];//是否节假日延期
                isset($data['return_shipping_option']) && $set['return_shipping_option'] = $data['return_shipping_option'];//运费承担方
                isset($data['restocking_fee_code']) && $set['restocking_fee_code'] = $data['restocking_fee_code'];//折旧费
                isset($data['return_description']) && $set['return_description'] = $data['return_description'];//退货说明
                isset($data['buyer_requirment_details']) && $set['buyer_requirment_details'] = json_encode($data['buyer_requirment_details']);//买家限制
                isset($data['variation_image']) && $set['variation_image'] = $data['variation_image'];//图片关联
                isset($data['restart_rule']) && $set['restart_rule'] = $data['restart_rule'];//重上规则 1,只要物品结束 2,所有物品卖出 3,没有物品卖出 4,没有物品卖出后仅刊登一次 5,当物品卖出数量大于或等于
                isset($data['restart_count']) && $set['restart_count'] = $data['restart_count'];//售出数量，达到此数量重上
                isset($data['restart_way']) && $set['restart_way'] = $data['restart_way'];//重上方式 1立即执行 2定时执行
                isset($data['restart_time']) && $set['restart_time'] = strtotime($data['restart_time']);//重上时间
                isset($data['restart_number']) && $set['restart_number'] = $data['restart_number'];//累计重上次数
                isset($data['bulk']) && $set['bulk'] = $data['bulk'];//单次重上0否1是
                isset($data['bulk_time']) && $set['bulk_time'] = strtotime($data['bulk_time']);//单次重上时间
                isset($data['internal']) && $set['internal'] = $data['internal'];//是否开启国内运输
                isset($data['publish_style']) && $set['publish_style'] = $data['publish_style'];//刊登风格
                isset($data['sale_note']) && $set['sale_note'] = $data['sale_note'];//销售说明
                isset($data['shipping_type']) && $set['shipping_type'] = $data['shipping_type'];//物流类型
                isset($data['specifics']) && $set['specifics'] = json_encode($data['specifics']);//类目属性
                isset($data['description']) && $set['description'] = $data['description'];//描述
                isset($data['mobile_desc']) && $set['mobile_desc'] = $data['mobile_desc'];//移动端描述
                isset($data['condition_id']) && $set['condition_id'] = $data['condition_id'];//物品状况id
                isset($data['condition_description']) && $set['condition_description'] = $data['condition_description'];//物品描述
                isset($data['send_content']) && $set['send_content'] = $data['send_content'];//刊登数据
                isset($data['message']) && $set['message'] = $data['message'];//API返回数据
                isset($data['promotion_set_return']) && $set['promotion_set_return'] = $data['promotion_set_return'];//促销设置返回数据
                isset($data['buhuo_return']) && $set['buhuo_return'] = $data['buhuo_return'];//补货返回数据
                isset($data['compatibility_count']) && $set['compatibility_count'] = $data['compatibility_count'];//兼容数量
                $compatibility = isset($data['compatibility']) ? $data['compatibility'] : [];//兼容值
                if (!isset($set['compatibility_count']) || !$set['compatibility_count']) {
                    return $set;
                }
                if (!$compatibility) {
                    return $set;
                }
                $newComp = [];
                foreach ($compatibility as $k => $comp) {
                    $NameValueList = [];
                    $i = 0;
                    foreach ($comp as $key=>$value) {
                        $NameValueList[$i]['name'] = $key;
                        $NameValueList[$i]['value'] = $value;
                        $i++;
                    }
                    $newComp[$k]['name_value_list'] = $NameValueList;
                    $newComp[$k]['compatibility_notes'] = $comp['notes'];
                }
                $set['compatibility'] = json_encode($newComp);
            }
            return $set;
        }catch (Exception $e){
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包图片,由于主图，详情图和子产品图用的是同一个数据表，且图片源可能相同，所以操作时，每一步都要在之前的基础上
     * 进行，否则会出现错误。如果不写数据表的话，就要有一个中间变量来存储图片当前的状态。
     * 子产品图不再和主图或详情图共用一条数据，即使图片源相同。
     * @param array $data
     * @param array $idInfo ['oldListId', 'newListId', 'userId', 'account_id']
     * @param int $type IMG_TYPE_MAIN, IMG_TYPE_DETAIL, IMG_TYPE_SKU
     * @param array $subInfo
     * @return array
     * @throws Exception
     */
    public function packImgs(array $data, array $idInfo, int $type, array $subInfo=[]) : array
    {
        try {
            $newImgPaths = [];//传递过来的图片路径
            $insertImgs = [];//需要插入的图片
            $updateImgs = [];//需要更新的图片
            $delIds = [];//需要删除的图片ids

            foreach ($data as $datum) {
                $newImgPaths[] = $datum['path'];//打包图片路径
            }
            $wh['listing_id'] = $idInfo['newListId'];
            $wh['main_de'] = ($type == Constants::IMG_TYPE_SKU) ? 1 : 0;
            $oldImgs = (new EbayListingImage())->field(true)->where($wh)->select();//获取所有的旧图片
            foreach ($oldImgs as $oldImg) {
                $updateFlag = 0;
                $oldImg = $oldImg->toArray();
                $sort = array_search($oldImg['path'], $newImgPaths);//获取旧图片在新图片中的位置
                if ($sort !== false) { //已存在，加入更新
                    switch ($type) {
                        case Constants::IMG_TYPE_MAIN:
                            if ($oldImg['main'] != 1 || $oldImg['sort'] != $sort) {
                                $oldImg['main'] = 1;
                                $oldImg['sort'] = $sort;
                                $updateFlag = 1;
                                $oldImg['main_de'] = 0;
                            }
                            break;
                        case Constants::IMG_TYPE_DETAIL:
                            if ($oldImg['detail'] != 1 || $oldImg['de_sort'] != $sort) {
                                $oldImg['detail'] = 1;
                                $oldImg['de_sort'] = $sort;
                                $oldImg['main_de'] = 0;
                                $updateFlag = 1;
                            }
                            break;
                        case Constants::IMG_TYPE_SKU://子图片
                            if (($oldImg['sku_sort'] != $sort //位置有变
//                                || $oldImg['main_de'] != 1 //是否是子产品图有变更
                                || $oldImg['name'] != $subInfo['attrName'] //绑定的属性名称有变更
                                || $oldImg['value'] != $subInfo['attrValue']) //绑定的属性值有变更
                                && $oldImg['sku'] == $subInfo['sku']) {//只能更新对应的子产品
                                $oldImg['sku_sort'] = $sort;
                                $oldImg['main_de'] = 1;
                                $oldImg['name'] = $subInfo['attrName'];
                                $oldImg['value'] = $subInfo['attrValue'];
//                                $oldImg['sku'] = $subInfo['sku'];
                                $updateFlag = 1;
                            }
                            break;
                        default:
                            break;
                    }
                    if ($updateFlag) { //只有真的改变了才加入更新
                        $updateImgs[] = $oldImg;//加入更新
                    }
                    if ($type != Constants::IMG_TYPE_SKU) {
                        unset($data[$sort]);
                        unset($newImgPaths[$sort]);//为保持与data里的数据顺序一直，这个也需要释放
                    }
                 } else { //新图片里面没有，删除或更新状态
                    if (($type == Constants::IMG_TYPE_MAIN && $oldImg['detail'] == 0) //原来仅是主图,现在不是了
                        || ($type == Constants::IMG_TYPE_DETAIL && $oldImg['main'] == 0)//原来仅是描述图,现在不是了
                        || ($type == Constants::IMG_TYPE_SKU && $oldImg['main'] == 0 && $oldImg['detail'] == 0 && $oldImg['main_de'] == 1 //原来仅是子产品图
                            && (($oldImg['value'] == $subInfo['attrValue'] && $oldImg['name'] == $subInfo['attrName']) //且绑定的属性名称和值与传递的相同
                                || ($oldImg['name'] == '')) //或绑定的属性名称为空
                            && $oldImg['sku'] == $subInfo['sku'])) {//必须对指定的子产品操作
                        $delIds[] = $oldImg['id'];
                    } else {
                        if ($type == Constants::IMG_TYPE_MAIN && $oldImg['main'] == 1) {//如果无法删掉说明此图还可能是描述图
                            $oldImg['main'] = 0;//去掉主图属性
                            $updateFlag = 1;
                        } else if ($type == Constants::IMG_TYPE_DETAIL && $oldImg['detail'] == 1) {//如果无法删掉说明此图还可能是主图
                            $oldImg['detail'] = 0;//去掉描述图属性
                            $updateFlag = 1;
                        } else if ($type == Constants::IMG_TYPE_SKU && $oldImg['main_de'] == 1//如果无法删掉说明此图还可能是主图,描述图或子产品图
                            && (($oldImg['name'] == $subInfo['attrName'] && $oldImg['value'] == $subInfo['attrValue'])//进一步确定子产品属性,避免影响其他子产品图
                                || $oldImg['name'] == '') && $oldImg['sku'] == $subInfo['sku']) {
                            $oldImg['main_de']  = 0;
                            $oldImg['name'] = '';
                            $oldImg['value'] = '';
                            $oldImg['sku'] = '';
                            $updateFlag = 1;
                        }
                        if ($updateFlag) {
                            $updateImgs[] = $oldImg;
                        }
                    }
                }
            }
            //重新打包需要新增的图片
            $accountInfo = Cache::store('EbayAccount')->getTableRecord($idInfo['account_id']);
            foreach ($data as $k => $v) {
                if (stripos($v['path'], 'i.ebayimg.com')) { //已上传的
                    $insertImgs[$k]['eps_path'] = $v['path'];
                    $insertImgs[$k]['status'] = 3;
                } else { //未上传，生成服务器地址
                    $insertImgs[$k]['ser_path'] = GoodsImage::getThumbPath($v['path'], 0, 0, $accountInfo['code'], true);
                }
                $insertImgs[$k]['path'] = $v['path'];
                $insertImgs[$k]['thumb'] = $v['path'];
                $insertImgs[$k]['update_time'] = time();
                $insertImgs[$k]['base_url'] = isset($v['base_url']) ? $v['base_url'] : "https://img.rondaful.com/";//图片服务器前缀地址
                if ($type == Constants::IMG_TYPE_MAIN) {
                    $insertImgs[$k]['main'] = 1;
                    $insertImgs[$k]['sort'] = $k;
                } else if ($type == Constants::IMG_TYPE_DETAIL) {
                    $insertImgs[$k]['detail'] = 1;
                    $insertImgs[$k]['de_sort'] = $k;
                } else if ($type == Constants::IMG_TYPE_SKU) {
                    $insertImgs[$k]['main_de'] = 1;
                    $insertImgs[$k]['sku_sort'] = $k;
                    $insertImgs[$k]['name'] = $subInfo['attrName'];
                    $insertImgs[$k]['value'] = $subInfo['attrValue'];
                    $insertImgs[$k]['sku'] = $subInfo['sku'];
                }
            }
            return ['updateImgs'=>$updateImgs, 'insertImgs'=>$insertImgs, 'delIds'=>$delIds];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包多属性
     * @param array $data
     * @param array $idInfo
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array
     * @throws Exception
     */
    public function packVarians(array $data, array $idInfo=[], int $dealType ) : array
    {
        try {
            $varians = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $varians = $data;
                foreach($data as $k=>$v){
                    $attrValue = json_decode($v['variation'],true);
                    foreach($attrValue as $key=>$value){
                        $varians[$k][$key] = $value;
                    }
                    $varians[$k]['path'] = json_decode($v['path'],true) ?? [];
                    $varians[$k]['thumb'] = json_decode($v['thumb'],true) ?? [];
                    $varians[$k]['d_imgs'] = (new EbayListingImage())->where(['listing_id'=>$v['listing_id'],'sku'=>$v['channel_map_code']])->select();
                    $varians[$k]['map_sku'] = json_decode($v['map_sku'],true) ?? [];
                }
            } else if ($dealType == Constants::DATA_TO_STORE) {
                $flag = 1;//是否未刊登标识
                if ($idInfo['oldListId'] != 0) { //更新才检测是否已刊登，新增的不用检测
                    $listingStatus = EbayListing::where(['id'=>$idInfo['oldListId'], 'draft'=>0])->value('listing_status');
                    if ($listingStatus >=2 && $listingStatus != 4) { //已刊登
                        $flag = 0;
                    }
                }
                $varians = [];
                $spuQty = 0;//商品总数量
                foreach ($data as $k => $v) {
                    $varians[$k]['variation'] = json_encode($v['variation']);
                    if (!$varians[$k]['variation']) {
                        throw new Exception('多属性刊登，必须至少选择一条启用的多属性');
                    }
                    $varians[$k]['v_sku'] = $v['v_sku'];
                    $mapSku = is_array($v['map_sku']) ? $v['map_sku'] : json_decode($v['map_sku'], true);
                    if (isset($mapSku[0])) {
                        $varians[$k]['goods_id'] = $mapSku[0]['goods_id'];
                        $varians[$k]['sku_id'] = $mapSku[0]['sku_id'];
                        if ($varians[$k]['sku_id'] != 0) {
                            $status = GoodsSku::where(['id' => $varians[$k]['sku_id']])->value('status');
                            $status && $varians[$k]['sku_status'] = $status;
                        }
                    }
                    $varians[$k]['v_price'] = $v['v_price'];
                    $varians[$k]['v_qty'] = $v['v_qty'];
                    $varians[$k]['v_pre_qty'] = $v['v_qty'];
                    $spuQty += $v['v_qty'];
                    $varians[$k]['map_sku'] = json_encode($mapSku);
                    $varians[$k]['upc'] = $v['upc'];
                    $varians[$k]['ean'] = $v['ean'];
                    $varians[$k]['isbn'] = $v['isbn'];
                    $varians[$k]['combine_sku'] = $v['combine_sku'];
                    $varians[$k]['path'] = json_encode($v['path']);
                    if ($flag) { //未刊登
                        $varians[$k]['unique_code'] = md5(json_encode($v['variation']));
                        $varians[$k]['thumb'] = json_encode($v['path']);
                    } else { //已刊登
                        $varians[$k]['id'] = $v['id'];
                        $varians[$k]['listing_id'] = $v['listing_id'];
                    }
                }
            }
            return $varians;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 打包国际物流
     * @param mixed $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array
     * @throws Exception
     */
    public function packInternalShipping($data, int $dealType=0) : array
    {
        try {
            $internalShipping = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $internalShipping = json_decode($data,true);
                foreach ($internalShipping as $k=>$v) {
                    if ($v['shiptolocation']!='Worldwide' && !is_array($v['shiptolocation'])) {
                        $internalShipping[$k]['shiptolocation'] = explode(',',$v['shiptolocation']);
                    }
                }
            } else if ($dealType == Constants::DATA_TO_STORE) {

            }
            return $internalShipping ? $internalShipping : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包国内物流
     * @param $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array|mixed
     * @throws Exception
     */
    public function packShipping($data, int $dealType=0) : array
    {
        try {
            $shipping = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $shipping = json_decode($data, true);
                foreach ($shipping as $k => $v) {
                    if (!isset($v['extra_cost'])) {
                        $shipping[$k]['extra_cost'] = 0;
                    }
                }
            } else if ($dealType == Constants::DATA_TO_STORE) {

            }
            return $shipping ? $shipping : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包买家限制详情
     * @param $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array
     * @throws Exception
     */
    public function packBuyerRequirementDetail($data, int $dealType) : array
    {
        try {
            $details = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $tmpDetails = json_decode($data, true);
                if ($tmpDetails && isset($tmpDetails[0])){
                    $details = $tmpDetails[0];
                } else if ($tmpDetails) {
                    $details = $tmpDetails;
                } else {
                    $details['credit'] = 0;
                    $details['strikes'] = 0;
                    $details['violations'] = 0;
                    $details['link_paypal'] = 0;
                    $details['registration'] = 0;
                    $details['requirements'] = 0;
                    $details['strikes_count'] = 0;
                    $details['strikes_period'] = 0;
                    $details['minimum_feedback'] = 0;
                    $details['violations_count'] = 0;
                    $details['violations_period'] = 0;
                    $details['minimum_feedback_score'] = 0;
                    $details['requirements_max_count'] = 0;
                    $details['requirements_feedback_score'] = 0;

                }
            } else if ($dealType == Constants::DATA_TO_STORE) {
                //解析详情数据，格式如下
                //link_paypal:1,
                //registration:0,
                //strikes:3,30,
                //violations:4,30,
                //credit:-1,
                //requirements:25,
                //minimum_feedback:
                $detail = explode("\n", $data);
                $nameValue = explode(':', $detail[0]);
                $buyerLimitDetail['link_paypal'] = intval($nameValue[1]);
                $nameValue = explode(':', $detail[1]);
                $buyerLimitDetail['registration'] = intval($nameValue[1]);
                $nameValue = explode(':', $detail[2]);//strikes
                if (isset($nameValue[1]) && strpos($nameValue[1], ',')) {
                    $buyerLimitDetail['strikes'] = 1;
                    $value = explode(',', $nameValue[1]);
                    $buyerLimitDetail['strikes_count'] =  intval($value[0]);
                    $buyerLimitDetail['strikes_period'] =  'Days_'.intval($value[1]);
                } else {
                    $buyerLimitDetail['strikes'] = 0;
                    $buyerLimitDetail['strikes_count'] = 0;
                    $buyerLimitDetail['strikes_period'] = 0;
                }
                $nameValue = explode(':', $detail[3]);//violations
                if (strpos($nameValue[1], ',')) {
                    $buyerLimitDetail['violations'] = 1;
                    $value = explode(',', $nameValue[1]);
                    $buyerLimitDetail['violations_count'] =  intval($value[0]);
                    $buyerLimitDetail['violations_period'] =  'Days_'.intval($value[1]);
                } else {
                    $buyerLimitDetail['violations'] = 0;
                    $buyerLimitDetail['violations_count'] = 0;
                    $buyerLimitDetail['violations_period'] = 0;
                }
                $nameValue = explode(':', $detail[4]);//credit
                if (empty($nameValue[1])) {
                    $buyerLimitDetail['credit'] = 0;
                    $buyerLimitDetail['requirements_feedback_score'] = 0;
                } else {
                    $buyerLimitDetail['credit'] = 1;
                    $buyerLimitDetail['requirements_feedback_score'] = intval($nameValue[1]);
                }
                $nameValue = explode(':', $detail[5]);
                if (empty($nameValue[1])) {
                    $buyerLimitDetail['requirements'] = 0;
                    $buyerLimitDetail['requirements_max_count'] = 0;
                    $buyerLimitDetail['minimum_feedback'] = 0;
                    $buyerLimitDetail['minimum_feedback_score'] = 0;
                } else {
                    $buyerLimitDetail['requirements'] = 1;
                    $buyerLimitDetail['requirements_max_count'] = intval($nameValue[1]);
                    $nameValue = explode(':', $detail[6]);
                    if (empty($nameValue[1])) {
                        $buyerLimitDetail['minimum_feedback'] = 0;
                        $buyerLimitDetail['minimum_feedback_score'] = 0;
                    } else {
                        $buyerLimitDetail['minimum_feedback'] = 1;
                        $buyerLimitDetail['minimum_feedback_score'] = intval($nameValue[1]);
                    }
                }
                $details = $buyerLimitDetail;
            }
            return $details;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @param $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array|mixed|string
     * @throws Exception
     */
    public function packExclude($data, int $dealType)
    {
        try {
            $exclude = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $exclude = json_decode($data, true);
                $exclude = empty($exclude) ? '' : implode('，', $exclude);
            } else if ($dealType == Constants::DATA_TO_STORE) {

            }
            return $exclude;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @param $data
     * @param int $dealType 数据处理方式。0：数据准备用来展示，1：数据准备存储起来
     * @return array|mixed
     * @throws Exception
     */
    public function packPaymethod($data, int $dealType)
    {
        try {
            $payMethod = [];
            if ($dealType == Constants::DATA_TO_DISPLAY) {
                $tmp = json_decode($data,true);
                if(is_array($tmp)){
                    $payMethod = $tmp;
                }else if($tmp =="PayPal"){
                    $payMethod = ["PayPal"];
                }else{
                    $payMethod = ["PayPal"];
                }
            } else if ($dealType == Constants::DATA_TO_STORE) {

            }
            return $payMethod;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 格式化listing信息，便于比对差异
     * @param array $data
     * @param bool $isNew
     * @return array
     * @throws Exception
     */
//    public function formatListingInfo(array $data, bool $isNew) : array
//    {
//        try {
////            $boolType = ['False', 'True'];
//            $set = $data['set'];
//            $list = $data['list'];
//
//            $row['ApplicationData'] = $set['application_data'];
//            $row['AutoPay'] = $list['autopay'];
//
//            //议价
//            $row['BestOfferDetails']['BestOfferEnabled'] = $list['best_offer'];
//            $row['BestOfferDetails'] = json_encode($row['BestOfferDetails']);
//
//            if ($list['best_offer']) {
//                $row['ListingDetails']['BestOfferAutoAcceptPrice'] = $set['auto_accept_price'];
//                $row['ListingDetails']['MinimumBestOfferPrice'] = $set['minimum_accept_price'];
//            }
//            $row['ListingDetails'] = json_encode($row['ListingDetails']);
//
//            $row['ConditionDescription'] = $set['condition_descrition'];
//            $row['ConditionID'] = $set['condition_id'];
//            $row['Country'] = $list['country'];
//            $row['Description'] = $set['description'];
//            $row['DescriptionReviseMode'] = 'Replace';
//            $row['DisableBuyerRequirements'] = $list['disable_buyer'];
//            if ($list['disable_buyer']) {
//
//            }
//            $row['DispatchTimeMax'] = $list['dispatch_time_max'];
//            if (intval($set['compatibility_count']) > 0) {
//                $compatibilitys = $set['compatibility'];
//                $row['ItemCompatibilityList'] = [];
//                foreach ($compatibilitys as $k => $compatibility) {
//                    unset($compatibility['id']);
//                    unset($compatibility['isCheck']);
//                    $row['ItemCompatibilityList']['Compatibility'][$k]['CompatibilityNotes'] = $compatibility['notes'];
//                    unset($compatibility['notes']);
//                    foreach ($compatibility as $name => $value) {
//                        $row['ItemCompatibilityList']['Compatibility'][$k]['NameValueList']['Name'] = $name;
//                        if (is_array($value)) {
//                            foreach ($value as $v) {
//                                $row['ItemCompatibilityList']['Compatibility'][$k]['NameValueList']['Value'][] = $v;
//                            }
//                        } else {
//                            $row['ItemCompatibilityList']['Compatibility'][$k]['NameValueList']['Value'] = $value;
//                        }
//                    }
//                }
//                $row['ItemCompatibilityList'] = json_encode($row['ItemCompatibilityList']);
//            }
////            $row['ReplaceAll'] = 'True';
//            //拥有的特性
//            $specifics = $set['specifics'];//specifics格式为['custom'=>0, 'attr_name'=>'Brand', 'attr_value'=>'Does not apply'],[],[]
//            $list['variation'] && $varians = $data['varians'];
//            if ($list['variation']) {
//                $varkey = array_keys(json_decode($varians[0]["variation"], true));
//                foreach ($specifics as $k => $v) {
//                    if (in_array($v['attr_name'], $varkey)) {
//                        unset($specifics[$k]);
//                    }
//                }
//            }
//            if (count($specifics)) {
//                $row['ItemSpecifics'] = [];
//                foreach ($specifics as $k => $specific) {
//                    foreach ($specifics as $name => $value) {
//                        $row['ItemSpecifics']['NameValueList'][$k]['Name'] = $name;
//                        if (is_array($value)) {
//                            foreach ($value as $k) {
//                                $row['ItemSpecifics']['NameValueList'][$k]['Value'][] = $v;
//                            }
//                        } else {
//                            $row['ItemSpecifics']['NameValueList'][$k]['Value'] = $value;
//                        }
//                    }
//                }
//                $row['ItemSpecifics'] = json_encode($row['ItemSpecifics']);
//            }
//            $row['ListingDuration'] = $list['listing_duration'];
//            $row['ListingEnhancement'] = $list['listing_enhancement'];
//            $row['location'] = $list['location'];
//            $row['PostalCode'] = $set['postal_code'];
//
//            foreach ($set['payment_method'] as $pay) {
//                $row['PaymentMethods'][] = $pay;
//            }
//            $row['PaymentMethods'] = json_encode($row['PaymentMethods']);
//            $row['PayPalEmailAddress'] = $list['paypal_emailaddress'];
//
//            //自提
//            if ($set['local_pickup']) {
//                if ($list['site'] == 0) {//实体店提货 适用于美国站点
//                    $row['PickupInStoreDetails']['EligibleForPickupInStore'] = 'True';
//                } else if (intval($list['site']) == 3 || intval($list['site']) == 15 || intval($list['site']) == 77) {
//                    //click & collect自提 适用于英国，澳洲，德国
//                    $row['PickupInStoreDetails']['EligibleForPickupDropOff'] = 'True';
//                }
//                $row['PickupInStoreDetails'] = json_encode($row['PickupInStoreDetails']);
//            }
//
//            $row['imgs'] = json_encode($data['imgs']);//刊登图片
//            $row['detail_imgs'] = json_encode($data['detail_imgs']);//详情图片
//            $row['GalleryType'] = $list['picture_gallery'];
//
//            $row['PrimaryCategory']['CategoryID'] = $list['primary_categoryid'];//第一分类
//            $row['SecondaryCategory']['CategoryID'] = $list['secondary_categoryid'];//第一分类
//
//            if ($list['variation'] == 0) {
//                $row['ProductListingDetails']['BrandMPN']['Brand'] = $set['brand'];
//                $row['ProductListingDetails']['BrandMPN']['MPN'] = $set['mpn'];
//                $row['ProductListingDetails']['EAN'] = $set['ean'];
//                $row['ProductListingDetails']['ISBN'] = $set['isbn'];
//                $row['ProductListingDetails']['UPC'] = $set['upc'];
//                $row['ProductListingDetails'] = json_encode($row['ProductListingDetails']);
//                $row['Quantity'] = $list['quantity'];
//                $row['SKU'] = $list['listing_sku'];
//                $row['StartPrice'] = $list['start_price'];
//            }
//            //退货
//            $row['ReturnPolicy']['Description'] = $set['return_description'];
//            $row['ReturnPolicy']['RefundOption'] = $set['return_type'];
//            $row['ReturnPolicy']['ReturnsAcceptedOption'] = $set['return_policy'];
//            $row['ReturnPolicy']['ReturnsWithinOption'] = $list['return_time'];
//            $row['ReturnPolicy']['ShippingCostPaidByOption'] = $set['return_shipping_option'];
//            $row['ReturnPolicy'] = json_encode($row['ReturnPolicy']);
//
//            //物流
//            $row['ShippingDetails'] = [];
//            if (!empty($set['exclude_location'])) {
//                $excludeLocation = explode('，', $set['exclude_location']);
//                foreach ($excludeLocation as $v) {
//                    $row['ShippingDetails']['ExcludeShipToLocation'] = $v;
//                }
//            }
//            foreach ($set['international_shipping'] as $k => $interShipping) {
//                $row['ShippingDetails']['InternationalShippingServiceOption']['ShippingService'] = $interShipping['shipping_service'];
//                $row['ShippingDetails']['InternationalShippingServiceOption']['ShippingServiceAdditionalCost'] = $interShipping['shipping_service_additional_cost'];
//                $row['ShippingDetails']['InternationalShippingServiceOption']['ShippingServiceCost'] = $interShipping['shipping_service_cost'];
//                $row['ShippingDetails']['InternationalShippingServiceOption']['ShippingServicePriority'] = $k + 1;
//                if (is_array($interShipping['shiptolocation'])) {
//                    foreach ($interShipping['shiptolocation'] as $shipLocation) {
//                        $row['ShippingDetails']['InternationalShippingServiceOption']['ShipToLocation'][] = $shipLocation;
//                    }
//                } else if (!empty($interShipping['shiptolocation'])) {
//                    $row['ShippingDetails']['InternationalShippingServiceOption']['ShipToLocation'] = $interShipping['shiptolocation'];
//                }
//            }
//            if ($list['site'] == 0) {
//                $row['ShippingDetails']['SalesTax']['SalesTaxPercent'] = $list['sales_tax'];
//                $row['ShippingDetails']['SalesTax']['SalesTaxState'] = $list['sales_tax_state'];
//                $row['ShippingDetails']['SalesTax']['ShippingIncludedInTax'] = $list['shipping_tax'];
//            }
//            foreach ($set['shipping'] as $k => $shipping) {
//                $row['ShippingDetails']['ShippingServiceOptions']['ShippingService'] = $shipping['shipping_service'];
//                $row['ShippingDetails']['ShippingServiceOptions']['ShippingServiceAdditionalCost'] = $shipping['shipping_service_additional_cost'];
//                $row['ShippingDetails']['ShippingServiceOptions']['ShippingServiceCost'] = $shipping['ShippingServiceCost'];
//                $row['ShippingDetails']['ShippingServiceOptions']['ShippingServicePriority'] = $k + 1;
//            }
//            $row['ShippingDetails'] = json_encode($row['ShippingDetails']);
//
//            if (is_array($set['ship_location'])) {
//                foreach ($set['ship_location'] as $shipLocation) {
//                    $row['ShipToLocations'][] = $shipLocation;
//                }
//            } else if (!empty($set['ship_location'])) {
//                $row['ShipToLocations'] = $shipLocation;
//            }
//            $row['ShipToLocations'] = json_encode($row['ShipToLocations']);
//
//            $row['Storefront']['StoreCategory2ID'] = $list['store_category2_id'];
//            $row['Storefront']['StoreCategoryID'] = $list['store_category_id'];
//            $row['Storefront'] = json_encode($row['Storefront']);
//
//            $row['Title'] = $list['title'];
//            $row['SubTitle'] = $list['sub_title'];
//
//            if ($list['variation']) {
//                $row['sku_imgs'] = [];
//                $varians = $data['varians'];
//                $varSpecifics = [];
//                foreach ($varians as $k => $varian) {
//                    $row['Variations']['Variation'][$k]['Quantity'] = $varian['v_qty'];
//                    $row['Variations']['Variation'][$k]['SKU'] = $varian['channel_map_code'];
//                    $row['Variations']['Variation'][$k]['StartPrice'] = $varian['v_price'];
//                    $row['Variations']['Variation'][$k]['VariationProductListingDetails']['EAN'] = $varian['ean'];
//                    $row['Variations']['Variation'][$k]['VariationProductListingDetails']['ISBN'] = $varian['isbn'];
//                    $row['Variations']['Variation'][$k]['VariationProductListingDetails']['UPC'] = $varian['upc'];
//                    $varNameValue = json_decode($varian['variation'], true);
//                    foreach ($varNameValue as $name => $value) {
//                        $row['Variations']['Variation'][$k]['VariationSpecifics']['NameValueList']['Name'] = $name;
//                        if (is_array($value)) {
//                            foreach ($value as $v) {
//                                $row['Variations']['Variation'][$k]['VariationSpecifics']['NameValueList']['Value'][] = $v;
//                            }
//                        } else {
//                            $row['Variations']['Variation'][$k]['VariationSpecifics']['NameValueList']['Value'] = $v;
//                        }
//                        $varSpecifics[$name][] = $value;
//                    }
//                    $row['sku_imgs'][] = $varian['path'];
//                }
//                $row['sku_imgs'] = json_encode($row['sku_imgs']);
//                foreach ($varSpecifics as $key => $varSpecific) {
//                    $varSpecifics[$key] = array_unique($varSpecific);
//                }
//                foreach ($varSpecifics as $k => $varSpecific) {
//                    $row['Variations']['VariationSpecificsSet']['NameValueList']['Name'] = $k;
//                    foreach ($varSpecific as $value) {
//                        $row['Variations']['VariationSpecificsSet']['NameValueList']['Value'][] = $value;
//                    }
//                }
//                $row['Variations'] = json_encode($row['Variations']);
//            }
//            return $row;
//        } catch (Exception $e) {
//                throw new Exception($e->getFile() | $e->getLine() | $e - getMessage());
//        }
//    }

//    public function packSpecifics($data, int $dealType)
//    {
//        try {
//            $specifics = [];
//            if ($dealType == DATA_TO_DISPLAY) {
//
//            }
//        } catch(Exception $e) {
//            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
//        }
//    }

//    public function packReturn($data, int $source) : array
//    {
//        try {
//
//        } catch(Exception $e) {
//            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
//        }
//    }

    /**
     * 更新图片
     * @param array $imgs
     * @param int $listingId
     * @throws Exception
     */
    public function updateImgs(array $imgs, int $listingId) : void
    {
        try {
            !empty($imgs['updateImgs']) && (new EbayListingImage())->saveAll($imgs['updateImgs']);
            !empty($imgs['delIds']) && EbayListingImage::destroy($imgs['delIds']);
            if (!empty($imgs['insertImgs'])) {
                foreach ($imgs['insertImgs'] as $k => $v) {
                    $imgs['insertImgs'][$k]['listing_id'] = $listingId;
                }
                (new EbayListingImage())->isUpdate(false)->saveAll($imgs['insertImgs']);
            }
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 更新多属性
     * @param array $varians
     * @param array $idInfo
     * @param int $assocOrder
     * @throws Exception
     */
    public function updateVarians(array $varians, array $idInfo, int $assocOrder=1) : void
    {
        try {
            $subInfo = [];
            $imgAttrVals = [];
            $channelMapCode = [];
            $listingStatus = EbayListing::where(['id'=>$idInfo['newListId']])->value('listing_status');
            $subInfo['attrName'] = EbayListingSetting::where(['id'=>$idInfo['newListId']])->value('variation_image');
            if ($listingStatus < 2 || $listingStatus == 4) { //未刊登
                $idInfo['oldListId'] != 0 && EbayListingVariation::destroy(['listing_id'=>$idInfo['oldListId']]);//更新时，删除旧的
                $map['channel_id'] = 1;
                $map['account_id'] = $idInfo['account_id'];
                foreach ($varians as $k => $varian) {
                    $varian['variation'] = json_decode($varian['variation'], true);
                    $map['sku_code'] = $varian['v_sku'];
                    $map['combine_sku'] = $varian['combine_sku'];
                    $varians[$k]['listing_id'] = $idInfo['newListId'];
                    $varians[$k]['channel_map_code'] = $this->createMappingSku($map, $idInfo['userId'], $assocOrder);
                    $subInfo['sku'] = $varians[$k]['channel_map_code'];
                    if (!isset($varian['variation'][$subInfo['attrName']])) {
                        throw new Exception('范本 【sku : '.$varian['v_sku'].'】，【图片关联】项的值必须在对应【变体属性】项中存在');
                    }
                    $subInfo['attrValue'] = $varian['variation'][$subInfo['attrName']];
                    $imgAttrVals[] = $subInfo['attrValue'];
                    $varImgs = $this->packImgs(json_decode($varian['path'], true), $idInfo, Constants::IMG_TYPE_SKU, $subInfo);
                    $this->updateImgs($varImgs, $idInfo['newListId']);
                    $channelMapCode[] = $subInfo['sku'];
                }
                (new EbayListingVariation())->isUpdate(false)->saveAll($varians);//插入
            } else { //已刊登
                (new EbayListingVariation())->isUpdate(true)->saveAll($varians);
            }

            if (isset($imgAttrVals) && $idInfo['oldListId'] != 0) {
                //先把共用的更新
                $whimg['listing_id'] = $idInfo['oldListId'];
                $whimg['main'] = 1;
                $whimg['main_de'] = 1;
                EbayListingImage::update(['main_de'=>0, 'sku'=>'', 'name'=>'', 'value'=>''], $whimg);
                unset($whimg['main']);
                $whimg['detail'] = 1;
                EbayListingImage::update(['main_de'=>0, 'sku'=>'', 'name'=>'', 'value'=>''], $whimg);

                //把非共用且仅是子产品图删除
                $wh['listing_id'] = $idInfo['oldListId'];
//                $wh['name'] = ['neq', $subInfo['attrName']];
                $wh['sku'] = ['not in', $channelMapCode];
                $wh['main_de'] = 1;
//                $wh['value'] = ['not in', $imgAttrVals];
                EbayListingImage::destroy($wh);
            }
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 更新属性映射
     * @param array $data
     * @param int $listingId
     * @throws Exception
     */
    public function updateMappingSpec(array $data, int $listingId) : void
    {
        try {
            EbayListingMappingSpecifics::destroy(['listing_id'=>$listingId]);
            $spec = [];
            foreach ($data as $k => $v) {
                $spec[$k]['listing_id'] = $listingId;
                $spec[$k]['is_check'] = intval($v['is_check']);
                $spec[$k]['channel_spec'] = $v['channel_spec'];#平台类目名称
                $spec[$k]['combine_spec'] = empty($v['combine_spec'])?'':$v['combine_spec'];#本地类目名称
            }
            (new EbayListingMappingSpecifics())->isUpdate(false)->saveAll($spec);
        }catch (Exception $e){
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }




    /**
     * 生成sku映射信息，写入订单关联也是在这里操作的
     * @param array $data
     * @param int $userId
     * @param int $needWrite
     * @return string
     * @throws Exception
     */
    public function createSku(array $data, int $userId=0, int $needWrite=1) : string
    {
        try {
            $map['sku_code'] = $data['local_sku'];
            $map['channel_id'] = 1;
            $map['account_id'] = $data['account_id'];
            if ($data['listing_type'] == 2) {
                $map['combine_sku'] = $data['sku'];
                return $this->createMappingSku($map, $userId, $needWrite);
            } else if ($data['listing_type'] == 1) {
                if ($data['variation']) {
                    $accountInfo = Cache::store('EbayAccount')->getTableRecord($data['account_id']);
                    $code = $accountInfo['code'];
                    return $this->createOnlyListingSku($data['sku'], 1, $data['account_id'], $code);
                } else {
                    $map['combine_sku'] = stripos($data['sku'], '*') ? $data['sku'] : $data['sku'] . '*1';
                    return $this->createMappingSku($map, $userId, $needWrite);
                }
            }
        }catch (Exception $e){
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 生成sku映射信息，里面有写订单关联的操作
     * @param array $map
     * @param int $userId
     * @param int $needWrite
     * @return string
     * @throws Exception
     */
    public function createMappingSku(array $map, int $userId=0, int $needWrite=1) : string
    {
        try {
            if ($needWrite) {
                $skuInfo = (new GoodsSkuMapService())->addSkuCodeWithQuantity($map, $userId);
                return $skuInfo['result'] == true ? $skuInfo['sku_code'] : '';
            } else {
                $where = [
                    'channel_id' => ['=', $map['channel_id']],
                    'account_id' => ['=', $map['account_id']],
                    'channel_sku' => ['=', $map['sku_code']],
                ];
                GoodsSkuMap::where($where)->delete();
                $skuCode = (new GoodsSkuMapService())->createSku($map['sku_code']);
                return $skuCode;
            }
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @title 用于生成唯一listingSku
     * @param $sku
     * @param $channel
     * @param $accountId
     * @param $prefix
     * @return string
     * @throws Exception
     */
    public function createOnlyListingSku($sku,$channel,$accountId,$prefix)
    {
        try {
            $wh['sku'] = $sku;
            $wh['channel'] = $channel;
            $wh['account_id'] = $accountId;
            $serInfo = EbayListingSerialNumber::get($wh);
            if ($serInfo) {#递增
                $num = $serInfo->number + 1;
                $serInfo->number = $num;
                $serInfo->save();
            } else {#新添加
                $num = 1;
                (new EbayListingSerialNumber())->insertGetId($wh);
            }
            if (trim($prefix)) {
                return $sku . "|" . $prefix . $num;
            } else {
                return $sku . "-" . $num;
            }
        }catch (Exception $e){
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 检测此账号是否属于此用户
     * @param int $accountId
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function isAccountBelongsToUser(int $accountId, int $userId) : bool
    {
        try {
            //验证此账号是否属于此销售员
            $users = $this->getUnderlingInfo($userId);
            $memberShipService = new MemberShipService();
            $accountList = [];
            foreach($users as $k => $user){
                $temp = $memberShipService->getAccountIDByUserId($user,1);
                $accountList = array_merge($temp,$accountList);
            }
            if(in_array($accountId, $accountList)){
                return true;
            }
            return false;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析组合模板
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseCombModel(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $comb = EbayCommonQuantity::get($id);
            } else {
                $comb = EbayCommonQuantity::get(['model_name' => $name]);
            }
            if (empty($comb)) {
                return [];
            }
            $res['promotion'] = empty($comb->promotion) ? [] : $this->parsePromotionModel($comb->promotion);
            $res['style'] = empty($comb->style) ? [] : EbayModelStyle::where(['id'=>$comb->style])->value('model_name');
            $res['sale'] = empty($comb->sale) ? [] : EbayModelSale::where(['id'=>$comb->sale])->value('model_name');
            $res['trans'] = empty($comb->trans) ? [] : $this->parseTransCommon($comb->trans);
            $res['exclude'] = empty($comb->exclude) ? [] : $this->parseExcludeCommon($comb->exclude);
            $res['choice'] = empty($comb->choice) ? [] : $this->parseChoiceCommon($comb->choice);
            $res['pickup'] = empty($comb->pickup) ? [] : $this->parsePickupCommon($comb->pickup);
            $res['location'] = empty($comb->location) ? [] : $this->parseLocationCommon($comb->location);
            $res['gallery'] = empty($comb->gallery) ? [] : $this->parseGalleryCommon($comb->gallery);
            $res['quantity'] = empty($comb->quantity) ? [] : $this->parseQuantityCommon($comb->quantity);
            $res['individual'] = empty($comb->individual) ? [] : $this->parseIndividualCommon($comb->individual);
            $res['refuse'] = empty($comb->refuse) ? [] : $this->parseRefuseCommon($comb->refuse);
            $res['receivbales'] = empty($comb->receivbales) ? [] : $this->parseReceivablesCommon($comb->receivbales);
            $res['return'] = empty($comb->returngoods) ? [] : $this->parseReturnCommon($comb->returngoods);
            $res['bargaining'] = empty($comb->bargaining) ? [] : $this->parseBargainingCommon($comb->bargaining);
            $res['comb'] = $comb->toArray();
            return $res;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析库存模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseQuantityCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonQuantity::get($id);
            } else {
                $res = EbayCommonQuantity::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析议价模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseBargainingCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonBargaining::get($id);
            } else {
                $res = EbayCommonBargaining::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析退货模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseReturnCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $tmp = EbayCommonReturn::get($id);
            } else {
                $tmp = EbayCommonReturn::get(['model_name' => $name]);
            }
//            if (empty($tmp)) {
//                return [];
//            }
//            $detail['return_policy'] = $tmp->return_policy;
//            $detail['return_type'] = $tmp->return_type;
//            $detail['return_time'] = $tmp->return_time;
//            $detail['extended_holiday'] = $tmp->extension;
//            $detail['return_shipping_option'] = $tmp->return_shipping_option;
//            $detail['restocking_fee_code'] = $tmp->restocking_fee_code;
//            $detail['return_description'] = $tmp->return_description;
//            $res['detail'] = $detail;
//            $res['id'] = $tmp->id;
            $res = $tmp;
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析收款模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseReceivablesCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonReceivables::get($id);
            } else {
                $res = EbayCommonReceivables::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析买家限制模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseRefuseCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $info = EbayCommonRefuseBuyer::get($id);
            } else {
                $info = EbayCommonRefuseBuyer::get(['model_name' => $name]);
            }
            if (empty($info)) {
                return [];
            }
            $detail['credit'] = $info->credit;
            $detail['strikes'] = $info->strikes;
            $detail['violations'] = $info->violations;
            $detail['link_paypal'] = $info->link_paypal;
            $detail['registration'] = $info->registration;
            $detail['requirements'] = $info->requirements;
            $detail['strikes_count'] = $info->strikes_count;
            $detail['strikes_period'] = $info->strikes_period;
            $detail['minimum_feedback'] = $info->minimum_feedback;
            $detail['violations_count'] = $info->violations_count;
            $detail['violations_period'] = $info->violations_period;
            $detail['minimum_feedback_score'] = $info->minimum_feedback_score;
            $detail['requirements_max_count'] = $info->requirements_max_count;
            $detail['requirements_feedback_score'] = $info->requirements_feedback_score;
            $res['detail'] = $detail;
            $res['id'] = $info->id;
            $res['refuse'] = $info->refuse;
            return $res;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析私人物品模板
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseIndividualCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonIndividual::get($id);
            } else {
                $res = EbayCommonIndividual::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    public function parseGalleryCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonGallery::get($id);
            } else {
                $res = EbayCommonGallery::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];

        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析物品所在地模板
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseLocationCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonLocation::get($id);
            } else {
                $res = EbayCommonLocation::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析自提模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parsePickupCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonPickup::get($id);
            } else {
                $res = EbayCommonPickup::get(['model_name' => $name]);
            }
            return $res ? $res->toArray() : [];
        } catch (Exception $e) {
            throw new Exception($e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 解析备货模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseChoiceCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonChoice::get($id);
            } else {
                $res = EbayCommonChoice::get(['model_name'=>$name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 解析不送达地区模块
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseExcludeCommon(int $id, string $name='')
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if ($id) {
                $res = EbayCommonExclude::get($id);
            } else {
                $res = EbayCommonExclude::get(['model_name'=>$name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }
    /**
     * 解析物流模快
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parseTransCommon(int $id, string $name='') : array
    {
        try {
            $res = [];
            if (empty($id) && empty($name)) {
                return [];
            }
            if (empty($id)) {
                $id = EbayCommonTrans::where(['model_name'=>$name])->value('id');
            }
            $detail = (new EbayCommonTransDetail())->field(true)->where(['trans_id'=>$id])->select();
            $internationalShipping = [];
            $shipping = [];
            foreach ($detail as $k => $v) {
                if ($v['inter'] == 1) {
                    $internationalShipping[$k]['shipping_service'] = $v['trans_code'];
                    $internationalShipping[$k]['shipping_service_cost'] = $v['cost'];
                    $internationalShipping[$k]['shipping_service_additional_cost'] = $v['add_cost'];
                    $internationalShipping[$k]['shiptolocation'] = $v['location'];
                } else {
                    $shipping[$k]['shipping_service'] = $v['trans_code'];
                    $shipping[$k]['shipping_service_cost'] = $v['cost'];
                    $shipping[$k]['shipping_service_additional_cost'] = $v['add_cost'];
                    $shipping[$k]['extra_cost'] = $v['extra_cost'];
                }
            }
            $res['internationalShipping'] = array_values($internationalShipping);
            $res['shipping'] = array_values($shipping);
            $res['id'] = $id;
            return $res;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

//    public function parseModel(string $name, int $id, string $modelName='') : array
//    {
//        try {
//            $res = [];
//            if (empty($id) && empty($name)) {
//                return [];
//            }
//            if ($id) {
//                switch ($name) {
//                    case 'promotion':
//                        $res = EbayModelPromotion::get($id);
//                        break;
//                    case 'trans':
//                        $detail = (new EbayCommonTransDetail())->field(true)->where(['trans_id'=>$id])->select();
//                        $internationalShipping = [];
//                        $shipping = [];
//                        foreach ($detail as $k => $v) {
//                            if ($v['inter'] == 1) {
//                                $internationalShipping[$k]['inter_shipping_service'] = $v['trans_code'];
//                                $internationalShipping[$k]['inter_shipping_service_cost'] = $v['cost'];
//                                $internationalShipping[$k]['inter_shipping_service_additional_cost'] = $v['add_cost'];
//                                $internationalShipping[$k]['inter_shiptolocation'] = $v['location'];
//                            } else {
//                                $shipping[$k]['shipping_service'] = $v['trans_code'];
//                                $shipping[$k]['shipping_service_cost'] = $v['cost'];
//                                $shipping[$k]['shipping_service_additional_cost'] = $v['add_cost'];
//                                $shipping[$k]['extra_cost'] = $v['extra_cost'];
//                            }
//                        }
//                        $res['internationalShipping'] = $internationalShipping;
//                        $res['shipping'] = $shipping;
//                        break;
//                    case 'exclude':
//                        $res = EbayCommonExclude::get($id);
//                        break;
//                    case 'choice':
//                        $res = EbayCommonChoice::get($id);
//                        break;
//                    case 'pickup':
//                        $res = EbayCommonPickup::get($id);
//                        break;
//                    case 'location':
//                        $res = EbayCommonLocation::get($id);
//                        break;
//                    case 'gallery':
//                        $res = EbayCommonGallery::get($id);
//                        break;
//                    case 'individual':
//                        $res = EbayCommonIndividual::get($id);
//                        break;
//                    case 'refuse':
//                        $res = EbayCommonRefuseBuyer::get($id);
//                        break;
//                }
//            }
//        } catch(Exception $e) {
//            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
//        }
//    }
    /**
     * 解析促销模板详细信息
     * @param int $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function parsePromotionModel(int $id, string $name='') : array
    {
        try {

            if ($id) {
                $res = EbayModelPromotion::get($id);
            } else {
                $res = EbayModelPromotion::get(['model_name'=>$name]);
            }
            return $res ? $res->toArray() : [];
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }




    /**
     * @param $img
     * @param $accountInfo
     * @param $site
     * @return mixed
     * @throws Exception
     */
    public function uploadImgsWithoutWrite($img, $accountInfo, $site=0)
    {
        try {
            $verb = 'UploadSiteHostedPictures';
            $ebayApi = new EbayPackApi();
            $ebayDealRes = new EbayDealApiInformation();
            $apiObj = $ebayApi->createApi($accountInfo, $verb);
            $xmls[0] = $ebayApi->createXml($img);
            for ($i=0; $i<3; $i++) {
                $response = $apiObj->createHeaders()->sendHttpRequestMulti($xmls);
                foreach ($response as $key => $res) {
                    $r = $ebayDealRes->dealWithApiResponse($verb, $res);
                    if ($r['result'] == true) {
                        return $r['data'];//直接返回图片eps_path
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }









    /**
     * 获取ebay站点id
     * @param string $code
     * @return int
     * @throw exception
     */
    public function getSiteIdByCode($code)
    {
        if (isset($this->sites[$code])) {
            return $this->sites[$code];
        }
        $siteInfo = Cache::store('ebaySite')->getSiteInfoByCode($code, 'country');
        if ($siteInfo) {
            $this->sites[$code] = $siteInfo['siteid'];
            return $siteInfo['siteid'];
        } else {
            throw new Exception('此站点'. $code . '不存在');
        }
    }
    
    /*
     * title 同步listing信息
     * @param list listing详细信息
     * @return boolean
     */
    public function syncEbayListing($list)
    {
        try {
            if ($this->accountId) {
                $listing['account_id'] = $this->accountId;
            } else {
                $accountName = $list['Seller']['UserID']??'';
                if (!$accountName) {
                    throw new Exception('解析listing账号名称失败，无法正确拉取');
                }
                $accountId = EbayAccount::where('account_name',$accountName)->value('id');
                $this->accountId = $accountId;
                $listing['account_id'] = $accountId;
            }
            $listing['site'] = $this->getSiteIdByCode($list['Site']);
            $listing['item_id'] = $list['ItemID'];
            $listing['listing_sku'] = $list['SKU'] ?? '';
            $listing['currency'] = $list['Currency'];
            // 判断是否为多属性产品
            if (isset($list['Variations'])) {
                $listing['variation'] = 1;
            } else {
                $listing['variation'] = 0;
            }
            $detail = []; // listing详情
            // listing基本信息
            $listing['paypal_emailaddress'] = isset($list['PayPalEmailAddress']) ? $list['PayPalEmailAddress'] : "";
            $listing['primary_categoryid'] = $list['PrimaryCategory']['CategoryID'];
            $listing['second_categoryid'] = isset($list['SecondCategory']) ? $list['SecondCategory']['CategoryID'] : 0;
            $listing['quantity'] = $list['Quantity'] ?? 0;
            $listing['sold_quantity'] = intval($list["SellingStatus"]["QuantitySold"]);
            $listing['buy_it_nowprice'] = $list["BuyItNowPrice"]; // 一口价
            $listing['start_price'] = $list["StartPrice"]; // 起始价

            $listing['max_price'] = $list['StartPrice'];
            $listing['min_price'] = $list['StartPrice'];

            //在线状态
            $listingStatus = $list['SellingStatus']['ListingStatus'] ?? 'Active';
            if ( $listingStatus != 'Active') {
                $listing['listing_status'] = EbayPublish::PUBLISH_STATUS['ended'];
            }

            //折扣信息
            $promotionalSaleDetails = $list['SellingStatus']['PromotionalSaleDetails'] ?? [];
            $endTime = $promotionalSaleDetails['EndTime']??0;
            $endTime = strtotime($endTime);
            if ($promotionalSaleDetails && $endTime && $endTime>time()) {//有折扣信息，且折扣结束日期未到
                $listing['is_promotion'] = 1;
            } else {
                $listing['is_promotion'] = 0;
            }

            $listing['reserve_price'] = $list['ReservePrice'] ?? 0.00; // 保留价
            $listing['img'] = isset($list["PictureDetails"]["GalleryURL"]) ? $list["PictureDetails"]["GalleryURL"] : "";
            $listing['title'] = $list['Title']; // 标题
            $listing['sub_title'] = $list['SubTitle'] ?? ''; // 副标题
            $listing['hit_count'] = isset($list['HitCount']) ? $list['HitCount'] : 0; // 点击量
            $listing['hit_counter'] = $this->getHitcounter($list['HitCounter']);
            $listing['watch_count'] = isset($list['WatchCount']) ? intval($list['WatchCount']) : 0; //收藏量
            $listing['listing_type'] = $this->getListingType($list['ListingType']); // 刊登类型
            $detail['description'] = $list['Description']; // 描述
            $listing['store_category_id'] = isset($list['Storefront']['StoreCategoryID']) ? intval($list['Storefront']['StoreCategoryID']) : 0;
            $listing['store_category2_id'] = isset($list['Storefront']['StoreCategory2ID']) ? intval($list['Storefront']['StoreCategory2ID']) : 0;
            $detail['condition_id'] = isset($list['ConditionID']) ? $list['ConditionID'] : '';
            $detail['condition_description'] = isset($list['ConditionDescription']) ? $list['ConditionDescription'] : "";
            $listing['start_date'] = strtotime($list['ListingDetails']['StartTime']);
            $listing['end_date'] = strtotime($list['ListingDetails']['EndTime']);

            $listing['listing_status'] = $this->getListingStatus($list['SellingStatus']['ListingStatus']);
            $listing['autopay'] = $list['AutoPay'] == 'false' ? 0 : 1;
            $listing['private_listing'] = $list['PrivateListing'] == 'false' ? 0 : 1;
            $listing['picture_gallery'] = isset($list["PictureDetails"]["PictureURL"]) ? $this->getGallery($list['PictureDetails']['GalleryType']) : 0;
            $images = array();
            if (isset($list["PictureDetails"]["PictureURL"])) {
                $images = is_array($list["PictureDetails"]["PictureURL"]) ? $list["PictureDetails"]["PictureURL"] : array($list["PictureDetails"]["PictureURL"]);
            }
            $listing['listing_enhancement'] = isset($list['ListingEnhancement']) ? 1 : 0;
            // 缺少是否开启BestOffer的判断
            $detail['auto_accept_price'] = $list['ListingDetails']['BestOfferAutoAcceptPrice'] ?? 0.00;
            $detail['minimum_accept_price'] = $list['ListingDetails']['MinimumBestOfferPrice'] ?? 0.00;
            // 拍卖信息
            $detail['bid_count'] = $list['SellingStatus']['BidCount'] ?? 0;
            $detail['bid_increment'] = $list['SellingStatus']['BidIncrement'] ?? 0.00;
            $detail['current_price'] = $list['SellingStatus']['CurrentPrice'] ?? 0.00;
            if (isset($list['SellingStatus']['PromotionalSaleDetails'])) {
                // 待处理
            }
            // 运输
            $internationalShipping = []; // 国际运输方式
            $shipping = []; // 国内运输方式
            $listing["location"] = $list["Location"]; // 商品所在地
            $listing["country"] = $list["Country"]; // 发货国家代码
            $listing['listing_duration'] = $this->getListingDuration($list['ListingDuration']); // 刊登天数
            $detail['application_data'] = isset($list['ApplicationData']) ? $list['ApplicationData'] : ""; // 应用名称
            $detail['description'] = isset($list['Description']) ? $list['Description'] : "";
            isset($list["DispatchTimeMax"]) && $listing["dispatch_max_time"] = intval($list["DispatchTimeMax"]); // 发货处理时间(dispatch_time_max)
            $detail["payment_method"] = json_encode($list['PaymentMethods']); // 付款方式
            $itemSpecifics = [];
            if (isset($list['ItemSpecifics']) && $list['ItemSpecifics']) {
                $speNameValues = isset($list['ItemSpecifics']['NameValueList']['Name']) ? [$list['ItemSpecifics']['NameValueList']] : $list['ItemSpecifics']['NameValueList'];
                //获取当前站点分类下所有的分类属性，以便对比合并
                $whCs = [
                    'site' => $listing['site'],
                    'category_id' => $listing['primary_categoryid'],
                ];
                $fieldCs = 'category_specific_name,max_values';
                $allCs = EbayCategorySpecific::where($whCs)->field($fieldCs)->column('max_values','category_specific_name');
                $cSNames = array_keys($allCs);

                foreach ($speNameValues as $nameValue) {
                    if (in_array($nameValue['Name'],$cSNames)) {//是标准属性
                        if ($allCs[$nameValue['Name']]>1 && !is_array($nameValue['Value'])) {//原数据是数组格式，但是这里不是，进行转换
                            $nameValue['Value'] = [$nameValue['Value']];
                        }
                        $custom = 0;
                    } else {
                        $custom = 1;
                    }
                    array_push($itemSpecifics, ['attr_name' => $nameValue['Name'], 'attr_value' => $nameValue['Value'], 'source' => $nameValue['Source'], 'custom' => $custom]);
                }
                $detail['specifics'] = json_encode($itemSpecifics);
            }

            // ItemCompatibilityCount
            if (isset($list['ItemCompatibilityCount'])) {
                $detail['compatibility_count'] = $list['ItemCompatibilityCount'];
            }
            $compatibilityList = [];
            if (isset($list['ItemCompatibilityList']) && $list['ItemCompatibilityList']) {
                $Compatibility = isset($list['ItemCompatibilityList']['Compatibility'][0]) ?
                    $list['ItemCompatibilityList']['Compatibility'] : [$list['ItemCompatibilityList']['Compatibility']];
                foreach ($Compatibility as $k => $comp) {
                    $NameValueList = $comp['NameValueList'];
                    $NameValueListTemp = [];
                    foreach ($NameValueList as $k2 => $nameVal) {
                        $NameValueListTemp[$k2]['name'] = lcfirst($nameVal['Name']);
                        $NameValueListTemp[$k2]['value'] = $nameVal['Value'];
                    }
                    $compatibilityList[$k]['name_value_list'] = $NameValueListTemp;
                    $compatibilityList[$k]['compatibility_notes'] = $comp['CompatibilityNotes'];
                }
            }
            $detail['compatibility'] = json_encode($compatibilityList);

            $excludeLocations = [];
            if (isset($list["ShippingDetails"])) {
                $ShippingDetails = $list["ShippingDetails"];
                if (isset($ShippingDetails["PaymentInstructions"])) {
                    $detail["payment_instructions"] = $ShippingDetails["PaymentInstructions"]; // 付款说明
                } else {
                    $detail["payment_instructions"] = "";
                }
                if (isset($ShippingDetails["ExcludeShipToLocation"])) { // 不送达地区
                    $excludeLocations = $ShippingDetails["ExcludeShipToLocation"];
                }
                if (isset($ShippingDetails['SellerExcludeShipToLocationsPreference'])) {
                    $detail['custom_exclude'] = $ShippingDetails['SellerExcludeShipToLocationsPreference']==="false" ? 3 : 2;
                }
                if (isset($ShippingDetails["ShippingServiceOptions"])) {// 国内运输
                    $ship = isset($ShippingDetails["ShippingServiceOptions"][0]) ? $ShippingDetails["ShippingServiceOptions"] : array($ShippingDetails["ShippingServiceOptions"]);
                    foreach ($ship as $ksh => $vsh) {
                        $shipping[$ksh]['shipping_service'] = $vsh['ShippingService'];
                        $shipping[$ksh]['extra_cost'] = 0;
                        $shipping[$ksh]['shipping_service_cost'] = isset($vsh['ShippingServiceCost']) ? $vsh['ShippingServiceCost'] : 0;
                        $shipping[$ksh]['shipping_service_additional_cost'] = isset($vsh['ShippingServiceAdditionalCost']) ? $vsh['ShippingServiceAdditionalCost'] : 0;
                        $shipping[$ksh]['shipping_service_priority'] = isset($vsh['ShippingServicePriority']) ? $vsh['ShippingServicePriority'] : 0;
                        $shipping[$ksh]['expedited_service'] = isset($vsh['ExpeditedService']) ? ($vsh['ExpeditedService'] == "true" ? 1 : 0) : 0;
                        $shipping[$ksh]['shipping_time_min'] = isset($vsh['ShippingTimeMin']) ? $vsh['ShippingTimeMin'] : 0;
                        $shipping[$ksh]['shipping_time_max'] = isset($vsh['ShippingTimeMax']) ? $vsh['ShippingTimeMax'] : 0;
                        $shipping[$ksh]['free_shipping'] = isset($vsh['FreeShipping']) ? ($vsh['FreeShipping'] == "true" ? 1 : 0) : 0;
                    }
                }

                if (isset($ShippingDetails["InternationalShippingServiceOption"])) { // 国际运输
                    $InternationalShippingServiceOption = isset($ShippingDetails["InternationalShippingServiceOption"][0]) ? $ShippingDetails["InternationalShippingServiceOption"] : array($ShippingDetails["InternationalShippingServiceOption"]);
                    $i = 0;
                    foreach ($InternationalShippingServiceOption as $in) {
                        $internationalShipping[$i]["shipping_service"] = $in["ShippingService"];
                        $internationalShipping[$i]["shipping_service_additional_cost"] = 0;
                        if (isset($in["ShippingServiceAdditionalCost"])) {
                            $internationalShipping[$i]["shipping_service_additional_cost"] = $in["ShippingServiceAdditionalCost"];
                        }
                        $internationalShipping[$i]["shipping_service_cost"] = 0;
                        if (isset($in["ShippingServiceCost"])) {
                            $internationalShipping[$i]["shipping_service_cost"] = $in["ShippingServiceCost"];
                        }
                        $internationalShipping[$i]["shipping_service_priority"] = $in["ShippingServicePriority"];
                        $ShipToLocation = is_array($in["ShipToLocation"]) ? $in["ShipToLocation"] : [$in["ShipToLocation"]];
                        $internationalShipping[$i]["shiptolocation"] = $ShipToLocation;
                        $i++;
                    }
                }
            }
            $detail['exclude_location'] = json_encode($excludeLocations);
            $shipToLocation = isset($list['ShipToLocations']) ? $list['ShipToLocations'] : [];
            $detail['ship_location'] = json_encode($shipToLocation);
            // 退换货政策
            if (isset($list["ReturnPolicy"])) {
                $ReturnPolicy = $list["ReturnPolicy"];
                // 退款详情
                if (isset($ReturnPolicy["Description"])) {
                    $detail["return_description"] = $ReturnPolicy["Description"];
                } else {
                    $detail["return_description"] = "";
                }
                // 退款方式
                if (isset($ReturnPolicy["RefundOption"])) {
                    $detail["return_type"] = $ReturnPolicy["RefundOption"];
                }
                // 退款天数
                $listing["return_time"] = isset($ReturnPolicy["ReturnsWithinOption"]) ? $this->getReturnTime($ReturnPolicy["ReturnsWithinOption"]) : 0;
                // 运费承担方
                if (isset($ReturnPolicy["ShippingCostPaidByOption"])) {
                    $detail["return_shipping_option"] = $ReturnPolicy["ShippingCostPaidByOption"] == "Buyer" ? 1 : 2;
                }
                // 是否支持退换货
                $detail["return_policy"] = 1;
            } else {
                $detail["return_policy"] = 0;
            }

            // 买家限制
            $buererRequiments = [];
            if (isset($list["BuyerRequirementDetails"])) {
                // paypal限制 绑定paypal的限制
                $BuyerRequirementDetails = $list["BuyerRequirementDetails"];
                if (isset($BuyerRequirementDetails["LinkedPayPalAccount"])) {
                    $buererRequiments["link_paypal"] = $BuyerRequirementDetails["LinkedPayPalAccount"] == "true" ? 1 : 0;
                } else {
                    $buererRequiments["link_paypal"] = 0;
                }

                // 未付款限制 弃标案显示
                if (isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"])) {
                    // 次数
                    $buererRequiments["strikes_count"] = isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Count"]) ? $BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Count"] : 0;
                    // 时限
                    $buererRequiments["strikes_period"] = isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Period"]) ? $BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Period"] : "";
                    $buererRequiments["strikes"] = 1;
                } else {
                    $buererRequiments["strikes"] = 0;
                }

                // 违反ebay政策相关
                if (isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"])) {
                    // 次数
                    $buererRequiments["violations_count"] = isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Count"]) ? $BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Count"] : 0;
                    // 时限
                    $buererRequiments["violations_period"] = isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Period"]) ? $BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Period"] : "";
                    $buererRequiments["violations"] = 1;
                } else {
                    $buererRequiments["violations"] = 0;
                }

                // 限制条件
                if (isset($BuyerRequirementDetails["MaximumItemRequirements"])) {
                    $buererRequiments["requirements_max_count"] = isset($BuyerRequirementDetails["MaximumItemRequirements"]["MaximumItemCount"]) ? $BuyerRequirementDetails["MaximumItemRequirements"]["MaximumItemCount"] : 0;
                    if (isset($BuyerRequirementDetails["MaximumItemRequirements"]["MinimumFeedbackScore"])) {
                        $buererRequiments["minimum_feedback"] = 1;
                        $buererRequiments['minimum_feedback_score'] = $BuyerRequirementDetails["MaximumItemRequirements"]["MinimumFeedbackScore"];
                    }
                    $buererRequiments["requirements"] = 1;
                } else {
                    $buererRequiments["requirements"] = 0;
                }

                // 信用限制
                if (isset($BuyerRequirementDetails["MinimumFeedbackScore"])) {
                    $buererRequiments["credit"] = 1;
                    if (isset($BuyerRequirementDetails["MinimumFeedbackScore"]))
                        $buererRequiments["requirements_feedback_score"] = $BuyerRequirementDetails["MinimumFeedbackScore"];
                } else {
                    $buererRequiments["credit"] = 0;
                }

                // 不在我的配送地
                if (isset($BuyerRequirementDetails["ShipToRegistrationCountry"])) {
                    $buererRequiments["registration"] = $BuyerRequirementDetails["ShipToRegistrationCountry"] == "true" ? 1 : 0;
                } else {
                    $buererRequiments["registration"] = 0;
                }
                $listing["disable_buyer"] = 1;
            } else {
                $listing["disable_buyer"] = 0;
            }

            $vs = array();
            $variationPics = [];
            if (isset($list["Variations"])) {
                $skuImgs = [];
                $variations = isset($list["Variations"]["Variation"][0]) ? $list["Variations"]["Variation"] : array($list["Variations"]["Variation"]);
                //变体图片
//                if (isset($list['Variations']['Pictures'])) {
//                    $pictureDetail = $list['Variations']['Pictures'];
//                    $name = $pictureDetail['VariationSpecificName'];
//                    $detail['variation_image'] = $name;
//                    foreach ($pictureDetail['VariationSpecificPictureSet'] as $set) {
//                        $value = $set['VariationSpecificValue'];
//                        if (!isset($set['PictureURL']) || !$set['PictureURL']) {
//                            continue;
//                        }
//                        $skuImgs[$value] = $set['PictureURL'];
//                    }
//                }
                $i = 0;

                foreach ($variations as $ia) {
                    #$vs[$i]["v_sku"]=$ia["SKU"];
                    $vs[$i]['channel_map_code'] = $ia['SKU'];
                    $vs[$i]["v_price"] = $ia["StartPrice"];
                    $listing['max_price'] = empty($listing['max_price']) ? $ia['StartPrice'] : max($listing['max_price'],$ia['StartPrice']);
                    $listing['min_price'] = empty($listing['min_price']) ? $ia['StartPrice'] : min($listing['min_price'],$ia['StartPrice']);
                    if ($i == 0) $listing['start_price'] = $ia["StartPrice"];
                    $vs[$i]["v_qty"] = $ia["Quantity"] ?? 0;
                    $vs[$i]["v_sold"] = intval($ia["SellingStatus"]["QuantitySold"]);
                    if (isset($ia["VariationProductListingDetails"]["UPC"]))
                        $vs[$i]["upc"] = $ia["VariationProductListingDetails"]["UPC"];
                    if (isset($ia["VariationProductListingDetails"]["ISBN"]))
                        $vs[$i]["isbn"] = $ia["VariationProductListingDetails"]["ISBN"];
                    if (isset($ia["VariationProductListingDetails"]["EAN"]))
                        $vs[$i]["ean"] = $ia["VariationProductListingDetails"]["EAN"];
                    $Specifics = isset($ia["VariationSpecifics"]["NameValueList"][0]) ? $ia["VariationSpecifics"]["NameValueList"] : array($ia["VariationSpecifics"]["NameValueList"]);
                    $temp = array();
                    foreach ($Specifics as $val) {
                        $temp[$val["Name"]] = $val["Value"];
//                        if ($val['Name'] == ($detail['variation_image']??'') && isset($skuImgs[$val['Value']])) {
//                            $vs[$i]['path'] = json_encode($skuImgs[$val['Value']]??'');
//                        }
                    }
                    ksort($temp);
                    $vs[$i]["variation"] = json_encode($temp);
                    $vs[$i]['unique_code'] = md5($vs[$i]['variation']);
                    $i++;
                }
            }

//            if (isset($list['Variations']) && isset($list['Variations']['Pictures'])) {
//                $pictureDetail = $list['Variations']['Pictures'];
//                $name = $pictureDetail['VariationSpecificName'];
//                $detail['variation_image'] = $name;
//                foreach ($pictureDetail['VariationSpecificPictureSet'] as $set) {
//                    $value = $set['VariationSpecificValue'];
//                    if (!isset($set['PictureURL']) || !$set['PictureURL']) {
//                        continue;
//                    }
//                    $vaPicList = is_array($set['PictureURL']) ? $set['PictureURL'] : [$set['PictureURL']];
//                    foreach ($vaPicList as $k => $img) {
//                        $image['name'] = $name;
//                        $image['value'] = $value;
//                        if ($k == 0) {
//                            $image['main'] = 1;
//                        } else {
//                            $image['main'] = 0;
//                        }
//                        $image['main_de'] = 1;
//                        $image['url'] = $img;
//                        array_push($variationPics, $image);
//                        unset($image);
//                    }
//                }
//            }

            $detail['shipping'] = json_encode($shipping);
            $detail['international_shipping'] = json_encode($internationalShipping);
            if (!$buererRequiments) {
                $buererRequiments = [
                    "credit" => 0,
                    "strikes" => 0,
                    "violations" => 0,
                    "link_paypal" => 0,
                    "registration" => 0,
                    "requirements" => 0,
                    "strikes_count" => 0,
                    "strikes_period" => "",
                    "minimum_feedback" => 0,
                    "violations_count" => 0,
                    "violations_period" => "",
                    "minimum_feedback_score" => 0,
                    "requirements_max_count" => 0,
                    "requirements_feedback_score" => 0
                ];
            }
            $detail['buyer_requirment_details'] = json_encode($buererRequiments);
            $listingData = [
                "listing" => $listing,
                "images" => $images,
                "detail" => $detail,
                "variations" => $vs,
                'variationPics' => $skuImgs??[],
            ];
            return $listingData;
        }catch (Exception $e){
            throw new Exception($e->getFile()|$e->getLine()|$e->getMessage());
        }
    }

    /*
     * title 同步listing数据
     * @param listingData listing信息
     */
    public function syncListingData($listingData,$draft=0,$notificationType='')
    {
        try{
            $variations = [];
            $images = [];
            $listingModel = new EbayListing();
            $settingModel = new EbayListingSetting();
            $imageModel = new EbayListingImage();
            $addFlag = false;
            $mainIds = [];
            sleep(1);//防止数据同步延迟
            //缓存已经废弃使用了
//            $cacheInfo = $this->cache->getProductCache($this->accountId, $listingData['listing']['item_id']);

            $list = $listingData['listing'];
            $field = 'id,item_id,goods_id,local_sku,sku,cost_price,adjusted_cost_price,listing_status';
            //先查item id,再根据条件过滤，是为了避免与本地刊登的listing合并时出现问题，因为有些情况下虽然刊登成功了，但是本地的
            //信息没有正确回写，直接用item id查找会查找不到，下面的查询条件可以保证listing的唯一性
            if (!($localListing = EbayListing::where('item_id',$listingData['listing']['item_id'])->field($field)->find())) {
                $isExistWh = [
                    'account_id' => $list['account_id'],
                    'title' => $list['title'],
                    'listing_type' => $list['listing_type'],
                    'site' => $list['site'],
                    'location' => $list['location'],
                    'primary_categoryid' => $list['primary_categoryid'],
                ];
                $localListing = EbayListing::where($isExistWh)->field($field)->find();
            }
            if ($localListing) {
                $id = $localListing['id'];
            } else {
                $id = 0;
            }

            if($draft==1 || $id==0){
                $id=0;
                $variationList = [];
                $variationList = [];
                $images = [];
                $mainIds = [];
                $listingData['listing']['create_date'] = time();
                $listingData['listing']['update_date'] = time();
                $userInfo = Common::getUserInfo();
                $userId = empty($userInfo) ? 0 : $userInfo['user_id'];#用户ID
                $listingData['listing']['user_id'] = $userId;
                $listingData['listing']['realname'] = $userId;
                $draft && $listingData['listing']['item_id'] = 0;
                $listingData['listing']['draft'] = 0;
            }
            if (in_array($notificationType,['ItemUnsold', 'ItemSold'])) {
                $listingData['listing']['listing_status'] = EbayPublish::PUBLISH_STATUS['ended'];
//                $listingData['listing']['end_type'] = 0;//自动下架
                $listingData['listing']['manual_end_time'] = time();//记录下架时间
            } elseif (!in_array($localListing['listing_status'],EbayPublish::OL_PUBLISH_STATUS)) {
                $listingData['listing']['listing_status'] = 3;
            }
            try {
                Db::startTrans();
                // Listing
                if ($id) {//更新
                    $listDb = $listingModel->field("application")->where(['id' => $id])->find();
                    if ($listDb['application'] == 1) {
                        unset($listingData['detail']['description']);
                    }
                    unset($listingData['listing']['img']);//更新不需要更新主图
                    $listingModel->allowField(true)->where(['id' => $id])->update($listingData['listing']);

                    $settingModel->allowField(true)->where(['id' => $id])->update($listingData['detail']);
                    $variationList = EbayListingVariation::where(['listing_id' => $id])->field('id,unique_code,variation,channel_map_code')->select();

                    if($listingData['listing']['variation']){
                        foreach ($variationList as $variationItem) {
                            $variation = json_decode($variationItem['variation'],true)??[];
                            foreach ($variation as $vk => &$vv) {
                                $vv = trim($vv);
                            }
                            ksort($variation);
                            $variations[md5(json_encode($variation))] = $variationItem['id'];
                        }
                        foreach ($listingData['variations'] as $variation) {
                            $variationModel = new EbayListingVariation();
                            if (isset($variations[$variation['unique_code']])) {//属性确定唯一性
                                $variationModel->allowField(true)->where(['id' => $variations[$variation['unique_code']]])->update($variation);
                            } else {
                                $variation['listing_id'] = $id;
                                $variationModel->insertGetId($variation);
                            }
                        }
                    } else {//单属性也写入变体表
                        $tmpVar = [
                            'v_price' => $listingData['listing']['start_price'],
                            'v_qty' => $listingData['listing']['quantity'],
                            'channel_map_code' => $listingData['listing']['listing_sku'],
                            'reserve_price' => $listingData['listing']['reserve_price'],
                            'buy_it_nowprice' => $listingData['listing']['buy_it_nowprice'],
                            'v_sold' => $listingData['listing']['sold_quantity'],
                            'listing_id' => $id,
                        ];
                        if (!$variationList) {//需要新建
                            $tmpVar['goods_id'] = $localListing['goods_id'];
                            $tmpVar['v_sku'] = $localListing['local_sku'];
                            $skuId = GoodsSku::where('sku',$localListing['local_sku'])->value('id');
                            $tmpVar['sku_id'] = empty($skuId) ? 0 : $skuId;
                            $tmpVar['combine_sku'] = $localListing['sku'];
                            $tmpVar['cost_price'] = $localListing['cost_price'];
                            $tmpVar['adjusted_cost_price'] = $localListing['adjusted_cost_price'];
                            $tmpVar['variation'] = json_encode([]);
                        } else {//更新
                            $tmpVar['id'] = $variationList[0]['id'];
                        }
                        (new EbayListingVariation())->saveAll([$tmpVar]);
                    }
                    //更新的时候旧图片格式转成新格式
//                    (new EbayPublish())->listingImgVersionO2N($id);
                    //变体
//                    if ($listingData['listing']['variation']) {
//                        $oVariations = EbayListingVariation::where('listing_id', $id)->column('variation', 'id');
//                        $reserveVarIds = [];
//                        foreach ($listingData['variations'] as &$variation) {
//                            $variation['listing_id'] = $id;
//                            $nVariation = json_decode($variation['variation'],true);
//                            foreach ($oVariations as $oId => $oVariation) {
//                                $oVariation = json_decode($oVariation,true);
//                                if (empty(array_diff_assoc($nVariation,$oVariation))) {//数组比较，避免顺序的影响
//                                    $variation['id'] = $oId;
//                                    $reserveVarIds[] = $oId;
//                                }
//                            }
//                        }
//                        $delVarIds = array_diff(array_keys($oVariations), $reserveVarIds);
//                        (new EbayListingVariation())->saveAll($listingData['variations']);
//                        if (!empty($delVarIds)) {
//                            EbayListingVariation::destroy($delVarIds);
//                        }
//                    }
                } else {//新增

                    $listingModel->allowField(true)->save($listingData['listing']);
                    $id = $listingModel->id;
                    $listingData['detail']['id'] = $id;
                    $listingData['detail']['specifics'] = json_encode([]);
                    $mongoSet['description'] = $listingData['detail']['description'];
                    $mongoSet['id'] = $id;
                    unset($listingData['detail']['description']);
                    $settingModel->allowField(true)->insertGetId($listingData['detail']);
                    //图片
                    $publishImgs = $listingData['images'];
                    $imgs = [];
                    foreach ($publishImgs as $k => $publishImg) {
                        $imgs[] = [
                            'listing_id' => $id,
                            'path' => $publishImg,
                            'thumb' => $publishImg,
                            'ser_path' => $publishImg,
                            'eps_path' => $publishImg,
                            'main' => 1,
                            'sort' => $k
                        ];
                    }
                    (new EbayListingImage())->saveAll($imgs);
                    if ($listingData['listing']['variation']) {
                        foreach ($listingData['variations'] as &$variation) {
                            $variation['listing_id'] = $id;
                        }
                        (new EbayListingVariation())->saveAll($listingData['variations']);
                    } else {//单属性
                        $tmpVar = [
                            'goods_id' => 0,
                            'v_sku' => 0,
                            'sku_id' => 0,
                            'v_price' => $listingData['listing']['start_price'],
                            'v_qty' => $listingData['listing']['quantity'],
                            'combine_sku' => '',
                            'channel_map_code' => $listingData['listing']['listing_sku'],
                            'cost_price' => 0.00,
                            'adjusted_cost_price' => 0.00,
                            'reserve_price' => $listingData['listing']['reserve_price'],
                            'buy_it_nowprice' => $listingData['listing']['buy_it_nowprice'],
                            'variation' => json_encode([]),
                            'v_sold' => $listingData['listing']['sold_quantity'],
                            'listing_id' => $id,
                        ];
                        EbayListingVariation::create($tmpVar);
                    }
                }

//                $this->listingId = $id;
//                if ($addFlag) {//新增
//
//                }
//                //橱窗图片
//                $saveIds = [];
//                foreach ($listingData['images'] as $k => $v) {
//                    if (!isset($images[$v])) {
//                        $imgArr['listing_id'] = $id;
//                        $imgArr['sku'] = isset($listingData['listing']['sku']) ? $listingData['listing']['sku'] : "";
//                        $imgArr['path'] = $v;
//                        $imgArr['thumb'] = $v;
//                        $imgArr['eps_path'] = $v;
//                        $imgArr['main'] = 1;
//                        $imgArr['sort'] = $k;
//                        $imgArr['status'] = 3; // 已上传至eps
//                        $imgArr['detail'] = 0;
//                        $imgArr['update_time'] = time();
//                        $imageModel->insertGetId($imgArr);
//                        unset($imgArr);
//                    } else {
//                        $imageModel->where(['id' => $images[$v]])->update(['main' => 1, 'sort' => $k]);
//                        $saveIds[] = $images[$v];
//                    }
//                }
//                $upIds = array_diff($mainIds, $saveIds);
//                $imageModel->where(['id' => ['in', $upIds]])->update(['main' => 0]);
//                $whDel['main'] = 0;
//                $whDel['main_de'] = 0;
//                $whDel['detail'] = 0;
//                $whDel['id'] = ['in', $upIds];
//                $imageModel->where($whDel)->delete();
//
//                #变体
//                if ($listingData['listing']['variation']) {
//                    foreach ($listingData['variations'] as $variation) {
//                        $variationModel = new EbayListingVariation();
//                        if (isset($variations[$variation['channel_map_code']])) {
//                            $variationModel->allowField(true)->where(['id' => $variations[$variation['channel_map_code']]])->update($variation);
//                        } else {
//                            $variation['listing_id'] = $id;
//                            $variationModel->insertGetId($variation);
//                        }
//                    }
//                }
//
//                // 变体图片
//                if ($listingData['listing']['variation'] && $listingData['variationPics']) {//多属性
//                    $name = $listingData['variationPics'][0]['name'];//图片绑定的属性名称
//                    $variationList = EbayListingVariation::where(['listing_id' => $id])->field('id,variation')->select();
//                    foreach ($variationList as $variationItem) {
//                        $nameValues = json_decode($variationItem['variation'], true);
//                        if (empty($nameValues)) {
//                            continue;//解析失败不做处理
//                        }
//                        if (isset($nameValues[$name])) {//确保变体有对应属性
//                            $variations[$nameValues[$name]] = $variationItem['id'];
//                        }
//                    }
//
//                    //变体图片
//                    $deImgs = [];
//                    $deIds = [];
//                    $deImgsList = EbayListingImage::where(['listing_id' => $id, 'main_de' => 1])->field('id,eps_path')->select();
//                    foreach ($deImgsList as $k => $img) {
//                        $deImgs[$img['eps_path']] = $img['id'];
//                        $deIds[] = $img['id'];
//                    }
//                    $saveDeIds = [];
//                    $k2 = count($deImgs);
//                    foreach ($listingData['variationPics'] as $v) {
//                        if ($v['main'] && isset($variations[$v['value']])) {
//                            (new EbayListingVariation())->where(['id' => $variations[$v['value']]])->update(['thumb' => $v['url']]);
//                        }
//                        if (!isset($deImgs[$v['url']])) {#新增
//                            $imgArr['listing_id'] = $id;
//                            $imgArr['sku'] = isset($listingData['listing']['sku']) ? $listingData['listing']['sku'] : "";
//                            $imgArr['thumb'] = $v['url'];
//                            $imgArr['eps_path'] = $v['url'];
//                            $imgArr['sort'] = $k2++;
//                            $imgArr['status'] = 3; // 已上传至eps
//                            $imgArr['detail'] = 0;
//                            $imgArr['main_de'] = 1;
//                            $imgArr['name'] = $v['name'];
//                            $imgArr['value'] = $v['value'];
//                            $imgArr['update_time'] = time();
//                            $imageModel->insertGetId($imgArr);
//                            unset($imgArr);
//                        } else {#更新
//                            $imageModel->where(['id' => $images[$v['url']]])
//                                ->update(['main_de' => 1, 'name' => $v['name'], 'value' => $v['value'], 'sort' => $k2++]);
//                            $saveDeIds[] = $deImgs[$v['url']];
//                        }
//                    }
//
//                    $upDeIds = array_diff($deIds, $saveDeIds);
//                    $imageModel->where(['id' => ['in', $upDeIds]])->update(['main_de' => 0]);
//                    $whDelDe['main'] = 0;
//                    $whDelDe['main_de'] = 0;
//                    $whDelDe['detail'] = 0;
//                    $whDelDe['id'] = ['in', $upDeIds];
//                    $imageModel->where($whDel)->delete();
//                }
                Db::commit();
                if (isset($mongoSet)) {
                    ELSMongo::create($mongoSet);
                }
            } catch (\Exception $e) {
                Db::rollback();
                throw new Exception($e->getMessage());
            }
        }catch(Exception $ex) {
            throw $ex;
        }
    }
    
    private function getHitcounter($hitCounter)
    {
        $counters = ['NoHitCounter' => 1, 'BasicStyle' => 1, 'HiddenStyle' => 2, 'RetroStyle' => 3, 'GreedLED' => 4, 'Hidden' => 5, 'HonestyStyle' => 6];
        
        if (isset($counters[$hitCounter])) {
            return $counters[$hitCounter];
        }
        return 0;
    }
    
    private function getListingType($type)
    {
        $durations = ['FixedPriceItem' => 1, 'Chinese' => 2];
        if (isset($durations[$type])) {
            return $durations[$type];
        }
        return 1;
    }
    
    private function getListingDuration($duration)
    {
        $durations = ['GTC' => 1, 'Days_1' => 2, 'Days_3' => 3, 'Days_5' => 4, 'Days_7' => 5, 'Days_10' => 6, 'Days_30' => 7];
        if (isset($durations[$duration])) {
            return $durations[$duration];
        }
        
        return 1;
    }
    
    private function getListingStatus($status)
    {
        $statuses = ["Active" => 3, 'Completed' => 11, 'Custom' => 12, 'CustomCode' => 12, 'Ended' => 11];
        if (isset($statuses[$status])) {
            return $statuses[$status];
        }
        
        return 3;
    }
    
    private function getReturnTime($time)
    {
        $times = ['Days_14' => 1, 'Days_30' => 2, 'Days_60' => 3, 'Months_1' => 4];
        if (isset($times[$time])) {
            return $times[$time];
        }
        
        return 0;
    }
    
    private function getGallery($gallery)
    {
        $galleries = ['None' => 0, 'Gallery' => 1, 'Featured' => 2, 'Plus' => 3];
        if (isset($galleries[$gallery])) {
            return $galleries[$gallery];
        }
        
        return 0;
    }

    
    private function update($id, &$listingData)
    {
        $variations = [];
        $images = [];
        $listingModel = new EbayListing();
        $settingModel = new EbayListingSetting();
        Db::startTrans();
        try {
            $listingModel->where(['id' => $id])->update($listingData['listing']);
            $postData = ['specifics' => $listingData['detail']['specifics']];
            isset($listingData['detail']['compatibility_count']) ? $postData['compatibility_count'] = $listingData['detail']['compatibility_count'] : '';
            isset($listingData['detail']['compatibility']) ? $postData['compatibility'] = $listingData['detail']['compatibility'] : '';
            $settingModel->where(['id' => $id])->update($postData);
            #同步变体
            foreach ($listingData['variations'] as $variation) {
                $variationModel = new EbayListingVariation();
                if (isset($variations[$variation['unique_code']])) {
                    $variationModel->allowField(true)->where(['id' => $variations[$variation['unique_code']]])->update($variation);
                } else {
                    $variation['listing_id'] = $id;
                    $variationModel->allowField(true)->save($variation);
                }
            }
            #同步主图
            $imageModel = new EbayListingImage();
            $mainImgsDb = [];
            $mainIds = [];
            $mainImgsList = EbayListingImage::where(['listing_id' => $id])->field('id,eps_path')->select();
            foreach($mainImgsList as $mainImg){
                $mainImgsDb[$mainImg['eps_path']] = $mainImg['id'];
                $mainIds[] = $mainImg['id'];
            }
            $saveIds = [];
            foreach($listingData['images'] as $k => $img){
                if(!isset($mainImgsDb[$img])){#新增
                    $insImg['listing_id'] = $id;
                    $insImg['sku'] = isset($listingData['listing']['sku']) ? $listingData['listing']['sku'] : "";
                    $insImg['thumb'] = $img;
                    $insImg['eps_path'] = $img;
                    $insImg['sort'] = $k;
                    $insImg['status'] = 3;
                    $insImg['detail'] = 0;
                    $insImg['main'] = 1;
                    $insImg['update_time'] = time();
                    $imageModel->insertGetId($insImg);
                }else{#同步
                    $saveIds[] = $mainImgsDb[$img];
                    $imageModel->where(['id'=>$mainImgsDb[$img]])->update(['main'=>1,'sort'=>$k]);
                }
            }
            $upIds = array_diff($mainIds,$saveIds);
            $imageModel->where(['id'=>['in',$upIds]])->update(['main'=>0]);
            $whDel['main'] = 0;
            $whDel['main_de'] = 0;
            $whDel['detail'] = 0;
            $whDel['id'] = ['in',$upIds];
            $imageModel->where($whDel)->delete();

            #同步多属性图片
            if($listingData['listing']['variation'] && $listingData['variationPics']){
                $name = $listingData['variationPics'][0]['name'];
                $variationList = EbayListingVariation::where(['listing_id' => $id])->field('id,variation')->select();
                foreach ($variationList as $variationItem) {
                    $nameValues = json_decode($variationItem['variation'], true);
                    $variations[$nameValues[$name]] = $variationItem['id'];
                }

                $imageList = EbayListingImage::where(['listing_id' => $id])->field('id,eps_path')->select();
                foreach ($imageList as $image) {
                    $images[$image['eps_path']] = $image['id'];
                }
            
                $k = count($images);
                foreach ($listingData['variationPics'] as $v) {
                    if ($v['main'] && isset($variations[$v['value']])) {
                        (new EbayListingVariation())->where(['id' => $variations[$v['value']]])->update(['thumb' => $v['url']]);
                    }
                    if (!isset($images[$v['url']])) {
                        $imgArr['listing_id'] = $id;
                        $imgArr['sku'] = isset($listingData['listing']['sku']) ? $listingData['listing']['sku'] : "";
                        $imgArr['thumb'] = $v['url'];
                        $imgArr['eps_path'] = $v['url'];
                        $imgArr['sort'] = $k++;
                        $imgArr['status'] = 3; // 已上传至eps                
                        $imgArr['detail'] = 0;
                        $imgArr['main_de'] = isset($v['main_de'])?$v['main_de']:0;
                        $imgArr['name'] = $v['name'];
                        $imgArr['value'] = $v['value'];
                        $imgArr['update_time'] = time();
                        $imageModel->insertGetId($imgArr);
                        unset($imgArr);
                    } else {
                        $imageModel->where(['id' => $images[$v['url']]])->update(['main_de'=>1,'name' => $v['name'], 'value' => $v['value']]);
                    }
                }
            }
            Db::commit();
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }
    }

    public function packDraftData(&$data,$flag){

    }

    public function parseDraftData(&$data){

    }
}
