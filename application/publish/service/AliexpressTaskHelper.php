<?php
/**
 * Created by PhpStorm.
 * User: XPDN
 * Date: 2017/5/13
 * Time: 17:51
 * Desc: Task专用Server
 */

namespace app\publish\service;

use aliexpress\operation\Product;
use app\common\exception\JsonErrorException;
use app\common\exception\QueueException;
use app\common\exception\TaskException;
use app\common\model\aliexpress\AliexpressAccountBrand;
use app\common\model\aliexpress\AliexpressAccountCategoryPower;
use app\common\model\aliexpress\AliexpressCategory;
use app\common\model\aliexpress\AliexpressCategoryAttr;
use app\common\model\aliexpress\AliexpressCity;
use app\common\model\aliexpress\AliexpressFreightTemplate;
use app\common\model\aliexpress\AliexpressProduct;
use app\common\model\aliexpress\AliexpressProductGroup;
use app\common\model\aliexpress\AliexpressProductInfo;
use app\common\model\aliexpress\AliexpressProductSku;
use app\common\model\aliexpress\AliexpressProductTemplate;
use app\common\model\aliexpress\AliexpressPromiseTemplate;
use app\common\model\aliexpress\AliexpressPublishPlan;
use app\common\model\aliexpress\AliexpressSizeTemplate;
use app\common\service\Twitter;
use app\common\service\UniqueQueuer;
use app\listing\queue\AliexpressRsyncProductQueue;
use aliexpress\AliexpressApi;
use app\common\cache\Cache;
use service\aliexpress\operation\PostProduct;
use think\Db;
use erp\AbsServer;
use think\Exception;
use app\common\model\aliexpress\AliexpressCategoryAttrVal;
use app\goods\service\GoodsImage;
use think\exception\DbException;
use think\exception\ErrorException;
use think\exception\PDOException;
use app\common\service\ChannelAccountConst;
use app\report\queue\StatisticByPublishSpuQueue;
use app\common\service\CommonQueuer;
use app\goods\service\GoodsSkuMapService;
use app\publish\queue\AliexpressCategoryQueue;
use app\publish\queue\AliexpressCategoryAttrValQueue;
use app\common\model\aliexpress\AliexpressProductImage;
use app\publish\queue\AliexpressPublishImageQueue;
use app\publish\service\ExpressHelper;

/**
 * Class AliexpressTaskHelper
 * @package app\publish\service
 */
class AliexpressTaskHelper extends AbsServer
{
    protected $productTemplateModel;
    public static $product_image_type = [
            'product' => 1,
            'sku' => 2,
            'detail' => 3
    ];

    public function __construct()
    {
        $this->model  = new \app\common\model\aliexpress\AliexpressProduct;
        $this->productTemplateModel = new \app\common\model\aliexpress\AliexpressProductTemplate;
    }

    public function findAeProductById(array $account,$product_id)
    {
        if(empty($account) || empty($product_id))
        {
            throw new QueueException("同步失败:帐号信息或者产品id为空");
        }
        $param['module']='product';
        $param['class']='product';
        $param['action']='findaeproductbyid';
        $param['product_id']=$product_id;
        $params=array_merge($account,$param);
        $productDetail = AliexpressService::execute($params);


        if(isset($productDetail['success']) && $productDetail['success'])
        {

            $productId = $productDetail['productId'];

            $productData = [
                'account_id'=>$account['id'],
                'product_id'=>$productId,
                'product_status_type'=>isset($productDetail['productStatusType'])?$productDetail['productStatusType']:'',//平台产品状态
                'subject'=>isset($productDetail['subject'])?$productDetail['subject']:'',//平台产品标题
                'delivery_time'=>isset($productDetail['deliveryTime'])?$productDetail['deliveryTime']:0,//备货期限
                'category_id'=>isset($productDetail['categoryId'])?$productDetail['categoryId']:0,//分类ID
                'product_price'=>isset($productDetail['productPrice']) && $productDetail['productPrice'] ?$productDetail['productPrice']:0,//一口价
                'product_unit'=>isset($productDetail['productUnit'])?$productDetail['productUnit']:'',//商品单位
                'package_type'=>(isset($productDetail['packageType'])&&$productDetail['packageType'])?1:0,//是否打包销售
                'lot_num'=>isset($productDetail['lotNum'])?$productDetail['lotNum']:0,//每包件数
                'package_length'=>isset($productDetail['packageLength'])?$productDetail['packageLength']:0,//商品包装长度
                'package_width'=>isset($productDetail['packageWidth'])?$productDetail['packageWidth']:0,//商品包装宽度
                'package_height'=>isset($productDetail['packageHeight'])?$productDetail['packageHeight']:0,//商品包装高度
                'gross_weight'=>isset($productDetail['grossWeight'])?$productDetail['grossWeight']:0,//商品毛重
                'is_pack_sell'=>isset($productDetail['isPackSell'])&&$productDetail['isPackSell']?1:0,//是否自定义计重
                'base_unit'=>isset($productDetail['baseUnit'])?$productDetail['baseUnit']:0,//几件内不增加邮费
                'add_unit'=>isset($productDetail['addUnit'])?$productDetail['addUnit']:0,//每次增加的件数
                'add_weight'=>isset($productDetail['addWeight']) && $productDetail['addWeight'] ?$productDetail['addWeight']:0,//每次增加的重量
                'ws_valid_num'=>isset($productDetail['wsValidNum'])?$productDetail['wsValidNum']:0,//商品有效天数
                'bulk_order'=>isset($productDetail['bulkOrder'])?$productDetail['bulkOrder']:1,//批发最小数量
                'bulk_discount'=>isset($productDetail['bulkDiscount'])?$productDetail['bulkDiscount']:0,//折扣率
                'reduce_strategy'=>isset($productDetail['reduceStrategy'])?$productDetail['reduceStrategy']:2,//库存扣减策略
                'currency_code'=>isset($productDetail['currencyCode'])?$productDetail['currencyCode']:'USD',//货币单位
                'gmt_create'=>isset($productDetail['gmtCreate'])?strtotime($productDetail['gmtCreate']):0,//产品发布时间
                'gmt_modified'=>isset($productDetail['gmtModified'])?strtotime($productDetail['gmtModified']):0,//最后更新时间
                'ws_offline_date'=>isset($productDetail['wsOfflineDate'])?strtotime($productDetail['wsOfflineDate']):0,//下架时间
                'ws_display'=>isset($productDetail['wsDisplay'])?$productDetail['wsDisplay']:0,//下架原因
                'product_min_price'=>isset($productDetail['productMinPrice'])?$productDetail['productMinPrice']:(isset($productDetail['productPrice']) ? $productDetail['productPrice'] : '0.00'),//最小价格
                'product_max_price'=>isset($productDetail['productMaxPrice'])?$productDetail['productMaxPrice']:(isset($productDetail['productPrice']) ? $productDetail['productPrice'] : '0.00'),//最大价格
                'promise_template_id'=>isset($productDetail['promiseTemplateId'])?$productDetail['promiseTemplateId']:'',//服务模板
                'sizechart_id'=>isset($productDetail['sizechartId'])?$productDetail['sizechartId']:0,//尺码模板
                'freight_template_id'=>isset($productDetail['freightTemplateId'])?$productDetail['freightTemplateId']:'',//运费模板
                'owner_member_seq'=>isset($productDetail['ownerMemberSeq'])?$productDetail['ownerMemberSeq']:'',//商品所属人loginId
                'owner_member_id'=>isset($productDetail['ownerMemberId'])?$productDetail['ownerMemberId']:'',//商品所属人Seq
                'imageurls'=>isset($productDetail['imageURLs'])?$productDetail['imageURLs']:'',//图片地址
                'group_id'=>(isset($productDetail['groupIds'])&&!empty($productDetail['groupIds']))?json_encode($productDetail['groupIds']['number']):'[]',
                'coupon_start_date'=>isset($productDetail['couponStartDate'])?strtotime($productDetail['couponStartDate']):0,//卡券商品开始有效期
                'coupon_end_date'=>isset($productDetail['couponEndDate'])?strtotime($productDetail['couponEndDate']):0,//卡券商品结束有效期
                'src'=>isset($productDetail['src']) ? $productDetail['src'] : '',//产品类型
                'is_image_dynamic'=>isset($productDetail['isImageDynamic'])?$productDetail['isImageDynamic']:'',//是否是动态图产品
                'status'=>2,
                'aeop_national_quote_configuration'=>isset($productDetail['aeopNationalQuoteConfiguration']['configurationData'])?$productDetail['aeopNationalQuoteConfiguration']['configurationData']:"[]",
                'quote_config_status'=>isset($productDetail['aeopNationalQuoteConfiguration']['configurationData'])?1:0,
                'configuration_type'=>isset($productDetail['aeopNationalQuoteConfiguration']['configurationType'])?$productDetail['aeopNationalQuoteConfiguration']['configurationType']:'',
            ];


            if($productData['add_weight'] == 'null') {
                unset($productData['add_weight']);
            }

            $productInfoData = [
                'product_id'=>isset($productDetail['productId'])?$productDetail['productId']:'',
                'detail'=>isset($productDetail['detail'])?$productDetail['detail']:'',
                'mobile_detail'=>isset($productDetail['mobileDetail'])?$productDetail['mobileDetail']:'',
                'product_attr'=>isset($productDetail['aeopAeProductPropertys'])?json_encode($productDetail['aeopAeProductPropertys']['aeopAeProductProperty']):'[]',
                'multimedia'=>isset($productDetail['aeopAEMultimedia'])?$productDetail['aeopAEMultimedia']:'[]',
            ];

            if(isset($productDetail['aeopAeProductSKUs']) && !empty($productDetail['aeopAeProductSKUs']))
            {
                foreach($productDetail['aeopAeProductSKUs']['aeopAeProductSku'] as $sku)
                {


                    $productSkuData[] = [
                        'product_id'=>$productId,
                        'sku_price'=>isset($sku['skuPrice'])?$sku['skuPrice']:'',
                        'sku_code'=>isset($sku['skuCode'])?$sku['skuCode']:'',
                        'sku_stock'=>isset($sku['skuStock'])?$sku['skuStock']:'',
                        'ipm_sku_stock'=>isset($sku['ipmSkuStock'])?$sku['ipmSkuStock']:'',
                        'merchant_sku_id'=>isset($sku['id'])?$sku['id']:'',
                        'currency_code'=>isset($sku['currencyCode'])?$sku['currencyCode']:'',
                        'sku_attr'=>isset($sku['aeopSKUPropertyList']['aeopSkuProperty'])?json_encode($sku['aeopSKUPropertyList']['aeopSkuProperty']):'[]',
                    ];
                }
            }
            $this->updateAliexpressProduct($productId,$productData,$productInfoData,$productSkuData);
        }else{
            if(isset($productDetail['error_message']))
            {
                throw new QueueException("同步失败:".$productDetail['error_message']);
            }
        }
    }

    /**
     * 保存商品信息
     * @param array $productData
     * @param array $productInfoData
     * @param array $productSkuData
     */
    public function updateAliexpressProduct($product_Id,array $productData,array $productInfoData,array $productSkuData)
    {
        $productModel       = new AliexpressProduct();
        $id = 0;
        if($cache=Cache::store('AliexpressRsyncListing')->getProductCache($productData['account_id'],$product_Id))
        {
            $id = $cache['id'];
            $objProduct = $productModel->where(['id'=>$cache['id']])->find();
            $productModel->updateProduct($objProduct,$productData,$productInfoData,$productSkuData);
        }else{
            if($objProduct = $productModel->field('id')->where('product_id',$product_Id)->find())
            {
                $id = $objProduct['id'];
                $productModel->updateProduct($objProduct,$productData,$productInfoData,$productSkuData);
            }else{
                $id = abs(Twitter::instance()->nextId(4,$productData['account_id']));
                $productData['id'] = $id;
                $productModel->addProduct($productData,$productInfoData,$productSkuData);
            }
        }

        $cache=[
            'id'=>$id,
            'gmt_modified'=>$productData['gmt_modified'],
        ];
        Cache::store('AliexpressRsyncListing')->setProductCache($productData['account_id'], $product_Id, $cache);
    }

    private function manage_data($data)
    {
        $return=[];
        foreach($data as $k=>$v)
        {
            $name = snake($k);
            $return[$name]=$v;
        }
        return $return;
    }

    public function findaeproductdetailmodulelistbyqurey($config,$page=1)
    {
        $param['module']='product';
        $param['class']='product';
        $param['module_status']='approved';
        $param['action']='findaeproductdetailmodulelistbyqurey';
        $param['page_index']=$page;
        $params = array_merge($config,$param);
        $queryResponse = AliexpressService::execute($params);
        $model = new AliexpressProductTemplate();
        if(isset($queryResponse['success']) && $queryResponse['success'])
        {
            $currentPage= $queryResponse['currentPage']  ;
            $totalPage = $queryResponse['totalPage']  ;
            $aeopDetailModuleList = $queryResponse['aeopDetailModuleList']['aeopdetailmodulelist']  ;
            foreach($aeopDetailModuleList as $list)
            {
                $data = $this->manage_data($list);
                $data['account_id']=$config['id'];
                if($model->check(['id'=>$list['id']]))
                {
                    $model->allowField(true)->save($data,['id'=>$list['id']]);
                }else{
                    $model->allowField(true)->save($data);
                }
            }

            if($currentPage!=$totalPage)
            {
                $this->findaeproductdetailmodulelistbyqurey($config, $currentPage+1);
            }
        }else{
            if(isset($queryResponse['error_code']))
            {
                throw new TaskException("帐号:[".$config['code']."]".$queryResponse['error_message']);
            }
        }
    }


    /**
     * 获取当前会员的服务模板
     * @param array $config
     * @param model $attributeModel attributeModel模型
     */
    public function  getAePromise($config)
    {

        $param['module']='product';
        $param['class']='product';
        $param['template_id']=-1;
        $param['action']='querypromisetemplatebyid';
        $params = array_merge($config,$param);
        $arr = AliexpressService::execute($params);

        if(is_array($arr) && isset($arr['templateList']))
        {
            Db::startTrans();
            try{
                AliexpressPromiseTemplate::where(['account_id'=>$config['id']])->delete();
                $arrData = [];
                $items = $arr['templateList']['templatelist'];
                foreach ($items as $v)
                {

                    $data=[
                        'account_id'=>$config['id'],
                        'template_id'=>$v['id'],
                        'template_name'=>$v['name'],
                    ];
                    $where=[
                        'account_id'=>['=',$config['id']],
                        'template_id'=>['=',$v['id']],
                        'template_name'=>['=',$v['name']]
                    ];
                    if(AliexpressPromiseTemplate::where($where)->find())
                    {
                        AliexpressPromiseTemplate::where($where)->update($data);
                    }else{
                        AliexpressPromiseTemplate::insert($data);
                    }
                }
                Db::commit();
                $data = (new AliexpressPromiseTemplate())->where(['account_id'=>$config['id']])->select();
                return ['message'=>'获取成功','result'=>true,'data'=>$data];
            }catch (\Exception $exp){
                Db::rollback();
                throw new JsonErrorException($exp->getMessage());
            }
        }else{
            return ['message'=>'获取失败,原因:'.$arr['error_message'],'result'=>false,'data'=>[]];
        }

    }

    /**
     * 获取当前会员的运费模板
     * @param array $config
     * @param model $attributeModel attributeModel模型
     */
    public function  getAeTransport($config)
    {

        $param['module']='product';
        $param['class']='product';
        $param['action']='listfreighttemplate';
        $params = array_merge($config,$param);
        $arr = AliexpressService::execute($params);
        if(isset($arr['resultSuccess']) && $arr['resultSuccess'] && isset($arr['aeopFreightTemplateDTOList']) && $arr['aeopFreightTemplateDTOList'])
        {
            Db::startTrans();
            try{
                AliexpressFreightTemplate::where(['account_id'=>$config['id']])->delete();
                $items = $arr['aeopFreightTemplateDTOList']['aeopfreighttemplatedtolist'];
                foreach ( $items as $v)
                {
                    $where=[
                        'template_id'=>['=',$v['templateId']],
                        'account_id'=>['=',$config['id']],
                    ];
                    $data=[
                        'template_id'=>$v['templateId'],
                        'account_id'=>$config['id'],
                        'template_name'=>$v['templateName'],
                        'is_default'=>(int)$v['isDefault']
                    ];

                    if($has=AliexpressFreightTemplate::where($where)->find())
                    {
                        AliexpressFreightTemplate::where($where)->update($data);
                    }else{
                        (new AliexpressFreightTemplate)->save($data);
                    }
                }
                Db::commit();
                $data = (new AliexpressFreightTemplate())->where(['account_id'=>$config['id']])->select();
                return ['message'=>'获取成功','result'=>true,'data'=>$data];
            }catch (PDOException $exp){
                Db::rollback();
                throw new JsonErrorException($exp->getMessage());
            }
        }else{
            return ['message'=>'获取失败,原因:'.$arr['error_message'],'result'=>false,'data'=>[]];
        }
    }

    /**
     * 获取当前会员的产品分组
     * @param array $config
     * @param model $attributeModel attributeModel模型
     */
    public function  getAeGroups($config)
    {

        $param['module']='product';
        $param['class']='product';
        $param['action']='productgroups';
        $params = array_merge($config,$param);
        $arr = AliexpressService::execute($params);

        if(is_array($arr) && isset($arr['targetList']))
        {
            Db::startTrans();
            try{
                AliexpressProductGroup::where(['account_id'=>$config['id']])->delete();
                $groups = $arr['targetList']['aeopAeProductTreeGroup'];
                if($groups)
                {
                    foreach ($groups as $group)
                    {
                        if(isset($group['childGroupList']) && $group['childGroupList'])
                        {
                            $childGroups = $group['childGroupList']['aeopAeProductChildGroup'];
                            foreach ($childGroups as $child)
                            {
                                $data=[
                                    'account_id'=>$config['id'],
                                    'group_id'=>$child['groupId'],
                                    'group_pid'=>$group['groupId'],
                                    'group_name'=>$child['groupName'],
                                ];
                                $where=[
                                    'account_id'=>['=',$config['id']],
                                    'group_id'=>['=',$child['groupId']]
                                ];
                                if(AliexpressProductGroup::where($where)->find())
                                {
                                    AliexpressProductGroup::where($where)->update($data);
                                }else{
                                    AliexpressProductGroup::insert($data);
                                }
                            }
                        }

                        $map=[
                            'account_id'=>['=',$config['id']],
                            'group_id'=>['=',$group['groupId']]
                        ];
                        $data=[];
                        $data=[
                            'account_id'=>$config['id'],
                            'group_id'=>$group['groupId'],
                            'group_pid'=>0,
                            'group_name'=>$group['groupName'],
                        ];


                        if(AliexpressProductGroup::where($map)->find())
                        {
                            AliexpressProductGroup::where($map)->update($data);
                        }else{
                            (new AliexpressProductGroup())
                                ->allowField(true)->isUpdate(false)
                                ->save($data);
                        }
                    }
                }
                Db::commit();
                $data = (new AliexpressProductGroup())->where(['account_id'=>$config['id']])->select();
                return ['result'=>true,'message'=>'获取成功','data'=>$data];
            }catch (\Exception $exp){
                Db::rollback();
                throw new JsonErrorException("获取失败");
            }
        }else{
            return ['result'=>false,'message'=>'获取失败,原因:'.$arr['error_message'],'data'=>[]];
        }
    }
    /**
     * 获取分类id对应的属性
     * @param array $config
     * @param model $attributeModel attributeModel模型
     * @param int $category_id category_id
     */
    public function  getAllProvince($config)
    {

        try{
            $config = [
                'id'            => $config['id'],
                'client_id'            => $config['client_id'],
                'client_secret'     => $config['client_secret'],
                'accessToken'    => $config['access_token'],
                'refreshtoken'      =>  @$config['refresh_token'],
            ];

            $service =AliexpressApi::instance($config)->loader('AeCategory');

            $response = $service->getAllProvince();

            if(isset($response['isSuccess']) && isset($response['result']))
            {
                $items = $response['result'];
                if($items)
                {
                    foreach($items as $item)
                    {
                        $areaId = $item['areaId'];
                        $data=[
                            'name'=>$item['cnDiplayName'],
                            'pinyin_name'=>$item['pyDiplayName'],
                            'area_id'=>$areaId
                        ];
                        $model = new AliexpressCity();
                        if($has = $model->where(['area_id'=>$areaId])->find())
                        {
                            $model->save($data,['id'=>$has['id']]);
                        }else{
                            $model->save($data);
                        }
                    }
                }
                return ['result'=>true,'message'=>'获取成功'];
            }else{
                return ['result'=>false,'message'=>'获取失败'];
            }
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    public function getNextLevelAddressData($config,$areaId,$parent_id)
    {
        try{
            $config = [
                'id'            => $config['id'],
                'client_id'            => $config['client_id'],
                'client_secret'     => $config['client_secret'],
                'accessToken'    => $config['access_token'],
                'access_token'    => $config['access_token'],
                'refresh_token'    => $config['refresh_token'],
                'refreshtoken'      =>  @$config['refresh_token'],
            ];

            $service =AliexpressApi::instance($config)->loader('AeCategory');
            $response = $service->getNextLevelAddressData($areaId);
            if(isset($response['isSuccess']) && isset($response['result']))
            {
                $items = $response['result'];
                if($items)
                {
                    foreach($items as $item)
                    {
                        $areaId = $item['areaId'];
                        $data=[
                            'name'=>$item['cnDiplayName'],
                            'pinyin_name'=>$item['pyDiplayName'],
                            'area_id'=>$areaId,
                            'parent_id'=>$parent_id,
                        ];
                        $model = new AliexpressCity();
                        if($has = $model->where(['area_id'=>$areaId])->find())
                        {
                            $model->save($data,['id'=>$has['id']]);
                            $insert_id = $has['id'];
                        }else {
                            $model->save($data);
                            $insert_id = $model->getLastInsID();
                        }
                        self::getNextLevelAddressData($config,$areaId,$insert_id);
                    }
                }
            }
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }
    private static function  saveNextLevelAddressData($items,$parent_id,$config)
    {
        if($items)
        {
            foreach($items as $item)
            {
                $areaId = $item['areaId'];
                $data=[
                    'name'=>$item['cnDiplayName'],
                    'pinyin_name'=>$item['pyDiplayName'],
                    'area_id'=>$areaId,
                    'parent_id'=>$parent_id,
                ];
                $model = new AliexpressCity();
                $model->save($data);
                $parent_id = $model->getLastInsID();
//                $has = $model->where(['area_id'=>$areaId])->find();
//                if(empty($has))
//                {
//                    $model->save($data,['id'=>$has['id']]);
//                    $parent_id = $has['id'];
//                }else {
//
//                }
                (new  self())->getNextLevelAddressData($config,$areaId,$parent_id);
            }
        }
    }
    public function sizeModelIsRequiredForPostCat($account,$cate_id)
    {
        $param['module']='product';
        $param['class']='Category';
        $param['action']='sizemodelsrequiredforpostcat';
        $param['cate_id']=$cate_id;
        $params = array_merge($account,$param);
        $response = AliexpressService::execute($params);
        return $response;
    }
    /**
     * 获取分类id对应的属性
     * @param array $config
     * @param model $attributeModel attributeModel模型
     * @param int $category_id category_id
     */
    public function  getAeAttribute($account,AliexpressCategoryAttr $attributeModel,$category_id,$parent_attrvalue_list="",$parent_attr_id=0)
    {

        try{

            $param['module']='product';
            $param['class']='Category';
            $param['action']='getallchildattributesresult';
            $param['cate_id']=$category_id;
            if($parent_attrvalue_list){
                $param['parent_attrvalue_list']=$parent_attrvalue_list;
            }
            $params = array_merge($account,$param);
            $response = AliexpressService::execute($params);

            if(isset($response['success']) && isset($response['attributes']))
            {
                $attributeModel = new AliexpressCategoryAttr();

                $attributes = isset($response['attributes']['aeopAttributeDto'])?$response['attributes']['aeopAttributeDto']:[];

                if($attributes)
                {
                    foreach($attributes as $attribute)
                    {
                        $attribute['category_id'] = $category_id;
                        $attribute['units']= isset($attribute['units'])?$attribute['units']:[];//判断属性单位是否存在
                        $attr_id = $attribute['id'];

                        $data = $this->managerAttribute($attribute,$attr_id,$category_id);

                        $data['parent_attr_id'] = $parent_attr_id;

                        if($attributeModel->where(['category_id'=>$category_id,'id'=>$attribute['id']])->find())
                        {
                            $attributeModel->update($data,['category_id'=>$category_id,'id'=>$attribute['id']]);
                        }else{
                            $attributeModel->insertGetId($data);
                        }
                        if(isset($attribute['values']['aeopAttrValueDto']) && $attribute['values']['aeopAttrValueDto'] && $attr_id!=2) {
                            //获取二级
                            $aeopAttrValueDto = $attribute['values']['aeopAttrValueDto'];
                            foreach ($aeopAttrValueDto as $aeop){
                                $parent_attrvalue_list=$attr_id."=".$aeop['id'];
                                $this->getAeAttribute($account,$attributeModel,$category_id,$parent_attrvalue_list,$aeop['id']);
                            }
                        }
                    }
                }
                return ['result'=>true,'message'=>'获取成功'];
            }else{
                return ['result'=>false,'message'=>'获取失败'];
            }
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }
    private function getChildAttributes($account,$attributeModel,$category_id,$parent_attrvalue_list){
        $param['module']='product';
        $param['class']='Category';
        $param['action']='getallchildattributesresult';
        $param['cate_id']=$category_id;
        if($parent_attrvalue_list){
            $param['parent_attrvalue_list']=$parent_attrvalue_list;
        }
        $params = array_merge($account,$param);
        $response = AliexpressService::execute($params);
        dump($parent_attrvalue_list);
        dump($response);
    }
    /**
     * 获取分类id对应的品牌属性
     * @param array $config
     * @param model $attributeModel attributeModel模型
     * @param int $category_id category_id
     */
    public function  getAeBrandAttribute($account,$category)
    {

        try{
            $param['module']='product';
            $param['class']='Category';
            $param['action']='getchildattributesresultbypostcateidandpath';
            $param['cate_id']=$category['category_id'];
            //$param['path']="2=".$value;
            $params = array_merge($account,$param);
            $response = AliexpressService::execute($params);

            if(!isset($response['success'])) {
                return ['status' => false, 'message' => $response['error_message']];
            }

            if(isset($response['attributes']) && $response['attributes'])
            {

                $attributes = isset($response['attributes']['aeopAttributeDto'])?$response['attributes']['aeopAttributeDto']:[];

                if($attributes)
                {
                    $model = new AliexpressAccountBrand();
                    foreach($attributes as $attribute)
                    {

                        $where=[
                            'account_id'=>['=',$account['id']],
                            'category_id'=>['=',$category['category_id']],
                        ];

                        if($attribute['id']==2)
                        {

                            //无品牌,则删除之前绑定的品牌
                            if(!isset($attribute['values']['aeopAttrValueDto']) || empty($attribute['values']['aeopAttrValueDto'])) {

                                $model->where($where)->delete();
                                return ['status' => false, 'message' => '先在店铺后台设置账号,之后再erp获取最新账号'];
                            }

                            $rows = $attribute['values']['aeopAttrValueDto'];
                            if($rows){
                                $sets=[];
                                foreach ($rows as $row){
                                    $set=[
                                        'account_id'=>$account['id'],
                                        'category_id'=>$category['category_id'],
                                        'attr_value_id'=>$row['id'],
                                        'create_time'=>time(),
                                        'update_time'=>time(),
                                    ];
                                    $sets[]=$set;
                                }

                                Db::startTrans();
                                try{
                                    $model->where($where)->delete();
                                    $model->allowField(true)->saveAll($sets);
                                    Db::commit();
                                }catch (PDOException $exp){
                                    Db::rollback();
                                    throw new Exception($exp->getMessage());
                                }
                            }
                        }
                    }
                }
            }

            return ['status' => true, 'message' => '获取最新品牌成功'];
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }
    /**
     * 处理属性
     * @param type $attribute
     * @return boolean
     */
    public function managerAttribute($attribute=[],$attr_id=0,$category_id)
    {
        if(!is_array($attribute))
        {
            return false;
        }else{

            $return=[];
            foreach ($attribute as $name => $attr)
            {
                if($name=='values')
                {
                    $list_val  = $this->managerAttributeValue($attr['aeopAttrValueDto'],$attr_id,$category_id);
                    $return['list_val']=$list_val;
                }elseif($name=='names'){
                    $names = json_decode($attr,true);
                    $return['names_zh']=$names['zh'];
                    $return['names_en']=$names['en'];
                }elseif ($name=='units') {
                    $return['units']= json_encode($attr);
                }else{
                    $name = snake($name, '_');
                    $return[$name]=$attr;
                }

            }
            return $return;
        }
    }
    /**
     * 处理属性值
     * @param type $value
     */
    public function  managerAttributeValue($value=[],$attr_id=0,$category_id=0)
    {
        $list_val='';
        if(!is_array($value))
        {
            return $list_val;
        }else{

            foreach ($value as $name => $v)
            {
                $names = json_decode($v['names'],true);
                $attr_val=[
                    'id'=>$v['id'],
                    'name_zh'=>$names['zh'],
                    'name_en'=>$names['en'],
                    'attr_id'=>$attr_id,
                    'category_id'=>$category_id,
                ];

                //写入到缓存中
                Cache::handler()->hSet('AliexpressCategoryAttrVal', $v['id'], json_encode($attr_val));
                (new UniqueQueuer(AliexpressCategoryAttrValQueue::class))->push($v['id']);
                $list_val=$list_val.','.$v['id'];
            }
            return substr($list_val, 1);
        }
    }

    /**
     * 获取指定分类和子分类
     * @param array $config 账号信息
     * @param model $AliexpressCategory AliexpressCategory模型
     * @param int $cateId 分类id
     */
    public function  getAeCategory($account,$cateId=0)
    {
        try{
            $param['module']='product';
            $param['class']='Category';
            $param['action']='getchildrenpostcategorybyid';
            $param['cate_id']=$cateId;
            $params = array_merge($account,$param);
            $response = AliexpressService::execute($params);

            if(isset($response['success']) && isset($response['aeopPostCategoryList']))
            {
                $aeopPostCategoryList = isset($response['aeopPostCategoryList']['aeopPostCategoryDto'])?$response['aeopPostCategoryList']['aeopPostCategoryDto']:[];

                if($aeopPostCategoryList)
                {
                    $model = new AliexpressCategory();
                    foreach($aeopPostCategoryList as $category)
                    {

                        $cate['category_id']=$category['id'];
                        $cate['category_pid']=$cateId;
                        $cate['category_level']=$category['level'];
                        $names = json_decode($category['names'],true);
                        $cate['category_name_zh']= isset($names['zh'])?$names['zh']:'';
                        $cate['category_isleaf']=$category['isleaf'];
                        $cate['category_name']= serialize($names);
                        $cate['account_id'] = $account['id'];

                        //写入到缓存中
                        Cache::handler()->hSet('AliexpressGetCategory', $category['id'], json_encode($cate));

                        (new UniqueQueuer(AliexpressCategoryQueue::class))->push($category['id']);
                        if($category['isleaf']==false) //非叶子节点
                        {
                            $this->getAeCategory($account, $category['id']);
                        }
                    }
                }
            }
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 整合刊登数据
     * @param type $jobs
     */
    public  function ManagerData($jobs)
    {
        foreach($jobs as $job)
        {
            $response = $this->postProduct($job);

            if(isset($response['success']) && $response['success'])
            {
                $update=[
                    'status'=>1,
                    'exec_time'=> time(),
                    'send_return'=>$response['success']
                ];
                $updateProduct=[
                    'product_id'=>$response['productId'],
                    'status'=>2,
                    'publish_time'=>time(),
                ];
                $productId=['product_id'=>$response['productId']];
            }else{
                $update=[
                    'status'=>-1,
                    'exec_time'=> time(),
                    'send_return'=> json_encode($response)
                ];

                $updateProduct=[
                    //'product_id'=>$response['productId'],
                    'status'=>4,
                    'publish_time'=>time(),
                ];
            }

            Db::startTrans();
            try {
                (new AliexpressPublishPlan())->where(['ap_id'=>$job['ap_id']])->update($update);
                (new AliexpressProduct())->where(['id'=>$job['ap_id']])->update($updateProduct);

                if(isset($response['success']) && $response['success']) //如果刊登成功則更新下列表
                {
                    (new AliexpressProductInfo())->where(['ali_product_id'=>$job['ap_id']])->update($productId);
                    (new AliexpressProductSku())->where(['ali_product_id'=>$job['ap_id']])->update($productId);
                }
                Db::commit();
            } catch (Exception $exp) {
                Db::rollback();
                throw new Exception($exp->getFile().$exp->getLine().$exp->getMessage());
            }
        }
    }

    public function setPublishedLog($product_id)
    {
        $log=[
            'type'=>6,
            'old_data'=>'刊登成功',
            'new_data'=>'刊登成功',
            'product_id'=>$product_id,
            'status'=>1,
            'run_time'=>time(),
        ];
    }

    /**
     * 刊登一个商品
     * @param $job
     *
     * @throws Exception
     *
     */
    public  function publishOneProduct($job)
    {

        try {

            $response = $this->postProduct($job);
        
            $productId=[];
            if(isset($response['isSuccess']) &&  isset($response['productId']) && $response['productId'])
            {


                $update=[
                    'status'=>1,
                    'exec_time'=> time(),
                    'send_return'=>json_encode($response),
                ];

                $product_id=$response['productId'];

                $updateProduct=[
                    'product_id'=>$product_id,
                    'status'=>2,
                    'product_status_type'=>1,
                    'publish_time'=>time(),
                ];
                $productId['product_id']=$product_id;

            }else{

                $update=[
                    'status'=>-1,
                    'exec_time'=> time(),
                    'send_return'=> json_encode($response)
                ];

                $updateProduct=[
                    'status'=>4,
                    'publish_time'=>time(),
                ];
            }


            Db::startTrans();
            try{

                (new AliexpressPublishPlan())->update($update, ['ap_id'=>$job['ap_id']]);
                (new AliexpressProduct())->update($updateProduct, ['id'=>$job['ap_id']]);

                if(!empty($productId)) //如果刊登成功則更新下列表
                {
                    (new AliexpressProductInfo())->update($productId, ['ali_product_id'=>$job['ap_id']]);
                    (new AliexpressProductSku())->update($productId, ['ali_product_id'=>$job['ap_id']]);

                    $this->spuStatistics($job['product']);
                }
                Db::commit();
                if (!empty($productId)) {
                    (new UniqueQueuer(AliexpressRsyncProductQueue::class))->push($product_id);

                    //同步每日刊登任务状态
                    //(new AliexpressProduct())->sycEveryDayPublish($productId);
                }
            }catch (PDOException $exp){
                Db::rollback();
                throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
            }catch (DbException $exp){
                Db::rollback();
                throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
            }catch (\Exception $exp){
                Db::rollback();
                throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
            }
        } catch (Exception $exp) {
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }catch (\Throwable $exp){
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }



    /**
     *刊登成功后push到"SPU上架实时统计队列"
     *
     */
    public function spuStatistics($product)
    {

        $param = [
            'channel_id' => ChannelAccountConst::channel_aliExpress,
            'account_id' => $product['account_id'],
            'shelf_id' => $product['salesperson_id'],
            'goods_id' => $product['goods_id'],
            'times'    => 1, //实时=1
            'quantity' => 0,
            'dateline' => time()
        ];

        (new CommonQueuer(StatisticByPublishSpuQueue::class))->push($param);
    }


    /**
     * 将图片路径转换成对应的账号路径
     * @param type $images
     * @param type $code
     * @return type
     */
    public  function translateImgToLoalPath($images,$code)
    {
        try{

            if(is_array($images))
            {
                foreach ($images as $key => &$img)
                {
                    if(false === strpos($img,'http'))
                    {
                        $img =CommonService::getUploadPath().$img;
                    }else{
                        $img=$img;
                    }
                }
                return $images;
            }else{

                $images = str_replace(config('picture_base_url'),'',$images);
                //没有找到
                if(false === strpos($images,'http'))
                {
                    return CommonService::getUploadPath().$images;
                }else{
                    return $images;
                }
            }
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * 将图片路径转换成对应的账号路径
     * @param type $images
     * @param type $code
     * @return type
     */
    public  function translateImgToFullPath($images,$code)
    {
        try{
            if(is_array($images))
            {
                foreach ($images as $key => &$img)
                {
                    $img = str_replace(config('picture_base_url'),'',$img);

                    if(strpos($img,'http')===false)
                    {
                        //$img = GoodsImage::getThumbPath($img, 0,0,$code,true);
                        $img = GoodsImage::getThumbPath($img, 1001,1001,$code, true);
                    }else{
                        $img = $img;
                    }
                }
                return $images;
            }else{
                $images = str_replace(config('picture_base_url'),'',$images);
                if(strpos($images,'http')===false)
                {
                    return GoodsImage::getThumbPath($images, 1001,1001,$code, true);
                    //return GoodsImage::getThumbPath($images, 0,0,$code,true);
                }else{
                    return $images;
                }
            }
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * 组装刊登资料数据
     * @param type $job
     * @return type
     */
    public  function postProduct($job)
    {

        try{
            $product = (new AliexpressProduct())->with(['productSku'=>function($query){$query->order('id ASC')->order('sku_price ASC');},'productInfo','account'])->where(['id'=>$job['ap_id']])->find();


            if($productImage = $product->getData('imageurls'))
            {
                $product['images']=$productImage;
            }else{
                $product['images']=[];
            }

            $product = $product->toArray();
            $account_id = $product['account_id'];

            //$account = Cache::store('AliexpressAccount')->getAccountById($account_id);

            $account = $product['account'];

            $code = $product['account']['code'];

            //商品图片
            $image_type = [
                'ali_product_id' => $job['ap_id'],
                'type' => self::$product_image_type['product']
            ];

            $imageURLsResponse = $this->uploadImageURLs($account, $product['images'], $code, $image_type);
            if(isset($imageURLsResponse['result']) && $imageURLsResponse['result'])
            {
                $imageURLs = $imageURLsResponse['data'];

            }elseif(isset($imageURLsResponse['error_message'])){
                return ['success'=>false,'error_message'=>'商品图片提交失败,原因:'.$imageURLsResponse['error_message']];
            }else{
                return ['success'=>false,'error_message'=>'图片提交失败'];
            }

            //处理描述详情里面的图片
            if(isset($product['product_info']['detail']) && $product['product_info']['detail'])
            {
                $detail=$product['product_info']['detail'];

                $image_type['type'] = self::$product_image_type['detail'];
                $detailResponse = $this->managerDetail($detail,$account,$code, $image_type);
                if(isset($detailResponse['result']) && $detailResponse['result']===true){
                    $detail = $detailResponse['data'];
                }else{
                    $detailResponse['error_message'] = '详情图片'.$detailResponse['error_message'];
                    return $detailResponse;
                }
            }else{
                $detail='';
            }

            if($product['relation_template_id']>0 || $product['custom_template_id']>0)
            {
                $relationResponse = $this->combineRelationCustomDescription($product,$detail,$account,$code);

                if(isset($relationResponse['result']) && $relationResponse['result']===true){
                    $detail = $relationResponse['data'];
                }else{
                    $relationResponse['error_message'] = '模板图片'.$relationResponse['error_message'];
                    return $relationResponse;
                }
            }else{
                $detail=$detail;
            }
            
            $mobileDetail =$product['product_info']['mobile_detail']?$this->managerMobileDetail($product['product_info']['mobile_detail'],$account,$code):'';

            $aeopAeProductSKUs=[];
            //商品sku
            $skus = isset($product['product_sku'])?$product['product_sku']:[];
            if($skus){
                $skus = $this->sortSkuAttrOrder($product['category_id'],$skus);
            }

            $product_attr = $product['product_info']['product_attr'];
            if($product_attr) {
                $expressHelperService = new ExpressHelper();
                $product_attr = json_decode($product_attr,true);

                foreach ($product_attr as $key => $val) {
                    if(isset($val['attrName']) && $val['attrName']) {
                        $product_attr[$key]['attrName'] = $expressHelperService->checkAttribute($val['attrName']);
                    }

                    if(isset($val['attrValue']) && $val['attrValue']) {
                        $product_attr[$key]['attrValue'] = $expressHelperService->checkAttribute($val['attrValue']);
                    }
                }

                $product_attr = json_encode($product_attr);
            }


            if($skus)
            {
                $goodsSkuMapService = new GoodsSkuMapService();
                $productSkuModel = new AliexpressProductSku();

                foreach ($skus as $k=>$sku)
                {

                    $sku_code = $sku['sku_code'];

                    $createRandSkuArray=[
                        'sku_code'=>$sku['sku_code'],
                        'channel_id'=>4,
                        'account_id'=>$account_id,
                        'is_virtual_send' => isset($product['virtual_send']) ? $product['virtual_send'] : 0,
                    ];

                    if(isset($sku['combine_sku']) && !empty($sku['combine_sku']))
                    {
                        $createRandSkuArray['combine_sku']=$sku['combine_sku'];
                        $newSku = $goodsSkuMapService->addSkuCodeWithQuantity($createRandSkuArray,$product['publisher_id']);
                    }else{
                        $newSku = $goodsSkuMapService->addSku($createRandSkuArray,$product['publisher_id']);
                    }

                    if(isset($newSku['sku_code']) && $newSku['sku_code']) {
                        $sku_code = $newSku['sku_code'];
                        $productSkuModel->update(['sku_code' => $sku_code], ['id' => $sku['id']]);
                    }

                    $aeopAeProductSKUs[$k]['skuPrice']=$sku['sku_price'];
                    $aeopAeProductSKUs[$k]['skuCode']=$sku_code;
                    $aeopAeProductSKUs[$k]['skuStock']=$sku['sku_stock']?true:false;
                    $aeopAeProductSKUs[$k]['ipmSkuStock']=$sku['ipm_sku_stock'];
                    $aeopAeProductSKUs[$k]['currencyCode']=$sku['currency_code'];
                    $skuAttrVal = json_decode($sku['sku_attr'],true);
                    if($skuAttrVal)
                    {
                        $aeopSKUProperty=[];
                        foreach ($skuAttrVal as $k1=>$val)
                        {
                            $aeopSKUProperty[$k1]['skuPropertyId']=$val['skuPropertyId'];
                            $aeopSKUProperty[$k1]['propertyValueId']=(int)$val['propertyValueId'];
                            if(isset($val['propertyValueDefinitionName']) && $val['propertyValueDefinitionName'])
                            {
                                $aeopSKUProperty[$k1]['propertyValueDefinitionName']=trim($val['propertyValueDefinitionName']);
                            }

                            if(isset($val['skuImage']) && $val['skuImage'])
                            {
                                $image_type['type'] = self::$product_image_type['sku'];
                                $skuImage = $this->uploadOneImage($account,$val['skuImage'],$code, $image_type);
                                if($skuImage)
                                {
                                    $aeopSKUProperty[$k1]['skuImage']=$skuImage;
                                }
                            }
                        }
                        $aeopAeProductSKUs[$k]['aeopSKUProperty']=$aeopSKUProperty;
                    }
                }
            }


            $post=[
                'subject'=>substr($product['subject'],0,128), //标题
                'detail'=>$detail, //详情描述
                'aeopAeProductSKUs'=>json_encode($aeopAeProductSKUs), //sku
                'aeopAeProductPropertys'=> $product_attr,//产品属性，以json格式进行封装后提交
                'categoryId'=>$product['category_id'], //分类id
                'imageURLs'=>$imageURLs, //产品的主图URL列表
            ];

            //备货期。取值范围:1-60;单位:天
            if($product['delivery_time'])
            {
                $post['deliveryTime'] = $product['delivery_time'];
            }

            //服务模板设置
            $post['promiseTemplateId'] = $product['promise_template_id'] ? $product['promise_template_id'] : 0;

            ////商品一口价
            if((float)$product['product_price'])
            {
                $post['productPrice'] = number_format($product['product_price'],2);
            }
            //运费模版ID
            if($product['freight_template_id'])
            {
                $post['freightTemplateId'] = $product['freight_template_id'];
            }
            //商品单位 (存储单位编号)
            if($product['product_unit'])
            {
                $post['productUnit'] = $product['product_unit'];
            }
            //打包销售: true 非打包销售:false,
            if($product['package_type'])
            {
                $post['packageType'] = 'true';
                //每包件数。 打包销售情况，lotNum>1,非打包销售情况,lotNum=1
                $post['lotNum']=$product['lot_num'];
            }else{
                $post['packageType'] = 'false';
                $post['lotNum']=1;
            }
            //包装长宽高
            if($product['package_length'])
            {
                $post['packageLength'] = $product['package_length'];
            }
            if($product['package_width'])
            {
                $post['packageWidth'] = $product['package_width'];
            }
            if($product['package_height'])
            {
                $post['packageHeight'] = $product['package_height'];
            }

            if($product['gross_weight'])
            {
                $post['grossWeight'] = $product['gross_weight'];
            }

            if($product['is_pack_sell'])
            {
                $post['isPackSell'] = 'true';
                $post['baseUnit'] = $product['base_unit'];
                $post['addUnit']=$product['add_unit'];
                $post['addWeight']=$product['add_weight'];
            }else{
                $post['isPackSell'] = 'false';
            }
            //商品有效天数。取值范围:1-30,单位天
            if($product['ws_valid_num'])
            {
                $post['wsValidNum']=$product['ws_valid_num'];
            }
            //批发最小数量，批发折扣,取值范围:1-99
            if($product['bulk_order'] && $product['bulk_discount'])
            {
                $post['bulkOrder']=$product['bulk_order'];
                $post['bulkDiscount']=$product['bulk_discount'];
            }
            //尺码表模版ID
            if($product['sizechart_id'])
            {
                $post['sizechartId']=$product['sizechart_id'];
            }
            //库存扣减策略，总共有2种：下单减库存(place_order_withhold)和支付减库存(payment_success_deduct)。
            if($product['reduce_strategy']==1)
            {
                $post['reduceStrategy']='place_order_withhold';
            }elseif($product['reduce_strategy']==2){
                $post['reduceStrategy']='payment_success_deduct';
            }
            //产品分组ID

            if($product['group_id']) {
                $post['groupId']=$product['group_id'];
            }

            ////货币单位
            if($product['currency_code'])
            {
                $post['currencyCode']=$product['currency_code'];
            }
            //
            if($mobileDetail)
            {
                $post['mobileDetail']=$mobileDetail;
            }
            ////卡券商品开始有效期
            if($product['coupon_start_date'])
            {
                $post['couponStartDate']=$product['coupon_start_date'];
            }
            //卡券商品结束有效期
            if($product['coupon_end_date'])
            {
                $post['couponEndDate']=$product['coupon_end_date'];
            }
            if(isset($product['quote_config_status']) && $product['quote_config_status']  && $product['configuration_type'])
            {
                if(isset($product['aeop_national_quote_configuration']) && $product['aeop_national_quote_configuration'])
                {
                    $post['aeopNationalQuoteConfiguration'] = $this->managerNationalQuoteConfiguration($product['aeop_national_quote_configuration'],$product['configuration_type']);
                }
            }

            $post['module']='product';
            $post['class']='product';
            $post['action']='postaeproduct';
            $post = array_merge($account,$post);

            $response = AliexpressService::execute(snakeArray($post));

            return $response;
        }catch (Exception $exp){
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }catch (\Throwable $exp){
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }


    /**
     * 组装刊登资料数据
     * @param type $job
     * @return type
     */
    public  function postProduct1($job)
    {

        try{
            $product = (new AliexpressProduct())->with(['productSku'=>function($query){$query->order('id ASC')->order('sku_price ASC');},'productInfo','account'])->where(['id'=>$job['ap_id']])->find();


            if($productImage = $product->getData('imageurls'))
            {
                $product['images']=$productImage;
            }else{
                $product['images']=[];
            }

            $product = $product->toArray();
            $account_id = $product['account_id'];

            $account = Cache::store('AliexpressAccount')->getAccountById($account_id);

            $account = $product['account'];

            $code = $product['account']['code'];

            $imageURLsResponse = $this->uploadImageURLs($account, $product['images'], $code, 0);

            if(isset($imageURLsResponse['result']) && $imageURLsResponse['result'])
            {
                $imageURLs = $imageURLsResponse['data'];

            }elseif(isset($imageURLsResponse['error_message'])){
                return ['success'=>false,'error_message'=>'商品图片提交失败,原因:'.$imageURLsResponse['error_message']];
            }else{
                return ['success'=>false,'error_message'=>'图片提交失败'];
            }

            $imageURLs = $product['images'];

            $marketImages = $product['product_info']['market_images'];
            if($marketImages) {
                $marketImages = \GuzzleHttp\json_decode($marketImages, true);
                $marketImageUrl = $marketImages['url'];
                $imageURLsResponse = $this->uploadImageURLs($account, $marketImageUrl, $code, 0);

                if(isset($imageURLsResponse['result']) && $imageURLsResponse['result'])
                {
                    $marketImageUrl = $imageURLsResponse['data'];

                }elseif(isset($imageURLsResponse['error_message'])){
                    return ['success'=>false,'error_message'=>'营销图片提交失败,原因:'.$imageURLsResponse['error_message']];
                }else{
                    return ['success'=>false,'error_message'=>'营销图片提提交失败'];
                }

                $marketImages['url'] = $marketImageUrl;

                $post['marketImages'] = json_encode($marketImages);
            }

            //处理描述详情里面的图片
            if(isset($product['product_info']['detail']) && $product['product_info']['detail'])
            {
                $detail=$product['product_info']['detail'];

                $detailResponse = $this->managerDetail($detail,$account,$code);
                if(isset($detailResponse['result']) && $detailResponse['result']===true){
                    $detail = $detailResponse['data'];
                }else{
                    $detailResponse['error_message'] = '详情图片'.$detailResponse['error_message'];
                    return $detailResponse;
                }
            }else{
                $detail='';
            }


            if($product['relation_template_id']>0 || $product['custom_template_id']>0)
            {
                $relationResponse = $this->combineRelationCustomDescription($product,$detail,$account,$code);

                if(isset($relationResponse['result']) && $relationResponse['result']===true){
                    $detail = $relationResponse['data'];
                }else{
                    $relationResponse['error_message'] = '模板图片'.$relationResponse['error_message'];
                    return $relationResponse;
                }
            }else{
                $detail=$detail;
            }

            $mobileDetail =$product['product_info']['mobile_detail']?$this->managerMobileDetail($product['product_info']['mobile_detail'],$account,$code):'';

            $category_id = $product['category_id'];
            $aeopAeProductSKUs=[];
            //商品sku
            $skus = isset($product['product_sku'])?$product['product_sku']:[];
            if($skus){
                $skus = $this->sortSkuAttrOrder($category_id,$skus);
            }

            if($skus)
            {
                $goodsSkuMapService = new GoodsSkuMapService();
                $productSkuModel = new AliexpressProductSku();

                foreach ($skus as $k=>$sku)
                {

                    $sku_code = $sku['sku_code'];

                    $createRandSkuArray=[
                        'sku_code'=>$sku['sku_code'],
                        'channel_id'=>4,
                        'account_id'=>$account_id,
                        'is_virtual_send' => isset($product['virtual_send']) ? $product['virtual_send'] : 0,
                    ];

                    if(isset($sku['combine_sku']) && !empty($sku['combine_sku']))
                    {
                        $createRandSkuArray['combine_sku']=$sku['combine_sku'];
                        $newSku = $goodsSkuMapService->addSkuCodeWithQuantity($createRandSkuArray,$product['publisher_id']);
                    }else{
                        $newSku = $goodsSkuMapService->addSku($createRandSkuArray,$product['publisher_id']);
                    }

                    if(isset($newSku['sku_code']) && $newSku['sku_code']) {
                        $sku_code = $newSku['sku_code'];
                        $productSkuModel->update(['sku_code' => $sku_code], ['id' => $sku['id']]);
                    }

                    $aeopAeProductSKUs[$k]['skuPrice']=$sku['sku_price'];
                    $aeopAeProductSKUs[$k]['skuCode']=$sku_code;
                    $aeopAeProductSKUs[$k]['skuStock']=$sku['sku_stock']?true:false;
                    $aeopAeProductSKUs[$k]['ipmSkuStock']=$sku['ipm_sku_stock'];
                    $aeopAeProductSKUs[$k]['currencyCode']=$sku['currency_code'];
                    $skuAttrVal = json_decode($sku['sku_attr'],true);
                    if($skuAttrVal)
                    {
                        $aeopSKUProperty=[];
                        foreach ($skuAttrVal as $k1=>$val)
                        {
                            $aeopSKUProperty[$k1]['skuPropertyId']=$val['skuPropertyId'];
                            $aeopSKUProperty[$k1]['propertyValueId']=(int)$val['propertyValueId'];
                            if(isset($val['propertyValueDefinitionName']) && $val['propertyValueDefinitionName'])
                            {
                                $aeopSKUProperty[$k1]['propertyValueDefinitionName']=trim($val['propertyValueDefinitionName']);
                            }

                            if(isset($val['skuImage']) && $val['skuImage'])
                            {
                                $skuImage = $val['skuImage'];
                                $skuImage = $this->uploadOneImage($account,$val['skuImage'],$code);
                                if($skuImage)
                                {
                                    $aeopSKUProperty[$k1]['skuImage']=$skuImage;
                                }
                            }
                        }
                        $aeopAeProductSKUs[$k]['aeopSKUProperty']=$aeopSKUProperty;
                    }
                }
            }


            $detailSourceList = ['locale' => true, 'mobile_detail' =>$mobileDetail, 'web_detail' => $detail];

            $subject = substr($product['subject'],0,128); //标题
            $subjectList = ['locale' => true, 'value' => $subject];

            $post=[
                'subjectList' => json_encode($subjectList),
                'detailSourceList' => json_encode($detailSourceList),
                'aeopAeProductSKUs'=>json_encode($aeopAeProductSKUs), //sku
                'aeopAeProductPropertys'=> $product['product_info']['product_attr'],//产品属性，以json格式进行封装后提交
                'categoryId'=>$product['category_id'], //分类id
                'imageURLs'=>$imageURLs, //产品的主图URL列表,
                'locale' => true,
            ];

            //备货期。取值范围:1-60;单位:天
            if($product['delivery_time'])
            {
                $post['deliveryTime'] = $product['delivery_time'];
            }

            //服务模板设置
            $post['promiseTemplateId'] = $product['promise_template_id'] ? $product['promise_template_id'] : 0;

            ////商品一口价
            if((float)$product['product_price'])
            {
                $post['productPrice'] = number_format($product['product_price'],2);
            }
            //运费模版ID
            if($product['freight_template_id'])
            {
                $post['freightTemplateId'] = $product['freight_template_id'];
            }
            //商品单位 (存储单位编号)
            if($product['product_unit'])
            {
                $post['productUnit'] = $product['product_unit'];
            }
            //打包销售: true 非打包销售:false,
            if($product['package_type'])
            {
                $post['packageType'] = 'true';
                //每包件数。 打包销售情况，lotNum>1,非打包销售情况,lotNum=1
                $post['lotNum']=$product['lot_num'];
            }else{
                $post['packageType'] = 'false';
                $post['lotNum']=1;
            }
            //包装长宽高
            if($product['package_length'])
            {
                $post['packageLength'] = $product['package_length'];
            }
            if($product['package_width'])
            {
                $post['packageWidth'] = $product['package_width'];
            }
            if($product['package_height'])
            {
                $post['packageHeight'] = $product['package_height'];
            }

            if($product['gross_weight'])
            {
                $post['grossWeight'] = $product['gross_weight'];
            }

            if($product['is_pack_sell'])
            {
                $post['isPackSell'] = 'true';
                $post['baseUnit'] = $product['base_unit'];
                $post['addUnit']=$product['add_unit'];
                $post['addWeight']=$product['add_weight'];
            }else{
                $post['isPackSell'] = 'false';
            }
            //商品有效天数。取值范围:1-30,单位天
            if($product['ws_valid_num'])
            {
                $post['wsValidNum']=$product['ws_valid_num'];
            }
            //批发最小数量，批发折扣,取值范围:1-99
            if($product['bulk_order'] && $product['bulk_discount'])
            {
                $post['bulkOrder']=$product['bulk_order'];
                $post['bulkDiscount']=$product['bulk_discount'];
            }
            //尺码表模版ID
            if($product['sizechart_id'])
            {
                $post['sizechartId']=$product['sizechart_id'];
            }
            //库存扣减策略，总共有2种：下单减库存(place_order_withhold)和支付减库存(payment_success_deduct)。
            if($product['reduce_strategy']==1)
            {
                $post['reduceStrategy']='place_order_withhold';
            }elseif($product['reduce_strategy']==2){
                $post['reduceStrategy']='payment_success_deduct';
            }

            //产品分组ID
            if(isset($product['group_id']) && is_numeric($product['group_id']))
            {
                $post['groupId']=$product['group_id'];
            }
            ////货币单位
            if($product['currency_code'])
            {
                $post['currencyCode']=$product['currency_code'];
            }
            //
            if($mobileDetail)
            {
                $post['mobileDetail']=$mobileDetail;
            }
            ////卡券商品开始有效期
            if($product['coupon_start_date'])
            {
                $post['couponStartDate']=$product['coupon_start_date'];
            }
            //卡券商品结束有效期
            if($product['coupon_end_date'])
            {
                $post['couponEndDate']=$product['coupon_end_date'];
            }
            if(isset($product['quote_config_status']) && $product['quote_config_status']  && $product['configuration_type'])
            {
                if(isset($product['aeop_national_quote_configuration']) && $product['aeop_national_quote_configuration'])
                {
                    $post['aeopNationalQuoteConfiguration'] = $this->managerNationalQuoteConfiguration($product['aeop_national_quote_configuration'],$product['configuration_type']);
                }
            }

            $post['module']='product';
            $post['class']='product';
            $post['action']='productpost';
            $post = array_merge($account,$post);
            $response = AliexpressService::execute(snakeArray($post));

            return $response;
        }catch (Exception $exp){
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }catch (\Throwable $exp){
            throw new QueueException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }


    /**
     * @param $category_id 分类id
     * @param $skus sku数组
     */
    private function sortSkuAttrOrder($category_id,$skus){
        if(empty($skus)){
            return [];
        }

        $where['category_id']=['=',$category_id];
        $where['sku']=['=',1];
        $aliexpressSkuAttrs=AliexpressCategoryAttr::where($where)->field('spec,id')->order('spec ASC')->select();

        if(empty($aliexpressSkuAttrs)){
            return $skus;
        }

        if(count($aliexpressSkuAttrs) == 1 && count($skus) > 1) {
            $skus = [reset($skus)];
        }

        foreach ($skus as &$sku){
            if($sku['sku_attr']){
                $sku['sku_attr']= $this->getSkuAttributesPosition($sku['sku_attr'],$aliexpressSkuAttrs);
            }
        }
        return $skus;
    }

    /**
     * 获取sku属性的排序位置
     * @param $attributes
     * @param $aliexpressSkuAttrs
     */
    private function getSkuAttributesPosition($attributes,$aliexpressSkuAttrs){
        $items = is_array($attributes)?$attributes:json_decode($attributes,true);
        if($items){
            foreach ($items as &$item){
                foreach ($aliexpressSkuAttrs as $attr){
                    if($attr['id']==$item['skuPropertyId']){
                        $item['spec']=$attr['spec'];
                    }
                }
            }
            $items = $this->arraySequence($items,'spec','SORT_ASC');
            return json_encode($items,true);
        }else{
            return '[]';
        }

    }
    public function arraySequence($array, $field, $sort = 'SORT_DESC')
    {
        $arrSort = array();
        foreach ($array as $uniqid => $row) {
            foreach ($row as $key => $value) {
                $arrSort[$key][$uniqid] = $value;
            }
        }
        array_multisort($arrSort[$field], constant($sort), $array);
        return $array;
    }

    private function getSortSkuAttributes($category_id,$attributes){
        $items = is_array($attributes)?$attributes:json_decode($attributes,true);

        $return=[];
        foreach ($items as $item){
            $k = $this->skuAttributesSort($category_id,$item);
            if(is_numeric($k)){
                $return[$k]=$item;
            }
        }
    }
    private function skuAttributesSort($category_id,$item){
        $where['category_id']=['=',$category_id];
        $where['sku']=['=',1];
        $aliexpressSkuAttrs=AliexpressCategoryAttr::where($where)->field('spec,id')->order('spec ASC')->select();
        if(!empty($aliexpressSkuAttrs)){
            foreach ($aliexpressSkuAttrs as $k=>$attr){
                if($attr['id']==$item['skuPropertyId']){
                    return $k;
                }
            }
        }
        return '';
    }
    /**
     * 整合商品分国家报价的配置
     */
    public function managerNationalQuoteConfiguration($configs,$type)
    {
        if (is_json($configs))
        {
            $configs = json_decode($configs,true);
        }

        $configuration['configurationType']=$type;


        //$configurationData=$configs;
        $configurationData = [];
        foreach ($configs as $config)
        {
            if(isset($config[$type]) && $config[$type]){
//                   if($config['symbol']){
//                       $config[$type]=-($config[$type]);
//                   }
                $configurationData[]= $config;
            }
//            $configurationData[]=[
//                'shiptoCountry'=>isset($config['shiptoCountry'])?$config['shiptoCountry']:$config['en_name'],
//                $type=>$config[$type],
//            ];

        }

        $configuration['configurationData']=json_encode($configurationData);

        return json_encode(snakeArray($configuration));
    }
    /**
     * 处理详情描述中的图片
     * @param $detail
     * @param $api
     * @param $code
     *
     * @return mixed
     */
    public function managerDetail(&$detail,$account,$code, $image_type)
    {
        try{
            if($detail)
            {
                $preg='/<img[\s\S]*?src\s*=\s*[\"|\'](.*?(jpg|jpeg|gif|png))[\"|\'][\s\S]*?>/';
                preg_match_all($preg,$detail,$match);

                if(count($match)>1 && !empty($match[1]))
                {
                    $images =implode(';',$this->replaceImagesSemicolon($match[1]));
                    $images = str_replace('https://img.rondaful.com','',$images);
                    $new_iamges = $this->uploadImageURLs($account,$images,$code, $image_type);
                    if(isset($new_iamges['result']) && $new_iamges['result']===true){
                        $bankImages = explode(';',$new_iamges['data']);
                        foreach ($match[1] as $i=>$img)
                        {
                            if(isset($bankImages[$i]) && $bankImages[$i]){
                                $detail = str_replace($img,$bankImages[$i],$detail);
                            }
                        }
                    }else{
                        return $new_iamges;
                    }
                }
            }
            return ['data'=>$detail,'result'=>true];
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }
    private function hasSemicolon($image){
        return stripos($image,'?');
    }
    private function replaceImagesSemicolon($images){
        if(is_array($images)){
            foreach ($images as &$image){
                if($pos = $this->hasSemicolon($image)){
                    $image = substr($image,0,$pos);
                }
            }
        }else{
            if($pos = $this->hasSemicolon($images)){
                $images = substr($images,0,$pos);
            }
        }
        return $images;
    }

    /**
     * 封装手机描述格式
     * @param $mobileDetail
     * @param $api
     *
     * @return mixed|string|void
     */
    public function formatMobileDetail($mobileDetail,$api,$code)
    {

        $string="";
        $mobileDetail = json_decode($mobileDetail,true);

        if(is_array($mobileDetail) && $mobileDetail)
        {
            foreach($mobileDetail as $k=>$d)
            {
                if(isset($d['images']) && $d['images'])
                {
                    foreach($d['images'] as $img)
                    {
                        $res = self::uploadOneImage($api,$img['imgUrl'],$code);
                        if($res)
                        {
                            $string=$string.'<p><img src="'.$res.'" alt=""></p>';
                        }
                    }
                }elseif(isset($d['content'])){
                    $string = $string.'<p>'.$d['content'].'</p>';
                }
            }
        }
        return $string;
    }

    /**
     * 处理手机详情描述中的图片
     * @param $mobileDetail
     * @param $api
     *
     * @return mixed|string|void
     */
    public function managerMobileDetail($mobileDetail,$account,$code)
    {
        try{
            $detail['mobileDetail']=[];
            $detail['version']="1.0";
            $detail['versionNum']=1;
            $mobileDetail = json_decode($mobileDetail,true);

            if(is_array($mobileDetail) && $mobileDetail)
            {
                foreach($mobileDetail as $k=>$d)
                {
                    if(isset($d['content'])){
                        $detail['mobileDetail'][]=$d;
                        //array_push($detail['mobileDetail'],$d);
                    }elseif(isset($d['images']) && $d['images'])
                    {
                        foreach($d['images'] as $img)
                        {

                            $res = self::uploadOneImage($account,$img['imgUrl'],$code);
                            if($res)
                            {
                                $image=[
                                    'col'=>1,
                                    'images'=>[
                                        [
                                            "height"=>1000,
                                            "imgUrl"=>$res,
                                            "targetUrl"=>"",
                                            "width"=>1000
                                        ]
                                    ],
                                    "type"=>"image"
                                ];
                                $detail['mobileDetail'][]=$image;
                            }
                        }
                    }
                }
            }

            if(!empty($detail['mobileDetail']))
            {
                return json_encode($detail);
            }else{
                return '';
            }
        }catch (Exception $exp){
            throw new Exception($exp->getMessage());
        }

    }
    /**
     * 组合关联信息模板，自定义信息模板，描述详情
     */
    public  function combineRelationCustomDescription($product,$detail,$account,$code)
    {
        try{

            $relationTempateContent='';
            if(isset($product['relation_template_id']) && isset($product['relation_template_postion']) && $product['relation_template_id'] && $product['relation_template_postion'])
            {
                $relationTemplate = $this->productTemplateModel->where(['id'=>$product['relation_template_id']])->find();

                if(is_object($relationTemplate))
                {
                    $relationTemplate = $relationTemplate->toArray();
                }
                if(isset($relationTemplate['module_contents']) && !empty($relationTemplate['module_contents']))
                {
                    $relationProducts = $this->model->whereIn('product_id',$relationTemplate['module_contents'])->page(1,25)->select();
                    if($relationProducts){
                        $relationTempateContent = $this->productTemplateModel->create_relation_template($relationProducts);
                    }
                }
            }
            $customTempateContent='';
            if(isset($product['custom_template_id']) && isset($product['custom_template_postion']) && $product['custom_template_id'] && $product['custom_template_postion'])
            {

                $customTemplate = $this->productTemplateModel->where(['id'=>$product['custom_template_id']])->find();

                if(is_object($customTemplate))
                {
                    $customTemplate = $customTemplate->toArray();
                }

                if(isset($customTemplate['module_contents']) && !empty($customTemplate['module_contents']))
                {
                    $customTempateContent = $customTemplate['module_contents'];
                }
            }

            //处理自定义图片

            $preg='/<img[\s\S]*?src\s*=\s*[\"|\'](.*?(jpg|jpeg|gif|png))[\"|\'][\s\S]*?>/';
            preg_match_all($preg,$customTempateContent,$match);
            if(count($match)>1 && !empty($match[1]))
            {
                $images =implode(';',$match[1]);
                $new_iamges = $this->uploadImageURLs($account,$images,$code);
                if(isset($new_iamges['result']) && $new_iamges['result']===true){
                    $bankImages = explode(';',$new_iamges['data']);
                    foreach ($match[1] as $i=>$img)
                    {
                        if(isset($bankImages[$i]) && $bankImages[$i]){
                            $customTempateContent = str_replace($img,$bankImages[$i],$customTempateContent);
                        }
                    }
                }else{
                    return $new_iamges;
                }
            }


            //更新模板内容
            if($customTempateContent) {
                $this->productTemplateModel->update(['module_contents' => $customTempateContent], ['id'=>$product['custom_template_id']]);
            }

            if($relationTempateContent && $customTempateContent)
            {
                //上-上-下
                if($product['relation_template_postion'] =='top' && $product['custom_template_postion']=='top' )
                {
                    $description = $relationTempateContent.$customTempateContent.$detail;
                }elseif($product['relation_template_postion'] =='top' && $product['custom_template_postion']=='bottom' ){ //上-中-下
                    $description = $relationTempateContent.$detail.$customTempateContent;
                }elseif($product['relation_template_postion'] =='bottom' && $product['custom_template_postion']=='bottom' ){//上-下-下
                    $description = $detail.$relationTempateContent.$customTempateContent;
                }elseif($product['relation_template_postion'] =='bottom' && $product['custom_template_postion']=='top' ){ //上-中-下
                    $description = $customTempateContent.$detail.$relationTempateContent;
                }
            }elseif($relationTempateContent){
                //上-下
                if($product['relation_template_postion'] =='top')
                {
                    $description = $relationTempateContent.$detail;
                }elseif($product['relation_template_postion'] =='bottom'){ //上-下
                    $description = $detail.$relationTempateContent;
                }
            }elseif($customTempateContent){
                //上-下
                if($product['custom_template_postion'] =='top')
                {
                    $description = $customTempateContent.$detail;
                }elseif($product['custom_template_postion'] =='bottom'){ //上-下
                    $description = $detail.$customTempateContent;
                }
            }else{
                $description = $detail;
            }
            return ['data'=>$description,'result'=>true];
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * 上传一张图片
     * @param $api
     * @param $image
     * @param $code
     *
     * @return string
     */
    public  function uploadOneImage($account,$image,$code, $image_type = [])
    {

        if(empty($image))
            return '';
        $param['module']='product';
        $param['class']='Photobank';
        $param['action']='uploadimageforsdk';

        $image_base_urls = [];

        if($image_type) {

            $productImageModel = new AliexpressProductImage();
            $productImages = $productImageModel->field('base_url')->where($image_type)->where('thumb','=',$image)->find();
            if($productImages) {
                return $productImages['base_url'];
            }
        }


        if(strpos($image,'alicdn.com')==false)
        {
            $param['url']=$this->translateImgToFullPath($image, $code);
            $params = array_merge($account,$param);
            $response = AliexpressService::execute($params);
            if(isset($response['photobankUrl']) && $response['photobankUrl'])
            {
                if($image_type) {
                    $data = [
                        'type' => $image_type['type'],
                        'thumb' => $image,
                        'url' => $response['photobankUrl'],
                        'ap_id' => $image_type['ali_product_id']
                    ];

                    (new UniqueQueuer(AliexpressPublishImageQueue::class))->push($data);
                }

                $returnUrl= $response['photobankUrl'];
                return $returnUrl;
            }else{
                return '';
            }
        }else{
            return $image;
        }

    }

    /**
     * 获取图片银行信息
     * @param $account
     * @return array|string
     */
    public static function getphotobankinfo($account){
        $param['module']='product';
        $param['class']='Photobank';
        $param['action']='getphotobankinfo';
        $params = array_merge($account,$param);
        $response = AliexpressService::execute($params);
        return $response;
    }
    /**
     * 提交商品相册图片
     * @param $api
     * @param $images
     * @param $code
     * @param int $local
     * @return string
     */
    public  function uploadImageURLs($account,$images,$code, $image_type = [], $local=0)
    {
        try{
            $param['module']='product';
            $param['class']='Photobank';
            $param['action']='uploadimageforsdk';
            if(empty($images))
                return '';
            $returnUrl=[];
            $images = explode(';', $images);
            $image_base_urls = [];

            if($image_type) {
                $publishImages = $this->checkPublishImage($image_type, $images);

                $images = $publishImages['image_thumbs'];
                $image_base_urls = $publishImages['image_base_urls'];
            }

            //图片全部存在
            if(empty($images)) {
                $return=[
                    'data'=>implode(';', $image_base_urls),
                    'result'=>true,
                ];

                return $return;
            }

            $imageError = '';

            foreach($images as $image)
            {
                if(strpos($image,'alicdn.com')==false)
                {

                    //本地上传图片
                    if(strpos($image,'/self/') !== false) {
                        $image = 'https://img.rondaful.com'.$image;
                    }

                    if($local==1)
                    {
                        $new_image = $this->translateImgToLoalPath($image, $code);
                    }elseif($local==0){
                        $new_image = $this->translateImgToFullPath($image, $code);
                    }

                    $param['url']=$new_image;
                    $params = array_merge($account,$param);
                    $response = AliexpressService::execute($params);
                    if(isset($response['photobankUrl']) && $response['photobankUrl'])
                    {
                        if($image_type) {
                            $data = [
                                'type' => $image_type['type'],
                                'thumb' => $image,
                                'url' => $response['photobankUrl'],
                                'ap_id' => $image_type['ali_product_id']
                            ];

                            (new UniqueQueuer(AliexpressPublishImageQueue::class))->push($data);
                        }

                        $returnUrl[] = $response['photobankUrl'];
                    }else{
                        $imageError = $new_image;
                        break;
                    }
                }else{
                    $returnUrl[]=$image;
                }
            }

            if(count($returnUrl) == count($images)) {
                $return=[
                    'data'=>implode(';', $returnUrl),
                    'result'=>true,
                ];
            }else{
                $error_code = isset($response['error_code']) && $response['error_code'] ? 'code:'.$response['error_code'].'|' : '';

                $return=[
                    'data'=>'',
                    'error_message'=>isset($response['error_message'])? $error_code.$response['error_message'].'|'.$imageError:'',
                    'result'=>false,
                ];
            }

            if($return['result'] == true) {
                $return['data'] = $image_base_urls ? implode(';',$image_base_urls).';'.$return['data'] : $return['data'];
            }

            return $return;
        }catch (Exception $exp){
            throw new Exception("{$exp->getFile()}；{$exp->getLine()};{$exp->getMessage()}");
        }

    }


    /**
     * 上传图片到图片银行
     * @param type $api
     * @param array $imags
     * @return string|array
     */

    public  function uploadImage($account,$images,$code, $image_type, $local=0)
    {
        $param['module']='product';
        $param['class']='Photobank';
        $param['action']='uploadimageforsdk';

        if(empty($images))
            return '';
        $returnUrl=[];
        $images = explode(';', $images);

        $image_base_urls = [];

        if($image_type) {
            $publishImages = $this->checkPublishImage($image_type, $images);

            $images = $publishImages['image_thumbs'];
            $image_base_urls = $publishImages['image_base_urls'];
        }


        //图片全部存在
        if(empty($images)) {
            return implode(';', $image_base_urls);
        }

        foreach($images as $image)
        {
            //$image = str_replace(config('picture_base_url'),'',$image);
            //没有上传的

            if(strpos($image,'alicdn.com')==false)
            {
                if($local==1)
                {
                    $new_image = $this->translateImgToLoalPath($image, $code);
                }elseif($local==0){
                    $new_image = $this->translateImgToFullPath($image, $code);
                }

                $param['url']=$new_image;
                $params = array_merge($account,$param);
                $response = AliexpressService::execute($params);

                if(isset($response['photobankUrl']) && $response['photobankUrl'])
                {
                    if($image_type) {
                        $data = [
                            'type' => $image_type['type'],
                            'thumb' => $image,
                            'url' => $response['photobankUrl'],
                            'ap_id' => $image_type['ali_product_id']
                        ];

                        (new UniqueQueuer(AliexpressPublishImageQueue::class))->push($data);
                    }
                    $returnUrl[] = $response['photobankUrl'];
                }
            }else{
                $returnUrl[]=$image;
            }
        }

        if(count($returnUrl)>0)
        {
            return  $image_base_urls ? implode(';', array_merge($image_base_urls, $returnUrl)) : implode(';', $returnUrl);
        }else{
            return '';
        }
    }



    /**
     * @desc 根据条件获取分类
     * @param $condition
     * @param string $field
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function getCategoryByCondition($condition,$field='')
    {
        $model = new AliexpressCategory();
        $arrCategory = $model->where($condition)->field($field)->select();
        return $arrCategory;
    }

    /**
     * @desc 保存尺码模板
     * @param $data
     */
    public function saveSizeTemp($data)
    {
        $objSizeTemp = AliexpressSizeTemplate::where(['account_id'=>$data['account_id'],'sizechart_id'=>$data['sizechart_id']])
            ->find();
        if(!empty($objSizeTemp)){
            $objSizeTemp->data($data)->isUpdate(true)->save();
        }else{
            $sizeTempModel = new AliexpressSizeTemplate();
            $sizeTempModel->save($data);
        }
    }

    /**
     * @desc 保存产品分类信息
     * @param $data
     * @throws Exception
     */
    public function saveCategory($data)
    {
        try{

            foreach($data as $category){
                $categoryModel = new AliexpressCategory();
                if($categoryModel->find(['category_id'=>$category['category_id']])){
                    $categoryModel->isUpdate(true)->save($category);
                }else{
                    $categoryModel->save($category);
                }
            }
        }catch(Exception $ex){
            throw new Exception($ex->getMessage());
        }
    }

    /**
     * 获取产品线上状态
     * @return array
     */
    public function getProductStatus()
    {
        return AliexpressProduct::PRODUCT_STATUS;
    }
    /**
     * 调用API拉取产品列表
     * @param $config   array api配置信息
     * @param $status   string 产品状态
     * @param $page     int 当前页数
     * @param $offLineTime int 商品的剩余有效期
     * @return array
     */
    public function getAliProductList($account,$status,$page,$offLineTime=0)
    {
        $requestParam = ['product_status_type'=>$status,'current_page'=>$page,'page_size'=>100];
        if($offLineTime>0)
        {
            $requestParam['offLineTime'] = $offLineTime;
        }
        $param['module']='product';
        $param['class']='product';
        $param['action']='findproductinfolistquery';
        $requestParam = array_merge($param,$requestParam);
        $params=array_merge($account,$requestParam);
        $response = AliexpressService::execute($params);
        if(isset($response['success']) && $response['success']){
            return [
                'data'=>isset($response['aeopAEProductDisplayDTOList'])?$response['aeopAEProductDisplayDTOList']['aeopAeProductDisplaySampleDto']:[],
                'totalPage'=>$response['totalPage'],
                'productCount'=>$response['productCount']
            ];
        }else{
            return [
                'error_code'=>isset($response['error_code']) ? $response['error_code'] : 0,
                //'error_message'=>isset($response['error_message']) ? $response['error_message'] : '未知错误，获取产品列表失败'
                'error_message'=>json_encode($response)
            ];
        }
    }

    /**
     * 商品列表查询接口。主账号可查询所有商品，子账号只可查询自身所属商品。
     * @param $account 账号数据
     * @param array $query　查询参数
     * @return array
     */
    public function findproductinfolistquery($account,array $query)
    {
        $requestParam=[
            'page_size'=>100,
        ];
        if(isset($query['product_status_type']) && $query['product_status_type']){
            $requestParam['product_status_type']=$query['product_status_type'];
        }
        if(isset($query['current_page']) && $query['current_page']){
            $requestParam['current_page']=$query['current_page'];
        }
        if(isset($query['gmt_modified_start']) && $query['gmt_modified_start']){
            $requestParam['gmt_modified_start']=$query['gmt_modified_start'];
        }
        if(isset($query['gmt_modified_end']) && $query['gmt_modified_end']){
            $requestParam['gmt_modified_end']=$query['gmt_modified_end'];
        }

        if(isset($query['gmt_create_start']) && $query['gmt_create_start']){
            $requestParam['gmt_create_start']=$query['gmt_create_start'];
        }
        if(isset($query['gmt_create_end']) && $query['gmt_create_end']){
            $requestParam['gmt_create_end']=$query['gmt_create_end'];
        }

        $param['module']='product';
        $param['class']='product';
        $param['action']='findproductinfolistquery';
        $requestParam = array_merge($param,$requestParam);
        $params=array_merge($account,$requestParam);
        $response = AliexpressService::execute($params);
        if(isset($response['success']) && $response['success']){
            return [
                'data'=>isset($response['aeopAEProductDisplayDTOList'])?$response['aeopAEProductDisplayDTOList']['aeopAeProductDisplaySampleDto']:[],
                'totalPage'=>$response['totalPage'],
                'productCount'=>$response['productCount']
            ];
        }else{
            return [
                'error_code'=>isset($response['error_code']) ? $response['error_code'] : 0,
                //'error_message'=>isset($response['error_message']) ? $response['error_message'] : '未知错误，获取产品列表失败'
                'error_message'=>json_encode($response)
            ];
        }
    }

    /**
     * 获取产品详细信息
     * @param $config   array api配置信息
     * @param $productId    int 平台产ID
     * @return array
     */
    public function getAliProductDetail(PostProduct $postProductServer,$productId)
    {
        //$postProductServer = AliexpressApi::instance()->loader('PostProduct');
        //$postProductServer->setConfig($config);
        $response = $postProductServer->findAeProductById($productId);
        if(isset($response['success'])&&$response['success']==1){
            return $response;
        }else{
            return [
                'error_code'=>isset($response['error_code']) ? $response['error_code'] : 0,
                //'error_message'=>isset($response['error_message']) ? $response['error_message'] : '未知错误，获取产品详细失败'
                'error_message'=>json_encode($response)
            ];
        }
    }

    /**
     * 获取产品详细信息
     * @param $config   array api配置信息
     * @param $productId    int 平台产ID
     * @return array
     */
    public function getAliexpressProductDetail($config,$productId)
    {
        $postProductServer = AliexpressApi::instance($config)->loader('PostProduct');
        $response = $postProductServer->findAeProductById($productId);

        if(isset($response['success'])&&$response['success']==1){
            return $response;
        }else{
            return [
                'error_code'=>isset($response['error_code']) ? $response['error_code'] : 0,
                //'error_message'=>isset($response['error_message']) ? $response['error_message'] : '未知错误，获取产品详细失败'
                'error_message'=>json_encode($response)
            ];
        }
    }

    /**
     * 保存商品信息
     * @param array $productData
     * @param array $productInfoData
     * @param array $productSkuData
     */
    public function saveAliProduct(array $productData,array $productInfoData,array $productSkuData)
    {
        $productModel       = new AliexpressProduct();
        //判断是否已有
        $objProduct = $productModel->where(['product_id'=>$productData['product_id']])->find();

        if(empty($objProduct))
        {
            $id = abs(Twitter::instance()->nextId(4,$productData['account_id']));
            $productData['id'] = $id;
            $productData['goods_id'] = 0;
            $productData['goods_spu'] = '';
            $productModel->addProduct($productData,$productInfoData,$productSkuData);
        }else{
            $productModel->updateProduct($objProduct,$productData,$productInfoData,$productSkuData);
        }

        /*Db::startTrans();
        try {
            $productModel       = new AliexpressProduct();
            $productInfoModel   = new AliexpressProductInfo();
            $productSkuModel    = new AliexpressProductSku();
            //判断是否已有
            $objProduct = $productModel->where(['account_id'=>$productData['account_id'],'product_id'=>$productData['product_id']])->find();
            if(empty($objProduct)){
                $productModel->save($productData);
                $productModel->productInfo()->save($productInfoData);
                $productModel->productSku()->saveAll($productSkuData);
            }else{
                $objProduct->isUpdate(true)->save($productData);
                $objProduct->productInfo()->save($productInfoData);
                foreach($productSkuData as $sku){
                    $productSkuModel->isUpdate(true)->save($sku,['product_id'=>$productData['product_id'],'merchant_sku_id'=>$sku['merchant_sku_id']]);
                }
            }
            Db::commit();
            Cache::store('AliexpressProductCache')->setModifiedTime($productData['account_id'],$productData['product_id'],$productData['gmt_modified']);
        } catch (Exception $ex) {
            Db::rollback();
            throw new Exception($ex->getMessage());
        }*/
    }


    public function setAliProductExpire(array $productId,$day,$accountId,$reset=false)
    {
        $productModel = new AliexpressProduct();
        Db::startTrans();
        try{
            if($reset){
                $productModel->where(['expire_day'=>['gt',0],'account_id'=>$accountId])->setField('expire_day',0);
            }
            foreach($productId as $id){
                $productModel->where(['product_id'=>$id])->setField('expire_day',$day);
            }
            Db::commit();
        }catch(Exception $ex){
            Db::rollback();
            throw new Exception($ex->getMessage());
        }
    }

    /**
     * 刊登之前检查图片是否已经上传
     *
     */
    public function checkPublishImage($image_type, $image)
    {

       if(is_string($image)) {
            $image = explode(';',$image);
       }

       $productImageModel = new AliexpressProductImage();

       $productImages = $productImageModel->field('thumb, base_url')->whereIn('thumb',$image)->where($image_type)->select();

        $image_thumbs = [];
        $image_base_urls = [];
       if($productImages) {

           $productImages = json_decode(json_encode($productImages), true);

           foreach ($productImages as $key => $val) {

               if(in_array($val['thumb'], $image)) {
                    $image_base_urls[] = $val['base_url'];
                    unset($image[$key]);
                    $image_thumbs = $image;
               }
           }
       }

       return ['image_thumbs' => $image, 'image_base_urls' => $image_base_urls];



    }
}