<?php
namespace app\publish\service;

use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\model\AttributeValue;
use app\common\model\Brand;
use app\common\model\ChannelUserAccountMap;
use app\common\model\Goods;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsLang;
use app\common\model\GoodsSku;
use app\common\model\GoodsSkuMap;
use app\common\model\GoodsTortDescription;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\shopee\ShopeeActionLog;
use app\common\model\shopee\ShopeeAttribute;
use app\common\model\shopee\ShopeeCategory;
use app\common\model\shopee\ShopeeDiscount;
use app\common\model\shopee\ShopeeDiscountDetail;
use app\common\model\shopee\ShopeeLogistic;
use app\common\model\shopee\ShopeeProduct;
use app\common\model\shopee\ShopeeProductInfo;
use app\common\model\shopee\ShopeeSite;
use app\common\model\shopee\ShopeeVariant;
use app\common\model\User;
use app\common\service\CommonQueuer;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsPublishMapService;
use app\goods\service\GoodsSkuMapService;
use app\publish\helper\shopee\ShopeeHelper;
use app\publish\queue\shopeeCategoryTranslateQueue;
use app\publish\queue\ShopeeDeleteItemQueue;
use app\publish\queue\ShopeeListingUpdateQueue;
use app\publish\queue\ShopeeQueueJob;
use app\publish\validate\ShopeePublishValidate;
use function GuzzleHttp\Psr7\str;
use think\Exception;
use think\exception\PDOException;
use think\Db;
use app\common\service\GoogleTranslate;
use app\goods\service\GoodsHelp;
use app\common\service\ChannelAccountConst;
use app\common\model\Department;
use app\index\service\Department as DepartmentService;
use app\common\model\DepartmentUserMap;
use app\common\service\Common;

class ShopeeService
{
    private $channel_id=9;
    private $userId;
    private $helper;
    const TYPE=[
        'updateItem'=>1,
        'deleteItem'=>2,
        'deleteVariation'=>3,
        'updateStock'=>4,
        'updatePrice'=>5,
        'updateVariationPrice'=>6,
        'logistics'=>7,
        'discount_id'=>8,
        'price'=>9,
        'stock'=>10,
        'rsyncProduct'=>11,
        'online'=>12,
        'weight'=>13,
        'updateVariationStock'=>14,
        'updateProduct'=>15,
    ];


    public function __construct($userId = 0)
    {
        $this->userId = $userId;
        $this->helper = new ShopeeHelper();

    }

    private $productAllowUpdateFields=[
        'price','stock','weight','discount_id','logistics','rsyncProduct','discount','attributes','wholesales','description'
    ];
    private $variantAllowUpdateFields=[
        'price','stock','discount_id','discount'
    ];
    public function batchSetting($type,$post){
        try{
            $product_fields = $this->productAllowUpdateFields;
            $variant_fields = $this->variantAllowUpdateFields;

            if(is_array($post))
            {
                $uid = $post['uid'];
                unset($post['uid']);
                foreach($post as $p)
                {

                    if(in_array($type,$product_fields ) && !isset($p['variation_id']))
                    {
                        $data['lock_product']=1;
                        $data['lock_update']=1;
                        $product_id = $p['item_id'];
                        //记录数据，等修改成功了更新表数据
                        if($type=='logistics'){
                            $row = ShopeeProductInfo::where('id','=',$product_id)->field($type)->find();
                        }elseif($type=='rsyncProduct'){
                            $row = ShopeeProduct::where('item_id','=',$product_id)->field('item_id,id')->find();
                        }else{
                            $row = ShopeeProduct::where('id','=',$product_id)->field($type)->find();
                        }

                        if($row)
                        {
                            $row = $row->toArray();

                            if($type=='rsyncProduct'){
                                $new_data='同步商品';
                                $old_data='';
                            }elseif($type=='discount_id'){
                                $new_data=$p;
                                $old_data=$this->getDiscountData($p);
                            }else{
                                $new_data=[
                                    $type=>$p[$type]
                                ];
                                $old_data=[
                                    $type=>$row[$type]
                                ];
                            }

                            $where['product_id']=['=',$product_id];
                            $where['status']=['=',0];
                            $where['create_id']=['=',$uid];
                            $where['new_data']=['=',json_encode($new_data)];

                            if($type=='stock'){
                                $type = 'updateStock';
                            }elseif($type=='price'){
                                $type = 'updatePrice';
                            }

                            $log=[
                                'create_id'=>$uid,
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'create_time'=>time(),
                                'type'=>self::TYPE[$type],
                                'product_id'=>$product_id,
                            ];
                            if($this->actionLog($log,$where))
                            {
                                $message = ShopeeProduct::where('item_id','=',$product_id)->update($data);
                            }
                        }
                        $message=false;
                    }elseif(in_array($type, $variant_fields)){

                        $variant_id = $p['variation_id'];
                        $product_id = $p['item_id'];
                        $data=[];
                        //$data[$type]=$p[$type];
                        $data['lock_variant']=1;
                        $row = ShopeeVariant::where('variation_id','=',$variant_id)->field($type.',item_id,pid')->find();
                        if($row)
                        {
                            $row = $row->toArray();

                            $pid = $row['pid'];
                            if($type=='discount_id'){
                                $new_data=$p;
                                $old_data=$this->getDiscountData($p);
                            }else{
                                $new_data=[
                                    $type=>$p[$type]
                                ];
                                $old_data=[
                                    $type=>$row[$type]
                                ];
                            }

                            //$where['product_id']=['=',$product_id];
                            $where['variant_id']=['=',$variant_id];
                            $where['status']=['=',0];
                            $where['create_id']=['=',$uid];
                            $where['new_data']=['=',json_encode($new_data)];

                            $log=[
                                'create_id'=>$uid,
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'create_time'=>time(),
                                'type'=>self::TYPE[$type],
                                'product_id'=>$product_id,
                                'variant_id'=>$variant_id,
                            ];
                            if($this->actionLog($log,$where))
                            {
                                $message = ShopeeVariant::where('variation_id','=',$variant_id)->update($data);
                                $update['lock_update']=1;
                                ShopeeProduct::where('id','=',$pid)->update($update);
                            }
                        }
                        $message=false;
                    }
                }
                return $message;
            }
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getFile().$exp->getLine().$exp->getMessage());
        }
    }

    public function getDiscountDetail($discount_id){
        return ShopeeDiscountDetail::where('discount_id',$discount_id)->select();
    }
    private function getDiscountData($item){
        $item_id = isset($item['item_id'])?$item['item_id']:0;
        $variant_id = isset($item['variation_id'])?$item['variation_id']:0;
        $discount = ShopeeDiscountDetail::where('item_id',$item_id)->find();
        $data=[];
        $variations = json_decode($discount['variations'],true);
        //如果存在变体,则获取变体里面的数据
        if($variant_id && $variations){
            foreach ($variations as $variation){
                if($variation['variation_id'] == $variant_id){
                    $data = $variation;
                    break;
                }
            }
        }else{
            $data = $discount;
        }
        return $data;
    }
    /**
     * 写入修改日志
     * @param array $log
     * @param array $where
     */
    public function actionLog(array $log,$where=[])
    {
        Db::startTrans();
        try{
            if($rs = ShopeeActionLog::where($where)->field('id')->find()){
                $id = $rs['id'];
                $return =  ShopeeActionLog::where('id',$rs['id'])->update($log);
            }else{
                $return = ShopeeActionLog::create($log);
                $id = $return->id;
            }
            Db::commit();
            $cron_time=null;
            if(isset($log['cron_time']) && $log['cron_time']){
                if(is_string($log['cron_time'])){
                    $cron_time=strtotime($log['cron_time']);
                }elseif(is_numeric($log['cron_time'])){
                    $cron_time = $log['cron_time'];
                }
            }
            (new CommonQueuer(ShopeeListingUpdateQueue::class))->push($id,$cron_time);
            return $return ;
        }catch (PDOException $exp){
            Db::rollback();
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        } catch (Exception $exp){
            Db::rollback();
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }
    public function addDiscount($params){

    }
    /**
     * 获取折扣列表
     * @param $params
     * @param $page
     * @param $pageSize
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAllDiscount($params,$page,$pageSize)
    {
        $model = new ShopeeDiscount();
        $where = [];
        if(isset($params['account_id']) && $params['account_id']){
            $where['account_id']=['=',$params['account_id']];
        }

        if(isset($params['discount_name']) && $params['discount_name']){
            $where['discount_name']=['like',$params['discount_name']."%"];
        }

        $total = $model->where($where)->count();
        $data  = $model->where($where)->page($page,$pageSize)->select();

        foreach ($data as &$d){
            $d['code'] = $d->account($d['account_id']);
        }

        return ['page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'data'=>$data];
    }
    public function logistics($account_id){
        return ShopeeLogistic::where('account_id',$account_id)->where('enabled',1)->select();
    }

    public function getGoodsData($goods_id, $accountId){
        $goods =Goods::where('id',$goods_id)->find();


        $data['site_country'] = '';
        $data['site_id'] = '';
        //站点
        if($accountId) {
            $siteInfo = (new ShopeeSite())->alias('s')->field('s.name as site_country, s.id as site_id')->join('shopee_account h','s.code = h.site','left')->where('h.id','=',$accountId)->find();

            if($siteInfo) {
                $siteInfo = $siteInfo->toArray();
                $data['site_country'] = $siteInfo['site_country'];
                $data['site_id'] = $siteInfo['site_id'];
            }
        }

        $sales_status = [1 =>'在售',2=>'停售', 3 => '待发布', '4'=> '卖完下架', '5' => '缺货', '6' => '部分在售'];
        $data['sales_status'] = $sales_status[$goods['sales_status']];

        //物流属性
        $data['transport_property'] = (new GoodsHelp())->getPropertiesTextByGoodsId($goods['id']);

        $data['name']=$goods['name'];
        $data['spu']=$goods['spu'];
        $data['tort_flag'] = GoodsTortDescription::where('goods_id',$goods_id)->value('id') ? 1 : 0;
        $brand = Brand::where('id',$goods['brand_id'])->find();
        if($brand){
            $data['brand']=$brand['name'];
        }else{
            $data['brand']='No Brand';
        }
        $data['category']=$goods->category;

        $information = GoodsLang::where('goods_id',$goods_id)->where('lang_id',2)->find();
        //拼接卖点
        if (!empty($information['selling_point'])) {
            $spStr = "Bullet Points:\r";
            $sellingPoints = json_decode($information['selling_point'], true);
            $i = 1;
            if (is_array($sellingPoints)) {
                foreach ($sellingPoints as $sellingPoint) {
                    if (empty($sellingPoint)) {
                        continue;
                    }
                    $spStr .= (string)$i.'. '.$sellingPoint."\r";
                    $i++;
                }
                $spStr .= "\r";
                $information['description'] = $spStr.$information['description'];
            }

        }


        $informationZh = GoodsLang::where('goods_id',$goods_id)->where('lang_id',1)->find();

        $skus = GoodsSku::where('goods_id','=',$goods_id)->whereIn('status',[1,4])->order('id ASC')->select();

        if($skus)
        {
            $skus = $this->getSkuAttr($skus,$goods_id);
        }

        $images = GoodsImage::getPublishImages($goods_id,$this->channel_id);

        $skuImages = $images['skuImages'];

        if($skus && $skuImages)
        {
            $skus = GoodsImage::replaceSkuImage($skus,$skuImages,$this->channel_id);
        }
//        $galleries = (new GoodsImageService)->getImgByGoodsId($goods_id);
        $galleries = array_column($images['spuImages'],'path');
        $vars = array(
            array(
                'account_id' => 0,
                'account_code' => '',
                'account_name' => '',
                'name' => $information['title'],
                'category_id'=>0,
                'attributes'=>[],
                'cron_time' => '',
                'item_sku'=>$goods['spu'],
                'description' => $information['description']?$information['description']:'',
                'description_zh'=>$informationZh['description']?$informationZh['description']:'',
                'weight'=>$goods['weight']/1000,
                'days_to_ship'=>7,
                'images' => $galleries,
                'variant' => $skus,
                'wholesales'=>[
                    [
                        'min'=>'',
                        'max'=>'',
                        'unit_price'=>'',
                    ]
                ],
                'logistics'=>[],
            ),
        );

        $data['channel_id']=$this->channel_id;
        $data['base_url']  = Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;
        $data['vars']=$vars;
        return $data;

    }
    public function category($category_id=0,$category_name='',$site='',$page=1,$pageSize=30){

        $where=[];
        if($category_name){
            //$where['category_name']=['like',"{$category_name}%"];
            //$where['category_id']=['=',$category_name];
            $where = ' category_name like "'.$category_name.'%" OR category_id="'.$category_name.'" ';
        }elseif($category_id>=0){
            $where['parent_id']=['=',$category_id];
        }

        $shopeeCategory = new ShopeeCategory;

        if(!empty($category_name)){
            $count = $shopeeCategory->where($where)->where('has_children',0)->where('site_id',$site)->count();
            $data = $shopeeCategory->where($where)->where('has_children',0)->where('site_id',$site)->page($page,$pageSize)->select();
            foreach ($data as &$d){
                $categoryNameTree='';
                $shopeeCategory->categoryNameTree($d->category_id,$categoryNameTree);
                $d->category_name = $categoryNameTree;
            }
            return ['data'=>$data,'count'=>$count,'page'=>$page,'pageSize'=>$pageSize];
        }else{
            $data = $shopeeCategory->where($where)->where('site_id',$site)->select();

            $data = $this->categoryNameEn($shopeeCategory, $data);

            return $data;
        }
    }


    /**
     * @param $shopeeCategory
     * @param $data
     * @return mixed
     * @throws Exceptions
     */
    public function categoryNameEn($shopeeCategory, $data) {


        //翻译
        $googleTranslate = new GoogleTranslate;

        $categoryNameEn = [];
        if($data) {
            foreach ($data as $key => $val) {
                $categoryNameEn[] = $val['category_name'];
                //越南，泰国，印尼的站点
                if (in_array($val['site_id'], [1, 4, 7]) && empty($val['category_name_en']) && $val['category_name']) {

                     if(!preg_match('/^([A-Za-z])+$/',$val['category_name'])) {
                         $categoryNameEn[] = $val['category_name'];
                     }else{
                         $shopeeCategory->update(['category_name_en' => $val['category_name']], ['id' => $val['id']]);
                     }
                }
            }

            if($categoryNameEn) {

                //获取翻译接口
                $translates = $googleTranslate->translateBatch($categoryNameEn, ['target' => 'en'], $this->userId, 9);

                if($translates) {
                    foreach ($translates as $key => $val) {

                       foreach ($data as $k => $v) {
                           if($val['input'] == $v['category_name']) {
                                $data[$k]['name_en'] = $val['text'];
                           }
                       }
                    }


                    foreach ($data as $key => $val) {

                        $category_name_en = $val['category_name_en'];
                        if(isset($val['name_en']) && empty($category_name_en)) {

                            $category_name_en = $val['name_en'];
                            $shopeeCategory->update(['category_name_en' => $category_name_en], ['id' => $val['id']]);

                        }

                        $data[$key]['category_name'] = $category_name_en;
                    }
                }
            }
        }

        return $data;
    }
    

    public function attribute($category_id){
        $data = ShopeeAttribute::where('category_id',$category_id)->select();
        return $data;
    }
    public function pushQueue($ids){
        $ids = explode(',',$ids);
        $model = new ShopeeVariant();
        $queueDriver  = new UniqueQueuer(ShopeeQueueJob::class);
        $total=0;
        foreach ($ids as $id){
            //还存在没有刊登成功的，则加入队列
            $variant= $model->with(['product'=>function($query){
                $query->field('id,cron_time');
            }])->field('vid,pid')->where('pid',$id)->where('publish_status','<>',1)->limit(1)->find();
            if($variant){
                if($queueDriver->push($id,$variant['product']['cron_time']))
                    ++$total;
            }
        }
        return ['message'=>"成功加入队列[{$total}]"];
    }
    public function getLogs($param, $page=1, $pageSize=30, $fields="*"){
        $where= [
            'product_id'=>['=',$param['product_id']],
        ];

        $const =[
            'name'=>'刊登标题',
            'description'=>'详情描述',
            'brand'=>'品牌',
            'images'=>'图片',
            'stock'=>'库存',
            'price'=>'售价',
            'original_price'=>'原售价',
            'status'=>'销售状态',
            'enabled'=>'是否有效',
            'combine_sku'=>'捆绑sku',
            'weight'=>'重量',
            'category_id'=>'商品分类',
            'days_to_ship'=>'发货期',
            'attributes'=>'商品属性',
            'discount_id'=>'折扣id',
            'wholesales'=>'批发',
            'logistics'=>'物流',
            'item_id'=>'产品id',
            'promotion_price'=>'促销价',
            'purchase_limit'=>'购买限制',
            'variation_id'=>'变体id',
            ];

        $model = new ShopeeactionLog();
        $count = $model->where($where)->count();

        $data = $model->order('create_time Desc')->with(['user'=>function($query){$query->field('id,realname');}])->where($where)->page($page,$pageSize)->select();

        if($data)
        {
            foreach ($data as &$d)
            {

                if(is_array($d['new_data']))
                {
                    $log='';

                    foreach ($d['new_data'] as $name=>$v)
                    {
                        if(!in_array($name,['account_id','variation_id','start_time','end_time','discount_name'])){
                            if(isset($d['old_data'][$name])){
                                $log=$log.$const[$name].':由['.$d['old_data'][$name].']改为['.$d['new_data'][$name].']'.'<br />';
                            }else{
                                $log=$log.$const[$name].':由[]改为['.$d['new_data'][$name].']'.'<br />';
                            }
                        }
                    }
                }else{
                    $log=$d['new_data'];
                }
                $d['log']=$log;
            }
        }

        return ['data'=>$data,'count'=>$count,'page'=>$page,'pageSize'=>$pageSize];
    }

    /**
     * 同步产品
     * @param $ids
     */
    public function rsyncProduct($ids){

    }

    /**
     * 批量操作
     * @param $ids
     */
    public function batchAction($ids,$uid,$type,$message){
        $products = ShopeeProduct::whereIn('id',$ids)->field('id,product_id')->select();
        if($products){
            $total = 0;
            foreach ($products as $product){
                $product_id = $product['item_id'];
                $where=[
                    'product_id'=>['=',$product_id],
                    'create_id'=>['=',$uid],
                    'type'=>['=',self::TYPE[$type]],
                    'status'=>['=',0]
                ];
                $log=[
                    'product_id'=>$product_id,
                    'type'=>self::TYPE[$type],
                    'create_id'=>$uid,
                    'new_data'=>$message,
                    'old_data'=>'',
                    'create_time'=>time(),
                ];
                if($this->actionLog($log,$where)){
                    ++$total;
                }
            }
            return ['message'=>$message.'['.$total.']条'];
        }else{
            throw new JsonErrorException("没有相关数据");
        }
    }
    /**
     * 更新未刊登成功的商品 信息
     * @param $params
     * @return array
     */
    private function updateUnpublishedProduct($post){
        try{
            if (isset($post['vars'])) {
                $vars = json_decode($post['vars'], true); //每个账号信息
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            $products = []; //产品
            if (is_array($vars))
            {
                foreach ($vars as $k => $var)
                {
                    $products[$k]['category_id']=$var['category_id'];
                    $products[$k]['days_to_ship']=$var['days_to_ship'];
                    $products[$k]['name'] = $var['name']; //刊登标题
                    $products[$k]['images'] = json_encode($var['images']); //商品主图
                    $products[$k]['weight'] = $var['weight'];//详情描述
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['attributes'] = json_encode($var['attributes']);//属性
                    $products[$k]['logistics'] = json_encode($var['logistics']);//物流
                    $products[$k]['wholesales'] = json_encode($var['wholesales']);//批发数据
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品原始图片
                    $products[$k]['id'] = $post['id'];
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['variants']=$var['variant'];
                }
            }else{
                throw new JsonErrorException("数据格式不合法");
            }

            if ($products)
            {
                if (is_array($products))
                {
                    foreach ($products as $p)
                    {

                        Db::startTrans();
                        try{
                            $queue = (string)$p['id'];
                            $variants = $p['variants'];
                            unset($p['variants']);
                            (new ShopeeProduct())->isUpdate(true)->allowField(true)->save($p,['id'=>$p['id']]);
                            (new ShopeeProductInfo())->allowField(true)->isUpdate(true)->save($p,['id'=>$p['id']]);

                            foreach ($variants as $variant)
                            {
                                if(isset($variant['vid']) && $variant['vid'])
                                {
                                    $map['vid'] = ['=', $variant['vid']]; //没有sku时，根据pid更新
                                    //$map['pid'] = ['=', $variant['pid']];
                                    (new ShopeeVariant())->isUpdate(true)->allowField(true)->save($variant,$map);
                                }else{
                                    (new ShopeeVariant())->isUpdate(false)->allowField(true)->save($variant);
                                }
                            }

                            $findWhere = [
                                'pid' => ['=', $queue],
                                'status' => ['<>', 1],
                            ];

                            //如果存在没有刊登成功的加入队列
                            if (ShopeeVariant::where($findWhere)->find())
                            {
                                ShopeeVariant::where($findWhere)->update(['status' => 0, 'message' => '', 'run_time' => '']);
                            } else {
                                //设置状态为已更新
                                (new ShopeeProduct())->update(['lock_product' => 1, 'lock_update' => 1], ['id' => $queue]);
                                (new ShopeeVariant())->update(['lock_variant' => 1], ['pid' => $queue]);
                            }

                            Db::commit();
                            if ($p['cron_time'] <= time()) {
                                (new UniqueQueuer(ShopeeQueueJob::class))->push($queue);
                            } else {
                                (new UniqueQueuer(ShopeeQueueJob::class))->push($queue,$p['cron_time']);
                            }
                            $return = ['result' => true, 'message' => '更新成功'];
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }else{
                    throw new JsonErrorException("数据格式非法");
                }
            }else{
                throw new JsonErrorException("数据为空");
            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");

        }
    }

    /**
     * 更新刊登成功的商品信息
     * @param $params
     * @return array
     */
    private function updatePublishedProduct($post)
    {
        try{
            if(isset($post['vars']))
            {
                $vars = json_decode($post['vars'],true);
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            $uid = $post['uid'];
            $products =[];
            if(is_array($vars))
            {
                foreach ($vars as $k=>$var)
                {

                    $products[$k]['category_id']=$var['category_id'];
                    $products[$k]['days_to_ship']=$var['days_to_ship'];
                    $products[$k]['name'] = $var['name']; //刊登标题
                    $products[$k]['images'] = json_encode($var['images']); //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['attributes'] = json_encode($var['attributes']);//属性
                    $products[$k]['logistics'] = json_encode($var['logistics']);//物流
                    $products[$k]['wholesales'] = json_encode($var['wholesales']);//批发数据
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品原始图片
                    $products[$k]['id'] = $post['id'];
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['variants']=$var['variant'];
                }
            }else{
                throw new JsonErrorException("数据格式非法");
            }

            if($products)
            {
                if(is_array($products))
                {
                    foreach($products as $p)
                    {

                        if( $this->saveProductUpdateField($uid,$p))
                        {
                            ShopeeProduct::where('id','=',$p['id'])->update(['lock_product'=>1,'lock_update'=>1]);
                        }
                        $variants = $p['variants'];
                        unset($p['variants']);
                        if(is_array($variants) && $variants)
                        {
                            foreach ($variants as $variant)
                            {

                                if(!empty($variant))
                                {
                                    //刊登成功之后添加的sku
                                    if (!isset($variant['vid']))
                                    {
                                        $variant['pid'] = $p['id'];
                                        (new ShopeeVariant())->allowField(true)->isUpdate(false)->save($variant);
                                    }else{
                                        if($this->saveVariantUpdateField($uid,$variant))
                                        {
                                            ShopeeVariant::where('id','=',$variant['id'])->update(['lock_variant'=>1]);
                                            ShopeeProduct::where(['id'=>$variant['id']])->update(['lock_update'=>1]);
                                        }
                                    }
                                }
                            }
                        }
                        $return=['result'=>true,'message'=>'更新成功'];
                    }
                }else{
                    throw new JsonErrorException("数据格式非法");
                }
            }else{
                throw new JsonErrorException("数据格式非法");
            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }
    /**
     * 保存发生了变化的数据，记录到action_log
     * @param $uid 用户id
     * @param $variant
     * @return bool
     */
    public function saveVariantUpdateField($uid,$variant)
    {
        try{
            $fields =['name','sku','stock','price','original_price','status','variation_sku','discount_id'];
            $update=false;

            if(isset($variant['id']) && $variant['id'])
            {
                $object = ShopeeVariant::where('id','=',$variant['id'])->limit(1)->find();

                if($object)
                {
                    $row = is_object($object)?$object->toArray():$object;

                    $row['combine_sku'] = $object->getData('combine_sku');
                    $new_data=$old_data=[];
                    foreach ($variant as $type=>$value)
                    {
                        //当新提交的数据和原始数据不一样，且在指定修改的字段中时

                        if(in_array($type,$fields) && $row[$type]!=$variant[$type])
                        {
                            $new_data[$type]=$variant[$type];
                            $old_data[$type]=$row[$type];
                        }
                    }

                    if($new_data && $old_data)
                    {

                        $keys =array_keys($new_data);

                        $where=[
                            'create_id'=>['=',$uid],
                            'new_data'=>['=',json_encode($new_data)],
                            'variant_id'=>['=',$row['variant_id']],
                            'status'=>['=',0],
                        ];
                        $log=[
                            'create_id'=>$uid,
                            'type'=>self::TYPE['updateVariant'],
                            'new_data'=>json_encode($new_data),
                            'old_data'=>json_encode($old_data),
                            'product_id'=>$row['item_id'],
                            'variant_id'=>$row['variation_id'],
                            'create_time'=>time(),
                        ];
                        //如果修改了捆绑销售
                        if(isset($new_data['combine_sku']))
                        {
                            //如果只是编辑了捆绑sku
                            if(count($keys)==1 && $keys[0]=='combine_sku')
                            {
                                $log['status']=1;
                            }

                            $queue=[
                                'id'=>$variant['id'],
                                'combine_sku'=>$variant['combine_sku']
                            ];

                            // (new CommonQueuer(JoomCombineSkuQueue::class))->push($queue);
                        }

                        if($this->actionLog($log,$where))
                        {
                            $update=true;
                        }
                    }
                }
            }
            return $update;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }
    /**
     * 保存发生了变化的数据，记录到action_log
     * @param $uid 用户id
     * @param $product
     * @return bool
     */
    public function saveProductUpdateField($uid,$product)
    {
        try{
            $fields =['category_id','name','brand','images','weight','days_to_ship'];
            $info=['description','attributes','logistics','wholesales'];
            $update=false;
            if(isset($product['id']) && $product['id'])
            {
                foreach ($product as $type=>$value)
                {
                    if(in_array($type,$fields))
                    {
                        $row = ShopeeProduct::where('id','=',$product['id'])->limit(1)->find();
                    }elseif(in_array($type,$info)){
                        $row = ShopeeProductInfo::where('id','=',$product['id'])->limit(1)->find();
                    }else{
                        $row=[];
                    }

                    if($row)
                    {
                        $new_data=$old_data=[];
                        if($row[$type]!=$product[$type])
                        {
                            $new_data[$type]=$product[$type];
                            $old_data[$type]=$row[$type];
                        }
                        if($new_data && $old_data)
                        {
                            $where=[
                                'create_id'=>['=',$uid],
                                'new_data'=>['=',json_encode($new_data)],
                                'product_id'=>['=',$row['item_id']],
                                'status'=>['=',0],
                            ];
                            $log=[
                                'create_id'=>$uid,
                                'type'=>self::TYPE['updateProduct'],
                                'new_data'=>json_encode($new_data),
                                'old_data'=>json_encode($old_data),
                                'product_id'=>$row['item_id'],
                                'create_time'=>time(),
                            ];

                            if($this->actionLog($log,$where))
                            {
                                $update=true;
                            }
                        }
                    }
                }
            }
            return $update;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 获取商品是否全部刊登成功，true全部成功,false还有没有成功的
     * @param $id
     * @return bool
     */
    private function getPublishStatus($id)
    {
        $where=[
            'pid'=>['=',$id],
            'status'=>['<>',1],
        ];
        $variant = (new ShopeeVariant())->where($where)->field('publish_status')->find();
        if($variant)
        {
            return false;
        }else{
            return true;
        }
    }

    /**
     * 更新条目，如果未刊登就加入刊登，如果已刊登就加入更新日志
     * @param $parmas
     * @return bool
     */
    public function updateProductAndVariant($params)
    {
        $dbFlag = false;
        try{
            $i = 0;
            if (empty($params['id'])) {
                throw new Exception('传递的id不能为空');
            }
            $id = $params['id'];
            $this->validateUpdate($params);
            $publishStatus = ShopeeProduct::where(['id'=>$id])->value('publish_status');
            $enableUpdateOnlineStatus = [
                ShopeeHelper::PUBLISH_STATUS['success'],
                ShopeeHelper::PUBLISH_STATUS['inUpdateQueue'],
                ShopeeHelper::PUBLISH_STATUS['failUpdate'],
            ];
            $enableUpdateOfflineStatus = [
                ShopeeHelper::PUBLISH_STATUS['fail'],
                ShopeeHelper::PUBLISH_STATUS['noStatus'],
                ShopeeHelper::PUBLISH_STATUS['inPublishQueue'],
                ShopeeHelper::PUBLISH_STATUS['offLine'],
            ];
            $data['id'] = $id;
            $data['goods_id'] = $params['goods_id'];
            $data['spu'] = $params['spu'];
            $vars = json_decode($params['vars'], true);
            Db::startTrans();
            $dbFlag = true;
            foreach ($vars as $var) {
                $data['var'] = $var;
                $cronTime = empty($var['cron_time']) ? 0 : strtotime($var['cron_time']);
                if (in_array($publishStatus, $enableUpdateOfflineStatus)) {//未刊登
                    $id = $this->helper->saveProduct($data, $this->userId, true);
                    if (!is_numeric($id)) {
                        throw new Exception($id);
                    }
                    if ($publishStatus != ShopeeHelper::PUBLISH_STATUS['inPublishQueue']) {
                        (new UniqueQueuer(ShopeeQueueJob::class))->push($id, $cronTime);
                    }
                    ShopeeProduct::update(['publish_status'=>ShopeeHelper::PUBLISH_STATUS['inPublishQueue']], ['id'=>$id]);
                } else if (in_array($publishStatus, $enableUpdateOnlineStatus)) {//已刊登，写日志
                    $log['create_id'] = $this->userId;
                    $log['type'] = ShopeeHelper::UPDATE_TYPE['updateItem'];
                    $log['old_data'] = [];
                    $log['new_data'] = json_encode($data);
                    $log['product_id'] = $id;
                    $log['create_time'] = time();
                    $log['cron_time'] = $cronTime;
                    $logId = (new ShopeeActionLog())->insertGetId($log);
                    if (empty($logId)) {
                        throw new Exception('写入更新日志失败');
                    }
                    (new UniqueQueuer(ShopeeListingUpdateQueue::class))->push($logId, $cronTime);
                    //处理新增的变体
                    $variants = $var['variant'];
                    $addVariants = [];
                    foreach ($variants as $variant) {
                        if (empty($variant['variation_id'])) {
                            $skuMap = [
                                'combine_sku' => $variant['sku'].'*1',
                                'sku_code' => $variant['sku'],
                                'account_id' => $var['account_id'],
                                'channel_id' => 9
                            ];
                            $res = (new GoodsSkuMapService())->addSkuCodeWithQuantity($skuMap, $this->userId);
                            $variant['variation_sku'] = $res['result'] ? $res['sku_code'] : (new GoodsSkuMapService())->createSku($variant['sku']);
                            $variant['pid'] = $id;
                            $variant['local_sku'] = $variant['sku'];
                            $variant['combine_sku'] = $variant['sku'].'*1';
                            $variant['sku_id'] = $variant['id'];
                            $variant['create_time'] = time();
                            (new ShopeeVariant())->allowField(true)->save($variant);
                            $addVariants[] = $variant;
                        }
                    }
                    if (!empty($addVariants)) {
                        $newData = [
                            'item_id' => $var['item_id'],
                            'variations' => $addVariants
                        ];
                        $log['new_data'] = json_encode($newData);
                        $log['type'] = ShopeeHelper::UPDATE_TYPE['addVariations'];
                        $logId = (new ShopeeActionLog())->insertGetId($log);
                        if (empty($logId)) {
                            throw new Exception('写入更新日志失败');
                        }
                        (new UniqueQueuer(ShopeeListingUpdateQueue::class))->push($logId, $cronTime+60);
                    }
                }
            }
            Db::commit();

//            if(isset($parmas['id']) && $parmas['id'])
//            {
//
//                //获取商品刊登状态
//                if($this->getPublishStatus($id))
//                {
//                    $response = $this->updatePublishedProduct($parmas);
//                }else{
//                    $response = $this->updateUnpublishedProduct($parmas);
//                }
//                return $response;
//            }else{
//                throw new JsonErrorException("id非法,找不到对应数据，无法进行更新");
//            }

        }catch (Exception $exp){
            if ($dbFlag) {
                Db::rollback();
            }
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 删除刊登失败数据
     * @param $id
     */
    public function delete($id)
    {
        try {
            $ids = explode(',', $id);
            $res = $this->helper->delProducts($ids);
            if (!is_numeric($res)) {
                throw new Exception($res);
            }
            return $res;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
//        $varaints = ShopeeVariant::whereIn('pid',$id)->where('status','<>',1)->select();
//        if($varaints){
//            Db::startTrans();
//            try{
//                ShopeeProduct::whereIn('id',$id)->delete();
//                ShopeeVariant::whereIn('pid',$id)->delete();
//                ShopeeProductInfo::whereIn('id',$id)->delete();
//                Db::commit();
//                return ['message'=>'删除成功！'];
//            }catch (Exception $exp){
//                Db::rollback();
//                throw new JsonErrorException($exp->getMessage());
//            }
//        }else{
//            throw new JsonErrorException("没有相关数据");
//        }
    }

    /**
     * 获取商品数据
     * @param $id
     * @param $status
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function getProductAndVariant($id, $status)
    {
        try{
            $data = $this->helper->getProduct($id);
            if (!is_array($data)) {
                throw new Exception($data);
            }
            $product = $data['product'];
            $productInfo = $data['productInfo'];
            $variant = $data['variant'];

            $goodsId = $product['goods_id'];
            $goods =Goods::where('id',$goodsId)->find();
            $return['brand']='无品牌信息';
            if($goods){
                $brand = Brand::where('id',$goods['brand_id'])->find();
                $return['brand'] = isset($brand['name']) ? $brand['name'] : '无品牌信息';
            }
            $return['id'] = $id;
            $return['tort_flag'] = GoodsTortDescription::where('goods_id',$goodsId)->value('id') ? 1:0;
            $return['name'] = isset($goods['name']) ? $goods['name'] : '';
            $return['category'] = isset($goods['category']) ? $goods['category'] : '';
            $return['spu'] = isset($goods['spu']) ? $goods['spu'] : '';
            $return['base_url']=Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;
            $category_name='';
            ShopeeCategory::categoryNameTree($product['category_id'],$category_name);
            $account = ShopeeAccount::field('site,name,code')->where(['id'=>$product['account_id']])->find();
            $accountName = $account['name'];
            $accountCode = $account['code'];
            $siteId = $account['site_id'];

            //编辑时，获取分类所有属性，并与存储的属性合并
            $attributes = ShopeeAttribute::where(['category_id'=>$product['category_id']])->select();
            $productInfo['attributes'] = json_decode($productInfo['attributes'], true);
            $combineAttr = [];
            foreach ($productInfo['attributes'] as $attribute) {
                $combineAttr[$attribute['attribute_id']] = $attribute['attribute_value'];
            }
            foreach ($attributes as &$attribute) {
                if (isset($combineAttr[$attribute['attribute_id']])) {
                    $attribute['attribute_value'] = $combineAttr[$attribute['attribute_id']];
                }
            }
            //处理物流
            $logistics = ShopeeLogistic::where(['account_id'=>$product['account_id'], 'enabled'=>1])->select();//所有可用物流
            $productInfo['logistics'] = json_decode($productInfo['logistics'], true);//已选物流
            $checkedLogisticIds = [];//已选物流id
            $combineLogistics = [];
            foreach ($productInfo['logistics'] as $logistic) {
                $checkedLogisticIds[] = $logistic['logistic_id'];
                $combineLogistics[$logistic['logistic_id']] = $logistic;
            }
            foreach ($logistics as &$logistic) {
                if (in_array($logistic['logistic_id'], $checkedLogisticIds)) {
                    $logistic['is_checked'] = 1;
                    $logistic['is_free'] = isset($combineLogistics[$logistic['logistic_id']]['is_free']) ? $combineLogistics[$logistic['logistic_id']]['is_free'] : 0;
                }
            }

            $return['vars'] = array(
                array(
                    'account_id' => $product['account_id'] ? $product['account_id'] : 0,
                    'realname' => User::where(['id'=>$product['create_id']])->value('realname'),
                    'item_sku' => $product['item_sku'],
                    'item_id' => $product['item_id'],
                    'account_name' => $accountName,
                    'account_code' => $accountCode,
                    'name' => $product['name'],
                    'category_id' => $product['category_id'],
                    'category_name' => $category_name,
                    'attributes' => json_encode($attributes),
                    'logistics' => json_encode($logistics),
                    'wholesales' => $productInfo['wholesales'],
                    'cron_time' => $product['cron_time'] ? $product['cron_time'] : '',
                    'description' => $productInfo['description'],
                    'weight' => $product['weight'],
                    'package_length' => $product['package_length'],
                    'package_width' => $product['package_width'],
                    'package_height' => $product['package_height'],
                    'days_to_ship' => $product['days_to_ship'],
                    'images'=> $product['images'],
                    'variant' => $variant,
                    'site_id'=>$siteId,
                ),
            );

//            $where['id'] = ['eq', $id];
//            $model = new ShopeeProduct();
//            $data = $model->field("*")->with(['info','variants'])->where($where)->find();
//            $user=[];
//            if(!empty($data))
//            {
//                $data = $data->toArray();
//                $data['original_images']=$data['info']['original_images'];
//                $data['attributes']=$data['info']['attributes'];
//                $data['logistics']=$data['info']['logistics'];
//                $data['wholesales']=$data['info']['wholesales'];
//                $data['description']=$data['info']['description']?$data['info']['description']:'';
//                $site_id=0;
//                if(isset($data['account_id']))
//                {
//                    $accountInfo = (new ShopeeAccount())->alias('a')->field('a.*,b.id as site_id')->join('shopee_site b','a.site=b.code')->where(['a.id'=>$data['account_id']])->find();
//                    $accountName=$accountInfo?$accountInfo['code']:'';
//                    $site_id = $accountInfo?$accountInfo['site_id']:0;
//                }else{
//                    $accountName='';
//                }
//
//                $skuImages=[];
//                if($status=='copy')
//                {
//                    if(isset($data['goods_id']) && $data['goods_id'])
//                    {
//                        $goods_id = $data['goods_id'];
//                        $productImages = GoodsImage::getPublishImages($goods_id,3);
//                        $images=$productImages['spuImages'];
//                        $skuImages = $productImages['skuImages'];
//                    }else{
//                        $images=[];
//                    }
//                }else{
//                    $images=$data['images'];
//                }
//
//                $variants =$data['variants'];
//                if($variants && $status=='copy' && $skuImages)
//                {
//                    $variants = GoodsImage::replaceSkuImage($variants,$skuImages,$this->channel_id,'sku_id');
//                    $variants = GoodsImage::ObjToArray($variants);
//                }
//
//                foreach ($variants as &$variant){
//                    $variant['refer_name']=$variant['refer_name']='无';
//                }
//
//                $goods_id = $data['goods_id'];
//                $goods =Goods::where('id',$goods_id)->find();
//                $return['brand']='无品牌信息';
//                if($goods){
//                    $brand = Brand::where('id',$goods['brand_id'])->find();
//                    $return['brand']=isset($brand['name'])?$brand['name']:'无品牌信息';
//                }
//
//                $user = Cache::store('user')->getOneUser($data['create_id']);
//                $return['id']=$id;
//                $return['name']=isset($goods['name'])?$goods['name']:'';
//                $return['category']=isset($goods['category'])?$goods['category']:'';
//                $return['spu']=isset($goods['spu'])?$goods['spu']:'';
//                $return['base_url']=Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;
//                $category_name='';
//                ShopeeCategory::categoryNameTree($data['category_id'],$category_name);
//                $return['vars'] = array(
//                    array(
//                        'account_id'=>$data['account_id']?$data['account_id']:0,
//                        'realname'=>isset($user['realname'])?$user['realname']:'',
//                        'item_sku'=>$data['item_sku'],
//                        'account_name'=>$accountName,
//                        'account_code'=>$accountName,
//                        'realname'=>$user?$user['realname']:'',
//                        'name'=>$data['name'],
//                        'category_id'=>$data['category_id'],
//                        'category_name'=>$category_name,
//                        'attributes'=>$data['info']['attributes'],
//                        'logistics'=>$data['info']['logistics'],
//                        'wholesales'=>$data['info']['wholesales'],
//                        'cron_time'=>$data['cron_time']?$data['cron_time']:'',
//                        'description'=>$data['description'],
//                        'weight'=> $data['weight'],
//                        'package_length'=> $data['package_length'],
//                        'package_width'=> $data['package_width'],
//                        'package_height'=> $data['package_height'],
//                        'days_to_ship'=>$data['days_to_ship'],
//                        'images'=> $images,
//                        'variant'=>$variants,
//                        'site_id'=>$site_id,
//                    ),
//                );
//            }else{
//                $return=[];
//            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 获取sku属性
     * @param $sku_id
     * @return mixed
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getSkuAttr($skus,$goods_id)
    {
        foreach ($skus as &$sku){
            $sku_attributes = json_decode($sku['sku_attributes'], true);

            $name = '';
            foreach ($sku_attributes as $attribute_id => $attribute_value_id)
            {
                list($attr, $attr_id) = explode('_', $attribute_id);//$attr_id //属性名

                $attrKeyVal = (new AttributeValue())->field('a.value,a.code vcode,b.code')->alias('a')->join('attribute b', 'a.attribute_id=b.id', 'LEFT')
                    ->where(['a.id' => $attribute_value_id, 'a.attribute_id' => $attr_id])->find();

                if ($attrKeyVal)
                {
                    //如果类型是type获取style,则取goods_attribute表里的alias
                    if($attrKeyVal['code']=='type' || $attrKeyVal['code']=='style')
                    {
                        $where=[
                            'goods_id'=>['=',$sku['goods_id']],
                            'attribute_id'=>['=',$attr_id],
                            'value_id'=>['=',$attribute_value_id]
                        ];

                        $goodsAttribute = GoodsAttribute::where($where)->find();

                        if($goodsAttribute)
                        {
                            $name = empty($name) ? $goodsAttribute['alias'] : $name.'_'.$goodsAttribute['alias'];
//                            $referName = empty($referName) ? $goodsAttribute['alias'] : $referName.'_'.$goodsAttribute['alias'];
//                            if(strlen($sku['name']))
//                            {
//                                $sku['name']= $sku['name'].'_'.$goodsAttribute['alias'];
//                            }else{
//                                $sku['name']= $sku['name'].$goodsAttribute['alias'];
//                            }
                        }
                    } else {
//                        $referName[$attrKeyVal['code']] = $attrKeyVal['value'];
                        $name = empty($name) ? $attrKeyVal['value'] : $name.'_'.$attrKeyVal['value'];
//                        if (count($sku_attributes) > 2)
//                        {
//                            if(strlen($sku['name'])>0)
//                            {
//                                $sku['name'] = $sku['name'] . '_' . $attrKeyVal['value'];
//                            }else{
//                                $sku['name'] = $attrKeyVal['value'];
//                            }
//                        } else {
//                            $sku['name'] = $attrKeyVal['value'];
//                        }
                    }
                }
            }
            $sku['name'] = $name;
            $sku['price'] = $sku['stock']='';
            $sku['refer_name'] = $name;
        }
        return $skus;
    }
    public function validateUpdate($post = array())
    {
        $validate = new ShopeePublishValidate();

        $error = $validate->checkData($post,'update');

        if ($error)
        {
            throw new JsonErrorException($error);
        }
        if (isset($post['vars']))
        {

            $vars = json_decode($post['vars'], true);
            if (is_array($vars) && !empty($vars))
            {
                $error = $validate->checkVars($vars, 'var');
                if ($error)
                {
                    throw new JsonErrorException($error);
                }
            }
        }
    }
    /**
     * @node 验证提交数据合法性
     * @access public
     * @param array $post
     * @return string
     */
    public function validatePost($post = array())
    {
        $validate = new ShopeePublishValidate();

        $error = $validate->checkData($post,'create');

        if ($error)
        {
            throw new JsonErrorException($error);
        }
        $vars = json_decode($post['vars'], true);
        if (empty($vars))
        {
            throw new JsonErrorException('vars解析失败');
        }
        $error = $validate->checkVars($vars, 'var');
        if ($error)
        {
            throw new JsonErrorException($error);
        }
    }

    /**
     * 刊登
     * @param $post
     * @return int|string
     * @throws \Exception
     */
    public function create($post)
    {
        $dbFlag = false;
        try {
            $this->validatePost($post);
            $vars = json_decode($post['vars'], true); //每个账号信息
            $productIds = [];
            $cronTimes = [];
            $data['spu'] = $post['spu'];
            $data['goods_id'] = $post['goods_id'];
            Db::startTrans();
            $dbFlag = true;
            foreach ($vars as $k => $var)
            {
                $data['var'] = $var;
                $cronTimes[] = empty($var['cron_time']) ? 0 : strtotime($var['cron_time']);
                $productId = $this->helper->saveProduct($data,$this->userId);
                if (!is_numeric($productId)) {
                    throw new \Exception($productId);
                }
                $productIds[] = $productId;
            }
            Db::commit();
            $i = 0;
            foreach ($productIds as $k => $productId) {
                $i++;
                (new UniqueQueuer(ShopeeQueueJob::class))->push($productId, $cronTimes[$k]);
                ShopeeProduct::update(['publish_status'=>1], ['id'=>$productId]);//更新状态
            }
            return $i;
        } catch (\Exception $e) {
            if ($dbFlag) {
                Db::rollback();
            }
            throw new Exception($e->getMessage());
        }
    }
    public  function ProductStat($variantModel,$where=[])
    {
        if(empty($where))
        {
            return false;
        }else{
            $v=[];
        }

        if($variantModel->where($where)->find())
        {
            $min_price = $variantModel->where($where)->min('price');
            $max_price = $variantModel->where($where)->max('price');
            $min_shipping = $variantModel->where($where)->min('shipping');
            $max_shipping = $variantModel->where($where)->max('shipping');
            $v['inventory'] = $variantModel->where($where)->sum('inventory');
            $v['lowest_price']=$min_price;
            $v['highest_price']=$max_price;
            $v['lowest_shipping']=$min_shipping;
            $v['highest_shipping']=$max_shipping;
        }else{
            $v=[];
        }

        return $v;
    }
//    private function createWhere($params){
//        $where=[];
//        //刊登工具
//        if (isset($params['application']) && is_numeric($params['application'])){
//            if($params['application']==1){
//                $where['application']='rondaful';
//            }else{
//                $where['application']=['neq','rondaful'];
//            }
//        }
//
//        //销售人员
//        if (isset($params['account_id']) && $params['account_id']){
//            $where['account_id']=$params['account_id'];
//        }
//
//
//        //销售人员
//        if (isset($params['create_id']) && $params['create_id']){
//            $where['create_id']=['=',$params['create_id']];
//        }
//        //刊登状态
//        if (isset($params['publish_status']) && is_numeric($params['publish_status'])){
//            $where['p.publish_status']=intval($params['publish_status']);
//        }
//
//        //搜索条件
//        if (isset($params['snType']) && $params['snType'] && isset($params['nContent']) && $params['nContent']){
//            if($params['snType']=='item_id' ){
//                $where['p.'.$params['snType']]=['=',$params['nContent']];
//            }elseif($params['snType']=='name'){
//                $where['p.'.$params['snType']]=['like',"%{$params['nContent']}%"];
//            } elseif($params['snType']=='local_sku' || $params['snType']=='sku'){
//                $where['v.'.$params['snType']]=['like',$params['nContent'].'%'];
//            }elseif($params['snType']=='spu'){
//                $where['p.'.$params['snType']]=['like',"{$params['nContent']}%"];
//            }
//        }
//        $params['nTime']='create_time';
//        if (isset ($params['start_time']) && isset ($params['end_time']) && $params['end_time'] && $params['start_time'])
//        {
//            $params['start_time'] = $params['start_time'].'00:00:00';
//            $params['end_time'] = $params['end_time'].'23:59:59';
//            $where['p.'.$params['nTime']] = ['between time', [strtotime($params['start_time']), strtotime($params['end_time'])]];
//        } elseif (isset ($params['end_time']) && $params['end_time']) {
//            $where['p.'.$params['nTime']] = array('<=', strtotime($params['end_time'] . '23:59:59'));
//        } elseif (isset($params['start_time']) && $params['start_time']) {
//            $where['p.'.$params['nTime']] = array('>=', strtotime($params['start_time'] . '00:00:00'));
//        }
//
//        return $where;
//    }

    /***
     * 查询列表
     * @param $params 查询条件
     * @param int $page 页码
     * @param int $pageSize 页数
     * @param string $fields 字段
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function lists($params)
    {
        $wh = [];
        foreach ($params as $key => $value) {
            if ($value == '') {
                continue;
            }
            switch($key) {
                case 'account_id':
                    $wh['p.account_id'] = (int)$value;
                    break;
                case 'application':
                    if ($value == 1) {
                        $wh['p.application'] = 'rondaful';
                    } else {
                        $wh['p.application'] = ['neq','rondaful'];
                    }
                    break;
                case 'create_id':
                    $wh['p.create_id'] = (int)$value;
                    break;
                case 'snType':
                    if ($params['nContent'] == '') {
                        break;
                    }
                    switch ($value) {
                        case 'spu'://目前不支持批量
                            $goodsId = Goods::where('spu',$params['nContent'])->value('id');
                            $wh['p.goods_id'] = $goodsId;
                            break;
                        case 'local_sku':
                            $goodsId = GoodsSku::where('sku',$params['nContent'])->value('goods_id');
                            $wh['p.goods_id'] = $goodsId;
                            break;
                        case 'item_id':
                            $wh['p.item_id'] = $params['nContent'];
                            break;
                        case 'name':
                            $wh['p.name'] = ['like','%'.$params['nContent'].'%'];
                            break;
                    }
                    break;
                case 'spu_status':
                    $wh['g.sale_status'] = $value;
                    break;
                case 'start_time':
                    $startTime = strtotime($value);
                    break;
                case 'end_time':
                    $endTime = strtotime($value.' 23:59:59');
                    break;
                case 'status':
                    $wh['p.status'] = $value;
                    break;

            }
        }
        if (isset($startTime) || isset($endTime)) {
            $startTime = $startTime ?? 0;
            $endTime = $endTime ?? time();
            $wh['p.publish_create_time'] = ['between',[$startTime,$endTime]];
        }
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 50;

        $field = 'p.id,p.goods_id,p.account_id,p.create_id,p.update_id,p.name,p.item_sku,p.has_variation,p.item_id,p.create_time,
            p.update_time,p.publish_create_time,p.publish_update_time,p.spu,p.spu_status,p.sales,p.likes,p.images,p.publish_status,
            p.publish_message';
        $products = [];

        if (isset($wh['g.sale_status'])) {

        } else {
            $products = ShopeeProduct::alias('p')->field($field)->where($wh)->order('p.id desc')->with('variants')
                ->with(['account'=>function($query){
                    $query->field('id,name account_name,code,shop_id,site');
                }])->page($page,$pageSize)->select();
            $count = ShopeeProduct::alias('p')->where($wh)->count();
        }
        if ($products) {
            foreach ($products as &$product) {
                $product['username'] = $product->sellerName($product['create_id']);
                $product['sellername'] = $this->getAccountUser($product->account_id);
                $product['product_link'] = \app\publish\helper\shopee\ShopeeUtil::getProductLink($product['account']['site'], $product['account']['shop_id'], $product['item_id']);
            }
        }

        //查询是否关联本地spu
//        $goodsSkuMap = new GoodsSkuMap();
//        $related_goods = $goodsSkuMap->alias('a')->where('channel_id',9)->where('goods_id','<>','0')->distinct('goods_id')
//            ->column('goods_id,sku_code');
//        foreach ($products as $k=>$val){
//            $products[$k]['spu'] = empty( $related_goods[$val['goods_id']]) ? '' :$related_goods[$val['goods_id']];
//        }


//        $where=$this->createWhere($params);
//        $map=[];
//        if(isset($params['status']) && is_numeric($params['status'])){
//            $map['v.status']=['=',$params['status']];
//        }
//        if(isset($params['field']) && $params['field'] &&  isset($params['order']) && $params['order']){
//            $field=$params['field'];
//            $order = $params['order'];
//        }else{
//            $field='p.create_time';
//            $order='DESC';
//        }
//
//
//        $model = new ShopeeProduct();
//        $total = $model->alias('p')->where($where)->where($map)
//            ->join('shopee_variant v','p.id=v.pid','LEFT')
//            ->count('DISTINCT(p.id)');
//
//        $data  = $model->alias('p')->where($where)->where($map)
//            ->join('shopee_variant v','p.id=v.pid','LEFT')->with(['variants'=>function($query)use($map){
//                $query->alias('v')->where($map);
//            },'account'=>function($query){
//                $query->field('id,code,name account_name');
//            },'goods'=>function($query){
//                $query->field('id,spu');
//            }])->field($fields)->order($field,$order)->page($page,$pageSize)->select();
//        foreach ($data as &$d){
//            $d['username'] = $d->sellerName($d['create_id']);
//            $d['sellername'] = $this->getAccountUser($d->account_id);
//        }


        return ['data'=>$products,'page'=>$page,'pageSize'=>$pageSize,'total'=>$count];
    }

    /**
     * @param $account_id
     * @return mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private  function getAccountUser($account_id){
        $where=[
            'channel_id'=>$this->channel_id,
            'account_id'=>$account_id,
        ];
        $user = ChannelUserAccountMap::where($where)->field('b.realname')->alias('a')->join('user b','a.seller_id=b.id','LEFT')->limit(1)->find();

        return $user?$user['realname']:'';
    }


    /**
     * @title 删除item
     * @param $data
     * @throws Exception
     */
    public function delItem($data)
    {
        try {
            foreach ($data as $datum) {
                (new UniqueQueuer(ShopeeDeleteItemQueue::class))->push($datum);
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * @title 同步账号物流
     * @param $accountId
     * @throws Exception
     */
    public function syncAccountLogistics($accountId)
    {
        try {
            $res = $this->helper->syncLogistics($accountId);
            if ($res !== true) {
                throw new Exception($res);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @title 获取当前用户下的组员和当前平台shopee的交集
     * @return array|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getSellersByAuth()
    {
        $departmentModel = new Department();
        $departmentService = new DepartmentService();
        $departmentUserMap = new DepartmentUserMap();
        $userModel = new User();

        $user_info = Common::getUserInfo();
        $channel_id = ChannelAccountConst::channel_Shopee;

        // 判断是不是上级部门
        $par_departments = $departmentService->getParentDepartmentsByChannelId($channel_id);
        $is_header = $departmentUserMap->where('user_id', $user_info['user_id'])->whereIn('department_id',$par_departments)->find();

        $is_code_farming = DB('role_user')->where('user_id', $user_info['user_id'])->whereIn('role_id',[1,211])->find();//如果是IT或超管

        $departments = $departmentModel->where('channel_id', $channel_id)->column('id');

        if ($is_header || (!empty($is_code_farming))) {

            $data = $departmentUserMap->alias('a')->whereIn('department_id',$departments)->field('b.id,b.realname')->alias('a')
                ->join('user b','a.user_id=b.id','LEFT')
                ->distinct('a.seller')->select();

        }else {

            $dui= $departmentUserMap->where('user_id',$user_info['user_id'])->whereIn('department_id',$departments)->select();//找到用户在所在部门
            $data = [];
            foreach ($dui as $k){

                // 判断是不是小组组长
                if($k->job_id == 18){
                    $res = $departmentUserMap->alias('d')->field('user.id,user.realname,user.username')
                        ->where('department_id',$k['department_id'])->join('user','d.user_id = user.id','LEFT')->select();
                    $data = $res;
                }else{
                    // 是组员
                    $res = $userModel->where('id',$user_info['user_id'])->field('id,realname,username')->find();
                    $data[] = $res;
                }
            }
        }
        return $data;
    }

    /**
     *  @title 获取产品的线上spu列表
     * @throws Exception
     * @param $data
     * @return array|bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getVariantList($data)
    {
        $where = [];
        if(!empty($data['shopee_product_id'])) {//shopee_product表的id
            $where['pid'] = $data['shopee_product_id'];
        }
        if(!empty($data['variation_id'])) {
            $where['variation_id'] = $data['variation_id'];//上传返回的变量id
        }
        if(!empty($data['item_id'])) {
            $where['item_id'] = $data['item_id'];//上传返回的item_id
        }
        if(empty($where)) {
            return false;
        }

        // 找出变体
        $lists = ShopeeVariant::where($where)
            ->field('vid id,pid,variation_id,item_id,variation_sku,price,stock,name')
            ->select();
        if(empty($lists)){//单属性没有变体
            $lists = ShopeeProduct::where('id',$data['shopee_product_id'])
                ->field('id pid,item_id,item_sku variation_sku,price,stock,name')
                ->select();
            foreach ($lists as $k=>$v){
                $lists[$k]['id'] = '';
            }
        }

        return $lists;
    }

    /**
     * 获取平台所有账号的 昨天刊登数量，昨天在线listing数量，昨天在线ASIN数量
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getPublishDaily(array $accountIds)
    {
        set_time_limit(0);
        $dateline = date("Y-m-d",strtotime("-1 day"));
        $startTime = strtotime($dateline.' 00:00:00');
        $endTime = $startTime+86400;

        $accounts = ShopeeAccount::field('site,id account_id')->whereIn('id',$accountIds)->select();

        $accountWhere = [
            'channel_id' => $this->channel_id,
            'account_id' => ['IN',$accountIds],
        ];
        $sellers = ChannelUserAccountMap::where($accountWhere)->distinct('account_id')->column('seller_id','account_id');

        $spuWhere= [
            'publish_create_time' => ['LT',$endTime],
            'publish_status' => 1,
            'account_id' => ['IN',$accountIds],
            'status' =>1
        ];
        $published = ShopeeProduct::where('publish_create_time','EGT',$startTime)->where($spuWhere)->group('account_id')->column('COUNT(id)','account_id');
        $onlineSpu = ShopeeProduct::where($spuWhere)->group('account_id')->column('COUNT(id)','account_id');

        $skuWhere = [
            'a.publish_create_time' => ['LT',$endTime],
            'a.publish_status' => 1,
            'a.account_id' => ['IN',$accountIds],
            'a.status' =>1,
            'b.status' => 1,
            'b.publish_status'=>1
        ];
        $onlineSku = ShopeeProduct::alias('a')->join('shopee_variant b','b.pid=a.id','LEFT')
            ->where($skuWhere)->group('a.account_id')->column('COUNT(b.vid)','account_id');

        $result = [];
        foreach ($accounts as $k=>$account){
            $result[$k] = $account->toArray();
            $result[$k]['dateline'] = $dateline;
            $result[$k]["channel_id"] = $this->channel_id;
            $result[$k]["seller_id"] = isset($sellers[$account['account_id']]) ? $sellers[$account['account_id']] :'0';
            $result[$k]['publish_quantity'] = isset($published[$account['account_id']]) ? $published[$account['account_id']] :'0';
            $result[$k]['online_listing_quantity'] = isset($onlineSpu[$account['account_id']]) ? $onlineSpu[$account['account_id']] :'0';
            $result[$k]['online_asin_quantity'] = isset($onlineSku[$account['account_id']]) ? $onlineSku[$account['account_id']] :'0';
        }

        return $result;

    }
}