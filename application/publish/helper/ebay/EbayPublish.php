<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2018/6/22
 * Time: 17:57
 */
namespace app\publish\helper\ebay;

use app\common\model\Attribute;
use app\common\model\AttributeValue;
use app\common\model\Category;
use app\common\model\Channel;
use app\common\model\ChannelUserAccountMap;
use app\common\model\ebay\EbayActionLog;
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
use app\common\model\ebay\EbayCustomCategory;
use app\common\model\ebay\EbayListing;
use app\common\model\ebay\EbayListingImage;
use app\common\model\ebay\EbayListingSetting;
use app\common\model\mongodb\ebay\EbayListingSetting as ELSMongo;
use app\common\model\ebay\EbayListingVariation;
use app\common\model\ebay\EbayListingMappingSpecifics;
use app\common\model\ebay\EbaySite;
use app\common\model\ebay\EbayTitle;
use app\common\model\Goods;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsGallery;
use app\common\model\GoodsSku;
use app\common\model\GoodsLang;
use app\common\model\ebay\EbayAccount;
use app\common\model\ebay\EbayCategory;
use app\common\model\ebay\EbayDraft;
use app\common\model\User as UserModel;

use app\common\model\GoodsSkuMap;
use app\common\model\GoodsTortDescription;
use app\common\model\Lang;
use app\common\model\paypal\PaypalAccount;
use app\common\model\RoleUser;
use app\common\model\TitleKey;
use app\common\service\ebay\EbayRestful;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsHelp;
use app\goods\service\GoodsSkuMapService;

use app\goods\service\GoodsImage;
use app\index\service\Role;
use app\publish\queue\EbayPublishItemQueuer;
use app\publish\queue\EbayUpdateOnlineListing;
use app\publish\service\EbayDealApiInformation;
use app\publish\service\EbayPackApi;
use erp\ErpRbac;
use think\Exception;
use think\Db;
use app\common\cache\Cache;
use app\common\traits\User;

class EbayPublish
{
    use User;
    //站点id站点标志对，用于Header中
    public const MarketPlaceId = [
        0 => 'EBAY_US',
        16 => 'EBAY_AT',
        15 => 'EBAY_AU',
        123 => 'EBAY_BE',
        23 => 'EBAY_BE',
        2 => 'EBAY_CA',
        210 => 'EBAY_CA',
        193 => 'EBAY_CH',
        77 => 'EBAY_DE',
        186 => 'EBAY_ES',
        71 => 'EBAY_FR',
        3 => 'EBAY_GB',
        201 => 'EBAY_HK',
        205 => 'EBAY_IE',
        203 => 'EBAY_IN',
        101 => 'EBAY_IT',
        207 => 'EBAY_MY',
        146 => 'EBAY_NL',
        211 => 'EBAY_PH',
        212 => 'EBAY_PL',
        216 => 'EBAY_SG',
        100 => 'EBAY_MOTORS_US'
    ];
    //站点id语言对
    public const SITE_LANG = [
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
    //刊登状态
    public const PUBLISH_STATUS = [
        'noStatus' => 0,
        'inPublishQueue'=>1,
        'publishing' => 2,
        'publishSuccess' => 3,
        'publishFail' => 4,
        'inUpdateQueue' => 5,
        'updating' => 6,
        'updateFail' => 7,
        'inEndQueue' => 8,
        'ending' => 9,
        'endFail' => 10,
        'ended' => 11,
        'relistQueue' => 12,
        'relistFail' => 13,
    ];
    //在线的状态
    public const OL_PUBLISH_STATUS = [
        self::PUBLISH_STATUS['publishSuccess'],
        self::PUBLISH_STATUS['inUpdateQueue'],
        self::PUBLISH_STATUS['updating'],
        self::PUBLISH_STATUS['updateFail'],
        self::PUBLISH_STATUS['inEndQueue'],
        self::PUBLISH_STATUS['ending'],
        self::PUBLISH_STATUS['endFail']
    ];
    //只读刊登状态
    public const RO_PUBLISH_STATUS = [
        self::PUBLISH_STATUS['publishing'],
        self::PUBLISH_STATUS['publishSuccess'],
        self::PUBLISH_STATUS['inUpdateQueue'],
        self::PUBLISH_STATUS['updating'],
        self::PUBLISH_STATUS['updateFail'],
        self::PUBLISH_STATUS['inEndQueue'],
        self::PUBLISH_STATUS['ending'],
        self::PUBLISH_STATUS['endFail']
    ];
    //不在线状态
    public const OFL_PUBLISH_STATUS = [
        self::PUBLISH_STATUS['noStatus'],
        self::PUBLISH_STATUS['inPublishQueue'],
        self::PUBLISH_STATUS['publishFail'],
        self::PUBLISH_STATUS['ended'],
        self::PUBLISH_STATUS['relistQueue'],
        self::PUBLISH_STATUS['relistFail'],

    ];
    //刊登状态文本
    public const PUBLISH_STATUS_TXT = [
        '未刊登',
        '刊登队列中',
        '刊登中',
        '刊登成功',
        '刊登失败',
        '更新队列中',
        '更新中',
        '更新失败',
        '下架队列中',
        '下架中',
        '下架失败',
        '已下架',
        '重上队列中',
        '重上失败',
        '已下架',
    ];
    //橱窗图片
    public const PICTURE_GALLERY = [
        'None' => 0,
        'Gallery' => 1,
        'Featured' => 2,
        'Plus' => 3
    ];
    //发送ebay api请求时，需要获取的ebay account必要字段
    public const ACCOUNT_FIELD_TOKEN = 'id,account_name,code,ru_name,dev_id,app_id,cert_id,token';
    public const ACCOUNT_FIELD_OTOKEN = 'id,account_name,code,ru_name,dev_id,app_id,cert_id,oauth_token';



    /**
     * 获取商品信息
     * goodsInfo,attrInfo,lang,goodsSku,category,imgs
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function getGoods(int $id, $siteId=0) : array
    {
        try {
            $goodsInfo = $this->getGoodsBase($id);
            if (empty($goodsInfo)) return [];
            $cateInfo = Cache::store('Category')->getCategory($goodsInfo['category_id']);
            $goodsInfo['category_name'] = $cateInfo['name'];
            $goodsInfo['category_name_tree'] = $this->getGoodsCategoryNameTree($goodsInfo['category_id'], '');
            $goodsInfo['transport_property_txt'] = (new GoodsHelp())->getProTransPropertiesTxt($goodsInfo['transport_property']);
            $data['goodsInfo'] = $goodsInfo;
            //自带的属性
            $attrCodes = $this->getGoodsAttrCodes($id);
            $codes = array_values($attrCodes);
            array_push($codes, 'package');
            $data['attrInfo'] = array_flip($codes);
            //标题与描述
            $data['lang'] = $this->getGoodsDesc($id, $siteId);
            //子产品信息
            $data['goodsSku'] = $this->getSkus($id);

            $imgs = $this->getGoodsImgs($id, $goodsInfo['channel_id']);
            foreach ($imgs as &$img) {
                $img = 'https://img.rondaful.com/'.$img['path'];
            }
            $data['imgs'] = $imgs;
            $data['goodsInfo']['tort_flag'] = GoodsTortDescription::where('goods_id',$id)->find() ? 1 : 0;
            $data['goodsInfo']['base_url'] = 'https://img.rondaful.com/';
//            foreach ($data['goodsSku'] as $k=>$sku) {
//                $i = 0;
//                $pathInfo = [];
//                foreach ($data['imgs'] as $key => $img) {
//                    if ($sku['id'] == $img['sku_id']) {
//                        $pathInfo[$i]['path'] = $img['path'];
//                        $pathInfo[$i]['base_url'] = $img['base_url'];
//                        $i++;
//                    }
//                }
//                $data['goodsSku'][$k]['path'] = $pathInfo;
//            }

            $data['channel_id'] = 1;
            //类目映射
            $data['category'] = ['id'=>$cateInfo['id'], 'code'=>$cateInfo['code'], 'name'=>$cateInfo['name']];
            return $data;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取子产品信息
     * @param int $goodsId
     * @param array $status 子产品状态过滤
     * @return array
     * @throws Exception
     */
    public function getSkus(int $goodsId, array $status=[]) : array
    {
        try {
            $skuInfo = [];
            $i = 0;
            $map['goods_id'] = $goodsId;
            !empty($status) && $map['status'] = ['in', $status];
            $skus = (new GoodsSku())->field(true)->where($map)->order('sku')->select();
            $attrIds = [];//属性id
            $attrValIds = [];//属性值id

            foreach ($skus as $sku) {
                $skuAttrVals = json_decode($sku['sku_attributes'], true);
                $skuAttrN = [];
                foreach ($skuAttrVals as $attr => $val) {
                    $attrId = intval(substr($attr,5));//获取属性id
                    $attrCode = Attribute::where(['id'=>$attrId])->value('name');
                    if ($attrId == 11 || $attrId == 15) {//使用别名
                        $wh = [
                            'goods_id' => $goodsId,
                            'attribute_id' => $attrId,
                            'value_id' => $val
                        ];
                        $skuAttrN[$attrCode] = GoodsAttribute::where($wh)->value('alias');
                    } else {
                        $skuAttrN[$attrCode] = AttributeValue::where(['id'=>$val])->value('value');
                    }
                }
                $skuInfo[$i]['id'] = $sku['id'];
                $skuInfo[$i]['sku'] = $sku['sku'];
                $skuInfo[$i]['thumb'] = $sku['thumb'];
                $skuInfo[$i]['status'] = $sku['status'];
                $skuInfo[$i]['img_is_exists'] = $sku['img_is_exists'];
                $skuInfo[$i]['cost_price'] = $sku['cost_price'];
                $skuInfo[$i]['retail_price'] = $sku['retail_price'];
                $skuInfo[$i]['sku_attributes_n'] = $skuAttrN;
                $skuInfo[$i]['map_sku'] = ['goods_id'=>$goodsId,'sku_id'=>$sku['id'],'sku'=>$sku['sku'].'*1'];
                $skuInfo[$i]['path'] = 'https://img.rondaful.com/'.$sku['thumb'];
                $i++;
            }
            return $skuInfo;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取产品图片
     * @param int $goodsId
     * @param int $goodsChannelId 产品开发平台id
     * @param int $channelId 产品销售平台id
     * @return array
     * @throws Exception
     */
    public function getGoodsImgs(int $goodsId, int $goodsChannelId, int $channelId=1) : array
    {
        try {
            $map['goods_id'] = $goodsId;
            $map['channel_id'] = ['in',[0,$channelId]];
            $field = 'id,goods_id,sku_id,path,sort,is_default,unique_code';
            //先查通用图片+对应平台图片
            $imgs = (new GoodsGallery())->field($field)->where($map)->order('is_default desc, sort asc')->select();
            if (empty($imgs)) {//如果为空，查所有平台图片
//                $channels = Channel::where(['id'=>['neq', $channelId]])->column('id');
//                foreach ($channels as $channel) {
//                    $map['channel_id'] = $channel;
                unset($map['channel_id']);
                $imgs = (new GoodsGallery())->field($field)->where($map)->order('is_default desc, sort asc')->select();
//                    if (!empty($imgs)) break;
//                }
            }
            $host = $this->getImgInnerBaseUrl();
            $uniqueImgs = [];
            foreach ($imgs as $k => $img) {
                $img['is_default_txt'] = $img->is_default_txt;
                $img['base_url'] = $host;
                $uniqueImgs[$img['unique_code']] = $img->toArray();
            }
            return array_values($uniqueImgs);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 打包设置信息
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function formatDLSetToStore($data,  $draftUpdate) : array
    {
        try {
            isset($data['application_data']) && $set['application_data'] = $data['application_data'];
            isset($data['postal_code']) && $set['postal_code'] = $data['postal_code'];//邮编
            isset($data['local_pickup']) && $set['local_pickup'] = $data['local_pickup'];//自提
            isset($data['auto_accept_price']) && $set['auto_accept_price'] = $data['auto_accept_price'];//自动交易价格
            isset($data['minimum_accept_price']) && $set['minimum_accept_price'] = $data['minimum_accept_price'];//自动拒绝价格
            isset($data['bid_count']) && $set['bid_count'] = $data['bid_count'];//投标次数
            isset($data['bid_increment']) && $set['bid_increment'] = $data['bid_increment'];//投标金额
            isset($data['current_price']) && $set['current_price'] = $data['current_price'];//当前价格
//            isset($data['upc']) && $set['upc'] = $data['upc'];//upc

            $set['ean'] = 'Does not apply';
            $set['upc'] = 'Does not apply';
            $set['isbn'] = 'Does not apply';//isbn
            $set['brand'] = 'Unbranded';
            $set['mpn'] = 'Does not apply';

            $set['custom_exclude'] = isset($data['custom_exclude']) ? $data['custom_exclude'] : 2;//不运送方式，默认选择使用eBay站点设置
            if ($set['custom_exclude'] == 1) {
                $set['exclude_location'] = json_encode([]);//运送至所有国家时，清空不运送地区
            }
            $set['exclude_location'] = json_encode([]);
            if ($set['custom_exclude'] == 3 && !empty($data['exclude_location'])) {
                $excludeLocation = json_encode(explode('，', $data['exclude_location']));
                $set['exclude_location'] = $excludeLocation;//不运送地区
            }
            $set['ship_location'] = json_encode([]);
            if (!empty($data['ship_location'])) {
                $set['ship_location'] = json_encode($data['ship_location']);
            }
//            isset($data['ship_location']) && $set['ship_location'] = json_encode($data['ship_location']);//送达地区
            $set['international_shipping'] = json_encode([]);
            if (!empty($data['international_shipping'])) {
                $set['international_shipping'] = json_encode($data['international_shipping']);//国际运输方式
            }
            $set['shipping'] = json_encode([]);
            if (!empty($data['shipping'])) {
                $set['shipping'] = json_encode($data['shipping']);//国内运输方式
            }
            if (!empty($data['payment_method'])) {
                $set['payment_method'] = json_encode(['PayPal']);
            }
//            isset($data['international_shipping']) && $set['international_shipping'] = json_encode($data['international_shipping']);//国际运输方式
//            isset($data['shipping']) && $set['shipping'] = json_encode($data['shipping']);//国内运输方式
//            isset($data['payment_method']) && $set['payment_method'] = json_encode($data['payment_method']);//支付方式
            isset($data['payment_instructions']) && $set['payment_instructions'] = $data['payment_instructions'];//支付说明

            //退货
            isset($data['return_policy']) && $set['return_policy'] = $data['return_policy'];//是否支持退货
            isset($data['return_type']) && $set['return_type'] = $data['return_type'];//退货方式
            isset($data['extended_holiday']) && $set['extended_holiday'] = $data['extended_holiday'];//是否节假日延期
            isset($data['return_shipping_option']) && $set['return_shipping_option'] = empty($data['return_shipping_option']) ? 1 : $data['return_shipping_option'];//运费承担方
            isset($data['restocking_fee_code']) && $set['restocking_fee_code'] = $data['restocking_fee_code'];//折旧费
            isset($data['return_description']) && $set['return_description'] = $data['return_description'];//退货说明
            $set['buyer_requirment_details'] = json_encode([]);
            if (!empty($data['buyer_requirment_details'])) {
                $set['buyer_requirment_details'] = json_encode($data['buyer_requirment_details']);//买家限制
            }
//            isset($data['buyer_requirment_details']) && $set['buyer_requirment_details'] = json_encode($data['buyer_requirment_details']);//买家限制
            $set['variation_image'] = empty($data['variation_image'])?'':trim($data['variation_image']);//图片关联
            isset($data['restart_rule']) && $set['restart_rule'] = $data['restart_rule'];//重上规则 1,只要物品结束 2,所有物品卖出 3,没有物品卖出 4,没有物品卖出后仅刊登一次 5,当物品卖出数量大于或等于
            isset($data['restart_count']) && $set['restart_count'] = $data['restart_count'];//售出数量，达到此数量重上
            isset($data['restart_way']) && $set['restart_way'] = $data['restart_way'];//重上方式 1立即执行 2定时执行
            isset($data['restart_time']) && $set['restart_time'] = strtotime($data['restart_time']);//重上时间
            isset($data['restart_number']) && $set['restart_number'] = $data['restart_number'];//累计重上次数
            isset($data['restart_invalid_time']) && $set['restart_invalid_time'] = strtotime($data['restart_invalid_time']);//有效期
            isset($data['bulk']) && $set['bulk'] = $data['bulk'];//单次重上0否1是
            isset($data['bulk_time']) && $set['bulk_time'] = strtotime($data['bulk_time']);//单次重上时间
            isset($data['internal']) && $set['internal'] = $data['internal'];//是否开启国内运输
            isset($data['publish_style']) && $set['publish_style'] = $data['publish_style'];//刊登风格
            isset($data['sale_note']) && $set['sale_note'] = $data['sale_note'];//销售说明
            isset($data['shipping_type']) && $set['shipping_type'] = $data['shipping_type'];//物流类型
            $set['specifics'] = json_encode([]);
            if (!empty($data['specifics'])) {
                $set['specifics'] = json_encode($data['specifics']);//类目属性
            }
//            isset($data['specifics']) && $set['specifics'] = json_encode($data['specifics']);//类目属性
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
            $set['compatibility'] = json_encode([]);
            if (is_array($data['compatibility'])) {
                foreach ($compatibility as $k => $comp) {
                    $NameValueList = [];
                    $i = 0;
                    foreach ($comp as $key => $value) {
                        $NameValueList[$i]['name'] = $key;
                        $NameValueList[$i]['value'] = $value;
                        $i++;
                    }
                    $newComp[$k]['name_value_list'] = $NameValueList;
                    $newComp[$k]['compatibility_notes'] = $comp['notes'];
                }
                $set['compatibility'] = json_encode($newComp);
            } else {
                $set['compatibility'] = $data['compatibility'];
            }
            return $set;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 打包多属性
     * @param array $data
     * @param array $idInfo
     * @return array
     * @throws Exception
     */
    public function formatDLVarToStore(array &$data, $isUpdate, array $idInfo) : array
    {
        $oldIds = [];
        if ($isUpdate) {//更新
            $oldVars = EbayListingVariation::where(['listing_id'=>$idInfo['oldListId']])->field('id,unique_code,variation')
                ->select();
            $assocIds = [];
            if ($oldVars) {
                foreach ($oldVars as $oldVar) {
                    $oldIds[] = $oldVar['id'];
                    $tmpVariation = json_decode($oldVar['variation'],true);
                    ksort($tmpVariation);
                    $md5 = md5(json_encode($tmpVariation));
                    $assocIds[$md5] = $oldVar['id'];
                }
            }
        }
        $updateVariants = [];//记录更新的变体信息
        $updateIds = [];//记录更新的变体id,用于与旧变体id比较得出需要删除的变体id
        $uniqueCodes = [];//记录已使用的唯一性标志，避免出现两条属性完全相同的变体
        $skuImgs = [];//记录变体图片，格式['value1'=>[0,1,2],'value2'=>[0,1,2]],这里的value指的是绑定的图片属性的值
        $maxPrice = 0;
        $minPrice = 0;
        foreach ($data as $v) {
            if (!$isUpdate && isset($v['id'])) {
                unset($v['id']);
            }
            $varian = [];
            if (!is_array($v['variation'])) {
                $v['variation'] = json_decode($v['variation'],true)??[];
            }
            if (is_array($v['variation'])) {
                $tmpV = [];
                foreach ($v['variation'] as $vn => $vv) {
                    $tmpV[trim($vn)] = $vv;
                }
                $v['variation'] = $tmpV;
                ksort($v['variation']);//必须进行排序,否则对比的时候会因为顺序问题出现错误
            }

            $varian['variation'] = is_array($v['variation']) ? json_encode($v['variation']) : $v['variation'];
            if (!$varian['variation']) {
                throw new Exception('多属性刊登，必须至少选择一条启用的多属性');
            }
            $varian['unique_code'] = md5($varian['variation']);//属性确定唯一性,用于同步线上数据时进行合并
            if (in_array($varian['unique_code'],$uniqueCodes)) {
                throw new Exception('存在两条属性完全相同的变体，无法保存，请检查');
            } else {
                $uniqueCodes[] = $varian['unique_code'];
            }
            $varian['v_sku'] = $v['v_sku'];
            $mapSku = is_array($v['map_sku']) ? $v['map_sku'] : json_decode($v['map_sku'], true);
            if (isset($mapSku[0])) {
                $varian['goods_id'] = $mapSku[0]['goods_id'];
                $varian['sku_id'] = $mapSku[0]['sku_id'];
                if ($varian['sku_id'] != 0) {
                    $status = GoodsSku::where(['id' => $varian['sku_id']])->value('status');
                    $status && $varian['sku_status'] = $status;
                }
            }
            $varian['v_price'] = $v['v_price'];
            $maxPrice = empty($maxPrice) ? $v['v_price'] : max($maxPrice,$v['v_price']);
            $minPrice = empty($minPrice) ? $v['v_price'] : min($minPrice,$v['v_price']);
            $varian['v_qty'] = $v['v_qty'];
            $varian['cost_price'] = isset($v['cost_price']) ? $v['cost_price'] : 0.00;
            $varian['v_pre_qty'] = $v['v_qty'];
            $varian['map_sku'] = json_encode($mapSku);
            $varian['upc'] = 'Does not apply'; //$v['upc'];
            $varian['ean'] = 'Does not apply'; //$v['ean'];
            $varian['isbn'] = 'Does not apply'; //$v['isbn'];
            $varian['combine_sku'] = $v['combine_sku'];
            /***********************************************************************************************************
             * 图片处理
             */
            if (!is_array($v['path'])) {
                $path = json_decode($v['path'],true);
            } else {
                $path = $v['path'];
            }
            $tmpImgs = [];
            foreach ($path as $p) {
                if (empty($p)) {
                    continue;
                }
                if (is_array($p)) {
                    if (isset($p['path'])) {
                        $tmpImgs[] = $p['path'];
                    }
                } else {
                    $tmpImgs[] = $p;
                }
            }
            $varian['path'] = json_encode($tmpImgs);//这里存储的是全路径
            $varian['thumb'] = $varian['path'];//这里存储的是全路径
            /**********************************************************************************************************/


            $varian['upc'] = $v['upc'];
            $varian['ean'] = $v['ean'];
            $varian['isbn'] = $v['isbn'];
            $varian['combine_sku'] = $v['combine_sku'];
            !empty($idInfo['oldListId']) && $varian['listing_id'] = $idInfo['oldListId'];

            $varian['channel_map_code'] = $v['channel_map_code']??'';
            if (empty($v['channel_map_code']) || $isUpdate==0 || empty($v['id'])) {//平台SKU未设置或新建时重新生成listing_sku
                $tmpSku = (new GoodsSkuMapService())->createSku($v['v_sku'],'|',mb_strlen($v['v_sku'])+11);
                $varian['channel_map_code'] = $idInfo['assoc_order'] ? $tmpSku : 'ebay'.$tmpSku;
            } else if (!$idInfo['assoc_order'] && strpos($v['channel_map_code'],'ebay')===false) {
                $varian['channel_map_code'] = 'ebay'.$v['channel_map_code'];
            }


            if (!empty($v['id'])) {
                $varian['id'] = $v['id'];
                $updateIds[] = $v['id'];
            } elseif (isset($assocIds[$varian['unique_code']])) {
                $varian['id'] = $assocIds[$varian['unique_code']];
                $updateIds[] = $varian['id'];
            }
            $updateVariants[] = $varian;//加入更新

            //图片
            $variation = json_decode($varian['variation'],true);
            if (!isset($variation[$idInfo['variationImg']])) {
                throw new Exception('关联的图片属性在变体属性中必须存在');
            }
            $value = $variation[$idInfo['variationImg']];
            self::optionImgHost($tmpImgs,'del');
            $skuImgs[] = [
                'channel_map_code' => $varian['channel_map_code'],
                'path' => $tmpImgs,
                'value' => $value,
            ];
        }
        //图片去重

        //查询是否需要删除变体
        $delIds = [];
//        if (!empty($updateIds)) {
            $delIds = array_values(array_diff($oldIds,$updateIds));
//        }
        return ['update'=>$updateVariants, 'del'=>$delIds,
            'skuImgs'=>$skuImgs,'maxPrice'=>$maxPrice,'minPrice'=>$minPrice];
    }

    /**
     * 维护商品映射表
     * @param array $data
     * @param int $userId
     * @param bool $isClear
     * @throws Exception
     */
    public function maintainTableGoodsSkuMap($data, $accountId,int $userId, bool $isClear = false)
    {
        try {
            $params['sku_code'] = $data['local_sku'] ?? $data['v_sku'];//本地SKU
            $params['channel_id'] = 1;
            $params['account_id'] = $accountId;
            $params['combine_sku'] = $data['combine_sku'] ?? $data['sku'];//捆绑信息
            $params['channel_sku'] = $data['channel_map_code'] ?? $data['listing_sku'];//平台SKU
            $params['is_virtual_send'] = $data['is_virtual_send'];//是否虚拟仓发货

            $map['account_id'] = $accountId;
            $map['channel_sku'] = $params['channel_sku'];
            $map['channel_id'] = 1;
            if ($isClear) {
                GoodsSkuMap::destroy($map);//删除
            } else {
                $row = GoodsSkuMap::get($map);
                //捆绑多个的时候使用的逗号分割，形式如下：sku1*1,sku2*5,sku3*2
                $combineSkus = explode(',', $params['combine_sku']);
                $skuCodeQty = [];
                foreach ($combineSkus as $k =>$combineSku) {
                    $skuQty = explode('*', $combineSku);
                    $skuInfo = GoodsSku::get(['sku'=>$skuQty[0]]);
                    if (empty($skuInfo)) {
                        return;
//                        throw new Exception('根据捆绑sku: '.$skuQty[0].',获取商品信息失败');
                    }
                    if ($k == 0) {
                        $goodsId = $skuInfo->goods_id;
                        $skuId = $skuInfo->id;
                    }
                    $skuCodeQty[$skuInfo->id] = [
                        'sku_id' => $skuInfo->id,
                        'sku_code' => $skuInfo->sku,
                        'quantity' => $skuQty[1]
                    ];
                }

                $update['sku_code'] = $params['sku_code'];
                $update['is_virtual_send'] = $params['is_virtual_send'];
                $update['sku_code_quantity'] = json_encode($skuCodeQty);
                $update['updater_id'] = $userId;
                $update['update_time'] = time();

                if (!empty($row)) {//已存在，更新
                    GoodsSkuMap::update($update, $map);
                } else {//不存在，新增
                    $update['goods_id'] = $goodsId;
                    $update['sku_id'] = $skuId;
                    $update['channel_id'] = 1;
                    $update['account_id'] = $accountId;
                    $update['channel_sku'] = $params['channel_sku'];
                    $update['is_virtual_send'] = $params['is_virtual_send'];
                    $update['quantity'] = 1;
                    $update['creator_id'] = $userId;
                    $update['create_time'] = time();
                    GoodsSkuMap::create($update);
                }
            }

        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取listing的图片，去除重复的
     * @param int $listingId
     * @param array $imgs ['imgs','detail_imgs','sku_imgs']
     * @return array
     * @throws Exception
     */
    public function getListingUniqueImgs(int $listingId, array $imgs=[]) : array
    {
        try {
            if (!empty($listingId)) {
                $uniqueImgs = (new EbayListingImage())->distinct(true)->field(true)
                    ->where(['listing_id'=>$listingId])->select();
                return json_decode(json_encode($uniqueImgs), true);
            }
            $paths = [];
            $i = 0;
            $mergedImgs = array_merge([], $imgs['imgs']);//主图
            foreach ($imgs['imgs'] as $mainImg) {
                $paths[$i++] = $mainImg['path'];
            }
            foreach ($imgs['detail_imgs'] as $detailImg) {
                if (!in_array($detailImg['path'], $paths)) {
                    $mergedImgs[$i++] = $detailImg;
                }
            }
            foreach ($imgs['sku_imgs'] as $sku_img) {
                if (!in_array($sku_img['path'], $paths)) {
                    $mergedImgs[$i++] = $sku_img;
                }
            }
            return $mergedImgs;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     *获取listing信息
     * @param $id
     * @return mixed
     * @throws Exception
     */
    public function getListInfo($id)
    {
        try {
            $list = EbayListing::get($id);
            if (empty($list)) {
                throw new Exception('获取listing信息失败');
            }
            $listInfo['list'] = $list->toArray();
            $listInfo['set'] = EbayListingSetting::get($id)->toArray();
            $imgs = (new EbayListingImage())->field(true)->where(['listing_id'=>$id, 'main'=>1])
                ->order('sort')->select();
            $listInfo['imgs'] = json_decode(json_encode($imgs), true);
            $detail_imgs = (new EbayListingImage())->field(true)->where(['listing_id'=>$id, 'detail'=>1])
                ->order('de_sort')->select();
            $listInfo['detail_imgs'] = json_decode(json_encode($detail_imgs), true);
            if ($list['variation']) {
                $varians = (new EbayListingVariation())->field(true)->where(['listing_id'=>$id])->select();
                $skuImgs = (new EbayListingImage())->field(true)->where(['listing_id'=>$id, 'main_de'=>1])
                    ->order('sort')->select();
                $listInfo['imgAttrValue'] = EbayListingImage::distinct(true)->where(['listing_id'=>$id, 'main_de'=>1])
                    ->column('value');
                $listInfo['sku_imgs'] = json_decode(json_encode($skuImgs), true);
                $listInfo['varians'] = json_decode(json_encode($varians), true);
            }

            return $listInfo;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 写入更新日志
     * @param $data
     * @return int|string
     * @throws Exception
     */
    public function writeUpdateLog($data)
    {
        try {
            $data['create_time'] = time();
            $data['new_val'] = json_encode($data['new_val']);
            $data['old_val'] = json_encode($data['old_val']);
            return (new EbayActionLog())->insertGetId($data);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 压入队列
     * @param $class
     * @param $data
     * @param int $time
     * @throws Exception
     */
    public function pushQueue($class, $data, $time=0)
    {
        try {
            $queue = new UniqueQueuer($class);
            $queue->push($data, $time);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 自动匹配PayPal账号大小账户
     * @param $accountId
     * @param $site
     * @param $price
     * @param $userId
     * @return mixed
     * @throws Exception
     */
    public function autoAdaptPaypal($accountId, $site, $price, $userId)
    {
        try {
            $field = 'min_paypal_id,max_paypal_id,currency';
            $accountInfo = (new EbayAccount())->field($field)->where(['id'=>$accountId])->find();
            if (empty($accountInfo)) {
                return '';
            }
            $wh['channel_id'] = 1;
            $wh['account_id'] = $accountId;
            $wh['seller_id'] = $userId;
            $wareHouseType = ChannelUserAccountMap::where($wh)->value('warehouse_type');//仓库类型
            if (empty($wareHouseType)) {//海外仓本地仓通用或为空
                $wareHouseType = 1;
                //获取seller角色,进一步判断
//                $roleId = (new RoleUser())->where(['user_id'=>$userId])->value('role_id');
//                $localWareHouseRoleIds = [1,41,48,49,66,67,75,83,110,112,143];
//                if (in_array($roleId, $localWareHouseRoleIds)) {
//                    $wareHouseType = 1;//本地仓
//                } else {
//                    $wareHouseType = 2;//海外仓
//                }
            }

            $siteCurrency = EbaySite::where(['siteid'=>$site])->value('currency');//站点货币
            //获取大小额账户及限额设置
            $minPaypals = json_decode($accountInfo['min_paypal_id'], true)??[];
            $maxPaypals = json_decode($accountInfo['max_paypal_id'], true)??[];
            $currency = json_decode($accountInfo['currency'], true)??[];

            $wareHouseMinPaypalId = '';//对应仓库下的小额账户id
            $wareHouseMaxPaypalId = '';//对应仓库下的大额账户id
            foreach ($minPaypals as $minPaypal) {
                if ($minPaypal['type'] == $wareHouseType) {
                    $wareHouseMinPaypalId = $minPaypal['id'];
                    break;
                }
            }
            foreach ($maxPaypals as $maxPaypal) {
                if ($maxPaypal['type'] == $wareHouseType) {
                    $wareHouseMaxPaypalId = $maxPaypal['id'];
                    break;
                }
            }
            $paypalId = empty($wareHouseMinPaypalId) ? $wareHouseMaxPaypalId : $wareHouseMinPaypalId;//默认小额
            if (!empty($currency)) {//有设置
                if (isset($currency[$siteCurrency]) && bccomp($price,$currency[$siteCurrency]) >= 0) {
                    !empty($wareHouseMaxPaypalId) && $paypalId = $wareHouseMaxPaypalId;
                }
            }
            $paypalEmail = PaypalAccount::where(['id'=>$paypalId])->value('account_name');
            return $paypalEmail;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 应用公共模板
     * @param $templates
     * @param $json bool 一些子元素是数组的是否返回json格式
     * @return array
     * @throws Exception
     */
    public function applyCommonTemplate($templates, $json=true)
    {
        try {
            $list = [];
            $set = [];
            if (!empty($templates['mod_style'])) {
                $list['mod_style'] = $templates['mod_style'];//风格
            }
            if (!empty($templates['mod_sale'])) {
                $list['mod_sale'] = $templates['mod_sale'];//销售说明
            }
            if (!empty($templates['mod_trans'])) {//物流信息
                $trans = $this->transTPL($templates['mod_trans']);
                $set['international_shipping'] = $json ? json_encode($trans['internationalShipping']) : $trans['internationalShipping'];
                $set['shipping'] = $json ? json_encode($trans['shipping']) : $trans['shipping'];
            }
            if (!empty($templates['mod_exclude'])) {//不运送地区
                $exclude = EbayCommonExclude::where(['id'=>$templates['mod_exclude']])->value('exclude');
//                $exclude = json_decode($exclude, true);
                $set['custom_exclude'] = 3;
                $set['exclude_location'] = $json ? $exclude : json_decode($exclude, true);
            }
            if (!empty($templates['mod_location'])) {//商品所在地
                $location = $this->locationTPL($templates['mod_location']);
                $list['location'] = $location['location'];
                $list['country'] = $location['country'];
                $set['postal_code'] = $location['postal_code'];
            }
            if (!empty($templates['mod_return'])) {//退货策略
                $return = $this->returnTPL($templates['mod_return']);
                $set['return_policy'] = $return['return_policy'];
                $set['return_type'] = $return['return_type'];
                $list['return_time'] = $return['return_time'];
                $set['extended_holiday'] = $return['extended_holiday'];
                $set['return_shipping_option'] = $return['return_shipping_option'];
                $set['return_description'] = $return['return_description'];
                $set['restocking_fee_code'] = $return['restocking_fee_code'];
            }
            if (!empty($templates['mod_refuse'])) {//买家限制
                $refuse = $this->buyerRequirementTPL($templates['mod_refuse']);
                $list['disable_buyer'] = $refuse['disable_buyer'];
                $set['buyer_requirment_details'] = $json ? json_encode($refuse['buyer_requirment_details']) : $refuse['buyer_requirment_details'];
            }
            if (!empty($templates['mod_receivables'])) {//收款设置
                $receivales = $this->receivablesTPL($templates['mod_receivables']);
                $list['autopay'] = $receivales['autopay'];
                $set['payment_method'] = $json ? $receivales['payment_method'] : json_decode($receivales['payment_method'],true);
                $set['payment_instructions'] = $receivales['payment_instructions'];
            }
            if (!empty($templates['mod_choice'])) {//备货周期
                $list['dispatch_max_time'] = EbayCommonChoice::where(['id'=>$templates['mod_choice']])->value('choice_date');
            }
            if (!empty($templates['mod_pickup'])) {//自提
                $set['local_pickup'] = EbayCommonPickup::where(['id'=>$templates['mod_pickup']])->value('local_pickup');
            }
            if (!empty($templates['mod_galley'])) {//橱窗
                $list['picture_gallery'] = EbayCommonGallery::where(['id'=>$templates['mod_galley']])->value('picture_gallery');
            }
            if (!empty($templates['mod_individual'])) {//私人物品
                $list['private_listing'] = EbayCommonIndividual::where(['id'=>$templates['mod_individual']])->value('individual_listing');
            }
            if (!empty($templates['mod_bargaining'])) {//议价
                $bargaining = $this->bargainingTPL($templates['mod_bargaining']);
                $list['best_offer'] = $bargaining['best_offer'];
                $set['auto_accept_price'] = $bargaining['auto_accept_price'];
                $set['minimum_accept_price'] = $bargaining['minimum_accept_price'];
            }
            if (!empty($templates['mod_quantity'])) {//库存
                $list['quantity'] = EbayCommonQuantity::where(['id'=>$templates['mod_quantity']])->value('quantity');
            }
            return ['list'=>$list, 'set'=>$set];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取物流模板信息
     * @param $id
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function transTPL($id, $name='')
    {
        try {
            if (!empty($id)) {
                $map = $id;
            } else {
                $map['model_name'] = $name;
            }
            $trans = EbayCommonTrans::get($map);
            if (empty($trans)) {
                throw new Exception('根据物流模板获取物流信息失败');
            }
            $details = (new EbayCommonTransDetail())->field(true)->where(['trans_id' => $id])->select();
            if (empty($details)) {
                throw new Exception('根据物流模板获取物流详情失败');
            }
            $internationalShipping = [];
            $shipping = [];
            $i = 0;
            $j = 0;
            foreach ($details as $detail) {
                if ($detail['inter'] == 1) {
                    $internationalShipping[$i]['shipping_service'] = $detail['trans_code'];
                    $internationalShipping[$i]['shipping_service_cost'] = $detail['cost'];
                    $internationalShipping[$i]['shipping_service_additional_cost'] = $detail['add_cost'];
                    $internationalShipping[$i]['shiptolocation'] = ($shipToLocation=json_decode($detail['location'],true)) ? $shipToLocation : 'Worldwide';
                    $i++;
                } else {
                    $shipping[$j]['shipping_service'] = $detail['trans_code'];
                    $shipping[$j]['shipping_service_cost'] = $detail['cost'];
                    $shipping[$j]['shipping_service_additional_cost'] = $detail['add_cost'];
                    $shipping[$j]['extra_cost'] = $detail['extra_cost'];
                    $j++;
                }
            }
            return ['internationalShipping' => $internationalShipping, 'shipping' => $shipping];
        } catch (Exception $e) {
            throw new Exception($e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage());
        }
    }

    /**
     * 获取商品所在地模板信息
     * @param $id
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function locationTPL($id, $name='')
    {
        try {
            if (empty($id)) {
                $map['model_name'] = $name;
            } else {
                $map = $id;
            }
            $location = EbayCommonLocation::get($map);
            if (empty($location)) {
                throw new Exception('根据模板获取商品所在地信息失败');
            }
            $details['location'] = $location->location;
            $details['country'] = $location->country;
            $details['postal_code'] = $location->post_code;
            return $details;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取退货设置模板信息
     * @param $id
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function returnTPL($id, $name='')
    {
        try {
            if (empty($id)) {
                $map['model_name'] = $name;
            } else {
                $map = $id;
            }
            $return = EbayCommonReturn::get($map);
            if (empty($return)) {
                throw new Exception('根据模板获取退货设置信息失败');
            }
            $details['return_policy'] = $return->return_policy;
            $details['return_type'] = $return->return_type;
            $details['return_time'] = $return->return_time;
            $details['extended_holiday'] = $return->extension;
            $details['return_shipping_option'] = $return->return_shipping_option;
            $details['return_description'] = $return->return_description;
            $details['restocking_fee_code'] = $return->restocking_fee_code;
            return $details;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取买家限制模板信息
     * @param $id
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function buyerRequirementTPL($id, $name='')
    {
        try {
            if (empty($id)) {
                $map['model_name'] = $name;
            } else {
                $map = $id;
            }
            $refuse = EbayCommonRefuseBuyer::get($map);
            if (empty($refuse)) {
                throw new Exception('根据模板获取买家限制设置失败');
            }
            $details['disable_buyer'] = $refuse->refuse;
            $detail['credit'] = $refuse->credit;
            $detail['strikes'] = $refuse->strikes;
            $detail['violations'] = $refuse->violations;
            $detail['link_paypal'] = $refuse->link_paypal;
            $detail['registration'] = $refuse->registration;
            $detail['requirements'] = $refuse->requirements;
            $detail['strikes_count'] = $refuse->strikes_count;
            $detail['strikes_period'] = $refuse->strikes_period;
            $detail['minimum_feedback'] = $refuse->minimum_feedback;
            $detail['violations_count'] = $refuse->violations_count;
            $detail['violations_period'] = $refuse->violations_period;
            $detail['minimum_feedback_score'] = $refuse->minimum_feedback_score;
            $detail['requirements_max_count'] = $refuse->requirements_max_count;
            $detail['requirements_feedback_score'] = $refuse->requirements_feedback_score;
            $details['buyer_requirment_details'] = $detail;
            return $details;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取收款模板信息
     * @param $id
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function receivablesTPL($id, $name='')
    {
        try {
            if (empty($id)) {
                $map['model_name'] = $name;
            } else {
                $map = $id;
            }
            $receivables = EbayCommonReceivables::get($map);
            if (empty($receivables)) {
                throw new Exception('根据模板获取收款信息失败');
            }
            $details['payment_method'] = $receivables->pay_method;
            $details['autopay'] = $receivables->auto_pay;
            $details['payment_instructions'] = $receivables->payment_instructions;
            return $details;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取议价模板信息
     * @param $id
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public function bargainingTPL($id, $name='')
    {
        try {
            if (empty($id)) {
                $map['model_name'] = $name;
            } else {
                $map = $id;
            }
            $bargaining = EbayCommonBargaining::get($map);
            if (empty($bargaining)) {
                throw new Exception('根据模板获取议价信息失败');
            }
            $details['best_offer'] = $bargaining['best_offer'];
            $details['auto_accept_price'] = $bargaining['accept_lowest_price'];
            $details['minimum_accept_price'] = $bargaining['reject_lowest_price'];
            return $details;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 自动补货
     * @param $itemId string 单条item_id，如果有多个,需多次调用此方法
     * @param $skuQty array 平台SKU:数量 对，例:['FC0041501|3775276231'=>7, 'FC0041502|1368924035'=>10]
     * @throws Exception
     */
    public function autoReplenish($itemId, $skuQty)
    {
        try {
            $list = EbayListing::get(['item_id'=>$itemId]);
            if (empty($list)) return;
            $list = $list->toArray();
            if ($list['replen'] == 0) return;
            $updateData['item_id'] = $list['item_id'];
            $updateData['listing_sku'] = 0;
            $updateData['account_id'] = $list['account_id'];
            $updateData['site'] = $list['site'];
            $updateData['cron_time'] = 0;
            $updateData['remark'] = '自动补货';
            $updateData['api_type'] = 1;
            $updateData['create_id'] = 0;

            if ($list['variation'] == 0) {
                $quantity[$list['listing_sku']] = $list['preset_quantity'];
            } else {
                $variants = EbayListingVariation::field('channel_map_code,v_pre_qty')->where(['listing_id'=>$list['id']])->select();
                foreach ($variants as $variant) {
                    $quantity[$variant['channel_map_code']] = $variant['v_pre_qty'];
                }
            }

            foreach ($skuQty as $sku => $qty) {
                $updateData['new_val'][] = [
                    'listing_sku' => $sku,
                    'quantity' => (empty($quantity[$sku]) || intval($quantity[$sku])==0) ? 3 : intval($quantity[$sku]),
                ];
            }
            $updateData['old_val'] = [];
            $logId = $this->writeUpdateLog($updateData);
            $this->pushQueue(EbayUpdateOnlineListing::class, $logId);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 自动重上
     * @param $itemId
     * @param $type
     * @throws Exception
     */
    public function autoRelist($itemId, $type)
    {
        try {
            $listField = 'id,variation,restart,application,quantity,listing_duration,preset_quantity';
            $listInfo = (new EbayListing())->field($listField)->where(['item_id'=>$itemId])->find();
            if (empty($listInfo) || $listInfo['application'] == 0
                || $listInfo['listing_duration'] == 1 || $listInfo['restart'] == 0) return;
            $setField = 'id,restart_rule,restart_count,restart_way,restart_time,restart_number,restart_invalid_time';
            $setInfo = (new EbayListingSetting())->field($setField)->where(['id'=>$listInfo['id']])->find();
            $queue = (new UniqueQueuer(EbayPublishItemQueuer::class));

            $siteZone = EbaySite::where(['siteid'=>$listInfo['site']])->value('time_zone');//获取站点时区

            //判断是否过期
            if ($setInfo['restart_invalid_time'] < time()+$siteZone) return;

            switch ($setInfo['restart_rule']) {
                case 1://只要物品结束
                    break;
                case 2://所有物品卖出
                    if ($listInfo['variation']) {//多属性
                        $varInfo = EbayListingVariation::get(['listing_id'=>$listInfo['id'], 'v_qty'=>['neq', 0]]);
                        if (!empty($varInfo)) return;//还有商品未卖出
                    } else if ($listInfo['quantity'] != 0) {
                        return;//没有卖完
                    }
                    break;
                case 3://没有物品卖出
                    if ($type != 'ItemUnsold') return;
                    break;
                case 4://没有物品卖出后仅刊登一次
                    if ($type != 'ItemUnsold' || $setInfo['restart_number'] !=0) return;
                    break;
                case 5://当物品卖出数量大于或等于n
                    if ($listInfo['variation']) {//多属性
                        $totalQty = (new EbayListingVariation())->where(['listing_id'=>$listInfo['id']])->sum('v_qty');
                        $totalPreQty = (new EbayListingVariation())->where(['listing_id'=>$listInfo['id']])->sum('v_pre_qty');
                        if ($totalPreQty - $totalQty < $setInfo['restart_count']) {
                            return;
                        }
                    } else if ($listInfo['preset_quantity'] - $listInfo['quantity'] < $setInfo['restart_count']) {//单属性
                        return;
                    }
                    break;
            }
            //先将可售量重设为预设库存
            if ($listInfo['variation']) {//多属性
                EbayListingVariation::update(['v_qty', ['exp', '=v_pre_qty']], ['listing_id'=>$listInfo['id']]);
            } else {//单属性
                EbayListing::update(['quantity', ['exp', '=preset_quantity']], ['id'=>$listInfo['id']]);
            }
            //重上时间
            if ($setInfo['restart_way'] == 1) {//立即重上
                $queue->push($listInfo['id']);
            } else if ($setInfo['restart_way'] == 2) {//定时重上
                $timeStr = date('H:i:s', $setInfo['restart_time']);//设置的重上时间
                $dateStr = date('Y-m-d');//日期
                $siteDateTime = strtotime($dateStr.' '.$timeStr);//站点日期时间
                $localDateTime = $siteDateTime + $siteZone;//本地日期时间
                if ($localDateTime<time()) {//如果小于当前时间
                    $localDateTime += 86400;//推迟一天
                }
                $queue->push($listInfo['id'], $localDateTime);
            }
            //更新重上累计次数
            $setInfo['restart_number'] += 1;
            $setInfo->save();
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取epid
     * @param $categoryId
     * @param $site
     * @param $oauthToken
     * @return int
     * @throws Exception
     */
    public function searchEbayCatalogProduct($categoryId, $site, $oauthToken)
    {
        try {
            $header['X-EBAY-C-MARKETPLACE-ID'] = self::MarketPlaceId[$site];
            $header['Authorization'] = 'Bearer '.$oauthToken;
            $url = 'https://api.ebay.com/commerce/catalog/v1_beta/product_summary/search?category_ids='.$categoryId.'&limit=3';
            $response = (new EbayRestful('GET', $header))->sendRequest($url);
            $res = json_decode($response, true);
            return isset($res['productSummaries'][0]['epid']) ? $res['productSummaries'][0]['epid'] : 0;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 标题库标题关键字id转名称
     * @param $title
     * @return void
     */
    public function titleKeyIdToName(&$title,$lang='')
    {
        $keyIds = [];
        if (empty($title)) {
            return;
        }
        foreach ($title as $k => $t) {
            if (!empty($lang) && $k != $lang) {
                continue;
            }
            $tmpKeyIds = array_merge($t['front']??[],$t['middle']??[],$t['back']??[]);
            $keyIds = array_merge($tmpKeyIds,$keyIds);
            if (!empty($lang)) {
                break;
            }
        }
        $keys = TitleKey::whereIn('id',$keyIds)->column('name','id');

        foreach ($title as $k => &$t) {
            if (!empty($lang) && $k != $lang) {
                continue;
            }
            foreach ($t as &$keyIds) {
                $keyIds = array_map(function($a) use ($keys) {
                    return $keys[$a];
                },$keyIds);
            }
            if (!empty($lang)) {
                break;
            }
        }
    }




    /******************************************************************************************************************
     * 下面的方法偏向于只做一件事
     */

    /**
     * 获取商品基本信息
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function getGoodsBase(int $id) : array
    {
        try {
            $field = ['id', 'category_id', 'spu', 'name', 'alias', 'packing_en_name', 'thumb', 'channel_id','transport_property'];
            $goods = (new Goods())->field($field)->where(['id'=>$id])->find();
            return empty($goods) ? [] : $goods->toArray();
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 移除详细地址中的域名地址
     * @param string $fullUrl
     * @return string
     * @throws Exception
     */
    public function removeBaseUrl(string $fullUrl) : string
    {
        try {
            $url = substr($fullUrl,strpos($fullUrl,'//')+2);
            return substr($url,strpos($url,'/')+1);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取商品分类目录树
     * @param int $id
     * @param string $names
     * @return string
     * @throws Exception
     */
    public function getGoodsCategoryNameTree(int $categoryId, string $tree='') : string
    {
        try {
            $categoryInfo = Cache::store('category')->getCategory($categoryId);
            if ($categoryInfo['pid'] != 0) {
                $tree = $this->getGoodsCategoryNameTree($categoryInfo['pid'], $tree);
            }
            return  $tree = empty($tree) ? $categoryInfo['name'] : $tree.'>'.$categoryInfo['name'];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取店铺分类链
     * @param $categoryId
     * @param $accountId
     * @throws Exception
     */
    public function getStoreCategoryChain($categoryId, $accountId)
    {
        try {
            $name = '';
            $tmpId = $categoryId;
            while (1) {
                $categoryInfo = (new EbayCustomCategory())->field('category_id, name, parent_id')
                    ->where(['category_id'=>$tmpId, 'account_id'=>$accountId])->find();
                $name = $categoryInfo['name'].(empty($name) ? '' : '>>'.$name);
                if ($categoryInfo['parent_id'] == 0) break;
                $tmpId = $categoryInfo['parent_id'];
            }
            return $categoryId.'->'.$name;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 返回可索引的商品 attr-code 对
     * @param int $goodsId
     * @return array
     * @throws Exception
     */
    public function getGoodsAttrCodes(int $goodsId) : array
    {
        try {
            $attrs = GoodsAttribute::where(['goods_id'=>$goodsId])->distinct(true)
                ->order('attribute_id')->column('attribute_id');
            $codes = Attribute::where(['id'=>['in', $attrs]])->order('id')->column('name');
            return array_combine($attrs, $codes);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取产品描述
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function getGoodsDesc(int $id, $siteId) : array
    {
        try {
            $field = 'description,title,selling_point';
            $wh['goods_id'] = $id;
            $goodsLangModel = new GoodsLang();
            $langName = self::SITE_LANG[$siteId];
            $langId = Lang::where(['name'=>$langName])->value('id');
            $langIds = [$langId,2,1];//按站点语言，英文，中文排序，逐个查询直到查到
            foreach ($langIds as $langId) {
                $wh['lang_id'] = $langId;
                $lang = $goodsLangModel->field($field)->where($wh)->find();
                if (empty($lang) || empty($lang['title']) || empty($lang['description'])) {
                    continue;
                }
                if ($title = $this->getGoodsTitleStore($id,$langName)) {
                    $lang['title'] = $title;
                }
//                else if ($langId == 2){
//                    //标题库没有，原标题直接加随机码,但是如果是中文(或其它一个字占多个字符的语言)标题，直接截取后面5位字
//                    //符替换成随机码会造成乱码，所以目前仅英文标题加随机码
//                    $tmpTitle = $lang['title'];
////                    $this->titleAddRandom($tmpTitle);
//                    $lang['title'] = $tmpTitle;
//                }
                break;
            }
            if (isset($lang['description'])) {
                $sellingPoints = json_decode($lang['selling_point'], true);
                if (!empty($sellingPoints)) {
                    $spStr = 'Bullet Points:<br>';
                    $i = 1;
                    foreach ($sellingPoints as $sellingPoint) {
                        if (!empty($sellingPoint)) {
                            $spStr .= (string)$i.'. '.$sellingPoint.'<br>';
                            $i++;
                        }
                    }
                    $spStr .= '<br>';
                    $lang['description'] = $spStr.$lang['description'];//拼接卖点
                }
                $lang['description'] = str_replace(["\n","\r"],'<br>',$lang['description']);
            }

            return empty($lang) ? [] : $lang->toArray();
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取商品分类所有子分类
     * @param int $categoryId
     * @return array
     * @throws Exception
     */
    public function getGoodsCategoryChildren(int $categoryId) : array
    {
        try {
            $categoryIds = [$categoryId];
            $categoryLevelIds = [$categoryId];
            while (1) {
                $subCategoryIds = Category::where(['pid'=>['in', $categoryLevelIds]])->column('id');
                if (empty($subCategoryIds)) break;
                $categoryIds = array_merge($categoryIds, $subCategoryIds);
                $categoryLevelIds = $subCategoryIds;
            }
            return $categoryIds;
        } catch(Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取图片基地址
     * @return string
     * @throws Exception
     */
    public function getImgInnerBaseUrl() : string
    {
        try {
            $baseUrl = Cache::store('configParams')->getConfig('innerPicUrl')['value'] . "/";
            return empty($baseUrl) ? 'https://img.rondaful.com' : $baseUrl;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取eBay分类链
     * @param $categoryId
     * @param $site
     * @return string
     * @throws Exception
     */
    public function getEbayCategoryChain($categoryId, $site)
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
     * listing setting表兼容属性json转array
     * @param $compatitbility
     * @return array
     * @throws Exception
     */
    public function compatibilityJsonToArray($compatitbility)
    {
        try {
            $tmp = json_decode($compatitbility, true);
            if (empty($tmp)) {
                return [];
            }
            $packCompatibility = [];
            foreach ($tmp as $key => $value) {
                foreach ($value['name_value_list'] as $k => $v) {
                    $packCompatibility[$key][$v['name']] = $v['value'];
                }
                $packCompatibility[$key]['id'] = $key;
                $packCompatibility[$key]['notes'] = isset($value['compatibility_notes']) ? $value['compatibility_notes'] : '';
            }
            return $packCompatibility;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 变体属性键值对，json转array
     * @param $variation
     * @return array
     * @throws Exception
     */
    public function variationJsonToArray($variation)
    {
        try {
            $tmp = json_decode($variation, true);
            if (empty($tmp)) {
                return [];
            }
            $nameValue = [];
            foreach ($tmp as $k => $v) {
                $nameValue[$k] = $v;
            }
            return $nameValue;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 买家限制详情json转array
     * @param $buyerRequirement
     * @return array|mixed
     * @throws Exception
     */
    public function buyerRequirementJsonToArray($buyerRequirement)
    {
        try {
            $tmp = json_decode($buyerRequirement, true);
            if (empty($tmp)) {
                $details['credit'] = 0;
                $details['strikes'] = 0;
                $details['violations'] = 0;
                $details['link_paypal'] = 0;
                $details['registration'] = 0;
                $details['requirements'] = 0;
                $details['strikes_count'] = 0;
                $details['strikes_period'] = '';
                $details['minimum_feedback'] = 0;
                $details['violations_count'] = 0;
                $details['violations_period'] = '';
                $details['minimum_feedback_score'] = 0;
                $details['requirements_max_count'] = 0;
                $details['requirements_feedback_score'] = 0;
                return $details;
            }
            return isset($tmp[0]) ? $tmp[0] : $tmp;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 批量删除范本
     * @param $ids
     * @throws Exception
     */
    public function delListings($ids)
    {
        try {
            Db::startTrans();
            EbayListing::destroy(['id'=>['in', $ids]]);
            EbayListingSetting::destroy(['id'=>['in', $ids]]);
            EbayListingImage::destroy(['listing_id'=>['in', $ids]]);
            EbayListingVariation::destroy(['listing_id'=>['in', $ids]]);
            EbayListingMappingSpecifics::destroy(['listing_id'=>['in', $ids]]);
            EbayDraft::destroy(['listing_id'=>['in',$ids]]);
            Db::commit();
            $ids = array_map(function ($a) {
                return (int)$a;
            },$ids);
            mongo('ebay_listing_setting')->deleteMany(['id'=>['$in'=>$ids]]);
        } catch (\Exception $e) {
            Db::rollback();
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 生成标题
     * @param $langName
     * @param $title
     * @return string
     */
    public function createTitle($langName, $title)
    {
        $this->titleKeyIdToName($title,$langName);
        $tmpTitle = $title[$langName];
        shuffle($tmpTitle['middle']);
        $tmpTitle = array_merge($tmpTitle['front']??[],$tmpTitle['middle'],$tmpTitle['back']??[]);
        $tmpTitle = implode(' ',$tmpTitle);
        $tmpTitle .= ' '.\Nette\Utils\Random::generate(4, '0-9A-Z');
        return $tmpTitle;
    }

    /**
     * 处理ebay api返回的错误信息
     * @param $response
     * @return array
     */
    public static function dealEbayApiError($response)
    {
        $errorInfo = $response['Errors'];
        $errors = isset($errorInfo[0]) ? $errorInfo : [$errorInfo];
        $errorMsg = [];
        foreach ($errors as $error) {
            if ($error['SeverityCode'] == 'Error') {
                $errorMsg[$error['ErrorCode']] = $error['SeverityCode'].':' . $error['LongMessage'];
            }
        }
        if (isset($response['Message'])) {
            $errorMsg['message'] = $response['Message'];
        }
        return $errorMsg;
    }

    /**
     * 变更listing状态，并记录错误信息（如果有）
     * @param $listingStatus
     * @param $errMsg
     */
    public static function updateListingStatusWithErrMsg($listingStatus,$id,$wh=[],$errMsg='')
    {
        if (empty($id) && empty($wh)) {
            throw new Exception('缺少更新条件');
        }
        try {
            Db::startTrans();
            EbayListing::update(['listing_status' => self::PUBLISH_STATUS[$listingStatus]],
                $id ? ['id' => $id] : $wh);
            if ($errMsg) {
                if (!$id) {
                    $id = EbayListing::where($wh)->value('id');
                }
                EbayListingSetting::update(['message' => $errMsg], ['id' => $id]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 变更listing状态，并将错误信息(如果有)记录到日志中
     * @param $listingStatus
     * @param $logMsg
     */
    public static function updateListingStatusAndLog($listingStatus, $wh,$logId,$logMsg='')
    {
        EbayListing::update(['listing_status'=>self::PUBLISH_STATUS[$listingStatus]],$wh);
        $update = empty($logMsg) ? ['status'=>2] : ['status'=>3,'message'=>$logMsg];
        EbayActionLog::update($update,['id'=>$logId]);
    }


    /**
     * 获取标题库设置
     * @param $goodsId
     * @param $langName
     * @return string
     */
    public function getGoodsTitleStore($goodsId, $langName)
    {
        //查标题库是否有设置
        $title = EbayTitle::where(['goods_id'=>$goodsId])->value('title');
        if (empty($title)) {
            return '';
        }
        $title = json_decode($title, true);
        if (!isset($title[$langName])) {
            return '';
        }
        return $this->createTitle($langName,$title);
    }

    /**
     * 原标题加随机码
     * @param $title
     */
    public function titleAddRandom(&$title)
    {
        if (strlen($title)>5) {
            $title = substr($title,0,-5);
            $random = \Nette\Utils\Random::generate(4,'0-9A-Z');
            $title .= ' '.$random;
        } else {
            $title = \Nette\Utils\Random::generate(5,'0-9A-Z');
        }
    }

    /**
     * 将图片路径拆分重组为baseurl=>[path1,path2]的形式
     * @param $paths
     * @return array
     */
    public function explodePathToBaseUrlPath($paths)
    {
        $baseUrlPaths = [];
        foreach ($paths as $path) {
            $match = [];
            if (preg_match('/http(s)?:\/\/[.a-z0-9]*\/',$path,$match)) {//带域名
                $baseUrl = $match[0];
                $tmpPath = str_replace($baseUrl,'',$path);
                $baseUrlPaths[$baseUrl][] = $tmpPath;
            } else {//不带域名
                $baseUrl = 'https://img.rondaful.com/';
                $baseUrlPaths[$baseUrl][] = $path;
            }
        }
        return $baseUrlPaths;
    }

    /**
     * 将图片路径由baseurl=>[path1,path2]的形式重组成数组形式
     * @param $baseUrlPaths
     * @return array
     */
    public function baseUrlPathToPath($baseUrlPaths)
    {
        $paths = [];
        foreach ($baseUrlPaths as $baseUrl => $baseUrlPath) {
            $paths[] = $baseUrl.$baseUrlPath;
        }
        return $paths;
    }

    /**
     * 图片旧存储格式转新存储格式
     * @param $oImgs
     * @return array
     */
    public function imgVersionOldToNew($oImgs)
    {
        $publishImgs = [];
        $detailImgs = [];
        $skuImgs = [];
        $serPath = [];
        $epsPath = [];
        foreach ($oImgs as $oImg) {
            $tmpPath = empty($oImg['path'])?$oImg['thumb']:$oImg['path'];
            $tmpPath = preg_replace('/\?.*$/','',$tmpPath);
            $path = str_replace(['http://img.rondaful.com/','https://img.rondaful.com/','http://14.118.130.19'],'',$tmpPath);
            if ($oImg['main'] == 1) {
                $publishImgs[$oImg['sort']] = $path;
            }
            if ($oImg['detail'] == 1) {
                $detailImgs[$oImg['de_sort']] = $path;
            }
            if ($oImg['main_de'] == 1) {
                $skuImgs[$oImg['value']][] = $path;
            }
            $serPath[md5($path)] = str_replace('http://47.88.100.67/','',$oImg['ser_path']);
            !empty($oImg['eps_path']) && $epsPath[md5($oImg['ser_path'])] = str_replace('https://i.ebayimg.com/','',$oImg['ser_path']);
        }
        $publishImgs = array_values($publishImgs);
        $detailImgs = array_values($detailImgs);
        return ['publishImgs'=>$publishImgs,'detailImgs'=>$detailImgs,'skuImgs'=>$skuImgs,'serPath'=>$serPath,'epsPath'=>$epsPath];
    }

    /**
     * listing 图片旧格式转新格式
     * @param $id
     * @return array
     */
    public function listingImgVersionO2N($id)
    {
        try {
            $oImgs = EbayListingImage::where('listing_id', $id)->select();
        } catch (\Exception $e) {
            return ['result'=>false,'message'=>$e->getMessage()];
        }
        if ($oImgs) {
            $oImgs = collection($oImgs)->toArray();
            $newImgs = [];
            $i = 0;
            $version = $oImgs[0]['spu'];
            if (empty($version)) {//第一版本
                foreach ($oImgs as $oImg) {
                    $used = 0;
                    if ($oImg['main'] == 1) {//主图
                        $newImgs[$i] = $oImg;
                        $newImgs[$i]['detail'] = 0;
                        $i++;
                        $used = 1;
                    }
                    if ($oImg['detail'] == 1) {//详情图
                        $newImgs[$i] = $oImg;
                        $newImgs[$i]['main'] = 0;
                        $newImgs[$i]['sort'] = $oImg['de_sort'];
                        if ($used) {
                            unset($newImgs[$i]['id']);
                        }
                        $i++;
                    }
                    if ($oImg['main_de']) {//变体图
                        $newImgs[$i] = $oImg;
                        $i++;
                    }

                }
            } elseif ($version == 1) {
                $publishImgs = json_decode($oImgs[0]['path'],true)??[];
                $detailImgs = json_decode($oImgs[0]['path'],true)??[];
                $serPath = json_decode($oImgs[0]['path'],true);
                $epsPath = json_decode($oImgs[0]['path'],true);

                $accountId = EbayListing::where('id',$id)->value('account_id');
                $code = EbayAccount::where('id',$accountId)->value('code');

                foreach ($publishImgs as $k => $publishImg) {
                    $newImgs[$i]['spu'] = 2;
                    $newImgs[$i]['sku'] = '';
                    $newImgs[$i]['path'] = $publishImg;
                    $newImgs[$i]['thumb'] = $publishImg;
                    $newImgs[$i]['base_url'] = '';
                    if (isset($serPath[md5($publishImg)])) {
                        $newImgs[$i]['ser_path'] = $serPath[md5($publishImg)];
                    } else {
                        $newImgs[$i]['ser_path'] = (strpos($publishImg,'https://i.ebayimg.com') ===false) ?
                            GoodsImage::getThumbPath($publishImg,0,0,$code,true) : $publishImg;
                    }
                    $newImgs[$i]['eps_path'] = '';
                    $newImgs[$i]['sort'] = $k;
                    $newImgs[$i]['main'] = 1;//主图标志
                    $newImgs[$i]['detail'] = 0;//详情图标志
                    $newImgs[$i]['main_de'] = 0;//变体图标志
                    $i++;
                }
                foreach ($detailImgs as $detailImg) {
                    $newImgs[$i]['spu'] = 2;
                    $newImgs[$i]['sku'] = '';
                    $newImgs[$i]['path'] = $detailImg;
                    $newImgs[$i]['thumb'] = $detailImg;
                    $newImgs[$i]['base_url'] = '';
                    if (isset($serPath[md5($detailImg)])) {
                        $newImgs[$i]['ser_path'] = $serPath[md5($detailImg)];
                    } else {
                        $newImgs[$i]['ser_path'] = (strpos($detailImg,'https://i.ebayimg.com') ===false) ?
                            GoodsImage::getThumbPath($detailImg,0,0,$code,true) : $detailImg;
                    }
                    $newImgs[$i]['eps_path'] = '';
                    $newImgs[$i]['sort'] = $k;
                    $newImgs[$i]['main'] = 0;//主图标志
                    $newImgs[$i]['detail'] = 1;//详情图标志
                    $newImgs[$i]['main_de'] = 0;//变体图标志
                    $i++;
                }
            } else {
              $newImgs = collection($oImgs)->toArray();
            }
            return ['result'=>true,'data'=>$newImgs];
        } else {
            return ['result'=>false,'message'=>'图片信息获取失败'];
        }
    }

    /**
     *变体图片baseurl,path分离模式转成完整路径模式
     * @param $sku
     * @return array
     */
    public function skuImgPathToFullPath($skuImgs)
    {
        $skuImgs = json_decode($skuImgs,true)??[];
        foreach ($skuImgs as &$skuImg) {
            if (isset($skuImg['path'])) {//旧版本格式
                $skuImg = str_replace(['http://img.rondaful.com/','https://img.rondaful.com/','http://14.118.130.19'],'',$skuImg['path']);//只保存路径
            } else {//新版本格式
                $skuImg = str_replace(['http://img.rondaful.com/','https://img.rondaful.com/','http://14.118.130.19'],'',$skuImg);//只保存路径
            }
        }
        return $skuImgs;
    }

    /**
     * 生成listing sku
     * @param $skuInfo
     * @param bool $isVariant
     * @return string
     */
    public function createListingSku($skuInfo, $isVariant= false)
    {
        if ($isVariant) {//变体
            $tmpSku = (new GoodsSkuMapService())->createSku($skuInfo['v_sku']);
            $listingSku = $skuInfo['assoc_order'] ? $tmpSku : 'ebay'.$tmpSku;
        } else {//不是变体
            $localSku = $skuInfo['variation'] ? $skuInfo['spu'] : $skuInfo['local_sku'];
            $tmpSku = (new GoodsSkuMapService())->createSku($localSku);
            //只有单属性，且不关联订单的情况下，才需要生成不规则listing sku
            $listingSku = ($skuInfo['variation']==0 && !$skuInfo['assoc_order']) ? 'ebay'.$tmpSku : $tmpSku;
        }
        return $listingSku;
    }

    /**
     * 生成海外图片服务器地址
     * @param $publishImgs
     * @param $detailImgs
     * @param array $skuimgs
     * @param array $oldSerpath
     * @return array
     */
    public function createSerPath($accountCode,$publishImgs, $detailImgs, $skuImgs=[], $oldSerpath=[])
    {
        $imgs = array_merge($publishImgs,$detailImgs);
        foreach ($skuImgs as $skuImg) {
            $imgs = array_merge($imgs,$skuImg);
        }
        $imgs = array_unique($imgs);
        $md5Imgs = [];
        foreach ($imgs as $img) {
            $md5Imgs[md5($img)] = $img;
        }
        $existSerpath = [];
        if (empty($oldSerpath)) {//不存在
            $needCreateSerpath = $md5Imgs;
        } else {//存在，对比更新
            foreach ($md5Imgs as $md5 => &$md5Img) {
                if (isset($oldSerpath[$md5])) {//已经存在
                    $existSerpath[$md5] = $oldSerpath[$md5];
                } else if (strpos($md5Img,'https://i.ebayimg.com/')!==false) {//本身是ebay图库地址
                    $existSerpath[$md5] = $md5Img;
                } else {
                    $needCreateSerpath[$md5] = $md5Img;
                }
            }
        }
        if (!empty($needCreateSerpath)) {
            foreach ($needCreateSerpath as $md5 => &$path) {
                $path = \app\goods\service\GoodsImage::getThumbPath($path,0,0,$accountCode,true,true);
            }
            $existSerpath = array_merge($needCreateSerpath,$existSerpath);
        }
        return $existSerpath;
    }

    public function formatList(&$list,$isUpdate,$userId)
    {
        $list['application'] = 1;
        $list['item_id'] = 0;
        //listing sku，遵循的原则就是确保关联的订单的可以被找到，未关联订单的不被找到
        //不再保存时生成，提交到平台时再生成
        if ($list['variation']) {//多属性的父SPU需要先生成
            $list['listing_sku'] = (new GoodsSkuMapService())->createSku($list['spu']);
        } else {
            $list['listing_sku'] = '';
        }

//        if (empty($list['listing_sku']) || $isUpdate==0) {//为空或创建时重新生成listing_sku
//            $list['listing_sku'] = $this->createListingSku($list);
//        } else if ($list['variation'] == 0 //单属性
//            && $list['assoc_order'] == 0 //不关联订单
//            && strpos($list['listing_sku'],'ebay')===false) {//旧listing sku是规范的
//            $list['listing_sku'] = 'ebay'.$list['listing_sku'];
//        }
        if (empty($list['currency'])) {
            $list['currency'] = EbaySite::where('siteid',$list['site'])->value('currency');
        }
        //定时
        $timing = strtotime($list['timing']??0);
        $list['timing'] = 0;
        if ($timing !== false) {
            $timezone = EbaySite::where('siteid',$list['site'])->value('time_zone');
            $localTime = $timing+$timezone;
            if ($localTime>time()) {
                $list['timing'] = $localTime;
            }
        }
        //价格
        if (!empty($list['start_price'])) {
            $list['max_price'] = $list['start_price'];
            $list['min_price'] = $list['start_price'];
        }

        $list['draft'] = 0;
        if ($list['autopay']) {
            $list['autopay'] = 1;
        }
        if (!$isUpdate) {//新增
            $list['listing_cate'] = '';
            $list['sold_quantity'] = 0;
            $list['hit_count'] = 0;
            $list['watch_count'] = 0;
            $list['create_date'] = time();
            $list['start_date'] = 0;
            $list['end_date'] = 0;
            $list['listing_status'] = 0;
            $list['insertion_fee'] = 0;
            $list['listing_fee'] = 0;
            $list['realname'] = $userId;
            $list['manual_end_time'] = 0;
        }
        $list['update_date'] = time();
        $list['user_id'] = $userId;
    }

    public function getListing($id)
    {
        try {
            $list = EbayListing::get($id);
            if (empty($list)) {
                sleep(2);
                $list = EbayListing::get($id);
                if (empty($list)) {
                    throw new Exception('获取listing信息失败');
                }
            }
            $data['list'] = $list->toArray();
            $set = EbayListingSetting::get($id);
            if (empty($set)) {
                throw new Exception('获取setting信息失败');
            }
            $data['set'] = $set->toArray();

            //图片
            $res = self::listingImgVersionO2N($id);
//            $field = 'id,spu,sku,path,thumb,ser_path,eps_path,name,value,sort,main,main_de,detail,de_sort';
//            $imgs = EbayListingImage::field($field)->where('listing_id',$id)->select();
//            if (empty($imgs)) {
//                throw new Exception('图片信息获取失败，请重新保存后再进行操作');
//            }
//            $imgs = collection($imgs)->toArray();
            if ($res['result'] === false) {
                throw new Exception('图片信息获取失败');
            }
            $data['imgs'] = $res['data'];

            if ($list['variation']) {
                $variants = EbayListingVariation::where('listing_id',$id)->select();
                if (empty($variants)) {
                    throw new Exception('变体信息获取失败');
                }
                $data['varians'] = collection($variants)->toArray();
            }
            return ['result'=>true,'data'=>$data];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 过滤掉只读的listing id
     * @param $ids
     * @return array
     */
    public function filterReadOnlyListingId($ids)
    {
        $enableStatus = self::OFL_PUBLISH_STATUS;
        $wh = [
            'id' => ['in',$ids],
            'listing_status' => ['in',$enableStatus]
        ];
        return EbayListing::where($wh)->column('id');
    }

    /**
     * 打包复制后的数据
     * @param $ids
     * @param $accountId
     * @param $paypal
     * @param $userId
     * @return array
     */
    public function packageCopyListing($ids, $userId, $accountId=0, $paypal='',$fromDraft=0)
    {
        try {
            $data = [];
//            $message = '';
            foreach ($ids as $k => $id) {
                $oList = EbayListing::get($id);
                $oSet = EbayListingSetting::get($id);
                $mongoSet = ELSMongo::get(['id'=>(int)$id]);
                if (!$fromDraft) {
                    $oImgs = EbayListingImage::where('listing_id', $id)->select();
                    if (!$oList || !$oSet || !$oImgs) {
                        throw new Exception('id:' . $id . ' 的listing信息获取失败');
                    }
                } else {//复制的范本，不使用源listing图片
                    if (!$oList || !$oSet) {
                        throw new Exception('id:' . $id . ' 的listing信息获取失败');
                    }
                }
                $variants = [];
//                if ($oList['variation']) {
                $variants = EbayListingVariation::where('listing_id',$id)->select();
//                    if (!$variants) {
//                        throw new Exception('id:'.$id.' 的listing变体信息获取失败，已忽略');
//                    }
//                }

                //主表
                $list = $oList->toArray();
                unset($list['id']);
                $list['item_id'] = 0;
                $list['draft'] = 0;
                !empty($accountId) && $list['account_id'] = $accountId;
                !empty($paypal) && $list['paypal_emailaddress'] = $paypal;
                $list['sold_quantity'] = 0;
                $list['hit_count'] = 0;
                $list['create_date'] = time();
                $list['start_date'] = 0;
                $list['end_date'] = 0;
                $list['update_date'] = time();
                $list['listing_status'] = 0;
                $list['insertion_fee'] = 0;
                $list['listing_fee'] = 0;
                $list['listing_cate'] = '';
                $list['timing'] = 0;
                $list['rule_id'] = 0;
                $list['user_id'] = $userId;
                $list['realname'] = $userId;
                $list['manual_end_time'] = 0;
                $list['listing_sku'] = '';//$this->createListingSku($list);提交到平台时再生成
                //setting表
                $set = $oSet->toArray();
                if ($mongoSet) {
                    $set['description'] = $mongoSet['description'];
                }
                $set['message'] = '';
                //image表
//                if ($accountId) {
//                    $accountCode = EbayAccount::where('id',$accountId)->value('code');
//                }
                $imgs = [];
                if (!$fromDraft && $oImgs) {
                    $res = $this->listingImgVersionO2N($id);
                    if ($res['result'] === false) {
                        throw new Exception($res['message']);
                    }
                    $imgs = $res['data'];


                    foreach ($imgs as &$img) {
                        if (isset($img['id'])) {
                            unset($img['id']);
                        }
                        $img['eps_path'] = '';
                        if ($accountId) {
                            $img['ser_path'] = '';//GoodsImage::getThumbPath($img['path'], 0, 0, $accountCode,true);
                        }
                    }
                } elseif ($fromDraft) {//复制的范本，使用产品图片
                    $imgs = $this->getGoodsImgs($id, 0);
                    foreach ($imgs as $kImg => &$img) {
                        $path = $img['path'];
                        $img = [];
                        $img['path'] = $path;
                        $img['thumb'] = $path;
                        $img['base_url'] = 'https://img.rondaful.com';
                        $img['ser_path'] = '';//GoodsImage::getThumbPath($img['path'], 0, 0, $accountCode,true);
                        $img['sort'] = $kImg;
                        $img['main'] = 1;
                    }
                }

                //变体
                if ($oList['variation']) {
                    if (!$variants) {
                        throw new Exception('id为' . $id . ' 的listing变体信息获取失败,无法进行复制，请取消勾选此条后再操作');
                    }
//                    if (!empty($variants)) {
//                        $variants = collection($variants)->toArray();
//                    }
                    //本地属性与平台属性映射
                    $mappingSpec = EbayListingMappingSpecifics::where('listing_id',$id)->select();
                    if ($mappingSpec) {
                        $mappingSpec = collection($mappingSpec)->toArray();
                        foreach ($mappingSpec as &$ms) {
                            unset($ms['id']);
                            unset($ms['listing_id']);
                        }
                    }
                } elseif (empty($variants)) {//单属性
                    $skuId = GoodsSku::where('sku',$list['local_sku'])->value('id');
                    $tmpVar = [
                        'goods_id' => $list['goods_id'],
                        'v_sku' => $list['local_sku'],
                        'sku_id' => empty($skuId) ? 0 : $skuId,
                        'v_price' => $list['start_price'],
                        'v_qty' => $list['quantity'],
                        'combine_sku' => $list['sku'],
                        'channel_map_code' => $list['listing_sku'],
                        'cost_price' => $list['cost_price'],
                        'adjusted_cost_price' => $list['adjusted_cost_price'],
                        'reserve_price' => $list['reserve_price'],
                        'buy_it_nowprice' => $list['buy_it_nowprice'],
//                        'v_sold' => $list['sold_quantity'],
                        'variation' => json_encode([]),
                    ];
                    $variants = [$tmpVar];
                }

                foreach ($variants as &$variant) {
                    if (is_object($variant)) {
                        $variant = $variant->toArray();
                    }
                    unset($variant['id']);
                    $variant['v_sold'] = 0;
                    if ($oList['variation']) {//多属性
                        $variation = json_decode($variant['variation'], true);
                        if (!isset($variation[$set['variation_image']])) {
                            throw new Exception('id:' . $id . ' 的listing关联的图片属性在变体属性中不存在');
                        }
                    }
                    $variant['channel_map_code'] = $this->createListingSku(['assoc_order'=>$list['assoc_order'],
                        'v_sku'=>$variant['v_sku']],true);
                }


                $data[] = [
                    'list' => $list,
                    'set' => $set,
                    'imgs' => $imgs,
                    'mappingspec' => $mappingSpec??[],
                    'variants' => $variants ?? []
                ];
            }
            return ['result'=>true,'data'=>$data];
        } catch (Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    public static function setListingStatus($id,$status,$message='')
    {
        EbayListing::update(['listing_status'=>self::PUBLISH_STATUS[$status]],['id'=>$id]);
        if ($status == 'publishFail' && $message) {
            EbayListingSetting::update(['message'=>$message],['id'=>$id]);
        }
    }

    public function endItem($itemId,$tortId=0)
    {
        try {
            $list = EbayListing::field('id,goods_id,account_id,site')->where(['item_id' => $itemId])->find();
            if (!$list) {
                EbayListing::update(['listing_status' => self::PUBLISH_STATUS['endFail']], ['item_id' => $itemId]);
                return false;
            }
            $accountInfo = EbayAccount::get($list['id']);
            if (!$accountInfo) {
                self::setListingStatus($list['id'], 'endFail', '账号信息获取失败');
                return false;
            }
            $accountInfo = $accountInfo->toArray();
            $packApi = new EbayPackApi();
            $api = $packApi->createApi($accountInfo, 'EndItem', $list['site']);
            $xml = $packApi->createXml(['item_id'=>$itemId]);
            $response = $api->createHeaders()->__set('requesBody', $xml)->sendHttpRequest2();
        } catch (\Exception $e) {
            self::setListingStatus($list['id'],'endFail',$e->getMessage());
            return false;
        }
        $res = (new EbayDealApiInformation())->dealWithApiResponse('EndItem',$list);
        if ($res['result'] === true) {
            self::setListingStatus($list['id'],'ended');
        }
        //侵权下架回写
        if (isset($list['end_type']) && $list['end_type'] == 2) {
            $backWriteData = [
                'goods_id' => $list['goods_id'],
                'goods_tort_id' => $tortId,
                'channel_id' => 1,
                'status' => ($res['result'] ? 1 : 2),
            ];
            (new UniqueQueuer(\app\goods\queue\GoodsTortListingQueue::class))->push($backWriteData);//回写
        }
    }


    /**
     * 获取listing
     * @param $itemId
     * @return array
     */
    public function getItem($itemId)
    {
        try {
            $field = self::ACCOUNT_FIELD_TOKEN;
            $account = EbayAccount::field($field)->where('is_invalid',1)->find()->toArray();

            $verb = 'GetItem';
            $packApi = new EbayPackApi();
            $api = $packApi->createApi($account,$verb);
            $xml = $packApi->createXml(['item_id'=>$itemId]);
            $response = $api->createHeaders()->__set('requesBody',$xml)->sendHttpRequest2();
            if (empty($response) || !isset($response['GetItemResponse'])) {
                throw new Exception('网络错误');
            }
            $res = $response['GetItemResponse'];
            return ['result'=>true, 'data'=>$res];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    public static function optionImgHost(&$imgs,$option,$type=0)
    {
        switch ($type) {
            case 0:
                $host = $option=='add' ? 'https://img.rondaful.com/' : ['https://img.rondaful.com/','http://img.rondaful.com/','http://14.118.130.19/'];
                break;
            case 1:
                $host = 'http://47.88.100.67/';
                break;
            case 2:
                $host = 'https://i.ebayimg.com/';
                break;
            default:
                $host = $option=='add' ? 'https://img.rondaful.com/' : ['https://img.rondaful.com/','http://img.rondaful.com/','http://14.118.130.19/'];
                break;
        }
        foreach ($imgs as &$img) {
            if (is_array($img)) {
                foreach ($img as &$item) {
                    if ($option == 'add' && strpos($item,'http')===false) {
                        $item = $host.$item;
                    } elseif ($option == 'del') {
                        $item = str_replace($host,'',$item);
                    }

                }
            } else {
                if ($option == 'add' && strpos($img,'http')===false) {
                    $img = $host.$img;
                } elseif ($option == 'del') {
                    $img = str_replace($host,'',$img);
                }
            }
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
            $wh['draft'] = 0;
            $wh['goods_id'] = $goodsIds;
            $wh['item_id'] = ['neq',0];
            $wh['application'] = 1;
            $wh['listing_status'] = ['in',[3,5,6,7,8,9,10]];
            $salesmenIds = EbayListing::distinct(true)->where($wh)->column('realname');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 根据商品id获取刊登过该SKU的销售员
     * @param $skuId
     * @return array
     */
    public static function getSalesmenByGoodsId($goodsId)
    {
        try {
            $wh['draft'] = 0;
            $wh['goods_id'] = $goodsId;
            $wh['item_id'] = ['neq',0];
            $wh['application'] = 1;
            $wh['listing_status'] = ['in',[3,5,6,7,8,9,10]];
            $salesmenIds = EbayListing::distinct(true)->where($wh)->column('realname');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 批量更新在线listing PayPal
     * @param $data
     *       $data = [
     *          'account_id' =>	1,
     *          'old_max_paypal' => [
     *                              ["id"=>89,"type"=>1],
     *                              ["id"=>89,"type"=>2],
     *          ],
     *          'old_min_paypal' => [
     *                              ["id"=>222,"type"=>1],
     *                              ["id"=>111,"type"=>2],
     *          ],
     *          'max_paypal' => [
     *                              ["id"=>44,"type"=>1],
     *                              ["id"=>33,"type"=>2],
     *          ],
     *          'min_paypal' => [
     *                              ["id"=>22,"type"=>1],
     *                              ["id"=>77,"type"=>2],
     *          ],
     *      ]
     *
     *
     *
     * @return
     */
    public function updateOLListingPaypal($data)
    {
        try {
            $accountId = $data['account_id'];
            $oldMaxPaypal = $data['old_max_paypal'];
            $oldMinPaypal = $data['old_min_paypal'];
            $maxPaypal = $data['max_paypal'];
            $minPaypal = $data['min_paypal'];
            $paypalIds = [];

            //检测大额账户
            foreach ($maxPaypal as $maxp) {
                foreach ($oldMaxPaypal as $omaxp) {
                    if ($maxp['type'] == $omaxp['type'] && $maxp['id'] != $omaxp['id']) {
                        $paypalIds['max_paypal'][] = [$omaxp['id'],$maxp['id']];
                    }
                }
            }
            //检测小额账户
            foreach ($minPaypal as $minp) {
                foreach ($oldMinPaypal as $ominp) {
                    if ($minp['type'] == $ominp['type'] && $minp['id'] != $ominp['id']) {
                        $paypalIds['min_paypal'][] = [$ominp['id'],$minp['id']];
                    }
                }
            }
            if (!$paypalIds) {
                return;
            }
            $ids = [];
            foreach ($paypalIds as $paypalId) {
                foreach ($paypalId as $ppid) {
                    $ids = array_merge($ids,$ppid);
                }
            }
            $paypalEmails = PaypalAccount::whereIn('id',$ids)->column('account_name','id');

            $wh = [
                'account_id' => $accountId,
                'item_id' => ['neq',0],
                'listing_status' => ['in',self::OL_PUBLISH_STATUS],
                'draft' => 0,
            ];
            $log = [
                'site' => 0,
                'listing_sku' => '',
                'api_type' => 2,
                'account_id' => $accountId,
                'create_id' => 0,
                'create_time' => time(),
                'cron_time' => 0,
                'remark' => '',
            ];
            foreach ($paypalIds as $paypalId) {
                foreach ($paypalId as $ppid) {
                    $wh['paypal_emailaddress'] = $paypalEmails[$ppid[0]];
                    $itemIds = EbayListing::where($wh)->column('item_id');
                    $newVal = json_encode(['paypal_emailaddress'=>$paypalEmails[$ppid[1]]]);
                    $oldVal = json_encode(['paypal_emailaddress'=>$paypalEmails[$ppid[0]]]);
                    $log['old_val'] = $oldVal;
                    $log['new_val'] = $newVal;
                    foreach ($itemIds as $itemId) {
                        $log['item_id'] = $itemId;
                        $logId = EbayActionLog::insertGetId($log);
                        (new UniqueQueuer(EbayUpdateOnlineListing::class))->push($logId);
                    }
                }
            }
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }


    //图片始终删除重加
    public static function formatImgs($imgs,$accountCode,$listingId,$variationImgs='')
    {
        $newImgs = [];
        $i = 0;
        $del = [];
        $reserveIds = [];
        $oldIds = [];

        try {
            $oldImgs = [];
            $field = 'id,listing_id,spu,sku,path,thumb,ser_path,eps_path,name,value,sort,main,main_de,detail,de_sort';
            if ($listingId) {
                $oldImgs = EbayListingImage::field($field)->where('listing_id',$listingId)->select();
                if (!$oldImgs || $oldImgs[0]['spu']!=2) {//只合并新版本，旧版本删除重建
                    $oldImgs = [];
                    $del = ['listing_id'=>$listingId];//设置删除条件
                }
            }
            if (!empty($oldImgs)) {
                $oldImgs = collection($oldImgs)->toArray();
            }
            //主图
            $publishImgs = $imgs['publishImgs']??[];
            foreach ($publishImgs as $k => $publishImg) {
                if (empty($publishImg)) {
                    continue;
                }
                if (!empty($oldImgs)) {//有旧图
                    $exist = 0;
                    foreach ($oldImgs as $oldImg) {
                        if ($oldImg['path'] == $publishImg && $oldImg['main']==1) {//旧图中存在
                            $newImgs[$i] = $oldImg;
                            $newImgs[$i]['sort'] = $k;//改变顺序
                            $reserveIds[] = $oldImg['id'];
                            $i++;
                            $exist = 1;
                            break;
                        }
                    }
                    if (!$exist) {//旧图中不存在
                        $newImgs[$i]['spu'] = 2;
                        $newImgs[$i]['listing_id'] = $listingId;
                        $newImgs[$i]['sku'] = '';
                        $newImgs[$i]['path'] = $publishImg;
                        $newImgs[$i]['thumb'] = $publishImg;
                        $newImgs[$i]['base_url'] = '';
                        $newImgs[$i]['ser_path'] = '';//不再记录海外图片地址，上传时临时生成(strpos($publishImg,'https://i.ebayimg.com') ===false) ?
                            //GoodsImage::getThumbPath($publishImg,0,0,$accountCode,true) : $publishImg;
                        $newImgs[$i]['eps_path'] = '';
                        $newImgs[$i]['sort'] = $k;
                        $newImgs[$i]['main'] = 1;//主图标志
                        $newImgs[$i]['detail'] = 0;//详情图标志
                        $newImgs[$i]['main_de'] = 0;//变体图标志
                        $i++;
                    }
                } else {//没有旧图
                    $newImgs[$i]['spu'] = 2;
                    $newImgs[$i]['listing_id'] = $listingId;
                    $newImgs[$i]['sku'] = '';
                    $newImgs[$i]['path'] = $publishImg;
                    $newImgs[$i]['thumb'] = $publishImg;
                    $newImgs[$i]['base_url'] = '';
                    $newImgs[$i]['ser_path'] = '';//(strpos($publishImg,'https://i.ebayimg.com') ===false) ?
//                        GoodsImage::getThumbPath($publishImg,0,0,$accountCode,true) : $publishImg;
                    $newImgs[$i]['eps_path'] = '';
                    $newImgs[$i]['sort'] = $k;
                    $newImgs[$i]['main'] = 1;//主图标志
                    $newImgs[$i]['detail'] = 0;//详情图标志
                    $newImgs[$i]['main_de'] = 0;//变体图标志

                    $i++;
                }

            }
            //详情图
            $detailImgs = $imgs['detailImgs']??[];
            foreach ($detailImgs as $k1 => $detailImg) {
                if (empty($detailImg)) {
                    continue;
                }
                if (!empty($oldImgs)) {//有旧图
                    $exist = 0;
                    foreach ($oldImgs as $oldImg) {
                        if ($oldImg['path'] == $detailImg && $oldImg['detail']==1) {//旧图中存在
                            $newImgs[$i] = $oldImg;
                            $newImgs[$i]['sort'] = $k1;//改变顺序
                            $reserveIds[] = $oldImg['id'];
                            $i++;
                            $exist = 1;
                            break;
                        }
                    }
                    if (!$exist) {//旧图中不存在
                        $newImgs[$i]['listing_id'] = $listingId;
                        $newImgs[$i]['spu'] = 2;
                        $newImgs[$i]['sku'] = '';
                        $newImgs[$i]['path'] = $detailImg;
                        $newImgs[$i]['thumb'] = $detailImg;
                        $newImgs[$i]['base_url'] = '';
                        $newImgs[$i]['ser_path'] = '';//(strpos($detailImg,'https://i.ebayimg.com') ===false) ?
//                            GoodsImage::getThumbPath($detailImg,0,0,$accountCode,true) : $detailImg;
                        $newImgs[$i]['eps_path'] = '';
                        $newImgs[$i]['sort'] = $k1;
                        $newImgs[$i]['main'] = 0;//主图标志
                        $newImgs[$i]['detail'] = 1;//详情图标志
                        $newImgs[$i]['main_de'] = 0;//变体图标志
                        $i++;
                    }
                } else {//没有旧图
                    $newImgs[$i]['spu'] = 2;
                    $newImgs[$i]['listing_id'] = $listingId;
                    $newImgs[$i]['sku'] = '';
                    $newImgs[$i]['path'] = $detailImg;
                    $newImgs[$i]['thumb'] = $detailImg;
                    $newImgs[$i]['base_url'] = '';
                    $newImgs[$i]['ser_path'] = '';//(strpos($detailImg,'https://i.ebayimg.com') ===false) ?
//                        GoodsImage::getThumbPath($detailImg,0,0,$accountCode,true) : $detailImg;
                    $newImgs[$i]['eps_path'] = '';
                    $newImgs[$i]['sort'] = $k1;
                    $newImgs[$i]['main'] = 0;//主图标志
                    $newImgs[$i]['detail'] = 1;//详情图标志
                    $newImgs[$i]['main_de'] = 0;//变体图标志
                    $i++;
                }
            }

            //变体图片
            $skuImgs = $imgs['skuImgs']??[];
            foreach ($skuImgs as $skuImg) {
                foreach ($skuImg['path'] as $k2 => $p) {
                    if (empty($p)) {
                        continue;
                    }
                    if (!empty($oldImgs)) {//有旧图
                        $exist = 0;
                        foreach ($oldImgs as $oldImg) {
                            if ($oldImg['path'] == $p && $oldImg['main_de']==1
                                && $oldImg['name']==$variationImgs && $oldImg['value']==$skuImg['value']) {//旧图中存在
                                $newImgs[$i] = $oldImg;
                                $newImgs[$i]['sort'] = $k2;//改变顺序
                                $reserveIds[] = $oldImg['id'];
                                $i++;
                                $exist = 1;
                                break;
                            }
                        }
                        if (!$exist) {//旧图中不存在
                            $newImgs[$i]['listing_id'] = $listingId;
                            $newImgs[$i]['spu'] = 2;
                            $newImgs[$i]['sku'] = $skuImg['channel_map_code'];
                            $newImgs[$i]['path'] = $p;
                            $newImgs[$i]['thumb'] = $p;
                            $newImgs[$i]['base_url'] = '';
                            $newImgs[$i]['ser_path'] = '';//(strpos($p,'https://i.ebayimg.com') ===false) ?
//                                GoodsImage::getThumbPath($p,0,0,$accountCode,true) : $p;
                            $newImgs[$i]['eps_path'] = '';
                            $newImgs[$i]['sort'] = $k2;
                            $newImgs[$i]['main'] = 0;//主图标志
                            $newImgs[$i]['detail'] = 0;//详情图标志
                            $newImgs[$i]['main_de'] = 1;//变体图标志
                            $newImgs[$i]['name'] = $variationImgs;//属性名称
                            $newImgs[$i]['value'] = $skuImg['value'];//属性名称
                            $i++;
                        }
                    } else {//没有旧图
                        $newImgs[$i]['listing_id'] = $listingId;
                        $newImgs[$i]['spu'] = 2;
                        $newImgs[$i]['sku'] = $skuImg['channel_map_code'];
                        $newImgs[$i]['path'] = $p;
                        $newImgs[$i]['thumb'] = $p;
                        $newImgs[$i]['base_url'] = '';
                        $newImgs[$i]['ser_path'] = '';//(strpos($p,'https://i.ebayimg.com') ===false) ?
//                            GoodsImage::getThumbPath($p,0,0,$accountCode,true) : $p;
                        $newImgs[$i]['eps_path'] = '';
                        $newImgs[$i]['sort'] = $k2;
                        $newImgs[$i]['main'] = 0;//主图标志
                        $newImgs[$i]['detail'] = 0;//详情图标志
                        $newImgs[$i]['main_de'] = 1;//变体图标志
                        $newImgs[$i]['name'] = $variationImgs;//属性名称
                        $newImgs[$i]['value'] = $skuImg['value'];//属性名称
                        $i++;
                    }
                }
            }
            if (empty($del)) {
                if (!empty($oldImgs)) {

                    $oldIds = array_column($oldImgs,'id');
                }
                $del = array_diff($oldIds,$reserveIds);
                $del = array_values($del);
            }
            return  ['result'=>true,'data'=>['update'=>$newImgs,'del'=>$del]];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }


    public static function seperateImgs($imgs)
    {
        try {
            $publishImgs = [];
            $detailImgs = [];
            $skuImgs = [];

            foreach ($imgs as $img) {
                if ($img['main'] == 1) {
                    $publishImgs[$img['sort']] = $img;
                } elseif ($img['detail'] == 1) {
                    $detailImgs[$img['sort']] = $img;
                } elseif ($img['main_de'] == 1) {
                    $skuImgs[$img['value']][$img['sort']] = $img;
                }
            }
            ksort($publishImgs);
            ksort($detailImgs);
            foreach ($skuImgs as &$skuImg) {
                ksort($skuImg);
            }
            return ['publishImgs'=>array_values($publishImgs),'detailImgs'=>array_values($detailImgs),'skuImgs'=>$skuImgs];
        } catch (\Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * 设置listing虚拟仓发货
     * @param $data
     * @return bool
     */
    public static function setListingVirtualSend($data)
    {
        try {
            $wh = [
                'draft' => 0,
                'listing_status' => ['in',[3,5,6,7,8,9,10]],
                'item_id' => ['<>',0],
                'listing_sku' => $data['channel_sku']
            ];
            $listing = EbayListing::where($wh)->field('id,is_virtual_send')->find();
            if ($listing) {//单属性
                $listing['is_virtual_send'] = $data['is_virtual_send'];
                $listing->save();
                return true;
            } else {//多属性
                unset($wh['listing_sku']);
                $wh['channel_map_code'] = $data['channel_sku'];
                $listingId = EbayListing::alias('l')->where($wh)
                    ->join('ebay_listing_variation v','v.listing_id=l.id','left')->value('id');
                if ($listingId) {
                    EbayListing::update(['is_virtual_send'=>$data['is_virtual_send']],['id'=>$listingId]);
                    $accountId = EbayListing::where('id',$listingId)->value('account_id');
                    $channelSkus = EbayListingVariation::where('listing_id',$listingId)->column('channel_map_code');
                    $condition = [
                        'goods_id' => $data['goods_id'],
                        'channel_id' => 1,
                        'account_id' => $accountId,
                        'channel_sku' => ['in',$channelSkus],
                    ];
                    GoodsSkuMap::update(['is_virtual_send'=>$data['is_virtual_send']],$condition);
                    return true;
                }
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
    }


    /**
     * 获取名下销售账号id
     * @param $userId
     * @return array
     * @throws Exception
     */
    public function getUnderlineSalesAccountIds($userId)
    {
        try {
            $serverIp = gethostbyname($_SERVER['SERVER_NAME']);
            //测试服和正式服过滤节点id不同，区别对待，避免测试出现问题
            $nodeFlag = strpos($serverIp,'172.18.8.241')!==false || strpos($serverIp,'172.19.23')!==false;
            $nodeId = $nodeFlag ? 345353 : 336578;//节点id,与【listing管理列表】节点保持一致
            //解析条件
            $wh = [];

            $admin = (new Role())->isAdmin($userId) ||
                UserModel::where('id',$userId)->value('job') == 'IT';
            if (!$admin) {//不是管理员或IT人员
                $underlineUserIds = $this->getUnderlingInfo($userId);
                $wh['c.seller_id'] = ['in',$underlineUserIds];
            }

            //查过滤器
            $role = ErpRbac::getRbac($userId);
            $filters = $role->getFilters($nodeId);
            if ($filters) {//过滤器存在且有设置
                foreach ($filters as $name => $filter) {
                    if ($name != 'app\\publish\\filter\\EbayListingFilter') {
                        continue;
                    }
                    if ($filter == '') {//过滤器关闭了，带出所有的账号
                        unset($wh['c.seller_id']);
                    } else {//获取过滤器设置
                        if (!is_array($filter)) {
                            continue;
                        }
                        if (count($filter) == 1 && $filter[0] == 0) {
                            //看自己不做处理
                            continue;
                        } else {
                            if (($key = array_search(0,$filter)) !== false) {//有设置看自己
                                unset($filter[$key]);
                                $whOr['c.account_id'] = ['in',$filter];
                            } else {//如果没有设置看自己，则仅看设置的账号
                                $wh['c.account_id'] = ['in',$filter];
                                unset($wh['c.seller_id']);//不能绑定人员
                            }
                        }
                    }
                }
            }

            $wh['u.status'] = 1;
            $wh['u.job'] = 'sales';
            $wh['a.is_invalid'] = 1;
            $wh['a.account_status'] = 1;
            $wh['c.channel_id'] = 1;
            $field = 'a.id,a.account_name,a.code,u.realname';

            if (isset($whOr)) {
                $accountIds = \app\common\model\ChannelUserAccountMap::alias('c')
                    ->where($wh)->whereOr($whOr)->field($field)
                    ->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->distinct(true)->column('a.id');
            } else {
                $accountIds = \app\common\model\ChannelUserAccountMap::alias('c')->where($wh)
                    ->field($field)->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->distinct(true)->column('a.id');
            }
            return $accountIds;
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @title 刊登统计接口
     * @param array $accountIds
     * @return array
     */
    public function getPublishDaily($accountIds)
    {
        $yesterdayBegin = mktime(0,0,0,date('m'),date('d')-1,date('Y'));
        $dateline = date('Y-m-d',$yesterdayBegin);
        $whEbayAccount = [
            'is_invalid' => 1,
            'account_status' => 1,
            'id' => ['in',$accountIds],
        ];
        $validAccountIds = EbayAccount::where($whEbayAccount)->column('id');
        if (!$validAccountIds) {
            return [];
        }
        $accountIds = $validAccountIds;


        $whAccount = [
            'channel_id' => 1,
            'warehouse_type' => ['in',[0,1]],
            'account_id' => ['in',$accountIds],
        ];
        $accountSellerIds = ChannelUserAccountMap::where($whAccount)->column('seller_id','account_id');

        $whListing = [
            'listing_status' => ['in',self::OL_PUBLISH_STATUS],
            'l.goods_id' => ['<>',0],
            'application' => 1,
            'item_id' => ['<>',0],
            'account_id' => ['in',$accountIds],
            'start_date' => ['<',$yesterdayBegin+86400],
        ];

        //昨日在线spu数量
        $yesterdayOnlineSpu = EbayListing::alias('l')->where($whListing)->group('account_id')->column('count(*)','account_id');

        //昨日在线sku数量
        $yesterdayOnlineSku = EbayListingVariation::alias('v')->where($whListing)->group('account_id')
            ->join('ebay_listing l','l.id=v.listing_id','left')->column('count(*)','account_id');

        //昨日刊登量
        $whListing['start_date'] = ['between',[$yesterdayBegin,$yesterdayBegin+86400-1]];
        $yesterdayPublish = EbayListing::alias('l')->where($whListing)->group('account_id')->column('count(*)','account_id');

        //组装返回数据
        $data = [];
        foreach ($accountIds as $aid) {
            $data[] = [
                'publish_quantity' => $yesterdayPublish[$aid]??0,
                'online_listing_quantity' => $yesterdayOnlineSpu[$aid]??0,
                'online_asin_quantity' => $yesterdayOnlineSku[$aid]??0,
                'dateline' => $dateline,
                'account_id' => $aid,
                'channel_id' => 1,
                'seller_id' => $accountSellerIds[$aid]??0,
                'site' => 0,
            ];
        }
        return $data;

    }


}
