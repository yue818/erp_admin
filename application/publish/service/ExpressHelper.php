<?php
namespace app\publish\service;
use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\model\aliexpress\AliexpressAccount;
use app\common\model\aliexpress\AliexpressAccountBrand;
use app\common\model\aliexpress\AliexpressCategoryAttrVal;
use app\common\model\aliexpress\AliexpressProductImage;
use app\common\model\aliexpress\AliexpressPublishTemplate;
use app\common\model\aliexpress\AliexpressQuoteCountry;
use app\common\model\aliexpress\AliexpressSizeTemplate;
use app\common\model\Brand;
use app\common\model\Category;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsCategoryMap;
use app\common\model\GoodsLang;
use app\common\model\GoodsPublishMap;
use app\common\model\GoodsSkuMap;
use app\common\model\RoleUser;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\StockRuleLog;
use app\common\model\wish\WishAccount;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\goods\service\CategoryHelp;
use app\goods\service\GoodsCategoryMapService;
use app\goods\service\GoodsPublishMapService;
use app\index\service\AccountService;
use app\index\service\DownloadFileService;
use app\index\service\MemberShipService;
use app\index\service\Role;
use app\listing\queue\AliexpressCombineSkuQueue;
use app\listing\service\AliexpressListingHelper;
use app\order\controller\Aliexpress;
use app\publish\exception\AliPublishException;
use erp\AbsServer;
use service\aliexpress\AliexpressApi;
use app\common\model\aliexpress\AliexpressCategory;
use app\common\model\aliexpress\AliexpressCategoryAttr;
use app\common\model\aliexpress\AliexpressProduct;
use app\common\model\aliexpress\AliexpressFreightTemplate;
use app\common\model\aliexpress\AliexpressPromiseTemplate;
use app\common\model\Goods;
use think\Exception;
use think\Loader;
use app\common\model\GoodsSku;
use app\common\model\AttributeValue;
use app\common\model\User;
use app\common\model\Warehouse;
use app\common\model\aliexpress\AliexpressProductGroup;
use app\common\model\GoodsGallery;
use app\common\model\aliexpress\AliexpressAccountCategoryPower;
use app\common\service\Twitter;
use app\common\model\aliexpress\AliexpressProductInfo;
use app\common\model\aliexpress\AliexpressProductAttr;
use think\Db;
use app\common\model\aliexpress\AliexpressProductSku;
use app\common\model\aliexpress\AliexpressProductSkuVal;
use app\common\model\aliexpress\AliexpressPublishPlan;
use app\common\traits\User as UserTraits;
use app\common\model\aliexpress\AliexpressGroupRegion as AliexpressGroupRegionModel;
use app\goods\service\GoodsHelp;
use app\common\service\UniqueQueuer;
use app\publish\queue\AliexpressQueueJob;
use app\publish\queue\AliexpressPublishTemplateQueue;
use app\publish\queue\AliexpressPublishFailQueue;
use app\publish\queue\AliexpressPublishSyncDetailQueue;
use app\common\model\aliexpress\AliexpressPublishTask;
use app\common\model\DepartmentLog as DepartmentLogModel;
use app\publish\queue\AliexpressCategoryAttributeQueue;
use app\common\model\aliexpress\AliexpressProductImage as AliexpressProductImageModel;


class ExpressHelper extends AbsServer
{
    use UserTraits;
    protected $ApiCategory;
    protected $ApiPostProduct;
    protected $ApiImages;

    public static $png_image_error = [
        'sku_image' => 'SKU展图',
        'detail_image' => '详情描述',
        'publish_image' => '刊登图片',
    ];

    public function dowonload($ids)
    {
        $model = new AliexpressProduct();
        $ids = str_replace(";",",",$ids);
        $products = $model->with(['productSku','productInfo'])->whereIn('id',$ids)->select();

        $rows = [];
        foreach ($products as $product){
            $goods['hs_code']='';
            if($product['goods_id'])
            {
                $goods = Cache::store('Goods')->getGoodsInfo($product['goods_id']);
            }
            $account = Cache::store('AliexpressAccount')->getAccountById($product['account_id']);
            $group_name = AliexpressProductGroup::getNameByGroupId($product['account_id'],json_decode($product['group_id'],true));

            $code = $account?$account['code']:'';
            $hs_code=$goods?$goods['hs_code']:'';
            $variants = $product['productSku'];

            foreach ($variants as $variant){
                $row['code']=$code;
                $row['group_name']=$group_name;
                $row['parent_sku']=$product['goods_spu'];
                $row['name']=$product['subject'];
                $row['description']=strip_tags($product['productInfo']['detail']);
                $row['description']=str_replace('&nbsp;',' ',$row['description']);
                $row['tags']='';
                $row['sku']=$variant['sku_code'];
                $row['hs_code']=$hs_code;
                $row['quantity']=$variant['ipm_sku_stock'];
                $row['price']=$variant['sku_price'];
                $row['msrp']='';
                $row['shipping']='';
                $row['shipping_time']=$product['delivery_time'];

                $row['length']=$product['package_length'];
                $row['width']=$product['package_width'];
                $row['height']=$product['package_height'];
                $row['weight']=$product['gross_weight'];

                $images = explode(';',$product->getData('imageurls'));
                if($images)
                {
                    foreach ($images as $index=>$image)
                    {
                        if($index==0){
                            $row['main_image']=$image;
                            $row['thumb'] = $image;
                        }else{
                            $row['thumb'.$index] = $image;
                        }
                    }
                }

                if($index<10){
                    $start = $index+1;
                    for ($i=$start;$i<=10;$i++){
                        $row['thumb'.$i] = '';
                    }
                }

                $sku_attr = json_decode($variant['sku_attr'],true);
                $row['size']='_';
                if($sku_attr)
                {
                    foreach ($sku_attr as $attr)
                    {
                        if(isset($attr['skuImage']))
                        {
                            $row['display_image']=$attr['skuImage'];
                        }
                        if(isset($attr['skuPropertyId']) && $attr['skuPropertyId']==14){
                            if(isset($attr['propertyValueDefinitionName'])){
                                $row['color']=$attr['propertyValueDefinitionName'];
                            }else{
                                $attrValue = AliexpressCategoryAttrVal::where('id',$attr['propertyValueId'])->find();
                                if($attrValue)
                                {
                                    $row['color']=$attrValue['name_en'];
                                }else{
                                    $row['color']='';
                                }
                            }
                        }else{
                            if(isset($attr['propertyValueDefinitionName']))
                            {
                                $row['size']=$row['size'].$attr['propertyValueDefinitionName'];
                            }else{
                                $attrValue = AliexpressCategoryAttrVal::where('id',$attr['propertyValueId'])->find();
                                if($attrValue)
                                {
                                    $row['size']=$row['size'].$attrValue['name_en'];
                                }
                            }
                        }
                    }
                    $row['size']=substr($row['size'],1);
                }else{
                    $row['display_image']='';
                    $row['size']='';
                    $row['color']='';
                }

                $rows[$variant['id']]= $row;
            }
        }

        $header = [
            ['title' => 'Account short name', 'key' => 'code', 'width' => 10],
            ['title'=>'Group Name','key'=>'group_name','width'=>15,],
            ['title' => 'Parent Unique ID', 'key' => 'parent_sku', 'width' => 10],
            ['title' => '*Product Name', 'key' => 'name', 'width' => 35],
            ['title' => 'Description', 'key' => 'description', 'width' => 40],
            ['title' => '*Tags', 'key' => 'tags', 'width' => 35],
            ['title' => '*Unique ID', 'key' => 'sku', 'width' => 10],
            ['title' => 'Color', 'key' => 'color', 'width' => 10],
            ['title' => 'Size', 'key' => 'size', 'width' => 10],
            ['title'=>'*Quantity','key'=>'quantity','width'=>10],
            ['title' => '*Price', 'key' => 'price', 'width' => 10],
            ['title' => 'MSRP', 'key' => 'msrp', 'width' => 10],
            ['title' => '*Shipping', 'key' => 'shipping', 'width' => 10],
            ['title'=>'Shipping Time(enter without " ", just the estimated days )','key'=>'shipping_time', 'width' => 10],
            ['title' => 'Shipping Weight', 'key' => 'weight', 'width' => 10],
            ['title' => 'Shipping Length', 'key' => 'length', 'width' => 10],
            ['title' => 'Shipping Width', 'key' => 'width', 'width' => 10],
            ['title' => 'Shipping Height', 'key' => 'height', 'width' => 10],
            ['title' => 'HS Code', 'key' => 'hs_code', 'width' => 10],
            ['title' => '*Product Main Image URL', 'key' => 'main_image', 'width' => 20],
            ['title' => 'Variant Main Image URL', 'key' => 'display_image', 'width' => 20],
            ['title' => 'Extra Image URL', 'key' => 'thumb', 'width' => 20],
            ['title' => 'Extra Image URL 1', 'key' => 'thumb1', 'width' => 20],
            ['title' => 'Extra Image URL 2', 'key' => 'thumb2', 'width' => 20],
            ['title' => 'Extra Image URL 3', 'key' => 'thumb3', 'width' => 20],
            ['title' => 'Extra Image URL 4', 'key' => 'thumb4', 'width' => 20],
            ['title' => 'Extra Image URL 5', 'key' => 'thumb5', 'width' => 20],
            ['title' => 'Extra Image URL 6', 'key' => 'thumb6', 'width' => 20],
            ['title' => 'Extra Image URL 7', 'key' => 'thumb7', 'width' => 20],
            ['title' => 'Extra Image URL 8', 'key' => 'thumb8', 'width' => 20],
            ['title' => 'Extra Image URL 9', 'key' => 'thumb9', 'width' => 20],
            ['title' => 'Extra Image URL 10', 'key' => 'thumb10', 'width' => 20],
        ];
        $file = [
            'name' => '导出速卖通刊登商品数据',
            'path' => 'goods'
        ];
        $ExcelExport = new DownloadFileService();
        return $ExcelExport->exportCsv($rows, $header, $file);
    }

    /**
     * 获取授权本地分类
     * @param $account_id
     */
    public function getAuthCategorys($account_id)
    {
        $where=[
            'a.account_id'=>['=',$account_id],
            //'pid'=>['=',0],
        ];
        $fields ="a.account_id,a.local_category_id,c.*";
        $categoryService = new CategoryHelp();
        $categorys = (new AliexpressAccountCategoryPower)

            ->alias('a')->field($fields)
            ->join('category c','a.local_category_id=c.id','LEFT')
            ->where($where)
            ->group('a.local_category_id')
            ->select();

        foreach ($categorys as &$category)
        {
            $category = $category->toArray();
            $category['childs']=$categoryService->getSubIds($category['local_category_id']);
        }
        $rows = $this->getAuthLocalCategory($categorys,$account_id);
        return $rows;
    }

    private function getAuthLocalCategory($categorys,$account_id)
    {
        $pids = array_column($categorys,'id');

        foreach ($categorys as $category)
        {
            if(!in_array($category['pid'],$pids) && $category['pid']>0)
            {
                $localCategory = Category::where('id',$category['pid'])->find();
                if($localCategory)
                {
                    $localCategory = $localCategory->toArray();
                    $localCategory['account_id']=$account_id;
                    $localCategory['local_category_id']=$localCategory['id'];
                    $localCategory['childs']=(new CategoryHelp())->getSubIds($localCategory['local_category_id']);
                    array_unshift($categorys,$localCategory);
                    $pids = array_column($categorys,'id');
                    if($localCategory['pid'])
                    {
                        self::getAuthLocalCategory($categorys,$account_id);
                    }
                }
            }
        }
        return $categorys;
    }


    public function getAccounts($userId,$spu='',$channel_id=4)
    {
        $users = $this->getUnderlingInfo($userId);
        $memberShipService = new MemberShipService();
        #所有所属下级销售账号
        $accountList = [];
        foreach($users as $k => $user){
            $temp = $memberShipService->getAccountIDByUserId($user,$channel_id);
            $accountList = array_merge($temp,$accountList);
        }
        $acService = new AccountService();
        #已启用已授权账号
        $accountIds = $acService->accountInfo($channel_id);

        #已绑定销售员的账号
        $saleAccount = [];
        $roles = $this->getUserRoles($userId);

        if($channel_id==3 && $roles){
            $addAccounts = [];
            foreach ($roles as $role) {
                $addAccounts  = array_merge($this->getRoleAccessAccounts($role),$addAccounts);
            }
            $accountIds['account'] = array_merge_recursive($accountIds['account'] ,$addAccounts);
        }

        $wh['channel'] = $channel_id;
        if($spu){
            $wh['spu'] = $spu;
        }
        $cache = GoodsPublishMap::where($wh)->value('publish_status');
        $cache = json_decode($cache, true);

        foreach($accountIds['account'] as &$ac)
        {
            if(in_array($ac['value'],$accountList) || !empty($addAccounts))
            {
                 $temp2 = $memberShipService->member($channel_id,$ac['value'],'sales');
                if(count($temp2)>0)
                {

                    if ($cache && in_array($ac['value'],$cache ))
                    {
                        //self::array_remove($sellers, $k);
                        $ac['publish']=1;
                    }else{
                        $ac['publish']=0;
                    }

                    foreach ($temp2 as $item)
                    {
                        $ac['id']=$ac['value'];
                        $account=[];
                        if($channel_id==9){
                            $account = ShopeeAccount::where('id',$ac['id'])->find();
                        }
                        if($account){
                            $ac['site_id'] = $account['site_id'];
                        }
                        
                        $ac['account_id']=$ac['value'];
                        $ac['code']=$ac['label'];
                        $ac['realname']=$item['realname'];
                        array_push($saleAccount,$ac);
                    }
                }
            }
        }

        return $saleAccount;
    }
    public function getUserRoles($userId){
        return RoleUser::where('user_id',$userId)->column('role_id');
    }
    /**
     * @param $rodeId 角色id
     * @param $nodeId 节点id
     */
    public function getRoleAccessAccounts($rodeId=219,$nodeId=337831){
//        $userInfo = Common::getUserInfo(request());
//        $userId = $userInfo['user_id'];
        $roleAccess = (new Role())->getNodeAccess($rodeId,$nodeId);
        $accounts = [];
        if($roleAccess){
            $accountArray = array_values($this->ObjToArray($roleAccess));
            if (empty($accountArray)) {
                return [];
            }
            $accountIds = implode(',',$accountArray[0]);
            $accounts = WishAccount::whereIn('a.id',$accountIds)->alias('a')->join('channel_user_account_map b','b.account_id=a.id')
                ->join('user u','u.id=b.seller_id')
                ->field('a.code label,a.id value,a.account_name')->where('channel_id',3)->select();
            foreach ($accounts as &$account){
                $account = $account->toArray();
            }
        }
        return $accounts;
    }

    /**
     * 获取一个分类下面的所有子分类
     * @param $pid 父分类id
     * @return array
     */
    public static function getAllChilds($pid,&$categories=[])
    {
        $items= (new AliexpressCategory())->field('category_id')->where('category_pid','=',$pid)->select();

        if($items)
        {

            foreach ($items as $item)
            {
                $categories[]=$item['category_id'];
            }

            foreach ($items as $item)
            {
                self::getAllChilds($item['category_id'],$categories);
            }
        }
        return $categories;

    }

    /**
     * 获取速卖通所有的分类
     * @param $params
     * @param int $page
     * @param int $pageSize
     */
    public function getAliexpressAllCategorysByBrand($account_id,$params,$page=1,$pageSize=30,$brand_id=0)
    {

        //step1:获取指定帐号绑定的分类

        $auth_categories = AliexpressAccountCategoryPower::where('account_id',$account_id)->field('distinct(category_id)')->select();

        if(empty($auth_categories))
        {
            throw new JsonErrorException("帐号还没有绑定平台分类，请先绑定平台分类");
        }

        foreach ($auth_categories as $auth)
        {
            $categories = self::getAllChilds($auth['category_id']);
        }

        if(isset($categories) && $categories)
        {
            $inCategory = $categories;
        }else{
            $inCategory=[];
        }

        $where="";

        if($params)
        {
            if(preg_match('/^[\x{4e00}-\x{9fa5}]+$/u',$params,$match))
            {
                $where=" name_zh ='{$params}' ";
            }else{
                $where=" name_en = '{$params}' ";
            }
        }

        $brand_count = AliexpressCategoryAttrVal::where($where)->count();

        if($brand_count>1 && empty($brand_id)){ //如果存在2个相同的品牌
            $brands = AliexpressCategoryAttrVal::where($where)->select();
            return ['brands'=>$brands,'multi_brand'=>1];
        }else{

            if(empty($brand_id))
            {
                $brand = AliexpressCategoryAttrVal::where($where)->find();
                $brand_id = $brand['id'];
            }
            $model =  (new AliexpressCategoryAttr());

            $total =$model->whereLike('list_val','%'.$brand_id.'%')
                ->alias('a')
                ->join('aliexpress_category b','a.category_id=b.category_id','LEFT')
                ->whereIn('a.category_id',$inCategory)
                ->count();

            $data =$model->whereLike('list_val','%'.$brand_id.'%')
                ->alias('a')
                ->join('aliexpress_category b','a.category_id=b.category_id','LEFT')
                ->whereIn('a.category_id',$inCategory)
                ->select();

            $items=[];

            if($data)
            {
                foreach ($data as $d)
                {
                    if($d['category_pid']>0)
                    {

                        $parentCategory = AliexpressCategory::getAllParent($d['category_pid']);
                        $name="";
                        if ($parentCategory)
                        {
                            foreach ($parentCategory as $item)
                            {
                                $name=$name.$item['category_name'].">>";
                            }
                        }
                        $d['category_name_zh']=$name.$d['category_name_zh'];
                    }
                    $items[]= $d;
                }
            }

            return ['data'=>$items,'multi_brand'=>0,'brand_id'=>$brand_id,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize];

        }







        $data = AliexpressCategoryAttrVal::where($where)->alias('a')->join('aliexpress_category b','a.category_id=b.category_id')->page($page,$pageSize)->select();

        $total = AliexpressCategoryAttrVal::where($where)->alias('a')->join('aliexpress_category b','a.category_id=b.category_id')->count();


        $items=[];

        if($data)
        {
            foreach ($data as $d)
            {
                if($d['category_pid']>0)
                {

                    $parentCategory = AliexpressCategory::getAllParent($d['category_pid']);
                    $name="";
                    if ($parentCategory)
                    {
                        foreach ($parentCategory as $item)
                        {
                            $name=$name.$item['category_name'].">>";
                        }
                    }
                    $d['category_name_zh']=$name.$d['category_name_zh'];
                }
                $items[]= $d;
            }
        }

        return ['data'=>$items,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize];


        if(empty($count))
        {
            throw new JsonErrorException("没有找到品牌{$params}");
        }elseif($count>1){ //多个品牌
            $multi_brand=1;
            $brands = AliexpressCategoryAttrVal::where($where)->select();
            $ids = array_column($brands,'id');
            //$categories = AliexpressCategoryAttr::whereIn('id',$ids)->alias('a')->join('aliexpress_category b','a.category_id=b.category_id')->select();
            dump($categories);die;
        }else{
            $multi_brand=0;
            $brand = AliexpressCategoryAttrVal::where($where)->count();
            $brand_id = $brand['id'];
        }



        $total = (new AliexpressCategoryAttr())->whereLike('list_val','%'.$brand_id.'%')
                 ->alias('a')
                 ->join('aliexpress_category b','a.category_id=b.category_id','LEFT')
                 ->whereIn('a.category_id',$inCategory)->count();

        $data = (new AliexpressCategoryAttr())->whereLike('list_val','%'.$brand_id.'%')
            ->alias('a')
            ->join('aliexpress_category b','a.category_id=b.category_id','LEFT')
            ->whereIn('a.category_id',$inCategory)->select();


//        $total = (new AliexpressCategory())
//            ->where(['category_isleaf'=>1])->whereIn('category_id',$inCategory)
//            ->with(['attribute'=>function($query)use($where){$query->where('id',2)
//                ->with(['attributeVal'=>function($query)use($where){
//                $query->where($where);
//            }]);}])
//            ->count();
//
//        $data = (new AliexpressCategory())->field('category_id,category_pid,category_name_zh')
//            ->where(['category_isleaf'=>1])->whereIn('category_id',$inCategory)
//            ->with(['attribute'=>function($query)use($where){$query->field('category_id,id')->where('id',2)
//                ->with(['attributeVal'=>function($query)use($where){
//                    $query->field('attr_id')->where($where);
//            }]);}])->select();

        $items=[];

        if($data)
        {
            foreach ($data as $d)
            {
                if($d['category_pid']>0)
                {

                    $parentCategory = AliexpressCategory::getAllParent($d['category_pid']);
                    $name="";
                    if ($parentCategory)
                    {
                        foreach ($parentCategory as $item)
                        {
                            $name=$name.$item['category_name'].">>";
                        }
                    }
                    $d['category_name_zh']=$name.$d['category_name_zh'];
                }
                $items[]= $d;
            }
        }

        return ['data'=>$items,'brand_id'=>$brand_id,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize];
    }
    /**
     * 获取速卖通所有的分类
     * @param $params
     * @param int $page
     * @param int $pageSize
     */
    public function getAliexpressAllCategorys($params,$page=1,$pageSize=30)
    {
        $where="";
        if($params)
        {
            //$where['category_id']=['=',$params];
            //$where['category_name_zh']=['like','%'.$params.'%'];
            //$where['_logic']='OR';

            $where=" category_id ='{$params}' OR  category_name_zh like '%{$params}%' ";
        }

        $total = (new AliexpressCategory())->where(['category_isleaf'=>1])->where($where)->count();

        $data = (new AliexpressCategory())->where(['category_isleaf'=>1])->where($where)->page($page,$pageSize)->select();

        $items=[];

        if($data)
        {
            foreach ($data as $d)
            {
                if($d['category_pid']>0)
                {

                    $parentCategory = AliexpressCategory::getAllParent($d['category_pid']);
                    $name="";
                    if ($parentCategory)
                    {
                        foreach ($parentCategory as $item)
                        {
                            $name=$name.$item['category_name'].">>";
                        }
                    }
                    $d['category_name_zh']=$name.$d['category_name_zh'];
                }
                $items[]= $d;
            }
        }


        return ['data'=>$items,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize];
    }
    /**
     * 保存刊登分类与属性
     * @param $publishData
     * @param $uid
     */
    public function savePublishTemplateData($publishData,$uid)
    {
        try{
            $arr_new_attr = [];

            if(!empty($publishData['attr']))
            {
                $publishAttr = json_decode($publishData['attr'],true);

                foreach($publishAttr as $k=>$value)
                {
                    if(isset($value['attrValueId']) && is_array($value['attrValueId']))
                    {
                        foreach($value['attrValueId'] as $valueId)
                        {
                            array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValueId'=>$valueId]);
                        }
                        if(isset($value['attrValue']) && !empty($value['attrValueId']))
                        {
                            array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValue'=>$value['attrValue']]);
                        }
                    }else{
                        array_push($arr_new_attr,$value);
                    }
                }
            }

            $attribute = json_encode($arr_new_attr);
            //保存刊登分类与属性模板
            $map=[
                'goods_id'=>['=',$publishData['goods_id']],
                'channel_category_id'=>['=',$publishData['category_id']],
            ];
            $publishTemplateData=[
                'goods_id'=>$publishData['goods_id'],
                'channel_category_id'=>$publishData['category_id'],
                'data'=>$attribute,
            ];

            if($oldTemplateData = (new AliexpressPublishTemplateService())->find($map))
            {
                //如果是同一个人创建的，则更新
                if($oldTemplateData['create_id']!= $uid)
                {
                    $publishTemplateData['create_id']=$uid;
                }else{
                    $publishTemplateData['create_id']=$oldTemplateData['create_id'];
                }

                $publishTemplateData['update_time']=time();
                $publishTemplateData['id']=$oldTemplateData['id'];

                $log=[
                    'tid'=>$oldTemplateData['id'],
                    'create_id'=>$oldTemplateData['create_id'],
                    'new_data'=>$attribute,
                    'old_data'=>$oldTemplateData['data'],
                    'create_time'=>time(),
                ];
                if((new AliexpressPublishTemplateLogService)->save($log))
                {
                    (new AliexpressPublishTemplate())->isUpdate(true)->save($publishTemplateData);
                }
            }else{
                $publishTemplateData['create_id']=$uid;
                $publishTemplateData['create_time']=time();

                if((new AliexpressPublishTemplateService())->save($publishTemplateData))
                {
                    $categoryMapWhere=[
                        'goods_id'=>['=',$publishData['goods_id']],
                        'channel_id'=>['=',4],
                        'channel_category_id'=>['=',$publishData['category_id']],
                    ];

                    $goodsCategoryMapData=[
                        'goods_id'=> $publishData['goods_id'] ,
                        'channel_id'=> 4,
                        'update_id'=> $uid,
                        'channel_category_id'=> $publishData['category_id'],
                    ];

                    if($has = (new GoodsCategoryMapService())->find($categoryMapWhere))
                    {
                        $goodsCategoryMapData['id']=$has['id'];
                        $goodsCategoryMapData['update_time']=time();
                        (new GoodsCategoryMapService())->save($goodsCategoryMapData);
                    }else{
                        $goodsCategoryMapData['create_time']=time();
                        (new GoodsCategoryMapService())->save($goodsCategoryMapData);
                    }
                }
                //回写商品平台分类关系
            }
            return true;
        }catch (JsonErrorException $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }


    /**
     *过滤属性非英文字符
     *
     */
    public function checkAttribute($attrValue)
    {
        //是否有中文
        if(preg_match('/([\x{4e00}-\x{9fa5}])/u',$attrValue,$match)) {
            return ['status' => -1 , 'message' => '检查属性名与属性值是否有中文'];
        }

        $attrValue = trim(preg_replace("/(\s+|\&nbsp\;|　|\xc2\xa0)/"," ",$attrValue));
        $attrValue = trim($attrValue,'​');
        return $attrValue;
    }


    /**
     * 提交刊登前校验
     *
     */
    protected function savePublishCheck($publishData)
    {
        //根据goods_id检查产品是否禁止上架
        if(isset($publishData['goods_id']) && $publishData['goods_id'] && empty((new GoodsHelp())->getPlatformForChannel($publishData['goods_id'], 4))){
            return ['status' => -1, 'message' => '商品禁止上架'];
        }


        //发货期
        if(isset($publishData['delivery_time']) && $publishData['delivery_time'] > 7) {
            return ['status' => -1, 'message' => '发货期不能大于7天'];
        }


        $accountId = $publishData['account_id'];
        //品牌
        if(isset($publishData['brand_id']) && $publishData['brand_id']) {
            $accountBrandModel = new AliexpressAccountBrand();
            $accountBrandInfo = $accountBrandModel->field('id')->where(['account_id' => $accountId, 'category_id' => $publishData['category_id'], 'attr_value_id' => $publishData['brand_id']])->find();
            if(empty($accountBrandInfo)) {
                return ['status' => -1, 'message' => '请获取最新品牌,再绑定品牌.获取失败,请先在店铺后台设置'];
            }
        }

        //产品分组
        if(isset($publishData['group_id']) && $publishData['group_id']) {
            $productGroupModel = new AliexpressProductGroup();
            $productGroupInfo = $productGroupModel->field('group_id')->where(['group_id' => $publishData['group_id'], 'account_id' => $accountId])->find();
            if(empty($productGroupInfo)) {
                return ['status' => -1, 'message' => '请获取最新产品分组,再选择对应的产品分组'];
            }
        }

        //产品运费模板
        if(isset($publishData['freight_template_id']) && $publishData['freight_template_id']) {
            $freightTemplateModel = new AliexpressFreightTemplate();
            $freightTemplateInfo = $freightTemplateModel->field('template_id')->where(['account_id' => $accountId, 'template_id' =>$publishData['freight_template_id']])->find();
            if(empty($freightTemplateInfo)) {
                return ['status' => -1, 'message' => '请获取最新产品运费模板,再选择对应的产品运费模板'];
            }
        }

        //服务模板
        if(isset($publishData['promise_template_id']) && $publishData['promise_template_id']) {
            $promiseTemplateModel = new AliexpressPromiseTemplate();
            $promiseTemplateInfo = $promiseTemplateModel->field('template_id')->where(['account_id' => $accountId, 'template_id' =>$publishData['promise_template_id'],'account_id' => $accountId])->find();
            if(empty($promiseTemplateInfo)) {
                return ['status' => -1, 'message' => '请获取最新服务模板,再选择对应的服务模板'];
            }
        }

        //检查是否是单属性分类,单属性分类只能添加单个sku
        if($publishData['category_id']) {
            $categroySkuAttr = (new AliexpressCategoryAttr())->field('id')->where(['category_id' => $publishData['category_id'], 'sku' => 1])->select();

            if(count($categroySkuAttr) == 1 && count($publishData['sku']) > 1) {
                return ['status' => -1, 'message' => '单属性分类只能添加一个SKU'];
            }
        }


        //校验商品图片.是否是png,是png则返回
        if($publishData['imageurls']){
            $imageUrls = explode(';', $publishData['imageurls']);
            $imagePng = '';
            foreach ($imageUrls as $imgVal){
                $imagePng .= $this->checkPngImages($imgVal,self::$png_image_error['publish_image']);
            }

            if($imagePng){
                return ['status' => -1, 'message' => $imagePng];
            }
        }

        //检测png图片
        $detail = $publishData['detail'];
        if($detail){
            $preg='/<img[\s\S]*?src\s*=\s*[\"|\'](.*?)[\"|\'][\s\S]*?>/';
            preg_match_all($preg,$detail,$match);

            $detailImagePng = '';
            if(isset($match[1]) && $match[1]){
                $base_url = Cache::store('configParams')->getConfig('innerPicUrl')['value'] . '/';
                foreach ($match[1] as $val) {
                    $val = str_replace($base_url, '', $val);
                    $detailImagePng .= $this->checkPngImages($val,self::$png_image_error['detail_image']);
                }

                if($detailImagePng){
                    return ['status' => -1, 'message' => $detailImagePng];
                }
            }
        }


        $checkAttrName = array_column($publishData['attr'],'attrName');
        if($checkAttrName && count($checkAttrName) != count(array_unique($checkAttrName))) {
            return ['status' => -1, 'message' => '自定义属性名重复,请去掉重复属性名,再进行刊登'];
        }

        return ['status' => 1, 'message' => 'true'];
    }


    public function savePublishData($publishData,$uid=0)
    {
        $publishCheck = $this->savePublishCheck($publishData);
        if(isset($publishCheck['status']) && $publishCheck['status'] < 0) {
            return $publishCheck;
        }


        $productModel = new AliexpressProduct();
        //组装ProductInfo表信息
        $arr_new_attr = [];
        if(!empty($publishData['attr']))
        {
            foreach($publishData['attr'] as $k=>$value)
            {
                if(isset($value['attrValueId'])&&is_array($value['attrValueId']))
                {
                    foreach($value['attrValueId'] as $valueId)
                    {
                        array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValueId'=>$valueId]);
                    }

                    if(isset($value['attrValue'])&&!empty($value['attrValueId']))
                    {
                        array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValue'=>$value['attrValue']]);
                    }

                }else{

                    if(isset($value['attrName']) && $value['attrName']){
                        $attrName = $this->checkAttribute($value['attrName']);

                        if(isset($attrName['status'])) {
                            return $attrName;
                        }

                        if(strlen($attrName) > 40) {
                            return ['status' => -1, 'message' => '自定义属性名超过了40个字符'];
                        }

                        $value['attrName'] = $attrName;
                    }

                    if(isset($value['attrValue']) && $value['attrValue']){
                        $attrValue = $this->checkAttribute($value['attrValue']);

                        if(isset($attrValue['status'])) {
                            return $attrValue;
                        }

                        if(strlen($attrValue) > 70) {
                            return ['status' => -1, 'message' => '自定义属性值长度超过了70个字符'];
                        }

                        $value['attrValue'] = $attrValue;
                    }

                    array_push($arr_new_attr,$value);
                }

            }
        }

        $publishData['attr'] = $arr_new_attr;


        if(isset($publishData['brand_id']))
        {
            array_push($publishData['attr'],['attrNameId'=>2,'attrValueId'=>$publishData['brand_id']]);
        }

        $arrProductInfoData = [
            'detail'=>$publishData['detail'],
            'mobile_detail'=>empty($publishData['mobile_detail'])?'{}':json_encode($publishData['mobile_detail']),
            'product_attr'=>json_encode($publishData['attr']),
            'region_group_id' => isset($publishData['region_group_id']) ? $publishData['region_group_id'] : 0,
            'region_template_id' => isset($publishData['region_template_id']) ? $publishData['region_template_id'] : 0,
        ];

        //组装ProductSku表信息
        $arrProductSkuData = [];
        foreach($publishData['sku'] as $sku)
        {
            $sku_attr_relation = json_encode($sku['sku_attr']);
            if($sku['sku_attr']){
                foreach($sku['sku_attr'] as $sku_key => $sku_attr){
                    if(isset($sku['sku_attr'][$sku_key]['attribute_id'])){
                        unset($sku['sku_attr'][$sku_key]['attribute_id']);
                    }
                    if(isset($sku['sku_attr'][$sku_key]['attribute_value_id'])){
                        unset($sku['sku_attr'][$sku_key]['attribute_value_id']);
                    }
                }
            }
            unset($sku['sku_attr']['attribute_id'],$sku['sku_attr']['attribute_value_id']);

            if( isset($sku['extend']) && !empty($sku['extend']))
            {
                foreach ($sku['extend'] as $extend)
                {
                    $arr_sku_attr = [];
                    $extend_attr = [
                        'skuPropertyId'=>$extend['ali_attr_id'],
                        'propertyValueId'=>$extend['ali_attr_val_id']
                    ];
                    $arr_sku_attr = $sku['sku_attr'];
                    $arr_sku_attr[] = $extend_attr;
                    $arrProductSkuData[] = [
                        //'ali_product_id'=>$id,
                        'sku_price'=>$extend['retail_price'],
                        'sku_code'=>$sku['sku_code'],
                        'combine_sku'=>$sku['combine_sku'],
                        'sku_stock'=>$extend['ipm_sku_stock']>0?1:0,
                        'ipm_sku_stock'=>$extend['ipm_sku_stock'],
                        'currency_code'=>isset($extend['currency_code'])?$sku['currency_code']:'USD',
                        'sku_attr_relation'=>$sku_attr_relation,
                        'sku_attr'=>json_encode($arr_sku_attr),
                        'goods_sku_id'=>$sku['goods_sku_id'],
                        'current_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                        'pre_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                    ];
                }
            }else{
                $arrProductSkuData[] = [
                    //'ali_product_id'=>$id,
                    'sku_price'=>$sku['sku_price'],
                    'sku_code'=>$sku['sku_code'],
                    'combine_sku'=>$sku['combine_sku'],
                    'sku_stock'=>$sku['ipm_sku_stock']>0?1:0,
                    'ipm_sku_stock'=>$sku['ipm_sku_stock'],
                    'currency_code'=>isset($sku['currency_code'])?$sku['currency_code']:'USD',
                    'sku_attr_relation'=>$sku_attr_relation,
                    'sku_attr'=>json_encode($sku['sku_attr']),
                    'goods_sku_id'=>$sku['goods_sku_id'],
                    'current_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                    'pre_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                ];
            }
        }
        
        unset($publishData['detail'],$publishData['mobile_detail'],$publishData['product_attr'],$publishData['sku']);
        //组装Product主表信息
        if(isset($publishData['isPackSell'])&&!$publishData['isPackSell'])
        {
            $publishData['baseUnit'] = $publishData['addUnit'] = $publishData['addWeight'] = 0;
        }

        if(isset($publishData['is_wholesale'])&&!$publishData['is_wholesale'])
        {
            $publishData['bulk_order'] = $publishData['bulk_discount'] = 0;
        }
        $publishData['currency_code'] = (isset($publishData['currency_code'])&&$publishData['currency_code'])?$publishData['currency_code']:'USD';

        $arrProductData = $publishData;

        $arrProductData['quote_config_status']=isset($publishData['quote_config_status'])?$publishData['quote_config_status']:0;

        if(isset($publishData['aeopNationalQuoteConfiguration']))
        {
            $arrProductData['aeop_national_quote_configuration']=json_encode($publishData['aeopNationalQuoteConfiguration']);
        }elseif(isset($publishData['aeop_national_quote_configuration'])){
            $arrProductData['aeop_national_quote_configuration']=json_encode($publishData['aeop_national_quote_configuration']);
        }

        //sku价格
        $arr_price = array_column($arrProductSkuData,'sku_price');
        $arrProductData['product_max_price'] = max($arr_price);
        $arrProductData['product_min_price'] = min($arr_price);
        $arrProductData['product_price']=max($arr_price);

        $arrProductData['group_id'] = empty($arrProductData['group_id'])?json_encode([]):json_encode([$arrProductData['group_id']]);

        $arrProductData['market_images'] = isset($arrProductData['market_images']) && $arrProductData['market_images']?json_encode([$arrProductData['market_images']]) : json_encode([]);

        //保存刊登分类与属性模板
        $map=[
            'goods_id'=> $arrProductData['goods_id'],
            'channel_category_id'=> $arrProductData['category_id'],
        ];

        $publishTemplateData=[
            'data'=>$arrProductInfoData['product_attr'],
            'create_id' => $uid
        ];

        //写入到缓存中
        $hashKey = $arrProductData['goods_id'].$arrProductData['category_id'];
        Cache::handler()->hSet('aliexpress_publish_template', $hashKey, \GuzzleHttp\json_encode($publishTemplateData));
        //速卖通模板队列
        (new UniqueQueuer(AliexpressPublishTemplateQueue::class))->push($map);

        //检测是否已存在
        $where = [];

        if(isset($publishData['id']) && !empty($publishData['id']))
        {
            $where['id'] = ['=',$publishData['id']];
        }

        if($where)
        {
            $objProduct = $productModel->where($where)->find();
        }else{
            $objProduct=[];
        }

        if(empty($objProduct))
        {

            $id = abs(Twitter::instance()->nextId(4,$publishData['account_id']));
            $arrProductData['id'] = $id;
            $arrProductData['application'] = 'rondaful';
            $arrProductData['publisher_id'] = $uid;

            $productSkuData = $this->productSkuData($arrProductSkuData);

            $result = $productModel->addProduct($arrProductData,$arrProductInfoData,$arrProductSkuData);
        }else{
            //如果已经刊登成功了

            if(isset($arrProductData['product_id']) && !empty($arrProductData['product_id']))
            {

                if(isset($arrProductData['reduce_strategy']))
                {
                    $strategry=[
                        'place_order_withhold'=>1,
                        'payment_success_deduct'=>2
                    ];
                    $arrProductData['reduce_strategy'] = $strategry[$arrProductData['reduce_strategy']];
                }

                $product_id = $arrProductData['product_id'];

                $modify=[];
                //product修改了的数据
                $modifyProduct = $this->saveProductUpdateLog($product_id,$uid,$arrProductData);

                //product_sku修改了的数据
                $productSkuData = $this->productSkuData($arrProductSkuData);
                $modifySku =$this->saveProductSkuUpdateLog($product_id,$uid,$arrProductSkuData);

                //product_info修改了的数据

                $modifyInformation = $this->saveProductInformationUpdateLog($product_id,$uid,$arrProductInfoData);

                $modify = array_merge($modifyProduct['new'],$modifyInformation['new']);

                $modify_old = array_merge($modifyProduct['old'],$modifyInformation['old']);

                if(isset($modifySku['new']) && $modifySku['new'])
                {
                    $modify['sku']=$modifySku['new'];
                    $modify_old['sku']=$modifySku['old'];
                    foreach ($modifySku['new'] as $k=>$sku)
                    {
                        if(isset($sku['combine_sku']) && $sku['combine_sku'] && isset($modifySku['old'][$k]['id']))
                        {
                            $queue =[
                                'vid'=>$modifySku['old'][$k]['id'],
                                'combine_sku'=>$sku['combine_sku'],
                            ];

                            (new CommonQueuer(AliexpressCombineSkuQueue::class))->push($queue);
                        }
                    }
                }
                
                if(!empty($modify))
                {
                    $type=4;
                    $where=[
                        'product_id'=>['=',$product_id],
                        'new_data'=>['=',json_encode($modify)],
                        'create_id'=>['=',$uid],
                        'status'=>['=',0],
                    ];

                    $log=[
                        'product_id'=>$product_id,
                        'type'=>$type,
                        'create_id'=>$uid,
                        'new_data'=>json_encode($modify),
                        'old_data'=>json_encode($modify_old),
                        'create_time'=>time(),
                    ];

                    if((new AliexpressListingHelper())->saveAliexpressActionLog($where,$log))
                    {
                        AliexpressProduct::where('product_id','=',$product_id)->update(['lock_update'=>1]);


                        //更新分组,分组区域模板id
                        AliexpressProductInfo::where('product_id', '=', $product_id)->update(['region_group_id' => $publishData['region_group_id'], 'region_template_id' => $publishData['region_template_id']]);
                        $result= ['status'=>true];
                    }else{
                        $result= ['status'=>false];
                    }

                }else{
                    $result= ['status'=>false];
                }
            }else {

                $productSkuData = $this->productSkuData($arrProductSkuData);
                $result = $productModel->updateProduct($objProduct, $arrProductData, $arrProductInfoData, $arrProductSkuData);
            }
        }
        return $result;
    }


    /**
     *
     *
     */
    protected function productSkuData($arrProductSkuData)
    {

        if($arrProductSkuData){

            $goodsSkuIds = array_column($arrProductSkuData,'goods_sku_id');
            $goodsSkus = (new GoodsSku())->whereIn('id', $goodsSkuIds)->field('sku')->select();

            $goodsSkus = $this->ObjToArray($goodsSkus);
            $goodsSkus = array_column($goodsSkus, 'sku');


            if(count($goodsSkus) == count($arrProductSkuData)) {

                foreach ($arrProductSkuData as $key => $val) {

                    $sku_code = $goodsSkus[$key];
                    $arrProductSkuData[$key]['sku_code'] = $val['sku_code'];
                    $combine_sku = explode('*',$val['combine_sku']);
                    $combine_sku = $combine_sku && isset($combine_sku[1]) && $combine_sku[1] ? $sku_code.'*'.$combine_sku[1] : $val['combine_sku'];
                    $arrProductSkuData[$key]['combine_sku'] =  $combine_sku;
                    $arrProductSkuData[$key]['local_sku_code'] = $sku_code;
                }
            }else{

                foreach ($arrProductSkuData as $key => $val) {

                    if($val['combine_sku']) {
                        $combine_sku = explode('*',$val['combine_sku']);
                        $arrProductSkuData[$key]['local_sku_code'] = strlen($combine_sku[0]) == 9 ? $combine_sku[0] : '';
                    }
                }
            }
        }

        return $arrProductSkuData;

    }


    /**
     * 商品sku修改日志
     */

    public function saveProductSkuUpdateLog($product_id,$uid,$skus)
    {
        try{
            $update['new']=$update['old']=[];
            if(is_array($skus))
            {
                foreach ($skus as $sku)
                {

                    $skuObject = AliexpressProductSku::where(['product_id'=>$product_id,'sku_code'=>$sku['sku_code']])->find();
                    if($skuObject)
                    {
                        $row  = $skuObject->toArray();
                        $row['combine_sku'] = $skuObject->getData('combine_sku');
                        if($this->productSkuDataIsEdit($sku,$row))
                        {

                            $update['old'][]=$row;
                            $update['new'][]=$sku;
                        }
                    }else{
                        $update['new'][]=$sku;
                    }
                }
            }

            return $update;
        }catch (JsonErrorException $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    public function getNewData($data,$product)
    {
        $result =[];
        foreach ($data as $name=>$v)
        {
            $result[$name]=$product[$name];
        }
        return $result;
    }

    /**
     * 循环比较数据是否做了修改
     * @param $data
     * @param $data
     */
    public function productSkuDataIsEdit($data,$product)
    {
        if($data)
        {
            foreach ($data as $name=>$v)
            {
                if($product[$name]!=$v)
                {
                    return true;
                    break;
                }
            }
            return false;
        }else{
            return false;
        }
    }



    /**
     * 保存商品修改日志
     */
    public function saveProductUpdateLog($product_id,$uid,$data)
    {
        try{
            $product = AliexpressProduct::get(['product_id'=>$product_id]);

            $row = $product->getData();

            $update['new']=$update['old']=[];
            $fileds=[
                'subject'=>'subject', //刊登标题
                'delivery_time'=>'deliveryTime',//发货期
                'category_id'=>'categoryId', //分类ID
                'product_price'=>'productPrice',
                'group_id'=>'groupId',
                'product_unit'=>'productUnit',
                'package_type'=>'packageType',
                'lot_num'=>'lotNum',
                'package_length'=>'packageLength',
                'package_width'=>'packageWidth',
                'package_height'=>'packageHeight',
                'is_pack_sell'=>'isPackSell',
                'base_unit'=>'baseUnit',
                'add_unit'=>'addUnit',
                'add_weight'=>'addWeight',
                'ws_valid_num'=>'wsValidNum',
                'bulk_order'=>'bulkOrder',
                'bulk_discount'=>'bulkDiscount',
                'reduce_strategy'=>'reduceStrategy',
                'freight_template_id'=>'freightTemplateId',
                'gross_weight'=>'grossWeight',
                'promise_template_id'=>'promiseTemplateId',
                'imageurls'=>'imageURLs',
                'relation_template_id'=>'relationTemplateId',
                'relation_template_postion'=>'relationTemplatePostion',
                'custom_template_id'=>'customTemplateId',
                'custom_template_id'=>'customTemplateId',
                'quote_config_status'=>'quoteConfigStatus',
                'configuration_type'=>'configurationType',
                'aeop_national_quote_configuration'=>'aeopNationalQuoteConfiguration',
                'virtual_send' => 'virtualSend',
            ];
            $keys = array_keys($fileds);
            if($row)
            {
                //$row = $row->toArray();
                foreach ($data as $name=>$v)
                {

                    if(in_array($name,$keys))
                    {

                        if($row[$name] !=$v)
                        {
                            $update['new'][$name]=$v;
                            $update['old'][$name]=$row[$name];
//                            $type=$fileds[$name];
//                            $where=[
//                                'product_id'=>['=',$product_id],
//                                'type'=>['=',$type],
//                                'create_id'=>['=',$uid],
//                                'status'=>['=',0],
//                            ];
//                            $log=[
//                                'product_id'=>$product_id,
//                                'type'=>$type,
//                                'create_id'=>$uid,
//                                'new_data'=>$v,
//                                'old_data'=>$row[$name],
//                                'create_time'=>time(),
//                            ];
//                            (new AliexpressListingHelper())->saveAliexpressActionLog($where,$log);
//                            $update=true;
                        }
                    }
                }
            }
            return $update;
        }catch (JsonErrorException $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }


    /**
     * 保存商品信息修改日志
     */
    public function saveProductInformationUpdateLog($product_id,$uid,$data)
    {
        try{
            $update['new']=$update['old']=[];
            $row = AliexpressProductInfo::where('product_id','=',$product_id)->find();
            $fileds=[
                'detail'=>'detail',
                'mobile_detail'=>'mobileDetail',
                'product_attr'=>'productProperties',
            ];
            if($row)
            {
                foreach ($data as $name=>$v)
                {
                    if($row[$name]!==$v)
                    {
                        $update['new'][$name]=$v;
                        $update['old'][$name]=$row[$name];
//                        $type=$fileds[$name];
//
//                        $where=[
//                            'product_id'=>['=',$product_id],
//                            'type'=>['=',$type],
//                            'create_id'=>['=',$uid],
//                            'status'=>['=',0],
//                        ];
//                        $log=[
//                            'product_id'=>$product_id,
//                            'type'=>$type,
//                            'create_id'=>$uid,
//                            'new_data'=>$v,
//                            'old_data'=>$row[$name],
//                            'create_time'=>time(),
//                        ];
//                        (new AliexpressListingHelper())->saveAliexpressActionLog($where,$log);
//                        $update=true;
                    }
                }
            }
            return $update;
        }catch (JsonErrorException $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");

        }

    }



    /**
     * @info 获取速卖通分类接口API类
     * @param unknown $intAliexpressAcountId
     * @param string $isRefresh
     * @return multitype:string number
     */
    public function getCategoryApi($intAliexpressAcountId,$isRefresh=FALSE)
    {
        if(empty($this->ApiCategory))
        {
            $arrAliexpressAccount = Cache::store('AliexpressAccount')->getAccountById($intAliexpressAcountId);
            if(!Loader::validate('ExpressValidate')->scene('getexpress')->check($arrAliexpressAccount))
            {
                return ['message'=>'获取商户信息失败','code'=>400];
            }
            $config = [
                'id'            => $arrAliexpressAccount['id'],
                'client_id'            => $arrAliexpressAccount['client_id'],
                'client_secret'     => $arrAliexpressAccount['client_secret'],
                'accessToken'    => $arrAliexpressAccount['access_token'],
                'refreshtoken'      =>  $arrAliexpressAccount['refresh_token'],
            ];
            $ApiCategory = AliexpressApi::instance($config)->loader('Category');
            if($isRefresh)
            {
                $arrResult = $ApiCategory->getTokenByRefreshToken($arrAliexpressAccount);
                $arrAliexpressAccount['access_token'] = $arrResult['access_token'];
            }
            $this->ApiCategory = $ApiCategory;
        }
        return $this->ApiCategory;
    }
    
    /**
     * @info 获取速卖通分类接口API类
     * @param unknown $intAliexpressAcountId
     * @param string $isRefresh
     * @return multitype:string number
     */
    public function getPostProductApi($intAliexpressAcountId,$isRefresh=FALSE)
    {
        if(empty($this->ApiCategory))
        {
            $AliexpressAccount = Cache::store('AliexpressAccount')->getAccountById($intAliexpressAcountId);
            $arrAliexpressAccount = $AliexpressAccount;
            if(!Loader::validate('ExpressValidate')->scene('getexpress')->check($arrAliexpressAccount))
            {
                return ['message'=>'获取商户信息失败','code'=>400];
            }
            $config = [
                'id'=>$AliexpressAccount['id'],
                'client_id'=>$AliexpressAccount['client_id'],
                'client_secret'=>$AliexpressAccount['client_secret'],
                'refreshtoken'=>$AliexpressAccount['refresh_token'],
                'accessToken'=>$AliexpressAccount['access_token'],
            ];
            $ApiCategory = AliexpressApi::instance($config)->loader('PostProduct');
            if($isRefresh)
            {
                $arrResult = $ApiCategory->getTokenByRefreshToken($arrAliexpressAccount);
                $arrAliexpressAccount['access_token'] = $arrResult['access_token'];
            }
            $ApiCategory->setConfig($arrAliexpressAccount);
            $this->ApiCategory = $ApiCategory;
        }
        return $this->ApiCategory;
    }
    
    
    /**
     * @info 获取速卖通分类接口API类
     * @param unknown $intAliexpressAcountId
     * @param string $isRefresh
     * @return multitype:string number
     */
    public function getImagesApi($intAliexpressAcountId,$isRefresh=FALSE)
    {
        if(empty($this->ApiImages))
        {
            $arrAliexpressAccount = Cache::store('AliexpressAccount')->getAccountById($intAliexpressAcountId);
            if(!Loader::validate('ExpressValidate')->scene('getexpress')->check($arrAliexpressAccount))
            {
                return ['message'=>'获取商户信息失败','code'=>400];
            }
            $config = [
                'id'            => $arrAliexpressAccount['id'],
                'client_id'            => $arrAliexpressAccount['client_id'],
                'client_secret'     => $arrAliexpressAccount['client_secret'],
                'accessToken'    => $arrAliexpressAccount['access_token'],
                'refreshtoken'      =>  $arrAliexpressAccount['refresh_token'],
            ];
            $ApiImages = AliexpressApi::instance($config)->loader('Images');
            if($isRefresh)
            {
                $arrResult = $ApiImages->getTokenByRefreshToken($arrAliexpressAccount);
                $arrAliexpressAccount['access_token'] = $arrResult['access_token'];
            }
            $ApiImages->setConfig($arrAliexpressAccount);
            $this->ApiImages = $ApiImages;
        }
        return $this->ApiImages;
    }
    
    
    /**
     * @info 根据父分类查询子分类
     * @info 如果传送了第二个商户ID，且为顶层分类的话，会过滤掉该商户未拥有的大类权限
     * @param number $category_id
     */
    public function GetPostCategoryById($intCategoryId=0,$intAccountId = 0)
    {

        $field = '`category_id`,`category_pid`,`category_level`,`category_name_zh`,`category_isleaf`,`update_status`,`required_size_model`';

        $AliexpressCategory = [];
        if($intAccountId) {
            $AliexpressCategory = AliexpressCategory::where('category_pid','=',$intCategoryId)->where('find_in_set ('.$intAccountId.', account_id)')->field($field)->select();
        }

        if(empty($AliexpressCategory)){
            $AliexpressCategory = AliexpressCategory::where('category_pid','=',$intCategoryId)->field($field)->select();
        }
        return  $AliexpressCategory;
    }
    
    /**
     * @info 上架产品
     * @param unknown $product_ids
     */
    public function OnLineaeProduct($product_ids)
    {
        $AliexpressProduct = new AliexpressProduct();
        $result =  $AliexpressProduct->field('account_id,product_id')->where('product_id','IN',$product_ids)->select();
        $arrProduct=[];
        $intSum = 0;
        foreach ($result as $v)
        {
            $arrProduct[$v->account_id][] = $v->product_id;
        }
        foreach ($arrProduct as $account_id=>$v)
        {
            $result = $this->getPostProductApi($account_id)->onlineAeProduct(implode(',', $v));
            $intSum +=$result['modifyCount'];
            $this->ApiPostProduct='';
            $result = '';
        }
        return '成功上架:'.$intSum.'个商品！';
    }
    
    /**
     * @info 下架产品
     * @param unknown $product_ids
     */
    public function OffLineaeProduct($product_ids)
    {
        $AliexpressProduct = new AliexpressProduct();
        $result =  $AliexpressProduct->field('account_id,product_id')->where('product_id','IN',$product_ids)->select();
        $arrProduct=[];
        $intSum = 0;
        foreach ($result as $v)
        {
            $arrProduct[$v->account_id][] = $v->product_id;
        }
        foreach ($arrProduct as $account_id=>$v)
        {
            $result = $this->getPostProductApi($account_id)->offlineAeProduct(implode(',', $v));
            $intSum +=$result['modifyCount'];
            $this->ApiPostProduct='';
            $result = '';
        }
        return '成功下架:'.$intSum.'个商品！';
    }
    
    /**
     * @info 获取尺码模板列表
     * @param unknown $arrData
     */
    public function GetSizeChartInfoyCategoryId($accountId,$categoryId)
    {
        $arrSizeTemp = AliexpressSizeTemplate::where(['account_id'=>$accountId,'category_id'=>$categoryId])
            ->field('sizechart_id,default,model_name,name')
            ->select();
        return $arrSizeTemp;
    }
    
    /**
     * @info 获取分类属性
     * @param unknown $arrData
     */
    public function GetAttrByCategoryId($arrData=[])
    {
        $AliexpressCategoryAttr = AliexpressCategoryAttr::where('category_id',$arrData['category_id']);
        if(isset($arrData['is_sku']))$AliexpressCategoryAttr->where('sku',(int)$arrData['is_sku']);
        return $AliexpressCategoryAttr->select();
    }
    
    /**
     * @info 获取服务模板
     * @param unknown $intAccountId
     */
    public function GetFreightTemplate($intAccountId)
    {
        return AliexpressFreightTemplate::where('account_id',$intAccountId)->select();
    }
    
    /**
     * @info 获取运费模板
     * @param unknown $intAccountId
     */
    public function GetPromiseTemplate($intAccountId)
    {
        return AliexpressPromiseTemplate::where('account_id',$intAccountId)->select();
    }
    
    
    /**
     * @info 获取商品分组
     * @param unknown $intAccountId
     */
    public function GetProductGroup($intAccountId)
    {
        $AliexpressProductGroup = new AliexpressProductGroup();
        return $AliexpressProductGroup->getGroupTree($intAccountId);
    }
    
    /**
     * @info 获取商品分组
     * @param unknown $intAccountId
     * @param unknown $intGoodsId
     */
    public function WhetherCategoryPower($intAccountId,$intGoodsId)
    {
        //第一步，找出该分类映射到速卖通的对应分类
        $objCategoryMap = $this->GetAliexpressCategoryIdByGoodsId($intGoodsId);
        if($objCategoryMap == false || empty($objCategoryMap->channel_category_id))return false;
        
        //第二步，找出该分类的顶层大类
        $AliexpressCategory = new AliexpressCategory();
        $objCategory = $AliexpressCategory->getTheMostPareantCategory($objCategoryMap->channel_category_id);
        
        //第三步，判断该商户是否拥有该大类刊登权限
        $objCategoryPower =  AliexpressAccountCategoryPower::where('account_id','=',$intAccountId)
        ->where('category_id','=',$objCategory->category_id)
        ->find();
        if(!$objCategoryPower){
            return false;
        }
        //存在则返回该
        $result = AliexpressCategory::getAllParent($objCategoryMap['channel_category_id']);
        return $result;
    }
    
    /**
     * @info 获取速卖通商户账户列表
     * @param unknown $arrData
     */
    public function GetAliexpressAccount($arrData=[])
    {
        $AliexpressAccount =  new AliexpressAccount();
        $arrWhere=[];
        foreach($arrData as $k=>$v)
        {
            if(empty($v) && !is_numeric($v))continue;
            switch ($k)
            {
                case 'code':$arrWhere[$k]=['LIKE',$v];break;
                case 'account_name':$arrWhere[$k]=['LIKE',$v];break;
                case 'page':break;
                case 'pagetotal':break;
                case 'pageSize':break;
                default:$arrWhere[$k]=['EQ',$v];
            }
        }
        $arrField = [
            'id',
            'code',
            'account_name',
            'is_invalid',
        ];
        $arrFilter = [
            'Where'=>$arrWhere,
            'Field'=>$arrField,
            'Data'=>$arrData
        ];
        return $this->GetList($AliexpressAccount,$arrFilter);
    }
    
    public function GetGoods($arrData)
    {
        $Goods = new Goods();
        //条件
        $arrWhere=[];
        foreach(array_filter($arrData) as $k=>$v)
        {
            if(empty($v) && !is_numeric($v))continue;
            switch ($k)
            {
                case 'id':$arrWhere['p1.id']=['EQ',$v];break;
                case 'spu':$arrWhere['p1.spu']=['LIKE','%'.$v.'%'];break;
                case 'name':$arrWhere['p1.name']=['LIKE','%'.$v.'%'];break;
                case 'page':break;
                case 'pagetotal':break;
                case 'pageSize':break;
               // default:$arrWhere[$k]=['EQ',$v];
            }
        }
        //字段
        $arrField = [
            "p1.id",//产品
            "p1.packing_en_name",//英文配货名称
            "p1.thumb",//产品图
            "p1.category_id",//分类ID
            "p1.spu",//SPU
            "p1.publish_time",//发布时间
            "p1.stop_selling_time",//停售时间
            "p1.name",//'标题（名称）'
            "p1.sales_status",//'出售状态 0-未出售 1-出售 2-停售',
            "p1.type",//'商品类型  0-普通 1-组合  2-虚拟 ',
            "p2.sku",
        ];
        $arrAlias=['goods'=>'p1'];
        $arrJoin[]=['goods_sku p2','p1.id=p2.goods_id'];
        $arrFilter = [
            'Alias'=>$arrAlias,
            'Where'=>$arrWhere,
            'Field'=>$arrField,
            'Data'=>$arrData,
            'Join'=>$arrJoin,
        ];
        return $this->GetList($Goods,$arrFilter);
    }
    
    
    /**
     * @info 获取刊登产品列表
     * @param unknown $arrData
     * @return multitype:\app\publish\service\unknown
     */
    public function GetProduct($arrData=[])
    {
        $AliexpressProduct = new AliexpressProduct();
        //条件
        $arrWhere=[];
        foreach(($arrData) as $k=>$v)
        {
            if(empty($v) && !is_numeric($v))continue;
            switch ($k)
            {
                case 'subject':$arrWhere['p1.subject']=['LIKE','%'.$v.'%'];break;
                case 'goods_name':$arrWhere['p5.name']=['LIKE','%'.$v.'%'];break;
                case 'sku_code':$arrWhere['p2.sku_code']=['LIKE','%'.$v.'%'];break;
                case 'goods_sku':$arrWhere['p6.sku']=['LIKE','%'.$v.'%'];break;
                case 'account_id':$arrWhere['p3.id']=['EQ',$v];break;
                //状态筛选
                case 'status':$arrWhere['p1.status']=['EQ',$v];break;
                case 'product_status_type':$arrWhere['p1.product_status_type']=['EQ',$v];break;
                //时间筛选
                case 'gmt_create':$arrWhere['p1.gmt_create']=['BETWEEN',$v];break;
                case 'gmt_modified':$arrWhere['p1.gmt_modified']=['BETWEEN',$v];break;
                case 'plan_time':$arrWhere['p7.plan_time']=['BETWEEN',$v];break;
                case 'exec_time':$arrWhere['p7.exec_time']=['BETWEEN',$v];break;
                case 'plan_status':$arrWhere['p7.status']=['NEQ','.$v.'];break;
                //分页数据跳过
                case 'page':break;
                case 'pagetotal':break;
                case 'pageSize':break;
               // default:$arrWhere[$k]=['EQ',$v];
            }
        }
        //字段
        $arrField = [
            'p1.account_id',
            'p1.product_status_type',
            'p1.ws_valid_num',
            'p1.create_time',
            'p1.is_balance',
            'p1.gmt_create',
            'p1.id',
            'p1.subject',
            'p1.gmt_modified',
            'p2.sku_code',
            'p2.sku_price',
            'p2.ipm_sku_stock',
            'p3.account_name',
            'p4.sku_image',
            'p5.name as goods_name',//商品标题
            'p6.sku as goods_sku',//商品sku code
            'p7.exec_time',
            'p7.plan_time',
            'p7.status as plan_status',
        ];
        //别名
        $arrAlias = ['aliexpress_product'=>'p1'];
        //关联
        $arrJoin = [];
        $arrJoin[]=['aliexpress_product_sku p2','p1.id=p2.ap_id','RIGHT'];
        $arrJoin[]=['aliexpress_account p3','p1.account_id=p3.id','LEFT'];
        $arrJoin[]=['aliexpress_product_sku_val p4','p4.ap_id=p1.id and p4.sku_code=p2.sku_code and p4.sku_image !="" ','LEFT'];
        $arrJoin[]=['goods p5','p5.id=p1.goods_id','LEFT'];
        $arrJoin[]=['goods_sku p6','p6.id=p2.goods_sku_id','LEFT'];
        $arrJoin[]=['aliexpress_publish_plan p7','p7.ap_id=p1.id','LEFT'];
        $arrFilter = [
            'Alias'=>$arrAlias,
            'Where'=>$arrWhere,
            'Join'=>$arrJoin,
            'Field'=>$arrField,
            'Data'=>$arrData
        ];
        return $this->GetList($AliexpressProduct,$arrFilter);
    }
    
    
    /**
     * @info 根据产品ID 获取映射的速卖通分类ID
     */
    public function GetAliexpressCategoryIdByGoodsId($intGoodsId)
    {
         $objCategoryMap = Goods::where('p1.id',$intGoodsId)
        //这里采取硬编码，强制选择速卖通，因为暂时不知道怎么连接关系
        ->where('p2.channel_id',4)
        ->alias('p1')
        ->field('p2.channel_category_id,p3.name')
        ->join('category_map p2','p2.category_id=p1.category_id','LEFT')
        ->join('category p3','p3.id=p2.category_id','LEFT')
        ->find();
         return $objCategoryMap?$objCategoryMap:false;
    }
    
    /**
     * @info 获取本地产品详情
     * @param unknown $intGoodsId
     */
    public function GetProductData($intGoodsId)
    {
        $arrGoodsField = [
            'p1.id',//商品ID
            'p1.name',//产品名称
            'p1.packing_en_name',//英文名称
            'p1.spu',//SPU
            'p1.category_id',//本地分类ID
            'p1.weight',//重量(g)
            'p1.width',//宽度(mm)
            'p1.height',//高度(mm)
            'p1.depth',//'深度(mm)'
            'p1.warehouse_id',//默认发货仓库ID
            'p1.brand_id goods_brand_id',
            //'p2.title',//'描述'
            //'p2.description',//'描述'
        ];
        $objGoods = Goods::where(['p1.id'=>$intGoodsId])
            ->field($arrGoodsField)
            ->alias('p1')
            ->find();

//        $objGoods = Goods::where(['p1.id'=>$intGoodsId,'p2.lang_id'=>2])
//        ->field($arrGoodsField)
//        ->alias('p1')
//        ->join('goods_lang p2','p1.id=p2.goods_id','LEFT')
//        ->find();
        if(empty($objGoods)){
            throw new Exception('商品不存在');
        }


        //是否平台侵权
        $goodsHelp = new GoodsHelp();
        $goods_tort = $goodsHelp->getGoodsTortDescriptionByGoodsId($objGoods['id']);
        $objGoods['is_goods_tort'] = $goods_tort ? 1 : 0;

        //物流属性
        $objGoods['transport_property'] = (new GoodsHelp())->getPropertiesTextByGoodsId($objGoods['id']);

        $objGoods = $objGoods->toArray();
        $goods_lang = GoodsLang::where(['goods_id'=>$intGoodsId,'lang_id'=>2])->field('title,description,selling_point')->find();
        $objGoods['title'] = '';
        $objGoods['description'] = '';
        $sellingPoint = '';

        if(!empty($goods_lang)){
            $objGoods['title'] = $goods_lang['title'];
            $objGoods['description'] = $goods_lang['description'];

            //获取产品卖点描述 //2英文;1.中文
            $goodsSellingPoint = \GuzzleHttp\json_decode($goods_lang['selling_point'],true);

            if($goodsSellingPoint){

                $i = 1;
                foreach ($goodsSellingPoint as $val){
                    if($val){
                        $sellingPoint.=$i.'、'.$val."\n";
                    }
                    $i++;
                }

                if($sellingPoint){
                    $sellingPoint = 'Bullet Points:'."\n".$sellingPoint."\n";
                }
            }
        }


        //卖点描述+描述
        $objGoods['description'] = $sellingPoint.CommonService::replaceDesriptionHtmlTags($objGoods['description']);

        $arrImgs = GoodsGallery::where(['goods_id'=>$intGoodsId])->field('path')->group('path')->select();

        if(!empty($arrImgs))
        {
            foreach ($arrImgs as &$img)
            {
                $img['path'] = \app\goods\service\GoodsImage::getImagePath($img['path']);
            }

        }

        $objGoods['imgs'] = $arrImgs;

        $objGoods['base_url']=Cache::store('configParams')->getConfig('innerPicUrl')['value'].DS;


        //$objGoods['imgs'] = [];
        $goodsServer = $this->invokeServer(\app\goods\service\GoodsHelp::class);

        $objGoods['category_name'] = $goodsServer->mapCategory($objGoods['category_id']);


        $objGoods['category_relation'] =[];
        if($mapCategory = $this->getBindAndPublishCategory($intGoodsId))
        {
            foreach ($mapCategory as $map)
            {
                $categoryAttr = AliexpressCategory::getAllParent($map['channel_category_id']);
                $objGoods['category_relation'][] = $categoryAttr ? $this->categoryTree($categoryAttr) : $categoryAttr;
            }
        }

        $objGoods['goods_brand']='';
        if($objGoods['goods_brand_id'])
        {
            $brand = Brand::where('id',$objGoods['goods_brand_id'])->find();
            if($brand)
            {
                $objGoods['goods_brand']=$brand['name'];
            }
        }


        $objGoods['width']=$objGoods['width']/10;
        $objGoods['height']=$objGoods['height']/10;
        $objGoods['depth']=$objGoods['depth']/10;
        $objGoods['virtual_send'] = 0;
        $objGoods['region_group_id'] = '';
        $objGoods['region_template_id'] = '';
        $objGoods['is_market_image'] = 0;
        $objGoods['market_images'] = [];

        //分组模板
        $objGoods = (new AliProductHelper())->getGroupTemplates($objGoods);
        return $objGoods;
    }

    /**
     * 将一个分类数组转换成a>>b>>c>>d
     * @param $categorys
     */
    public function categoryTree(array  $categorys)
    {
        $nameTree='';
        $childCategory =0;
        $newAttribute=[];
        foreach ($categorys as $category)
        {
            $nameTree=$nameTree.">>".$category['category_name'];
            $childCategory=$category['category_id'];
            $required_size_model=$category['required_size_model'];
        }
        //暂时不要带上属性值，都在选中分类时统一选择
//        if($childCategory)
//        {
//            $publishAttribute= (new AliexpressPublishTemplateService())->find(['channel_category_id'=>$childCategory]);
//            if($publishAttribute)
//            {
//                $newAttribute =(new AliProductHelper())->bulidAttrData($childCategory,json_decode($publishAttribute['data'],true));
//            }else{
//                $arrAllAttr = (new AliexpressCategoryAttr)->getCategoryAttr($childCategory,0,['id'=>['neq',2]]);
//                $newAttribute =(new AliProductHelper())->bulidAttrData($childCategory,$arrAllAttr);
//            }
//        }
        return [
            'category_name'=>substr($nameTree,2),
            'category_id'=>$childCategory,
            'required_size_model'=>$required_size_model,
            'attribute'=>$newAttribute
        ];

    }

    /**
     * 获取商品绑定分离与刊登使用过的分类
     * @param $goods_id
     */
    public function getBindAndPublishCategory($goods_id)
    {

        if(empty($goods_id))
        {
            return [];
        }else{
            $publishCategory = (new AliexpressPublishTemplate())->field('channel_category_id')->where('goods_id','=',$goods_id)->select();

            $arr=[];
            if($publishCategory)
            {
                foreach ($publishCategory as $category)
                {
                    $arr[] = $category['channel_category_id'];
                }
            }

            $bindCategory = (new GoodsCategoryMap())->field('channel_category_id')->whereNotIn('channel_category_id',$arr)->where(['goods_id'=>$goods_id,'channel_id'=>4])->select();

            return array_merge_recursive($bindCategory,$publishCategory);
        }
    }

    /**
     * 获取商品分类与平台分类映射关系
     */
    public function getCategoryMapChannel($goods_id,$channel_id=4)
    {
        return GoodsCategoryMap::where(['goods_id'=>$goods_id,'channel_id'=>$channel_id])->find();
    }

    /**
     * 获取商品分类与平台分类映射关系
     */
    public function getAllCategoryMapChannel($goods_id,$channel_id=4)
    {
        return GoodsCategoryMap::where(['goods_id'=>$goods_id,'channel_id'=>$channel_id])->select();
    }
    
    /**
     * @info 获取刊登时选择的仓库列表
     */
    public function GetWareHouse()
    {
        return Warehouse::field('id,name')->select();
    }
    
    /**
     * @info 根据产品ID获取刊登商品所需要的基础数据
     * @param unknown $intGoodsId
     */
    public function GetPulishData($intGoodsId,$intAccountId,$intAliCategoryId=false)
    {
        // 第一步、获取速卖通该分类下面的所有类目属性和SKU属性(除品牌)
        $objAliexpressCategoryAttr = AliexpressCategoryAttr::where(['category_id'=>$intAliCategoryId,'id'=>['neq',2]])
        ->field('id,parent_attr_id,required,spec,names_zh,names_en,sku,units,attribute_show_type_value,customized_pic,customized_name,list_val')
        ->select();


        $arrAliexpressCategoryAttr = $this->ObjToArray($objAliexpressCategoryAttr);

        $publishAttr = (new AliexpressPublishTemplate())->where(['channel_category_id'=>$intAliCategoryId,'goods_id'=>$intGoodsId])->find();
        $publishAttrData = $publishAttr ? json_decode($publishAttr['data'],true) : [];

        $arrAttr =[];//普通属性
        $arrSku = [];//SKU属性
        foreach($arrAliexpressCategoryAttr as $arrValue)
        {
            if($arrValue['sku'])
            {
                $arrValue['used_vaules'] = [];
                $arrSku[$arrValue['id']] = $arrValue;
            }
            else
            {
               $arrAttr[] = $this->getChoosedAttribute($arrValue,$publishAttrData);
            }
        }

        $goodsInfomation = Goods::where('id',$intGoodsId)->find();

        if(empty($goodsInfomation))
        {
            throw new JsonErrorException("商品不存在");
        }

        $develop_id = $goodsInfomation['channel_id'];

        //第二步、获取该产品本地的SKU属性
        $skus = GoodsSku::where('goods_id',$intGoodsId)->whereIn('status',[1,4])->order('sku ASC')->select();

        $objGoodsListing = $this->GoodsSkuAttrJsonToArray($skus);

        $arrGoodsListing = $this->ObjToArray($objGoodsListing);

        //拼装SKU生成Listing部分数据
        $arrSkuData =  $this->getSkuInfo($arrSku,$arrGoodsListing, $intAliCategoryId);

        $images = GoodsImage::getPublishImages($intGoodsId,4);

        $spuImage = $images['spuImages'];

        $skuImages = $images['skuImages'];

        $arrSkuData['listing'] = GoodsImage::replaceSkuImage($arrSkuData['listing'],$skuImages,4,'id');

        if($arrSkuData['listing'])
        {
            foreach ($arrSkuData['listing'] as &$sku)
            {
                $sku['ipm_sku_stock']=0;
                $sku['d_imgs'] = GoodsImage::getSkuImagesBySkuId($sku['id'],4,$develop_id);
            }
        }

        //根据分类及账号ID获取相应品牌信息
        $arrBrand = AliexpressAccountBrand::getBrandByAccount($intAccountId,$intAliCategoryId);

        $brandRequried=AliexpressCategoryAttr::where(['category_id'=>$intAliCategoryId,'id'=>2])->field('required')->find();

        if($brandRequried)
        {
            $brand_required=$brandRequried['required'];
        }else{
            $brand_required=0;
        }

        $diyAttr = $this->getSelfDefineAttr($publishAttrData);

        $aeopNationalQuoteConfiguration = $this->getQuoteCountry();

        //是否有营销图
        $is_market_image = $this->marketImageCheck($intAliCategoryId);

        //开始拼接返回的数据
        $arrReturnData = [
            'brand'=>$arrBrand, //品牌数据
            'brand_required'=>$brand_required,
            'attr_info'=>['attr'=>$arrAttr,'diy_attr'=>$diyAttr],    //普通属性
            'listing_info'=>array_values($arrSkuData['listing']),  //listing
            'sku_attr_info'=>array_values($arrSkuData['ali_attr']),    //sku属性array_values($arrSkuData['local_attr'])
            'local_attr'=>array_values($arrSkuData['local_attr']),
            'imgs'=>$spuImage,
            'aeopNationalQuoteConfiguration'=>$aeopNationalQuoteConfiguration,
            'quote_config_status'=>0,
            'region_group_id' => '',
            'region_template_id' => '',
            'configuration_type' => '',
            'is_market_image' => $is_market_image
        ];


        $arrReturnData = (new AliProductHelper())->getGroupTemplates($arrReturnData);

        return $arrReturnData;
    }


    /**
     * @param $intAliCategoryId
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * 营销图检查
     */
    public function marketImageCheck($intAliCategoryId)
    {
        $is_market_image = 0;
        //判断男装,女装需要添加营销图
      /*  if($intAliCategoryId) {
            $categoryPid = AliexpressCategory::where(['category_id'=>$intAliCategoryId])->field('category_pid')->find();

            $is_market_image = $categoryPid && in_array($categoryPid['category_pid'], [200000345, 200000343]) ? 1 : 0;
        }*/

        return $is_market_image;
    }

    public function getQuoteCountry()
    {
        $countrys = AliexpressQuoteCountry::field('id,zh_name,en_name shiptoCountry')->select();

        foreach ($countrys as &$country)
        {
            $country['percentage']=0;
            $country['symbol']="0";
        }
        return $countrys;
    }

    /**
     * 获取自定义属性
     * @param $publishAttr 刊登属性
     */
    public function getSelfDefineAttr($publishAttr)
    {
        $diyAttr=[];
        if(empty($publishAttr))
        {
            return $diyAttr;
        }
        foreach ($publishAttr as $attr)
        {
            if(isset($attr['attrName']) && isset($attr['attrValue']))
            {
                $diyAttr[]=$attr;
            }
        }
        return $diyAttr;
    }
    /**
     * 根据平台属性和刊登属性获取默认值
     * @param $channelAttr
     * @param $publishAttr
     */
    public function getChoosedAttribute($channelAttr,$publishAttr)
    {
        try{
            $type = $channelAttr['attribute_show_type_value'];
            switch ($type)
            {
                case 'input':
                    $channelAttr['default_value']='';
                    break;
                case 'list_box':
                    $channelAttr['default_id']='';
                    break;
                case 'check_box':

                    $list_val = [];
                    foreach ($channelAttr['list_val'] as $listKey => $listVal) {
                        if(isset($listVal['name_en']) && $channelAttr['required'] == 1 && $listVal['name_en'] == 'Other') {
                            continue;
                        }
                        $list_val[] = $channelAttr['list_val'][$listKey];
                    }

                    $channelAttr['list_val'] = $list_val;
                    $channelAttr['default_id']=[];
                    break;
                case 'interval':
                    $channelAttr['default_value']='';
                    break;
                default:
                    $channelAttr['default_value']='';
                    break;
            }


            if(empty($publishAttr))
            {
                return $channelAttr;
            }
            foreach ($publishAttr as $attr)
            {
                if(isset($attr['attrNameId']) && $attr['attrNameId']==$channelAttr['id'])
                {
                    if(isset($attr['attrValueId']) && isset($attr['attrValue']))
                    {
                        if(!empty($channelAttr['list_val']))
                        {
                            $items = $channelAttr['list_val'];
                            foreach ($items as $item)
                            {
                                if($item['id']==$attr['attrValueId'])
                                {

                                    if(isset($channelAttr['default_value']))
                                    {
                                        if(is_array($channelAttr['default_value']))
                                        {
                                            $channelAttr['default_value'][]=$attr['attrValueId'];
                                        }elseif (is_string($channelAttr['default_value'])){
                                            $channelAttr['default_value']=$attr['attrValueId'];
                                        }
                                        $channelAttr['default_id']=$attr['attrValueId'];
                                    }elseif (isset($channelAttr['default_id'])){

                                        if(is_array($channelAttr['default_id']))
                                        {
                                            $channelAttr['default_id'][]=$attr['attrValueId'];
                                        }elseif (is_string($channelAttr['default_id'])){
                                            $channelAttr['default_id']=$attr['attrValueId'];
                                        }
                                        $channelAttr['default_value']=$attr['attrValue'];
                                    }
                                }
                            }
                        }
                    }elseif(isset($attr['attrValueId'])) {
                        if(!empty($channelAttr['list_val']))
                        {
                            $items = $channelAttr['list_val'];
                            foreach ($items as $item)
                            {
                                if($item['id']==$attr['attrValueId'])
                                {
                                    if(isset($channelAttr['default_value']))
                                    {
                                        if(is_array($channelAttr['default_value']))
                                        {
                                            $channelAttr['default_value'][]=$attr['attrValueId'];
                                        }elseif (is_string($channelAttr['default_value'])){
                                            $channelAttr['default_value']=$attr['attrValueId'];
                                        }
                                    }elseif (isset($channelAttr['default_id'])){
                                        if(is_array($channelAttr['default_id']))
                                        {
                                            $channelAttr['default_id'][]=$attr['attrValueId'];
                                        }elseif (is_string($channelAttr['default_id'])){
                                            $channelAttr['default_id']=$attr['attrValueId'];
                                        }
                                    }
                                }
                            }
                        }
                    }elseif(isset($attr['attrValue'])){
                        $channelAttr['default_value']=$attr['attrValue'];
                    }
                }
            }

            return $channelAttr;
        }catch (Exception $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }catch (\RuntimeException $exp){
            throw new Exception("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @info 获取新的listing,本地属性，平台属性
     * @param array $aliSkuAttr 平台sku属性
     * @param array $arrGoodsListing    本地listing
     * @return array
     */
    public function getSkuInfo(array $aliSkuAttr,array $arrGoodsListing, $intAliCategoryId=false)
    {

        $arrAliSkuAttr = $this->recombinationAliAttr($aliSkuAttr);

        //获取本地listing用到的属性和值
        $arrLocalAttr = [];
        $arrCheckedAttrVal = [];    //匹配到的平台属性值

        $aliProductSkus = [];
        if($intAliCategoryId) {

            //查询是否有相关的spu刊登过
            $goodIds = array_column($arrGoodsListing,'goods_id');
            $goodIds = reset($goodIds);
            $aliProduct = (new AliexpressProduct())->field('id, product_id')->where(['status' => ['=', 2], 'goods_id' => $goodIds, 'category_id' => $intAliCategoryId])->find();

            if($aliProduct && $aliProduct['product_id'] > 0){
                $aliProductSkus = (new AliexpressProductSku())->alias('a')->field('a.sku_attr,g.sku as sku_code')->where(['ali_product_id' => $aliProduct['id']])->join('goods_sku g','a.goods_sku_id = g.id')->select();
                $aliProductSkus = $this->ObjToArray($aliProductSkus);
            }
        }

        $newAliSkuAttr = [];
        foreach ($aliSkuAttr as $key => $val) {
            $newAliSkuAttr[$val['id']] = $val;
        }

        foreach($arrGoodsListing as $key => &$sku)
        {
            if(!empty($sku['sku_attributes']))
            {
                foreach($sku['sku_attributes'] as $attrKey => &$attribute)
                {
                    //判断本地属性是否存在于平台属性中，有就写入平台属性ID
                    $name = strtolower($attribute['attribute_name']);
                    $code = strtolower($attribute['attribute_code']);

                    //如果类型是type获取style,则取goods_attribute表里的aliag
                    if($code=='type' || $code=='style')
                    {
                        $where=[
                            'goods_id'=>['=',$sku['goods_id']],
                            'attribute_id'=>['=',$attribute['attribute_id']],
                            'value_id'=>['=',$attribute['id']]
                        ];
                        $goodsAttribute = GoodsAttribute::where($where)->find();
                        if($goodsAttribute)
                        {
                            $attribute['value'] = $goodsAttribute['alias'];
                        }
                    }


                    $intAliAttrId = $intAliAttrValId= $intAliCustomizedPic = $intAliCustomizedName = '';

                    if(isset($arrAliSkuAttr[$code]) || isset($arrAliSkuAttr[$name]))
                    {

                        $arrAliAttrVals = [];
                        $intAliCustomizedPic = 0;
                        $intAliCustomizedName = 1;
                        if(isset($arrAliSkuAttr[$code])) {
                            $arrAliAttr = $arrAliSkuAttr[$code];

                            $arrAliAttrVals = $arrAliAttr['attr_val_list'];

                            //平台属性ID
                            $intAliAttrId = $arrAliAttr['id'];
                            //平台是否允许自定义图片和名称
                            $intAliCustomizedPic = $arrAliAttr['customized_pic'];
                            $intAliCustomizedName = $arrAliAttr['customized_name'];
                        }


                        $attribute['customized_pic'] = $intAliCustomizedPic;
                        $attribute['customized_name'] = $intAliCustomizedName;
                        $code = strtolower($attribute['code']);
                        $name = strtolower($attribute['value']);
                        //判断本地属性值是否存在于平台
                        if( isset($arrAliAttrVals[$code]) || isset($arrAliAttrVals[$name]))
                        {
                            $arrAliAttrVal = isset($arrAliAttrVals[$code]) ? $arrAliAttrVals[$code] : $arrAliAttrVals[$name];
                            //平台属性值ID
                            $intAliAttrValId = $arrAliAttrVal['id'];
                            //记录匹配到的平台属性值
                            if(!isset($arrCheckedAttrVal[$intAliAttrId])||!in_array($intAliAttrValId,$arrCheckedAttrVal[$intAliAttrId])){
                                $arrCheckedAttrVal[$intAliAttrId][] = $intAliAttrValId;
                            }
                        }

                    }else{

                        if($aliProductSkus && isset($aliProductSkus[$key]['sku_code']) && $aliProductSkus[$key]['sku_code'] == $sku['sku']) {

                            $sku_attr = json_decode($aliProductSkus[$key]['sku_attr'], true);

                            $attribute['customized_pic'] = '0';
                            $attribute['customized_name'] = '0';
                            if($sku_attr){

                                if(isset($sku_attr[$attrKey]['skuPropertyId'])){
                                    $intAliAttrId = $sku_attr[$attrKey]['skuPropertyId'];
                                }

                                if(isset($sku_attr[$attrKey]['propertyValueId'])){
                                    $intAliAttrValId = $sku_attr[$attrKey]['propertyValueId'];
                                }


                                $attribute['customized_pic'] =  0;
                                $attribute['customized_name'] = 1;

                                if(isset($newAliSkuAttr[$intAliAttrId]) && $newAliSkuAttr[$intAliAttrId]) {
                                    $attribute['customized_pic'] =  $newAliSkuAttr[$intAliAttrId]['customized_pic'];
                                    $attribute['customized_name'] = $newAliSkuAttr[$intAliAttrId]['customized_name'];
                                }
                            }
                        }
                    }

                    $attribute['ali_attr_id']       = $intAliAttrId;
                    $attribute['ali_attr_val_id']   = $intAliAttrValId;
                    $arrAttributeValues = [
                        $attribute['code']=>[
                            'id'    => $attribute['id'],
                            'code'  => $attribute['code'],
                            'value' => $attribute['value'],
                            'ali_attr_val_id'   => $intAliAttrValId,
                            'pic'=> GoodsGallery::getPicByGoodsAttr($sku['goods_id'],$attribute['attribute_id'],$attribute['id'])
                        ]
                    ];

                    //将本地属性与平台属性做对应
                    if(isset($arrLocalAttr[$attribute['attribute_id']]))
                    {
                        $arrLocalAttr[$attribute['attribute_id']]['attribute_values'] = array_merge(
                            $arrLocalAttr[$attribute['attribute_id']]['attribute_values'],
                            $arrAttributeValues
                        );

                    }else{

                        $arrLocalAttr[$attribute['attribute_id']] = [
                            'ali_attr_id'           => $intAliAttrId,
                            'ali_customized_pic'    => $intAliCustomizedPic,
                            'ali_customized_name'   => $intAliCustomizedName,
                            'attribute_name'        => $attribute['attribute_name'],
                            'attribute_code'        => $attribute['attribute_code'],
                            'attribute_id'          => $attribute['attribute_id'],
                            'attribute_values'      => $arrAttributeValues,
                        ];
                    }


                    if($intAliAttrId)
                    {
                        $arr_local_attr = $arrLocalAttr[$attribute['attribute_id']];
                        $arr_local_attr['attribute_values'] = array_values($arr_local_attr['attribute_values']);
                        $aliSkuAttr[$intAliAttrId]['local_attr'] = $arr_local_attr;
                        $aliSkuAttr[$intAliAttrId]['attribute_id'] = isset($arr_local_attr['attribute_id'])?$arr_local_attr['attribute_id']:'';
                        $aliSkuAttr[$intAliAttrId]['attribute_name'] = isset($arr_local_attr['attribute_name'])?$arr_local_attr['attribute_name']:'';
                        $aliSkuAttr[$intAliAttrId]['is_checked'] = $intAliAttrId ? 1 : 0;    //属性是否被选用
                        $aliSkuAttr[$intAliAttrId]['checked_val'] = isset($arrCheckedAttrVal[$intAliAttrId])?$arrCheckedAttrVal[$intAliAttrId]:[];    //被选用的属性值
                        $aliSkuAttr[$intAliAttrId]['used_vaules'] = $aliSkuAttr[$intAliAttrId]['checked_val'];
                    }

                }
            }

            $sku['combine_sku']=$sku['sku'].'*1';
        }

        if($arrLocalAttr) {
            $local_attrs = array_column($arrLocalAttr, 'attribute_id');
        }

        //没有属性补空值
        foreach($arrGoodsListing as &$goods)
        {
            $goods['thumb'] = empty($goods['thumb'])?'':\app\goods\service\GoodsImage::getThumbPath($goods['thumb'],200,200);
            $goods['goods_sku_id'] = $goods['id'];
            $goods['extend'] = [];
        }


        return [
            'listing'=>$arrGoodsListing,
            'local_attr'=>$arrLocalAttr,
            'ali_attr'=>$aliSkuAttr
        ];
    }

    /**
     * @info 重组平台属性，方便与本地属性做匹配
     * @param array $aliSkuAttr
     * @return array
     */
    public function recombinationAliAttr(array $aliSkuAttr)
    {
        //处理平台Sku属性数据
        $arrAliSkuAttr = [];
        foreach($aliSkuAttr as $attr){
            $arrAttrVal = [];
            foreach($attr['list_val'] as $val){
                $arrAttrVal[strtolower($val['name_en'])] = $val;
                $arrAttrVal[strtolower($val['name_zh'])] = $val;
            }
            unset($attr['list_val']);
            $attr['attr_val_list'] = $arrAttrVal;
            $arrAliSkuAttr[strtolower($attr['names_en'])] = $attr;
            $arrAliSkuAttr[strtolower($attr['names_zh'])] = $attr;
        }
        return $arrAliSkuAttr;
    }
    
    /**
     * @info 格式化树形结构
     * @param unknown $a
     * @param number $pid
     */    
    public function getTree($a,$pid=0)
    {  
        $tree = array();                              
        foreach($a as $v)
        {  
            if($v['group_pid'] == $pid)
            {                    
                $v['children'] = $this->getTree($a,$v['group_id']); 
                if($v['children'] == null)
                {  
                    unset($v['children']);           
                }  
                $tree[] = $v;                         
            }  
        }  
        return $tree;                                  
    }  
    /**
     * @info 拼接刊登产品的SKU (弃用)
     * @param unknown $arrSku
     * @param unknown $arrGoodsSku
     */
    public function MosaicSku(array $arrSku,$arrGoodsListing)
    {
        //获取速卖通所有SKU的类型中英文名称
        $arrSku_En = [];
        $arrSku_Zh = [];
        foreach($arrSku as &$arrASku)
        {
            $arrAttrVal_En = [];
            $arrAttrVal_Cn = [];
            foreach($arrASku['list_val'] as $arrAttrVal)
            {
                $arrAttrVal_En[strtolower($arrAttrVal['name_en'])] = $arrAttrVal['id'];
                $arrAttrVal_Cn[strtolower($arrAttrVal['name_zh'])] = $arrAttrVal['id'];
            }
            $arrASku['list_val_cn'] = $arrAttrVal_Cn;
            $arrASku['list_val_en'] = $arrAttrVal_En;
            $arrASku['names_en'] = strtolower($arrASku['names_en']);
            $arrASku['names_zh'] = strtolower($arrASku['names_zh']);
            $arrSku_En[$arrASku['names_en']] = &$arrASku;
            $arrSku_Zh[$arrASku['names_zh']] = &$arrASku;
            //另外生成一组，用来求自定义值的可选参数
            $arrSku_En_Temp[$arrASku['names_en']] = $arrASku;
            $arrSku_Zh_Temp[$arrASku['names_zh']] = $arrASku;
        }   
        //先筛选出所有的属性和值
        $arrTempAttr = [];
        foreach($arrGoodsListing as $arrListing)
        {
            foreach($arrListing['sku_attributes'] as $key=>$arrGoodsSku)
            {
                $strAttributeCode = strtolower($arrGoodsSku['attribute_code']);
                $strAttributeName = strtolower($arrGoodsSku['attribute_name']);
                $strAttributeValueCode = strtolower($arrGoodsSku['code']);
                $strAttributeValueValue = strtolower($arrGoodsSku['value']);
                if(isset($arrSku_En_Temp[$strAttributeCode]['list_val_en'][$strAttributeValueCode]))
                {
                    unset($arrSku_En_Temp[$strAttributeCode]['list_val_en'][$strAttributeValueCode]);
                }
                if(isset($arrSku_Zh_Temp[$strAttributeName]['list_val_cn'][$strAttributeValueValue]))
                {
                   unset($arrSku_Zh_Temp[$strAttributeName]['list_val_cn'][$strAttributeValueValue]);
                }
            }
        }
        //统计使用了哪些SKU属性和属性值
        $arrSumSkuValue = [];
        //获取本地商品Listing的Sku中英文名称,如果不存在的 则剔除，如果存在则匹配对应值或者修改值
        foreach($arrGoodsListing as &$arrListing)
        {
            foreach($arrListing['sku_attributes'] as $key=>&$arrGoodsSku)
            {
                $strAttributeCode = strtolower($arrGoodsSku['attribute_code']);
                $strAttributeName = strtolower($arrGoodsSku['attribute_name']);
                $strAttributeValueCode = strtolower($arrGoodsSku['code']);
                $strAttributeValueValue = strtolower($arrGoodsSku['value']);
                //英文检测
                if(in_array($strAttributeCode, array_keys($arrSku_En)))
                {
                    //检测英文属性名字是否存在于速卖通SKU属性英文名集
                    $arrGoodsSku['ali_sku_id'] = $arrSku_En[$strAttributeCode]['id'];
                    
                    //读取英文键的SKU属性值里面是否存在本地英文值的键
                    if(in_array($strAttributeValueCode,array_keys($arrSku_En[$strAttributeCode]['list_val_en'])))
                    {
                        //定位到结果集,将值ID传送给本地SKU集
                        $arrGoodsSku['ali_sku_val_id'] = $arrSku_En[$strAttributeCode]['list_val_en'][$strAttributeValueCode];
                    }
                    elseif($arrSku_En[$strAttributeCode]['customized_name'] && count($arrSku_En_Temp[$strAttributeCode]['list_val_en'])>0) 
                    {
                        //如果以上没有查询到，但是可以自定义名字,强制使用未使用的属性值第一个参数
                         $strKey = array_keys($arrSku_En_Temp[$strAttributeCode]['list_val_en'])[0];
                         $arrGoodsSku['ali_sku_val_id'] = $arrSku_En_Temp[$strAttributeCode]['list_val_en'][$strKey];
                         
                         //注入自定义值  ['属性ID_值ID']['custom_value'] = 本地定义code;
                         $arrCustomSkuVal[$arrGoodsSku['ali_sku_id']][$arrGoodsSku['ali_sku_val_id']]['custom_value']=$arrGoodsSku['code'];
                         //注入检测集里面，为下一个用同一个自定义属性做贮备
                         $arrSku_En[$strAttributeCode]['list_val_en'][$strAttributeValueCode] = $arrGoodsSku['ali_sku_val_id'];
                         unset($arrSku_En_Temp[$strAttributeCode]['list_val_en'][$strKey]);
                    }
                    else
                    {   
                        //不可以自定义名字且值无法匹配则丢弃该SKU本地属性
                        unset($arrListing['sku_attributes'][$key]);
                        continue;
                    }
                    //检测是否可以自定义图片，如果可以则添加自定义SKU图片
                    if($arrSku_En[$strAttributeCode]['customized_pic'] && !empty($arrListing['thumb']) )//&& file_exists($arrListing['thumb']))
                    {
                        $arrCustomSkuVal[$arrGoodsSku['ali_sku_id']][$arrGoodsSku['ali_sku_val_id']]['custom_thumb'] =$arrListing['thumb'];
                    }
                }
                //中文检测
                elseif(in_array($strAttributeName, array_keys($arrSku_Zh)))
                {
                    //检测中文属性名字是否存在于速卖通SKU属性中文名集
                    $arrGoodsSku['ali_sku_id'] = $arrSku_Zh[$strAttributeName]['id'];
                    //读取中文键的SKU属性值里面是否存在本地中文值的键
                    if(in_array($strAttributeValueValue,array_keys($arrSku_Zh[$strAttributeName]['list_val_cn'])))
                    {
                        //定位到结果集,将值ID传送给本地SKU集
                        $arrGoodsSku['ali_sku_val_id'] = $arrSku_Zh[$strAttributeName]['list_val_cn'][$strAttributeValueValue];
                    }
                    elseif($arrSku_Zh[$strAttributeName]['customized_name'])
                    {
                        //如果以上没有查询到，但是可以自定义名字,强制使用未使用的属性值第一个参数
                        $strKey = array_keys($arrSku_Zh_Temp[$strAttributeName]['list_val_cn'])[0];
                        $arrGoodsSku['ali_sku_val_id'] = $arrSku_Zh_Temp[$strAttributeName]['list_val_cn'][$strKey];
                        //注入自定义值  ['属性ID_值ID']['custom_value'] = 本地定义code;
                        $arrCustomSkuVal[$arrGoodsSku['ali_sku_id']][$arrGoodsSku['ali_sku_val_id']]['custom_value']=$arrGoodsSku['code'];
                        //注入检测集里面，为下一个用同一个自定义属性做贮备
                        $arrSku_Zh[$strAttributeName]['list_val_cn'][$strAttributeValueValue] = $arrGoodsSku['ali_sku_val_id'];
                        unset($arrSku_Zh_Temp[$strAttributeName]['list_val_cn'][$strKey]);
                    }
                    else
                    {
                        //不可以自定义名字且值无法匹配则丢弃该SKU本地属性
                        unset($arrListing['sku_attributes'][$key]);
                        continue;
                    }
                    //检测是否可以自定义图片，如果可以则添加自定义SKU图片
                    if($arrSku_Zh[$strAttributeName]['customized_pic'] && !empty($arrListing['thumb']) && file_exists($arrListing['thumb']))
                    {
                        $arrCustomSkuVal[$arrGoodsSku['ali_sku_id']][$arrGoodsSku['ali_sku_val_id']]['custom_thumb'] =$arrListing['thumb'];
                    }
                }
                else
                {
                    //未检测到存在于速卖通的该SKU属性名称，剔除！
                    unset($arrListing['sku_attributes'][$key]);
                    continue;
                }
                //统计所有使用过的SKU属性和值
                $arrSumSkuValue[$arrGoodsSku['ali_sku_id']][] = $arrGoodsSku['ali_sku_val_id'];
                $arrSumSkuValue[$arrGoodsSku['ali_sku_id']] = array_unique($arrSumSkuValue[$arrGoodsSku['ali_sku_id']]);
                //清除无用的数据
                unset($arrListing['thumb']);
                unset($arrGoodsSku['attribute_id']);
                unset($arrGoodsSku['code']);
                unset($arrGoodsSku['value']);
                unset($arrGoodsSku['attribute_name']);
                unset($arrGoodsSku['attribute_code']);
            }
            //清除无用的数据
            unset($arrListing['create_time']);
            unset($arrListing['update_time']);
            unset($arrListing['spu_name']);
            unset($arrListing['weight']);
            unset($arrListing['status']);
            unset($arrListing['out_code']);
            unset($arrListing['alias_sku']);
            unset($arrListing['name']);
        }
        //生成选项卡数据
        $arrNewSku = [];
        foreach($arrSku as &$value)
        {
            unset($value['required']);
            unset($value['sku']);
            unset($value['list_val']);
            unset($value['list_val_cn']);
            //对基础SKU数据，结合已经选择的SKU属性ID，添加自定义属性值框框和自定义图片框框
            $value['list_val_en']=array_flip($value['list_val_en']);
            $arrNewSku[$value['id']] = $value;
        }
        unset($arrSku);
        //拼装自定义属性
        $arrCustom =[];
        foreach($arrSumSkuValue as $k=>$v)
        {
            $arrTempCustom = ['id'=>$k,'name'=>$arrNewSku[$k]['names_zh']];
            foreach($arrNewSku[$k]['list_val_en'] as $key=>$value)
            {
                if(!$arrNewSku[$k]['customized_pic'] && !$arrNewSku[$k]['customized_name'])
                {
                    continue;
                }
                $arrTempCustom['value_id']=$key;
                if($arrNewSku[$k]['customized_pic'])
                {
                    //如果可以自定义图片则添加栏位
                    $arrTempCustom['custom_thumb'] = '';
                    if(isset($arrCustomSkuVal[$k][$key]['custom_thumb']))
                    {
                        $arrTempCustom['custom_thumb'] = $arrCustomSkuVal[$k][$key]['custom_thumb'];
                    }
                }
                if($arrNewSku[$k]['customized_name'])
                {
                    //如果可以自定义值则添加栏位
                    $arrTempCustom['custom_value'] = '';
                    if(isset($arrCustomSkuVal[$k][$key]['custom_value']))
                    {
                        $arrTempCustom['custom_value'] = $arrCustomSkuVal[$k][$key]['custom_value'];
                    }
                }
                $arrCustom[]=$arrTempCustom;
            }
        }
        return ['newsku'=>$arrNewSku,'custom'=>$arrCustom,'listing'=>$arrGoodsListing,'sumsku'=>array_keys($arrSumSkuValue)];
    }
    
    /**
     * @info 获取商品可选图片列表
     * @param unknown $intGoodsId
     */
    public function GetPublishImage($intGoodsId)
    {
        $imgs = GoodsGallery::where('goods_id',$intGoodsId)->select();
        if(!empty($imgs)){
            $imgs = collection($imgs)->toArray();
            foreach ($imgs as &$img)
            {
                $img['path'] = \app\goods\service\GoodsImage::getImagePath($img['path']);
            }
        }
        return $imgs;
    }
    
    /**
     * @info GoodsSku表里面的SKU属性从JSON转换为数组并且查询出来
     * @param unknown $objGoodsSku
     */
    public function GoodsSkuAttrJsonToArray($objGoodsSku)
    {
        foreach ($objGoodsSku as &$GoodsSku)
        {
            $sku_attributes = json_decode($GoodsSku->sku_attributes,true);
            $arrTemp =  join(',',$sku_attributes);

            $goods_id = $GoodsSku->goods_id;
            $sku_attributes = GoodsHelp::getAttrbuteInfoBySkuAttributes($sku_attributes, $goods_id);

            $skuAttributes = [];
            if($sku_attributes) {
                foreach ($sku_attributes as $val) {
                    $skuAttributes[] = [
                        'id' => $val['value_id'],
                        'attribute_id' => $val['id'],
                        'attribute_name' => $val['name'],
                        'value' => $val['value'],
                        'attribute_code' => $val['code'],
                        'code' => '',
                    ];
                }

            }

            $GoodsSku->sku_attributes = $skuAttributes;

           /*$arrTemp =  join(',',json_decode($GoodsSku->sku_attributes,true));

           $GoodsSku->sku_attributes = AttributeValue::where('p1.id','IN',$arrTemp)
           ->field('p1.id,p1.attribute_id,p1.code,p1.value,p2.name as attribute_name,p2.code as attribute_code')
           ->alias('p1')
           ->join('attribute p2','p2.id=p1.attribute_id','LEFT')
           ->select();
           $arrTemp = '';*/
        }
        return $objGoodsSku;
    }
    
    /**
     * @info 速卖通上传图片，参数为图片地址，这个图片地址要么是
     * @param unknown $strUrl
     * @param unknown $intAccountId
     */
    public function uploadTempImage($strUrl,$intAccountId)
    {
        return $this->getImagesApi($intAccountId)->uploadTempImage($strUrl);
    }
    
    /**
     * @info 速卖通上传图片，参数为图片地址，这个图片地址要么是
     * @param unknown $strUrl
     * @param unknown $intAccountId
     */
    public function uploadImage($strUrl,$intAccountId)
    {
        return $this->getImagesApi($intAccountId)->uploadImage($strUrl);
    }
    
    public function ListImagePagination($arrData)
    {
        $intAccountId = $arrData['account_id'];
        unset($arrData['account_id']);
        return $this->getImagesApi($intAccountId)->listImagePagination($arrData);
    }
    
    public function TestDelete($intId)
    {
        AliexpressProduct::where('id',$intId)->delete();
        AliexpressProductInfo::where('id',$intId)->delete();
        AliexpressProductAttr::where('ap_id',$intId)->delete();
        AliexpressProductSku::where('ap_id',$intId)->delete();
        AliexpressProductSkuVal::where('ap_id',$intId)->delete();
        AliexpressPublishPlan::where('ap_id',$intId)->delete();
        return 1;
    }
    
    /**
     * @info 刊登商品
     * @param unknown $arrData
     */
    public function Publish($arrData)
    {
        //第一步、检测该产品 goods_id在数据库是否存在该商户刊登的数据，如果存在，且已经刊登，则直接return 产品ID
        //第一步、如果存在该goods_id 但是该产品未进行刊登，则修改这个数据
        //return $this->TestDelete(968474468496789632);
        $objProduct = AliexpressProduct::get(function($query) use ($arrData){
            $query->where('goods_id',$arrData['goods_id'])
            ->where('account_id',$arrData['account_id']);
        });
        if($objProduct != NULL  && !empty($objProduct->product_id))
        {
            //检测goods_id 数据存在，且存在速卖通刊登成功返回的ID，则已经刊登的数据
            return $objProduct->id;
        }
        $intStatus = $arrData['is_plan_publish']?1:2;
        $arrProduct = FieldAdjustHelper::adjust($arrData,'publish','HTU');

        if(empty($objProduct->id))
        {
            $intId = abs(Twitter::instance()->nextId(4,$arrData['account_id']));
            $objProduct = new AliexpressProduct();
            //如果不存在ID，则说明该商品不存在需要增加数据
            $arrProduct['id']= $intId;
            $arrProduct['create_time']=time();
        }
        else 
        {
            $intId = $objProduct->id;
            $objProduct->isUpdate(true);
        }
        $arrProduct['status'] = $intStatus;
        $arrProduct['update_time'] = time();
        $objProduct->allowField(true);
        
        //第二步、添加或者修改AliexpressProductInfo
        $objProductInfo =  AliexpressProductInfo::get(['id'=>$intId]);
        if($objProductInfo !=null)
        {
            $objProductInfo->isUpdate(true);
        }
        else 
        {
            $objProductInfo = new AliexpressProductInfo;
            $arrProduct['id']=$intId;
            $arrProduct['create_time'] = time();
        }
        $arrProduct['update_time'] = time();
        $objProductInfo->allowField(true);
        //第三步、添加AliexpressProductAttr、前提是删除掉所有的这个产品ID 的属性，然后再加入
        $arrTempAttr = [];
        foreach ($arrData['attr'] as $value)
        {
            $value['ap_id'] = $intId;
            $value['create_time'] = time();
            $value['update_time'] = time();
            $arrTempAttr[] = $value;
        }
        //第四步、添加Listing数据 ,先删除所有的SKU Listing和SKU Listing属性值
        $arrTempSku = [];
        $arrTempSkuVal = [];
        foreach($arrData['sku'] as $value)
        {
            $value = FieldAdjustHelper::adjust($value, '','HTU');
            $value['ap_id'] = $intId;
            $value['create_time'] = time();
            $value['update_time'] = time();
            $arrTempSku[] = $value;
            foreach($value['sku_val'] as $v)
            {
                $v['ap_id'] = $intId;
                $v['sku_code'] = $value['sku_code'];
                $v['create_time'] = time();
                $v['update_time'] = time();
                $arrTempSkuVal[] =  $v;
            }
        }
        //第六步、如果是计划刊登，则需要添加计划刊登表数据
        $arrSend = [];
        if($arrData['is_plan_publish'])
        {
            $objPublishPlan = new AliexpressPublishPlan();
            if($objPublishPlan->get(['ap_id'=>$intId]))
            {
                $objPublishPlan->isUpdate(true);
            }
            else
            {
                $objPublishPlan->ap_id = $intId;
                $objPublishPlan->create_time = time();
            }
            $objPublishPlan->status = 0;
            $objPublishPlan->plan_time = $arrData['plan_publish_time'];
            $objPublishPlan->send_return = '';
            $objPublishPlan->update_time = time();
        }
        else 
        {
            $arrSend = $this->CreateAliexpressPublishSend($arrData);
        }

        //第七步、开始干活，启动事务处理
        Db::startTrans();
        $objAttr = new AliexpressProductAttr();
        $objSku = new AliexpressProductSku();
        $objSkuVal = new AliexpressProductSkuVal();
        try
        {
            $objProduct->allowField(true)->save($arrProduct);
            $objProductInfo->allowField(true)->save($arrProduct);
            $objAttr->where('ap_id',$intId)->delete();
            $objAttr->allowField(true)->saveAll($arrTempAttr);
            $objSku->where('ap_id',$intId)->delete();
            $objSkuVal->where('ap_id',$intId)->delete();
            $objSku->allowField(true)->saveAll($arrTempSku);
            $objSkuVal->allowField(true)->saveAll($arrTempSkuVal);
            if($arrData['is_plan_publish'])
            {
                $objPublishPlan->save([],['ap_id'=>$intId]);
            }
            else 
            {
                /*
                $arrResult = $this->getPostProductApi($arrData['account_id'])->postAeProduct($arrSend);
                if(!isset($arrResult['productId']) || empty($arrResult['productId']))
                {
                    throw new  \Exception ('速卖通刊登失败，请检查参数！');
                }
                Db::execute("UPDATE `aliexpress_product` set `product_id`={$arrResult['productId']} WHERE `id`={$intId};");
                */
            }
            Db::commit();
            return $intId;
        } 
        catch (\Exception $err) 
        {
            Db::rollback();
            throw new  \Exception ($err->getMessage());
        }
    }
    
    /**
     * @info 拼接速卖通刊登发送数据
     * @param unknown $arrData
     */
    public function CreateAliexpressPublishSend($arrData)
    {
        $arrAeopAeProductSKUs = [];
        $arrAeopAeProductPropertys = [];
        //拼装普通属性
        $arrAttr = $arrData['attr'];
        foreach($arrAttr as $value)
        {
            $arrTemp = [];
            foreach ($value as $k=>$v)
            {
                if(empty($v))continue;
                switch ( $k )
                {
                    case  'attr_name_id' :
                        $arrTemp['attrNameId'] = $v;
                        break;
                    case  'attr_value_id' :
                        $arrTemp['attrValueId'] = $v;
                        break;
                    case  'attr_name' :
                        $arrTemp['attrName'] = $v;
                        break;
                    case  'attr_value' :
                        $arrTemp['attrValue'] = $v;
                        break;
                }
            }
            if(!empty($arrTemp))$arrAeopAeProductPropertys[]=$arrTemp;
        }
        //拼接SKU属性
        $arrSkuAttr = $arrData['sku'];
        foreach ($arrSkuAttr as $value)
        {
            $arrTempOne = [];
            $arrTempOne['skuPrice'] = (string)$value['skuPrice'];
            $arrTempOne['skuCode'] = $value['skuCode'];
            $arrTempOne['skuStock'] = $value['ipmSkuStock']>0?true:false;
            $arrTempOne['ipmSkuStock'] = $value['ipmSkuStock'];
            $arrTempOne['currencyCode'] = $value['currencyCode'];
            $arrTempOne['aeopSKUProperty'] = [];
            foreach ($value['skuVal'] as $v)
            {
                $arrTempTwo = [];
                foreach ($v as $smallK=>$smallV)
                {
                    if(empty($smallV))continue;
                    switch ( $smallK )
                    {
                        case  'sku_property_id' :
                            $arrTempTwo['skuPropertyId'] = $smallV;
                            break;
                        case  'property_value_id' :
                            $arrTempTwo['propertyValueId'] = $smallV;
                            break;
                        case  'property_value_definition_name' :
                            $arrTempTwo['propertyValueDefinitionName'] = $smallV;
                            break;
                        case  'sku_image' :
                            $arrTempTwo['skuImage'] = $smallV;
                            break;
                    }
                }
                if(!empty($arrTempTwo))
                {
                    $arrTempOne['aeopSKUProperty'][] = $arrTempTwo;
                }
            }
            $arrAeopAeProductSKUs[] = $arrTempOne;
        }
        $arrSend = [
            'detail'=>$arrData['detail'],
            'aeopAeProductSKUs'=>$arrAeopAeProductSKUs,
            'deliveryTime'=>$arrData['deliveryTime'],
            'promiseTemplateId'=>$arrData['promiseTemplateId'],
            'categoryId'=>$arrData['category_id'],
            'subject'=>$arrData['title'],
            'freightTemplateId'=>$arrData['freightTemplateId'],
            'imageURLs'=>$arrData['imageURLs'],
            'productUnit'=>$arrData['productUnit'],
            'packageType'=>(bool)$arrData['packageType'],
            'lotNum'=>$arrData['lotNum'],
            'packageLength'=>$arrData['packageLength'],
            'packageWidth'=>$arrData['packageWidth'],
            'packageHeight'=>$arrData['packageHeight'],
            'grossWeight'=>$arrData['grossWeight'],
            'isPackSell'=>(bool)$arrData['isPackSell'],
            'baseUnit'=>$arrData['baseUnit'],
            'addUnit'=>$arrData['addUnit'],
            'addWeight'=>$arrData['addWeight'],
            'wsValidNum'=>$arrData['wsValidNum'],
            'aeopAeProductPropertys'=>$arrAeopAeProductPropertys,
            'bulkOrder'=>$arrData['bulkOrder'],
            'bulkDiscount'=>$arrData['bulkDiscount'],
            'reduceStrategy'=>$arrData['reduceStrategy'],
            'groupId'=>$arrData['groupId'],
            'currencyCode'=>$arrData['currencyCode'],
            'mobileDetail'=>$arrData['mobileDetail'],
        ];
        return $arrSend;
    }

    /**
     * @info 获取产品计数单位
     * @return array
     */
    public function getProductUnit()
    {
        $arrProductUnit = AliexpressProduct::PRODUCT_UNIT;
        array_walk($arrProductUnit, function(&$value,$key){
            $value = ['id'=>$key,'name'=>$value];
        });
        return array_values($arrProductUnit);
    }

    
    /**
     * @info 对象转换成数组
     * @param unknown $obj
     */
    public function ObjToArray($obj)
    {
        return json_decode(json_encode($obj),true);
    }
    
    /**
     * @info 分页数据处理
     * @param unknown $objTable
     * @param unknown $arrWhere
     * @param unknown $arrData
     * @return multitype:unknown number Ambigous <number, unknown>
     */
    public function GetList($objTable,$arrFilter)
    {
        //数据
        if(isset($arrFilter['Field']))
        {
            $arrFilter['Field']=implode(',', $arrFilter['Field']);
        }
        if(isset($arrFilter['Alias']))$objTable->alias($arrFilter['Alias']);
        if(isset($arrFilter['Where']))$objTable->where($arrFilter['Where']);
        if(isset($arrFilter['Join']))$objTable->join($arrFilter['Join']);
        if(isset($arrFilter['Field']))$objTable->field($arrFilter['Field']);
        //是否为分页呢
        if(isset($arrFilter['Data']['page']))
        {
            //当前单页条数
            $intPageSize = isset($arrFilter['Data']['pageSize'])?$arrFilter['Data']['pageSize']:50;
            //总条数
            $intTotalData = $objTable->count();
            //总页数
            $intTotalPage = ($intTotalData/$intPageSize)>0?ceil($intTotalData/$intPageSize):1;
            //数据
            if(isset($arrFilter['Alias']))$objTable->alias($arrFilter['Alias']);
            if(isset($arrFilter['Where']))$objTable->where($arrFilter['Where']);
            if(isset($arrFilter['Join']))$objTable->join($arrFilter['Join']);
            if(isset($arrFilter['Field']))$objTable->field($arrFilter['Field']);
            $objTable->page($arrFilter['Data']['page'],$intPageSize);
            $returnData = $objTable->select();
            return ['page'=>$arrFilter['Data']['page'],'count'=>$intTotalData,'totalpage'=>$intTotalPage,'pageSize'=>$intPageSize,'data'=>$returnData];
        }
        else
        {
            return $objTable->select();
        }
    }

    public function getAliProductList()
    {

    }

    /**
     * @desc 违禁词检测
     * @param $accountId
     * @param $categoryId
     * @param string $title
     * @param string $detail
     * @param array $attrVal
     * @param array $keywords
     * @return mixed
     */
    public function checkProhibitedWords($accountId,$categoryId,$title='',$detail='',$attrVal=[],$keywords=[])
    {
        $productServer = $this->getPostProductApi($accountId);
        $response = $productServer->findAeProductProhibitedWords(
            [
                'categoryId'=>$categoryId,
                'title'=>$title,
                'keywords'=>$keywords,
                'productProperties'=>$attrVal,
                'detail'=>$detail
            ]
        );

        if(isset($response['error_code']))
        {
            return json(['message'=>$response['error_message']],500);
        }
        return $response;
    }

    /**
     * @desc 批量删除待刊登商品
     * @param array $arrId
     * @return string
     */
    public function deleteProduct(array $arrId)
    {
        $result = AliexpressProduct::where(['id'=>['in',$arrId],'product_id'=>['>',0]])->find();
        if(!empty($result)){
            throw new AliPublishException('已刊登产品不能删除');
        }
        $service = new GoodsPublishMapService();
        $products = AliexpressProduct::where(['id'=>['in',$arrId]])->field('id,goods_spu,account_id')->select();
        if(empty($products)){
            throw new AliPublishException('未找到任何要删除的产品');
        }
        $products = array_combine(array_column($products,'id'),$products);

        Db::startTrans();
        try{
	        foreach ($arrId as $id)
	        {
                if(!empty($products[$id]['goods_spu'])){
                    GoodsPublishMapService::update(4,$products[$id]['goods_spu'],$products[$id]['account_id'],0);
                }
	        }

            AliexpressProduct::destroy(['id' => ['in', $arrId]]);
            AliexpressPublishPlan::destroy(['ap_id' => ['in', $arrId]]);
            AliexpressProductImage::destroy(['ali_product_id' => ['in', $arrId]]);
            Db::commit();
            return '操作成功';
        }catch(Exception $ex){
            Db::rollback();
            throw new AliPublishException($ex->getMessage());
        }
    }

    public function getSkuList($categoryId,array $skuId)
    {
        //获取分类下所有sku属性
        $categoryAttrModel = new AliexpressCategoryAttr();
        $arrSkuAttr = $categoryAttrModel->getCategoryAttr($categoryId,1);
        $arrSku = [];
        if(!empty($arrSkuAttr)){
            foreach($arrSkuAttr as $arrValue)
            {
                $arrSku[$arrValue['id']] = $arrValue;
            }
        }
        $objGoodsListing = $this->GoodsSkuAttrJsonToArray(GoodsSku::where(['id'=>['in',$skuId]])->select());
        $arrGoodsListing = $this->ObjToArray($objGoodsListing);

        //拼装SKU生成Listing部分数据
        $arrSkuData =  $this->getSkuInfo($arrSku,$arrGoodsListing, $categoryId);

        $items = $arrSkuData['listing'];

        foreach ($items as &$item){
            $item['local_attr']=$item['sku_attributes'];
        }
        return $items;
        //return $arrSkuData['listing'];
    }

    /**
     * 获取违禁类型
     * @return array
     */
    public function getProhibitedType()
    {
        return AliexpressProduct::PROHIBITED_TYPE;
    }



    /**
     *添加区域分组
     *
     */
    public function addRegionGroup($post)
    {


        if(isset($post['region_group_id']) && $post['region_group_id']){
            $post['id'] = $post['region_group_id'];
            unset($post['region_group_id']);
        }

        if(isset($post['region_template_id']) && $post['region_template_id']){
            $post['id'] = $post['region_template_id'];
            unset($post['region_template_id']);
        }

        $time = time();

        $model = new AliexpressGroupRegionModel;

        if(isset($post['name'])){
            //检查分组/模板是否已经存在
            $where['name'] = ['=',"{$post['name']}"];

            if(isset($post['parent_id']) && $post['parent_id']){
                $where['parent_id'] = ['=', $post['parent_id']];
            }

            if(isset($post['id']) && $post['id']){
                $where['id'] = ['not in', $post['id']];
            }

            $where['create_id'] = ['=', $post['create_id']];
            $data = $model->field('id')->where($where)->find();

            if($data){
                return ['message' => '名称已经存在', 'status' => -1];
            }
        }

        //根据id,判断是否 是添加/删除
        if(isset($post['id']) && $post['id']){

            //编辑
            $data = [
                'updated_time' => $time,
                'create_id' => $post['create_id'],
            ];


            if(isset($post['name'])){
                $data['name'] = $post['name'];
            }

            if(isset($post['parent_id']) && $post['parent_id']){
                $data['parent_id'] = $post['parent_id'];
            }

            if(isset($post['aeopNationalQuoteConfiguration']) && $post['aeopNationalQuoteConfiguration']){
                $data['aeopNationalQuoteConfiguration'] = $post['aeopNationalQuoteConfiguration'];
            }

            $id = $model->isUpdate(true)->save($data, ['id' => $post['id']]);

            return $id ? ['message' => '更新成功', 'status' => 0] : ['message' => '更新失败', 'status' => -1];
        }


        //添加
        $post['created_time'] = $time;

        $model->save($post);
        $id = $model->id;

        return $id ? ['message' => '添加成功', 'status' => 0] : ['message' => '添加失败', 'status' => -1];
    }



    /**
     *删除分组/区域模板
     *
     */
    public function regionDel($params)
    {
            $model = new AliexpressGroupRegionModel;

            if(isset($params['region_group_id']) && $params['region_group_id']){
                $id = $params['region_group_id'];
            }

            if(isset($params['region_template_id']) && $params['region_template_id']){
                $id = $params['region_template_id'];
            }

            $id = $model->where(['id' => $id])->delete();

            //分组id存在,则删除分组下的模板信息
            if($id && isset($params['region_group_id']) && $params['region_group_id']){
                $model->where(['parent_id' => $params['region_group_id']])->delete();
            }

            return $id ? '删除成功' : '删除失败';

    }



    /**
     *分组,区域模板列表
     *
     */
    public function regionTemplate($groupId)
    {

        //根据登录id 获取分组, 区域模板
        $userInfo = Common::getUserInfo();
        $userIds = $this->getUnderlingInfo($userInfo['user_id']);//获取下属人员信息
        $userIds = implode(',', $userIds);
        $groupRegionModel = new AliexpressGroupRegionModel;

        //根据用户id获取分组
        $where = [];
        if($groupId){
            $where = ['parent_id' => ['=', $groupId]];
        }else{
            $where = ['parent_id' => ['>', 0]];
        }

        $groupRegion = $groupRegionModel->field('id as region_template_id,parent_id,name,type as configuration_type,aeopNationalQuoteConfiguration')->where($where)->whereIn('create_id', $userIds)->select();

        return $groupRegion ? $groupRegion :[];
    }


    /**
     *分组列表
     *
     */
    public function regionGroup()
    {
        //根据登录id 获取分组, 区域模板
        $userInfo = Common::getUserInfo();
        $userIds = $this->getUnderlingInfo($userInfo['user_id']);//获取下属人员信息
        $userIds = implode(',', $userIds);
        $groupRegionModel = new AliexpressGroupRegionModel;

        //根据用户id获取分组
        $groupRegion = $groupRegionModel->field('id as region_group_id,parent_id,name')->where('parent_id', '=', 0)->whereIn('create_id', $userIds)->select();

        return $groupRegion ? $groupRegion : [];
    }

    /**
     * 根据模板id获取模板信息
     *
     */
    public function regionTemplateInfo($templateId)
    {
        //根据登录id 获取分组, 区域模板
        $groupRegionModel = new AliexpressGroupRegionModel;

        //根据用户id获取分组
        $groupRegion = $groupRegionModel->field('id as region_template_id,parent_id,name,type as 
configuration_type,aeopNationalQuoteConfiguration')->where('id', '=', $templateId)->find();

        if ($groupRegion) {
            $groupRegion = $groupRegion->toArray();

            $groupRegion['aeopNationalQuoteConfiguration'] = json_decode($groupRegion['aeopNationalQuoteConfiguration'], true);
        }
        return $groupRegion ? $groupRegion : [];
    }



    /**
     *刊登失败批量提交刊登
     *
     */
    public function batchAddFailPublish($params)
    {
        $model = new AliexpressProduct();

        $where = ['id' => ['in', $params],'status' => 4];
        $result = $model->field('id')->where($where)->select();

        if($result){

            $productModel = new AliexpressProduct();
            $publishPlanModel = new AliexpressPublishPlan();

            foreach ($result as $val){

                //1.修改刊登异常状态为正在刊登状态
                $productModel->update(['status' => 3], ['id' => $val['id']]);
                
                //3.添加到队列中
                (new UniqueQueuer(AliexpressPublishFailQueue::class))->push($val['id']);
            }

            return ['message' => '批量提交成功,系统稍后会执行....', 'status' => 1];
        }

        return ['message' => '未选择刊登失败数据', 'status' => 0];
    }


    /**
     * @param $publishData
     * @param int $uid
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * 速卖通保存草稿
     */
    public function saveDraftData($publishData,$uid=0)
    {
        //根据goods_id检查产品是否禁止上架
        if(isset($publishData['goods_id']) && $publishData['goods_id'] && empty((new GoodsHelp())->getPlatformForChannel($publishData['goods_id'], 4))){
            return ['status' => -1];
        }

        $productModel = new AliexpressProduct();


        //组装ProductInfo表信息
        $arr_new_attr = [];
        if(!empty($publishData['attr']))
        {
            foreach($publishData['attr'] as $k=>$value)
            {
                //复制过滤品牌
                if(isset($value['attrNameId']) && $value['attrNameId'] == 2) {
                    continue;
                }
                if(isset($value['attrValueId'])&&is_array($value['attrValueId']))
                {
                    foreach($value['attrValueId'] as $valueId)
                    {
                        array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValueId'=>$valueId]);
                    }

                    if(isset($value['attrValue'])&&!empty($value['attrValueId']))
                    {
                        array_push($arr_new_attr,['attrNameId'=>$value['attrNameId'],'attrValue'=>$value['attrValue']]);
                    }

                }else{
                    array_push($arr_new_attr,$value);
                }
            }
        }
        $publishData['attr'] = $arr_new_attr;

        $arrProductInfoData = [
            'detail'=>$publishData['detail'],
            'mobile_detail'=>empty($publishData['mobile_detail'])?'{}':json_encode($publishData['mobile_detail']),
            'product_attr'=>json_encode($publishData['attr']),
            'region_group_id' => isset($publishData['region_group_id']) ? $publishData['region_group_id'] : 0,
            'region_template_id' => isset($publishData['region_template_id']) ? $publishData['region_template_id'] : 0,
        ];

        //组装ProductSku表信息
        $arrProductSkuData = [];
        foreach($publishData['sku'] as $sku)
        {
            $sku_attr_relation = json_encode($sku['sku_attr']);
            if(!empty($sku['sku_attr'])){
                foreach($sku['sku_attr'] as &$sku_attr){
                    if(isset($sku_attr['attribute_id'])){
                        unset($sku_attr['attribute_id']);
                    }
                    if(isset($sku_attr['attribute_value_id'])){
                        unset($sku_attr['attribute_value_id']);
                    }
                }
            }
            unset($sku['sku_attr']['attribute_id'],$sku['sku_attr']['attribute_value_id']);

            if( isset($sku['extend']) && !empty($sku['extend']))
            {
                foreach ($sku['extend'] as $extend)
                {
                    $arr_sku_attr = [];
                    $extend_attr = [
                        'skuPropertyId'=>$extend['ali_attr_id'],
                        'propertyValueId'=>$extend['ali_attr_val_id']
                    ];
                    $arr_sku_attr = $sku['sku_attr'];
                    $arr_sku_attr[] = $extend_attr;
                    $arrProductSkuData[] = [
                        //'ali_product_id'=>$id,
                        'sku_price'=>$extend['retail_price'],
                        'sku_code'=>$sku['sku_code'],
                        'combine_sku'=>$sku['combine_sku'],
                        'sku_stock'=>$extend['ipm_sku_stock']>0?1:0,
                        'ipm_sku_stock'=>$extend['ipm_sku_stock'],
                        'currency_code'=>isset($extend['currency_code'])?$sku['currency_code']:'USD',
                        'sku_attr_relation'=>$sku_attr_relation,
                        'sku_attr'=>json_encode($arr_sku_attr),
                        'goods_sku_id'=>$sku['goods_sku_id'],
                        'current_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                        'pre_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                    ];
                }
            }else{
                $arrProductSkuData[] = [
                    //'ali_product_id'=>$id,
                    'sku_price'=>$sku['sku_price'],
                    'sku_code'=>$sku['sku_code'],
                    'combine_sku'=>$sku['combine_sku'],
                    'sku_stock'=>$sku['ipm_sku_stock']>0?1:0,
                    'ipm_sku_stock'=>$sku['ipm_sku_stock'],
                    'currency_code'=>isset($sku['currency_code'])?$sku['currency_code']:'USD',
                    'sku_attr_relation'=>$sku_attr_relation,
                    'sku_attr'=>json_encode($sku['sku_attr']),
                    'goods_sku_id'=>$sku['goods_sku_id'],
                    'current_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                    'pre_cost'=>isset($sku['cost_price'])?$sku['cost_price']:0,
                ];
            }
        }

        unset($publishData['detail'],$publishData['mobile_detail'],$publishData['product_attr'],$publishData['sku']);
        //组装Product主表信息
        if(isset($publishData['isPackSell'])&&!$publishData['isPackSell'])
        {
            $publishData['baseUnit'] = $publishData['addUnit'] = $publishData['addWeight'] = 0;
        }

        if(isset($publishData['is_wholesale'])&&!$publishData['is_wholesale'])
        {
            $publishData['bulk_order'] = $publishData['bulk_discount'] = 0;
        }
        $publishData['currency_code'] = (isset($publishData['currency_code'])&&$publishData['currency_code'])?$publishData['currency_code']:'USD';

        $arrProductData = $publishData;

        $arrProductData['quote_config_status']=isset($publishData['quote_config_status'])?$publishData['quote_config_status']:0;

        if(isset($publishData['aeopNationalQuoteConfiguration']))
        {
            $arrProductData['aeop_national_quote_configuration']=json_encode($publishData['aeopNationalQuoteConfiguration']);
        }elseif(isset($publishData['aeop_national_quote_configuration'])){
            $arrProductData['aeop_national_quote_configuration']=json_encode($publishData['aeop_national_quote_configuration']);
        }

        //sku价格
        $arr_price = array_column($arrProductSkuData,'sku_price');

        if($arr_price){
            $arrProductData['product_max_price'] = max($arr_price);
            $arrProductData['product_min_price'] = min($arr_price);
            $arrProductData['product_price']=max($arr_price);
        }


        $arrProductData['group_id'] = empty($arrProductData['group_id'])?json_encode([]):json_encode([$arrProductData['group_id']]);
        $arrProductData['market_images'] = isset($arrProductData['market_images']) && $arrProductData['market_images'] ? json_encode([$arrProductData['market_images']]) : json_encode([]);

        //保存刊登分类与属性模板
        $map=[
            'goods_id'=> $arrProductData['goods_id'],
            'channel_category_id'=> $arrProductData['category_id'],
        ];

        $publishTemplateData=[
            'data'=>$arrProductInfoData['product_attr'],
            'create_id' => $uid
        ];

        //写入到缓存中
        $hashKey = $arrProductData['goods_id'].$arrProductData['category_id'];
        Cache::handler()->hSet('aliexpress_publish_template', $hashKey, \GuzzleHttp\json_encode($publishTemplateData));
        //速卖通模板队列
        (new UniqueQueuer(AliexpressPublishTemplateQueue::class))->push($map);

        $where = [];

        if(isset($publishData['id']) && $publishData['id'])
        {
            $where['id'] = ['=',$publishData['id']];
        }

        //检测是否已存在
        if($where)
        {
            $objProduct = $productModel->field('id')->where($where)->find();
        }else{
            $objProduct=[];
        }


        if(empty($objProduct))
        {
            $id = abs(Twitter::instance()->nextId(4,$publishData['account_id']));
            $arrProductData['id'] = $id;
            $arrProductData['application'] = 'rondaful';
            $arrProductData['publisher_id'] = $uid;

            $productSkuData = $this->productSkuData($arrProductSkuData);

            $result = $productModel->addProduct($arrProductData,$arrProductInfoData,$arrProductSkuData);

        }else{

            $productSkuData = $this->productSkuData($arrProductSkuData);
            $result = $productModel->updateProduct($objProduct, $arrProductData, $arrProductInfoData, $arrProductSkuData);
        }
        return $result;
    }



    /**
     *sku属性别名20个字符以内的英文、数字
     *
     */
    public function checkSkuAttrAlias($propertyValueDefinitionName)
    {
        if(preg_match("/^[A-Za-z0-9]{1,20}$/", $propertyValueDefinitionName)) {
            return false;
        }
        return true;
    }



    /**
     * 校验sku个数是否匹配sku值
     *
     */
    public function  checkSkuAttr($skuAttr)
    {
        if($skuAttr){

            $attrCount = [];
            $attrImgs = '';
            $definitionName = '';
            foreach ($skuAttr as $valAttr){
                foreach ($valAttr['sku_attr'] as $key => $attr){
                    $attrCount[$key][$attr['propertyValueId']] = $attr['skuPropertyId'];

                    if(isset($attr['skuImage'])){

                        $pngImage= $this->checkPngImages($attr['skuImage'],self::$png_image_error['sku_image']);

                        if($pngImage){
                            $attrImgs.= $pngImage;
                        }
                    }
                }
            }

            if($attrCount){

                //去重
                $attrCount = array_unique($attrCount, SORT_REGULAR);

                $counts = [];
                foreach ($attrCount as $key => $val){

                    $counts[$key] = count($val);
                }

                //计算数组值个数的乘积
                $counts = array_product($counts);
            }

            if(isset($counts) && $counts != count($skuAttr)){
                return ['message' => '例如一件衬衫包含两个维度的SKU属性：颜色（3种：蓝、黑、绿）和尺码（4个：M、L、XL、XXL），那么最终可以组合出3*4=12个SKU', 'status' => false];
            }
        }
        
        if($attrImgs){
            return ['message' => $attrImgs, 'status' => false];
        }

        return ['message' => 'sku个数与实际数量相同', 'status' => true];
    }


    
    /**
     *检测是否是png图片
     * 
     */
    public function checkPngImages($images, $pngImageErrer)
    {
        $base_url = Cache::store('configParams')->getConfig('innerPicUrl')['value'] . '/';
        return strrpos($images, '.png') !== false || strrpos($images, '.JPG') !== false ? '仅支持JPEG、JPG格式的图片，请检查 '.$pngImageErrer.' '.$base_url.$images.'中的图片格式！': false;
    }


    /**
     *刊登队列批量提交刊登
     *
     */
    public function batchAddWaitPublish($params)
    {
        $model = new AliexpressProduct();

        $where = ['id' => ['in', $params],'status' => 3];
        $result = $model->field('id')->where($where)->select();
        if($result){
            $publishPlanModel = new AliexpressPublishPlan();

            foreach ($result as $val){
                //添加到队列中
                (new UniqueQueuer(AliexpressQueueJob::class))->push($val['id']);
            }

            return ['message' => '批量提交成功,系统稍后会执行....', 'status' => 1];
        }

        return ['message' => '未选择刊登数据', 'status' => 0];
    }




    /**
     *修复线上速卖通刊登异常数据
     *post:product sku attribute value error","isSuccess":false}
     */
    public function failPublishSave($params)
    {

        $where['p.product_id'] = ['>',0];
        if(isset($params['custom_template_id'])) {
            $where['p.custom_template_id'] = ['=', $params['custom_template_id']];
        }


        $page = 1;
        $pageSize = 100;

        do {

            $productModel = new AliexpressProduct();

            $list = $productModel->alias('p')->field('p.product_id')->where($where)->page($page++, $pageSize)->select();

            if(empty($list)) {
                return;
            }

            $productInfoModel = new AliexpressProductInfo();

            foreach ($list as $key => $val) {

                if($val) {
                    $val = $val->toArray();

                    (new UniqueQueuer(AliexpressPublishSyncDetailQueue::class))->push(['product_id' => $val['product_id'], 'custom_template_id' => $params['custom_template_id']]);
                }
            }

        } while (count($list) == $pageSize);

        return true;
    }


    /**
     *速卖通是否虚拟仓发货
     *
     */
    public function aliVirtualSendSync($data)
    {
        try{
            if($data) {
                $where = ['sku_code' => $data['channel_sku'], 'goods_sku_id' => $data['sku_id']];

                $productSkuModel = new AliexpressProductSku();

                $productSkuInfo = $productSkuModel->field('ali_product_id')->where($where)->find();

                if(empty($productSkuInfo)) {
                    return true;
                }

                $productSkuInfo = $productSkuInfo->toArray();

                $productModel = new AliexpressProduct();
                $productModel->update(['virtual_send' => $data['is_virtual_send']],['id' => $productSkuInfo['ali_product_id']]);


                $skuList = $productSkuModel->field('sku_code, goods_sku_id')->where(['ali_product_id' => $productSkuInfo['ali_product_id']])->whereNotIn('goods_sku_id', [$data['sku_id']])->select();

                if($skuList) {

                    $skuMapModel = new GoodsSkuMap();
                    foreach ($skuList as $val) {

                        $updateWhere = [
                            'sku_id' => $val['goods_sku_id'],
                            'channel_sku' => $val['sku_code'],
                            'channel_id' => 4
                        ];
                        $skuMapModel->update(['is_virtual_send' => $data['is_virtual_send']], $updateWhere);
                    }
                }
            }

            return true;
        } catch (JsonErrorException $exp){
throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }



    /**
     *同步分类属性
     *
     */
    public function syncCategoryAttr($categoryId)
    {
        (new UniqueQueuer(AliexpressCategoryAttributeQueue::class))->push($categoryId);

        return ['message' => '系统在同步中,请稍后再查看属性', 'status' => true];
    }


    /**
     * 昨天刊登记录(spu,sku),截止今天spu刊登记录
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function aliexpressPublishDaily(array $accountIds = [])
    {

        if(empty($accountIds)) {
            return;
        }
        $starTime = strtotime(date("Y-m-d",strtotime("-1 day")));
        $endTime = strtotime(date("Y-m-d 23:59:59",strtotime("-1 day")));
        //有效账号
        $accounts = DB::table('aliexpress_account')->alias('a')
            ->field('a.id account_id, c.seller_id')
            ->where('c.channel_id',4)
            ->where('a.is_invalid',1)
            ->where('a.is_authorization',1)
            ->whereIn('a.id',$accountIds)
            ->join('channel_user_account_map c','c.account_id = a.id')
            ->select();
        if(empty($accounts)) {
            return;
        }

        //账号ID
        $accountIds = array_column($accounts,'account_id');
        //销售人ID
        $sellerIds = array_column($accounts, 'seller_id');

        $productModel = new AliexpressProduct();
        $productSkuModel = new AliexpressProductSku();
        //1.有效账号的昨日有效刊登量SPU
        $where = [
            'a.account_id' => ['in', $accountIds],
            'a.salesperson_id' => ['in', $sellerIds],
            'a.status' => 2,
            'a.product_status_type' => 1,
            ];

        $productOnlineListing = $productModel->alias('a')->field('count(*) as online_listing_quantity, a.account_id')->where($where)->where('a.create_time','between',[$starTime, $endTime])->group('a.account_id,a.salesperson_id')->select();

        //2.累计有效刊登spu
        $productPublishListing = $productModel->alias('a')->field('count(*) as publish_quantity, a.account_id')->where($where)->group('a.account_id,a.salesperson_id')->select();

        $dateline = date('Y-m-d');
        foreach ($accounts as $key => $val) {
            $accounts[$key]['dateline'] = $dateline;
            $accounts[$key]['site'] = '';
            $accounts[$key]['publish_quantity'] = isset($productPublishListing[$val['account_id']]['publish_quantity']) ? $productPublishListing[$val['account_id']]['publish_quantity'] :'0';
            $accounts[$key]['online_listing_quantity'] = isset($productOnlineListing[$val['account_id']]['online_listing_quantity']) ? $productOnlineListing[$val['account_id']]['online_listing_quantity'] :'0';

            //3.昨天累计有效刊登sku
            $onlineAsinQuantity = 0;
            $productIds = $productModel->alias('a')->field('a.id')->where(['a.status' => 2, 'a.product_status_type' => 1, 'a.account_id' => $val['account_id'], 'a.salesperson_id' => $val['seller_id']])->select();

            $productIds = array_column($productIds,'id');
            if($productIds) {
                $onlineAsinQuantity = $productSkuModel->field('count(*) as total')->whereIn('ali_product_id', $productIds)->where('ipm_sku_stock > 0')->count();
            }
            $accounts[$key]['online_asin_quantity'] = $onlineAsinQuantity;
            $accounts[$key]['account_id'] = 4;
        }

        return $accounts;

    }
}