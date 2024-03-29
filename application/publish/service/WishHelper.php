<?php
/**
 * Created by NetBeans.
 * User: joy
 * Date: 2017-3-15
 * Time: 上午10:19
 */

namespace app\publish\service;

use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\model\aliexpress\AliexpressAccount;
use app\common\model\amazon\AmazonAccount;
use app\common\model\AttributeValue;
use app\common\model\ChannelUserAccountMap;
use app\common\model\DepartmentUserMap;
use app\common\model\ebay\EbayAccount;
use app\common\model\GoodsAttribute;
use app\common\model\GoodsGallery;
use app\common\model\GoodsInventoryHold;
use app\common\model\GoodsSku;
use app\common\model\GoodsSkuAlias;
use app\common\model\GoodsTort;
use app\common\model\GoodsTortDescription;
use app\common\model\User;
use app\common\model\Warehouse;
use app\common\model\wish\WishAccount;
use app\common\model\wish\WishDownloadField;
use app\common\model\wish\WishWaitUploadProduct;
use app\common\model\wish\WishWaitUploadProductInfo;
use app\common\model\wish\WishWaitUploadProductVariant;
use app\common\model\Goods;
use app\common\model\Brand;
use app\common\service\Excel;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsSkuMapService;
use app\index\service\Department;
use app\index\service\DepartmentUserMapService;
use app\index\service\DownloadFileService;
use app\publish\controller\Common;
use app\publish\queue\WishQueueJob;
use app\report\model\ReportExportFiles;
use think\Db;
use service\wish\WishApi;
use app\common\service\Twitter;
use app\common\model\GoodsLang;
use app\publish\validate\WishValidate;
use app\common\model\wish\WishColor;
use app\common\model\wish\WishSize;
use app\common\model\GoodsPublishMap;
use think\Exception;
use app\listing\service\WishListingHelper;
use app\goods\service\GoodsImage;
use app\goods\service\GoodsPublishMapService;
use app\publish\queue\WishQueue;
use app\listing\service\RedisListing;
use app\common\model\wish\WishDraft;
use think\exception\DbException;
use think\exception\PDOException;
use app\common\model\wish\WishExpressTemplate;
use app\common\model\Channel;
use app\goods\service\GoodsHelp AS GoodsHelpService;
use app\common\model\GoodsDiscount;

/**
 * @node wish刊登助手
 * Class WishHelper
 * packing app\publish\service
 */
class WishHelper
{
    private $channelId = 3;

    protected static $publish_error_code = [
        '010025' => '检查账号申请该品牌没有,或者申请有没有到期',
        '030002' => '请检查产品分组是否填写,如果填写了,可以重新同步,再选择产品分组',
        '010019' => '普通属性中必填的属性未填写,填写之后重新刊登',
        '010008' => '是否是有效的卖家,以及分类是否可以刊登',
        '150019' => '当前选择的品牌没有发布产品的权限,在卖家后台-账号及认证——我的申请——我的品牌申请中查看有没有申请,或者申请到期没,通过后,重新获取最新品牌,再次刊登',
    ];


    public function getDownloadFields(){
        return WishDownloadField::all();
    }
    public function saveManyDraft($post,$uid=0)
    {
        try{
            /**
             * notice：此处还需要传入一个用户sessionid来区分是哪个用户刊登的
             */
            $options['type'] = 'file';
            \think\Cache::connect($options);
            if(isset($post['account_id']))
            {
                $account_id = $post['account_id'];
            }else{
                $account_id=0;
            }
            $account=[];$account_code=$account_name='';
            if($account_id)
            {
                $account = Cache::store('wishAccount')->getAccount($account_id);
            }

            if($account)
            {
                $account_code = $account['code'];
                $account_name = $account['account_name'];
            }

            $count = 0 ;
            if (isset($post['sku']))
            {
                $rows = $post['sku'];

                foreach ($rows as $row)
                {

                    $goods_id = $row['goods_id'];

                    $cache=array();
                    $vars =[
                        [
                            'accountid'=>$post['account_id'],
                            'account_code'=>$account_code,
                            'account_name'=>$account_name,
                            'name'=>$row['name'],
                            'tags'=>$row['tags'],
                            'description'=>$row['description'],
                            'images'=>$row['images'],
                            'variant'=>$row['variant'],
                            'cron_time'=>$post['cron_time'],
                        ],
                    ];

                    $cache['inventory']=$post['cron_time'];
                    $cache['msrp']=0;
                    $cache['inventory']=0;
                    $cache['price']=0;
                    $cache['shipping']="";
                    $cache['shipping_time']="";

                    $cache['goods_id']=$goods_id;
                    $cache['parent_sku']=$row['parent_sku'];
                    $cache['brand']=isset($row['brand'])?$row['brand']:'';
                    $cache['upc']=isset($row['upc'])?$row['upc']:'';
                    $cache['landing_page_url']=isset($row['landing_page_url'])?$row['landing_page_url']:'';
                    $cache['uid']=$uid;
                    $cache['channel_id']=3;
                    $cache['default_account_id']=$post['account_id'];
                    $cache['vars']=json_encode($vars);
                    $res = \think\Cache::set('wishPublishCache:' . $goods_id . '_' . $uid, $cache, 0);
                    if($res)
                    {

                        $model = new WishDraft();
                        $where['goods_id'] = $goods_id;
                        $where['uid'] = $uid;
                        $where['account_id'] = $post['account_id'];
                        $data['spu'] = $row['parent_sku'];
                        $data['goods_id'] = $goods_id;
                        $data['uid'] = $uid;
                        $data['account_id'] = $post['account_id'];
                        $data['name'] = $row['name'];
                        Db::startTrans();
                        try{
                            if ($model->get($where)) {
                                $data['update_time'] = time();
                                $model->isUpdate(true)->save($data, $where);
                            } else {
                                $data['create_time'] = time();
                                $model->isUpdate(false)->save($data);
                            }
                            Db::commit();
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException($exp->getMessage());
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException($exp->getMessage());
                        }catch (\Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException($exp->getMessage());
                        }
                        ++$count;
                    }
                }
            }
            return ['message'=>'成功保存到草稿箱['.$count.']条'];
        }catch (Exception $exp){
            throw new JsonErrorException($exp->getMessage());
        }


    }
    public function getWishUsers($channel_id=3)
    {
        $departments = (new Department())->getDepsByChannel($channel_id);
        $ids = array_column($departments,'id');
        $users = (new DepartmentUserMap())->whereIn('department_id',$ids)->field('b.id,b.username,realname')->alias('a')->group('user_id')->join('user b','a.user_id=b.id','RIGHT')->select();
        return $users;
    }

    /**
     *获取变体数据
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function getVariants($id)
    {
        return (new WishWaitUploadProductVariant())->where('pid',$id)->select();
    }

    public function delete($id)
    {
        Db::startTrans();
        try {
            //$res = (new WishQueue('wish:publish:queue'))->delete($id);
            //$res = WishQueue::single('app\publish\queue\WishQueue')->delete($id);
            //队列删除成功，才能删除

            $row = (new WishWaitUploadProduct())->field('id,goods_id,parent_sku,accountid')->where(['id' => $id])->find();
            if ($row)
            {
                $goods_id=$row['goods_id'];
                if($goods_id)
                {
                    $goodsInfo =Cache::store('Goods')->getGoodsInfo($goods_id);
                    if($goodsInfo)
                    {
                        $spu = $goodsInfo['spu'];
                        GoodsPublishMapService::update(3, $spu, $row['accountid'], 0);
                    }
                }

            }
            WishWaitUploadProductVariant::where(['pid' => $id])->delete();

            WishWaitUploadProduct::where(['id' => $id])->delete();

            WishWaitUploadProductInfo::where(['id' => $id])->delete();
            Db::commit();
            return true;
        } catch (\Exception $exp) {
            Db::rollback();
            throw new JsonErrorException($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }

    public function listDraft($param, $page, $pageSize, $uid)
    {
        $where = [];
        if (isset($param['type']) && $param['type'] == 'spu' && $param['content']) {
            $where['spu'] = ['=', $param['content']];
        }

        if (isset($param['type']) && $param['type'] == 'name' && $param['content']) {
            $where['name'] = ['like', '%' . $param['content'] . '%'];
        }

        if (isset($param['account_id']) && $param['account_id']) {
            $where['account_id'] = ['=', $param['account_id']];
        }
        //更新时间
        if (isset ($param['start_time']) && isset ($param['end_time']) && $param['end_time'] && $param['start_time']) {
            if ($param['end_time'] == $param['start_time']) //同一个时间
            {
                $param['start_time'] = $param['start_time'] . ' 00:00:00';
                $param['end_time'] = $param['end_time'] . ' 23:59:59';
            }
            $where['create_time'] = ['between time', [strtotime($param['start_time']), strtotime($param['end_time'])]];
        } elseif (isset ($param['end_time']) && $param['end_time']) {
            $where['create_time'] = array('<=', strtotime($param['end_time']));
        } elseif (isset($param['start_time']) && $param['start_time']) {
            $where['create_time'] = array('>=', strtotime($param['start_time']));
        }

        $where['uid'] = ['=', $uid];

        $count = WishDraft::where($where)->count('id');

        $data = WishDraft::where($where)->with(['goods', 'user', 'account'])->order('create_time desc')->page($page, $pageSize)->select();

        if ($data) {
            foreach ($data as &$d) {
                $d['thumb'] = GoodsImage::getThumbPath($d['goods']['thumb'], 0);
            }
        }

        return ['data' => $data, 'page' => $page, 'pageSize' => $pageSize, 'count' => $count];


    }

    /**
     * 批量删除草稿箱
     * @param $where
     * @return int
     */
    public function deleteDraft($where,$uid)
    {
        $goodsIds = $this->getGoodsIds($where);
        $num=0;
        $options['type'] = 'file';
        if($goodsIds)
        {

            foreach ($goodsIds as $goods_id)
            {
                \think\Cache::connect($options);
                if (\think\Cache::has('wishPublishCache:' . $goods_id . '_' . $uid))
                {
                    \think\Cache::rm('wishPublishCache:' . $goods_id . '_' . $uid);
                    ++$num;
                }
            }
        }
        Db::startTrans();
        try{
            WishDraft::destroy($where);
            Db::commit();
            return $num;
        }catch (PDOException $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }catch (DbException $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }catch (Exception $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }
    }

    /**
     * 获取草稿箱goods_id
     * @param $where
     * @return array
     */
    public function getGoodsIds($where)
    {
        $drafts = WishDraft::where($where)->field('goods_id')->select();
        $goods_ids = array_column($drafts,'goods_id');
        return $goods_ids;
    }

    public function saveDraft($post)
    {
        $response = false;
        try {
            $model = new WishDraft();
            if (isset($post['vars']) && $post['vars']) {
                $skus = json_decode($post['vars'], true);

                foreach ($skus as $key => $sku) {

                    $where['goods_id'] = $post['goods_id'];
                    $where['uid'] = $post['uid'];
                    $where['account_id'] = $skus[$key]['accountid'];

                    $data['spu'] = $post['parent_sku'];
                    $data['goods_id'] = $post['goods_id'];
                    $data['uid'] = $post['uid'];
                    $data['account_id'] = $skus[$key]['accountid'];
                    $data['name'] = $skus[$key]['name'];
                    Db::startTrans();
                    try{
                        if ($model->get($where)) {
                            $data['update_time'] = time();
                            $model->isUpdate(true)->save($data, $where);
                        } else {
                            $data['create_time'] = time();
                            $model->isUpdate(false)->save($data);
                        }
                        Db::commit();
                    }catch (\Exception $exp){
                        Db::rollback();
                        throw new JsonErrorException($exp->getMessage());
                    }
                }
                $response = true;
            }
            return $response;
        } catch (JsonErrorException $exp) {
            throw new JsonErrorException($exp->getMessage());
        }


    }

    public function wishColors()
    {
        $colors = (new WishColor())->field('color_value')->select();

        foreach ($colors as $key => &$color) {
            if (is_object($color)) $color = $color->toArray();
            $color['color_value'] = lcfirst($color['color_value']);
        }
        return $colors;
    }

    /**
     *
     * @param int $goods_id 商品id
     */
    public function multiSize($goods_id)
    {
        $sku = [];
        $sku = (new GoodsSku())->field('sku_attributes')->where(['goods_id' => $goods_id])->find();

        if ($sku)
        {
            $sku = is_object($sku)?$sku->toArray():$sku;
            $sku_attributes = json_decode($sku['sku_attributes'], true);
            if (count($sku_attributes) > 2)
            {
                foreach ($sku_attributes as $attribute_id => $attribute_value_id)
                {
                    list($attr, $attr_id) = explode('_', $attribute_id);//$attr_id //属性名

                    $attrKeyVal = (new AttributeValue())->field('a.value,b.code')->alias('a')->join('attribute b', 'a.attribute_id=b.id', 'LEFT')
                        ->where(['a.id' => $attribute_value_id, 'a.attribute_id' => $attr_id])->find();

                    if ($attrKeyVal) {
                        if ($attrKeyVal['code'] != 'color') {
                            if (count($sku_attributes) > 2) {
                                //$sku['size'][$attrKeyVal['code']] = $attrKeyVal['value'];
                                $sku['size'][$attrKeyVal['code']] = $attrKeyVal['value'];
                            } else {
                                $sku['size'] = $attrKeyVal['value'];
                            }
                        }
                    }
                }
            } else {
                $sku['size'] = '';
            }

            unset($sku['sku_attributes']);
        } else {
            $sku['size'] = '';
        }
        return $sku;

    }

    /**
     * @node 插入从产品库刊登数据
     * @access public
     * @param array $post
     * @return int
     */
    public function insertManyData(array $post)
    {
        try{
            $vars = $post['sku'];
            $products = []; //产品
            $goodsSkuMapModel = new \app\goods\service\GoodsSkuMapService();
            //$variants =[];//产品变体

            $timestamp = time();
            if (is_array($vars)) {
                foreach ($vars as $k => $var) {
                    $pid = (string)(Twitter::instance()->nextId(3, $post['account_id']));
                    $products[$k]['id'] = $pid;
                    $products[$k]['goods_id'] = $var['goods_id'];  //商品id
                    //获取GOODS物流属性-pan
                    $aGoods = Cache::store('goods')->getGoodsInfo($var['goods_id']);
                    $products[$k]['transport_property']=$aGoods['transport_property'];

                    $products[$k]['accountid'] = $post['account_id']; //账号id
                    $products[$k]['uid'] = $post['uid']; //登录用户id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    //$products[$k]['zh_name'] = @$post['zh_name']; //中文标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['local_spu'] = $var['parent_sku']; //SPU
                    $products[$k]['parent_sku'] = (new GoodsSkuMapService())->createSku($var['parent_sku']);
                    $products[$k]['brand'] = isset($post['brand'])?$post['brand']:''; //品牌
                    //$products[$k]['upc'] = @$var['upc']; //UPC
                    //$products[$k]['landing_page_url'] = @$var['landing_page_url']; //商品展示页面
                    $products[$k]['extra_images'] = implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品原始图片
                    $products[$k]['cron_time'] = strtotime($post['cron_time']); //定时刊登
                    $products[$k]['warehouse'] = @$post['depot'];
                    $products[$k]['last_updated'] = $timestamp;
                    $products[$k]['addtime'] = $timestamp;
                    $products[$k]['application'] = 'rondaful';
                    $variants = [];

                    if (isset($var['variant']) && !empty($var['variant'])) {
                        $variants = $var['variant'];
                        foreach ($variants as &$v) {
                            $v['pid'] = $pid;
                            $v['add_time'] = $timestamp;
                            $v['status'] = 0;
                            if (isset($v['sku_id'])) {
                                $v['sku_id'] = $v['sku_id'];
                            } else {
                                $v['sku_id'] = 0;
                            }

                            if (isset($v['sell_status'])) {
                                $v['sell_status'] = $v['sell_status'];
                            } else {
                                $v['sell_status'] = '';
                            }

                            $create_sku_code_response = $goodsSkuMapModel->addSku(['sku_code' => $v['sku'], 'channel_id' => 3, 'account_id' => $post['account_id']], $post['uid']);

                            if ($create_sku_code_response['result']) {
                                $v['sku'] = $create_sku_code_response['sku_code'];
                            }
                            $v['pre_cost']=$v['current_cost']=$v['cost_price'];
                        }
                    } else {
                        //$vid = abs(Twitter::instance()->nextId(3, $post['account_id']));

                        $create_sku_code_response = $goodsSkuMapModel->addSku(['sku_code' => $var['parent_sku'], 'channel_id' => 3, 'account_id' => $post['account_id']], $post['uid']);

                        if ($create_sku_code_response['result']) {
                            $sku = $create_sku_code_response['sku_code'];
                        } else {
                            $sku = '';
                        }
                        $sku_id = $this->getGoodsSKuIdBySpu($var['parent_sku']);
                        $variants = array(
                            //'vid' => $vid,
                            'pid' => $pid,
                            'sku' => $sku ? $sku : $var['parent_sku'],
                            'main_image' => $var['images'][0],
                            'price' => $var['price'],
                            'msrp' => $var['msrp'],
                            'inventory' => $var['inventory'],
                            'shipping' => $var['shipping'],
                            'color' => '',
                            'size' => '',
                            'cost' => isset($var['cost']) ? $var['cost'] : 0,
                            'pre_cost' => isset($var['cost']) ? $var['cost'] : 0,
                            'current_cost' => isset($var['cost']) ? $var['cost'] : 0,
                            'weight' => isset($var['weight']) ? $var['weight'] : 0,
                            'shipping_time' => $var['shipping_time'],
                            'add_time' => $timestamp,
                            'status' => 0,
                            'sku_id' => $sku_id ? $sku_id : 0,
                        );
                    }
                    $products[$k]['variants'] = $variants;
                }
            }
            $num = 0;

            if ($products) {
                if (is_array($products)) {
                    foreach ($products as $k => $p)
                    {

                        Db::startTrans();
                        try{
                            $variants = $p['variants'];

                            $spu = $p['parent_sku'];

                            $p['parent_sku']=(new GoodsSkuMapService())->createSku($spu);

                            $pid =$p['id'];

                            //自动配对物流属性-pan
                            foreach ($variants as $v){
                                $temp[]=$v['price'];
                            }
                            $max_price= max($temp);//求变体中价格最大的
                            $all_country_shipping=$this->likeExpress($p['transport_property'],$max_price);
                            if ($all_country_shipping)
                            {
                                $p['all_country_shipping'] =json_encode($all_country_shipping);
                                $p['auto_sp']=1;//标为自动匹配的
                            }

                            (new WishWaitUploadProduct())->isUpdate(false)->allowField(true)->save($p);

                            (new WishWaitUploadProductInfo())->isUpdate(false)->allowField(true)->save($p);
                            //echo WishWaitUploadProductInfo::getLastSql();


                            if (count($variants) == count($variants, 1))
                            {
                                (new WishWaitUploadProductVariant())->allowField(true)->isUpdate(false)->save($variants);
                            } else {
                                (new WishWaitUploadProductVariant())->allowField(true)->isUpdate(false)->saveAll($variants);
                            }

                            Db::commit();
                            $queue = (string)$pid;
                            //非定时刊登
                            if ($p['cron_time'] <= time())
                            {
                                (new WishQueue(WishQueueJob::class))->push($queue);
                            } else {
                                (new WishQueue(WishQueueJob::class))->push($queue, $p['cron_time']);
                            }

                            if ($pid)
                            {
                                $update = (new WishListingHelper())->ProductStat((new WishWaitUploadProductVariant()), ['pid' => $pid]);
                                if ($update)
                                {
                                    (new WishWaitUploadProduct())->update($update, ['id' => $pid]);
                                }
                            }
                            $num = $num + 1;
                            GoodsPublishMapService::update(3, $spu, $p['accountid']);
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }
            }

            if($num==0)
            {
                $num='未知异常';
            }

            return $num;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * 获取wish尺码
     * @param string $toArray
     * @return array $sizes
     */
    public function getWishSize($status, $toArray = true)
    {
        if ($status != 'edit') {
            $where['name'] = ['neq', 'Custom size'];
        } else {
            $where = [];
        }

        $data = WishSize::where($where)->select();
        if (is_bool($toArray) && $toArray) {
            if (is_array($data)) {
                foreach ($data as &$d) {
                    if (!empty($d['size_val'])) //如果size_val不为空
                    {
                        $d['size_val'] = explode(',', $d['size_val']);
                    }
                }
            }
        }

        return $data;
    }

    /**
     *
     * @param type $post
     * @return string
     */
    public function getGoodsWhereStr($post)
    {
        $where = '';

        if (isset($post['snType']) && $post['snType'] == 'spu' && $post['snText']) {
            //$where[$post['snType']] = array('eq',$post['snText']);
            $where = $where . ' AND  g.spu="' . $post["snText"] . '"';
        }


        if (isset($post['snType']) && $post['snType'] == 'id' && $post['snText']) {
            //$where['g.'.$post['snType']] = array('eq',$post['snText']);
            $where = $where . ' AND g.id="' . $post["snText"] . '"';
        }

        if (isset($post['snType']) && $post['snType'] == 'name' && $post['snText']) {
            //$where['g.'.$post['snType']] = array('like','%'.$post['snText'].'%');
            $where = $where . ' AND g.name like "%' . $post["snText"] . '%"';
        }

        if (isset($post['snType']) && $post['snType'] == 'alias' && $post['snText']) {
            //$where['g.'.$post['snType']] = array('like','%'.$post['snText'].'%');

            $where = $where . ' AND g.alias like "%' . $post["snText"] . '%"';
        }

        if (isset($post['snType']) && $post['snType'] == 'keywords' && $post['snText']) {
            //$where['g.'.$post['snType']] = array('like','%'.$post['snText'].'%');

            $where = $where . ' AND g.keywords like "%' . $post["snText"] . '%"';
        }

        //分类名
        if (isset($post['snType']) && $post['snType'] == 'cname' && $post['snText']) {
            //$where['c.name'] = array('like','%'.$post['snText'].'%');
            $where = $where . ' AND c.cname like "%' . $post["snText"] . '%"';
        }

        //$where['g.status']=['eq',1];
        //$where['platform_sale$.wish']=['eq',1];

        return $where;
    }

    /**
     * 获取待刊登商品列表搜索条件
     * @param array $arr
     */
    public function getGoodsWhere($post)
    {
        $where = [];

        $where['g.status'] = ['eq', 1];
        $where['platform_sale$.wish'] = ['eq', 1];

        if (isset($post['snType']) && $post['snType'] == 'spu' && $post['snText']) {
            $where[$post['snType']] = array('eq', $post['snText']);
        }


        if (isset($post['snType']) && $post['snType'] == 'id' && $post['snText']) {
            $where['g.' . $post['snType']] = array('eq', $post['snText']);
        }

        if (isset($post['snType']) && $post['snType'] == 'name' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        if (isset($post['snType']) && $post['snType'] == 'alias' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        if (isset($post['snType']) && $post['snType'] == 'keywords' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        //分类名
        if (isset($post['snType']) && $post['snType'] == 'cname' && $post['snText']) {
            $where['c.name'] = array('like', '%' . $post['snText'] . '%');
        }

        return $where;
    }

    /**
     * 统计未刊登商品数量
     * @param type $where
     */
    public function getGoodsCount($where)
    {
        return (new Goods())->alias('g')->join('category c', 'g.category_id=c.id', 'LEFT')
            ->where($where)->count();
    }

    /**
     * 获取待刊登商品列表
     */
    public function waitPublishGoodsMap($param, $page, $pageSize, $fields)
    {
        $where = [];
        $join = [];
        $channelId = empty($params['channelId']) ? 3 : $params['channelId'];//默认wish

        $where['channel'] = ['eq', $channelId];
        $where['g.sales_status'] = ['IN', array(1, 4,6)];
        $where2 = "platform&{$channelId}=$channelId";//平台是否可售
//        $where['m.platform_sale'] = ['=',1];

        $post = $param;

        if (isset($post['snType']) && $param['snType'] == 'spu' && $post['snText']) {
            $where['m.' . $post['snType']] = array('IN', $post['snText']);
        }

        if (isset($post['snType']) && $post['snType'] == 'id' && $post['snText']) {
            $where['m.goods_id'] = array('eq', $post['snText']);
        }

        if (isset($post['snType']) && $post['snType'] == 'name' && $post['snText']) {
            $where['name'] = array('like', '%' . $post['snText'] . '%');
        }
        if (isset($post['snType']) && $post['snType'] == 'alias' && $post['snText']) {
            $where['alias'] = array('like', '%' . $post['snText'] . '%');
        }

        if (isset($post['snType']) && $post['snType'] == 'sku' && $post['snText']) {
            $where['sku'] = array('IN', $post['snText']);
            $join[] = ['goods_sku gs','gs.goods_id=g.id'];
        }

        if (isset($post['developer_id']) && $post['developer_id'] ) {
            $where['g.developer_id'] = array('=', $post['developer_id']);
        }

        if (isset($post['category_id']) && $post['category_id'] ) {
            $category_id = (int)$post['category_id'];

            $categories = CommonService::getSelfAndChilds($category_id);

            $where['g.category_id'] = array('IN', $categories);
        }
        if (!empty($post['warehouse_id'])) {//仓库
//            $wareHouseIds = Warehouse::where(['name'=>['like', $post['warehouse_name'].'%']])->column('id');
            $where['g.warehouse_id'] = $post['warehouse_id'];
        }

        if (isset($post['accountVal']) && is_numeric($post['accountVal'])) {
            //$where['m.publish_status$."'.$post['accountVal'].'"'] = ['=',0];
            //$map = " JSON_CONTAINS(publish_status, '".$post['accountVal']."') IS NULL ";
            $map = " JSON_SEARCH(m.publish_status,'one', " . $post['accountVal'] . ") IS NULL ";
        } elseif(isset($post['account_id']) && is_numeric($post['account_id'])){
            $map = " JSON_SEARCH(m.publish_status,'one', " . $post['account_id'] . ") IS NULL ";
        }else {
            $map = [];
        }

        $fields='distinct(m.goods_id),g.developer_id,m.publish_status,m.platform_sale,m.spu,g.thumb,g.category_id,g.name,g.publish_time,g.packing_name name_cn, g.packing_en_name, g.warehouse_id';
        if(!empty($join))
        {
            $count = (new GoodsPublishMap())->alias('m')
                ->join('goods g', 'm.goods_id=g.id', 'LEFT')
                ->join($join)
                ->where($where)
                ->where($where2)
                ->where($map)->count('m.id');

            $data = (new GoodsPublishMap())->alias('m')
                ->join('goods g', 'm.goods_id=g.id', 'LEFT')
                ->join($join)
                ->order('publish_time desc')
                ->field($fields)
                ->where($map)
                ->where($where)
                ->where($where2)
                ->page($page, $pageSize)->select();

        }else{
            $count = (new GoodsPublishMap())->alias('m')->join('goods g', 'm.goods_id=g.id', 'LEFT')->where($where)->where($where2)->where($map)->count('m.id');

            $data = (new GoodsPublishMap())->order('publish_time desc')->field($fields)->alias('m')->join('goods g', 'm.goods_id=g.id', 'LEFT')->where($map)->where($where)->where($where2)->page($page, $pageSize)->select();
        }
        $goodsModel = new Goods();
        $discountIds = [];
        if ($data) {
            $data = collection($data)->toArray();
            $goodsIds = array_column($data,'goods_id');
            $tortGoodsIds = GoodsTortDescription::distinct(true)->whereIn('goods_id',$goodsIds)->column('goods_id');
            //获取降价ids
            $discountIds = (new GoodsDiscount())->whereIn('goods_id',$goodsIds)->where('valid_time','<',time())->where('over_time','>',time())->where('status',1)->column('goods_id');
        }

        foreach ($data as $k => &$d) {
            $d['id'] = $d['goods_id'];
            $d['tort_flag'] = in_array($d['goods_id'],$tortGoodsIds) ? 1 : 0;//侵权
            $category = $goodsModel->getCategoryAttr("",$d);
            if($category)
            {
                $d['category'] = $category;
            }else{

                $d['category'] = '';
            }

            $lang = GoodsLang::where(['goods_id' => $d['goods_id'], 'lang_id' => 2])->field('title')->find();

            if ($lang) {
                $d['packing_en_name'] = $lang['title'];
            }

            $d['thumb'] = GoodsImage::getThumbPath($d['thumb'], 60, 60);

            $d['is_discounting'] = in_array($data[$k]['goods_id'],$discountIds) ? 1 : 0;
        }

        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];
    }

    public function getWaitPublishGoods($param, $page, $pageSize, $fields)
    {

        $where = $this->getWaitPublishGoodsWhere($param);

        $count = $this->getWaitPublishGoodsCount($where);

        $data = (new GoodsPublishMap())->alias('p')->join('goods g ', 'p.goods_id=g.id', 'LEFT')->join('category c', 'g.category_id=c.id', 'LEFT')
            ->where($where)->field($fields)->page($page, $pageSize)->order('g.id desc')->select();
        //刊登账号
        $len = 0;
        if (isset($param['accountVal']) && $param['accountVal']) {
            $accountid = $param['accountVal'];
            foreach ($data as $k => &$d) {
                $map = [
                    'accountid' => $accountid,
                    'parent_sku' => $d['spu'],
                ];
                $product = WishWaitUploadProduct::where($map)->find();
                if ($product) {
                    unset ($data[$k]);
                    $len++;
                }
            }
        }

        foreach ($data as $k => &$d) {
            $d['thumb'] = GoodsImage::getThumbPath($d['thumb'], 0);
        }

        return ['data' => $data, 'count' => $count - $len, 'page' => $page, 'pageSize' => $pageSize];
    }

    /**
     * 统计未刊登商品数量
     * @param type $where
     */
    public function getWaitPublishGoodsCount($where)
    {
        return (new GoodsPublishMap())->alias('p')->join('goods g ', 'p.goods_id=g.id', 'LEFT')->join('category c', 'g.category_id=c.id', 'LEFT')
            ->where($where)->count();
    }

    public function getWaitPublishGoodsWhere($post)
    {
        $where = [];

        $where['p.status'] = ['eq', 1];
        $where['p.channel'] = ['eq', 3];
        $where['p.platform_sale'] = ['eq', 1];
        $where['p.publish_status'] = ['eq', 0];

        if (isset($post['snType']) && $post['snType'] == 'spu' && $post['snText']) {
            $where['p.' . $post['snType']] = array('eq', $post['snText']);
        }

        if (isset($post['snType']) && $post['snType'] == 'id' && $post['snText']) {
            $where['g.id'] = array('eq', $post['snText']);
        }

        if (isset($post['snType']) && $post['snType'] == 'name' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        if (isset($post['snType']) && $post['snType'] == 'alias' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        if (isset($post['snType']) && $post['snType'] == 'keywords' && $post['snText']) {
            $where['g.' . $post['snType']] = array('like', '%' . $post['snText'] . '%');
        }

        //分类名
        if (isset($post['snType']) && $post['snType'] == 'cname' && $post['snText']) {
            $where['c.name'] = array('like', '%' . $post['snText'] . '%');
        }

        return $where;
    }


    public function getGoodsLists($param, $page, $pageSize, $fields)
    {
        $where = $this->getGoodsWhere($param);

        $count = $this->getGoodsCount($where);

        $data = (new Goods)->alias('g')->join('category c', 'g.category_id=c.id', 'LEFT')
            ->where($where)->field($fields)->page($page, $pageSize)->order('g.id desc')->select();
        //刊登账号
        if (isset($param['account']) && $param['account']) {
            $accountid = $param['account'];
            foreach ($data as $k => &$d) {
                $map = [
                    'accountid' => $accountid,
                    'parent_sku' => $d['spu'],
                ];
                $product = WishWaitUploadProduct::where($map)->find();
                if ($product) unset ($data[$k]);
            }
        }

        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];


    }

    /**
     * @node wish商品是否存在
     * @access public
     * @param array $where
     * @return array
     */

    public function hasOne($where = array())
    {
        $model = new WishWaitUploadProduct();
        return $model->get($where);
    }

    /**
     * 将一维数组转换成键名为$key的二维数组
     * @param array $arr
     * @param type $key
     */
    public function arrayAddKey(array $arr, $key = '')
    {
        if ($key) {
            $res = [];
            foreach ($arr as $k => $v) {
                $res[$k][$key] = $v;
            }
        } else {
            $res = $arr;
        }
        return $res;
    }


    /**
     * @node 插入数据
     * @access public
     * @param array $post
     * @return int
     */
    public function insertData(array $post)
    {
        try {
            if (isset($post['vars']))
            {
                $vars = json_decode($post['vars'], true); //每个账号信息

            }

            if (isset($post['warehouse']) && $post['warehouse'] == 'null') {
                $post['warehouse'] = '';
            }
            $goodsSkuMapModel = new GoodsSkuMapService();

            $spu = $post['parent_sku'];

            $products = []; //产品
            $variants =[];//产品变体
            $timestamp = time();
            if (is_array($vars))
            {
                foreach ($vars as $k => $var)
                {

                    $pid = Twitter::instance()->nextId(3, $var['accountid']);
                    $products[$k]['id'] = $pid;
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id

                    $goodsImp=new \app\goods\service\GoodsImport();//物流属性-pan
                    $products[$k]['transport_property'] = $goodsImp->getTransportProperty($post['transport_property']);

                    $products[$k]['accountid'] = $var['accountid']; //账号id
                    $products[$k]['uid'] = $post['uid']; //登录用户id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    //$products[$k]['zh_name'] = $post['zh_name']; //中文标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['local_spu'] = $spu;
                    $products[$k]['parent_sku'] = (new GoodsSkuMapService())->createSku($spu); //SPU
                    $products[$k]['brand'] = $post['brand']; //品牌
                    $products[$k]['upc'] = $post['upc']; //UPC
                    $products[$k]['landing_page_url'] = $post['landing_page_url'] ? @$post['landing_page_url'] : ''; //商品展示页面
                    $products[$k]['extra_images'] = implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品原始图片
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['warehouse'] = $post['warehouse'];
                    $products[$k]['last_updated'] = $timestamp;
                    $products[$k]['addtime'] = $timestamp;
                    $products[$k]['application'] = 'rondaful';
                    $variants = [];

                    if (isset($var['variant']) && !empty($var['variant']))
                    {
                        $variants = $var['variant'];

                        foreach ($variants as &$v)
                        {
                            if (isset($v['product_id'])) {
                                unset($v['product_id']);
                            }
                            if (isset($v['variant_id'])) {
                                unset($v['variant_id']);
                            }
                            if (isset($v['enabled'])) {
                                unset($v['enabled']);
                            }

                            //$vid = abs(Twitter::instance()->nextId(3, $var['accountid']));
                            //$v['vid'] = $vid;
                            $v['pid'] = $pid;
                            $v['status'] = 0;
                            if (isset($v['sku_id'])) {
                                $v['sku_id'] = $v['sku_id'];
                            } else {
                                $v['sku_id'] = 0;
                            }

                            if (isset($v['sell_status'])) {
                                $v['sell_status'] = $v['sell_status'];
                            } else {
                                $v['sell_status'] = '';
                            }

                            $v['add_time'] = $timestamp;

                            if(isset($v['combine_sku']) && !empty($v['combine_sku']))
                            {
                                $create_sku_code_response = $goodsSkuMapModel->addSkuCodeWithQuantity(['combine_sku'=>$v['combine_sku'],'sku_code' => $v['sku'], 'channel_id' => 3, 'account_id' => $var['accountid']], $post['uid']);
                            }else{
                                $create_sku_code_response = $goodsSkuMapModel->addSku(['sku_code' => $v['sku'], 'channel_id' => 3, 'account_id' => $var['accountid']], $post['uid']);
                            }

                            if ($create_sku_code_response['result']) {
                                $v['sku'] = $create_sku_code_response['sku_code'];
                            }
                            $v['pre_cost']=$v['current_cost']=$v['cost_price']??0;
                        }
                    } else {

                        $create_sku_code_response = $goodsSkuMapModel->addSku(['sku_code' => $post['parent_sku'], 'channel_id' => 3, 'account_id' => $var['accountid']], $post['uid']);

                        if ($create_sku_code_response['result']) {
                            $sku = $create_sku_code_response['sku_code'];
                        } else {
                            $sku = '';
                        }
                        $sku_id = $this->getGoodsSKuIdBySpu($post['parent_sku']);

                        $variants = array(
                            //'vid' => $vid,
                            'pid' => $pid,
                            'sku' => $sku ? $sku : $post['parent_sku'],
                            'main_image' => $var['images'][0],
                            'price' => $var['price'],
                            'msrp' => $var['msrp'],
                            'inventory' => $var['inventory'],
                            'shipping' => $var['shipping'],
                            'color' => '',
                            'size' => '',
                            'status' => 0,
                            'sku_id' => $sku_id ? $sku_id : 0,
                            'cost' => $post['cost'],
                            'pre_cost'=>$post['cost'],
                            'current_cost'=>$post['cost'],
                            'weight' => $post['weight'],
                            'shipping_time' => $var['shipping_time'],
                            'add_time' => $timestamp,
                        );
                    }
                    $products[$k]['variants'] = $variants;
                }
            }

            $num = 0;

            if ($products)
            {
                if (is_array($products))
                {
                    foreach ($products as $k => $p)
                    {
                        Db::startTrans();
                        try{
                            $variants = $p['variants'];

                            $pid =$p['id'];

                            //自动配对物流属性-pan
                            foreach ($variants as $vv){
                                $temp[]=$vv['price'];
                            }
                            $max_price= max($temp);//求变体中价格最大的
                            $all_country_shipping=$this->likeExpress($p['transport_property'],$max_price);
                            if ($all_country_shipping)
                            {
                                $p['all_country_shipping'] =json_encode($all_country_shipping);
                                $p['auto_sp']=1;//标为自动匹配的
                            }


                            (new WishWaitUploadProduct())->isUpdate(false)->allowField(true)->save($p);

                            (new WishWaitUploadProductInfo())->isUpdate(false)->allowField(true)->save($p);

                            if (count($variants) == count($variants, 1))
                            {
                                (new WishWaitUploadProductVariant())->allowField(true)->isUpdate(false)->save($variants);
                            } else {
                                (new WishWaitUploadProductVariant())->allowField(true)->isUpdate(false)->saveAll($variants);
                            }
                            if(empty($p['cron_time']))
                            {
                                $p['cron_time']=0;
                            }
                            $queue = (string)$pid;
                            //非定时刊登
                            if ($p['cron_time'] <= time())
                            {
                                (new WishQueue(WishQueueJob::class))->push($queue);
                            } else {
                                (new WishQueue(WishQueueJob::class))->push($queue, $p['cron_time']);
                            }

                            $num = $num + 1;

                            if ($pid)
                            {
                                $update = (new WishListingHelper())->ProductStat((new WishWaitUploadProductVariant()), ['pid' => $pid]);
                                if ($update)
                                {
                                    (new WishWaitUploadProduct())->update($update, ['id' => $pid]);
                                }
                            }

                            GoodsPublishMapService::update(3, $spu, $p['accountid']);
                            Db::commit();
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }
            }
            if($num==0)
            {
                $num='未知异常';
            }
            return $num;

        } catch (Exception $exp) {
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * 根据spu获取sku的id
     * @param string $spu
     */
    public function getGoodsSKuIdBySpu($spu)
    {
        $data = Goods::where(['spu' => $spu])->with(['sku'])->find();
        if ($data && is_object($data)) {
            $data = $data->toArray();
            if (count($data['sku']) == 1) {
                return $data['sku'][0]['id'];
            } else {
                return '';
            }
        } else {
            return '';
        }

    }


    /**
     * 修改未上传商品的资料
     */
    public function updateData($post)
    {
        try{
            if (isset($post['vars'])) {
                $vars = json_decode($post['vars'], true); //每个账号信息
            }

            $products = []; //产品
            if (is_array($vars))
            {
                foreach ($vars as $k => $var)
                {
                    $products[$k]['id'] = $post['id'];
                    $products[$k]['goods_id'] = $post['goods_id'];  //商品id
                    $products[$k]['accountid'] = $var['accountid']; //账号id
                    //$products[$k]['uid'] = $post['uid']; //登录用户id
                    $products[$k]['name'] = $var['name']; //刊登标题
                    //$products[$k]['zh_name'] = $post['zh_name']; //中文标题
                    $products[$k]['main_image'] = $var['images'][0]; //商品主图
                    $products[$k]['description'] = $var['description'];//详情描述
                    $products[$k]['tags'] = $var['tags']; //Tags
                    $products[$k]['parent_sku'] = $post['parent_sku']; //SPU
                    $products[$k]['brand'] = $post['brand']; //品牌
                    $products[$k]['upc'] = $post['upc']; //UPC

                    if (preg_match('/^http(s)?:\\/\\/.+/', $post['landing_page_url'])) {
                        $products[$k]['landing_page_url'] = $post['landing_page_url'];
                    } else {
                        $products[$k]['landing_page_url'] = ''; //商品展示页面
                    }

                    $products[$k]['extra_images'] = implode('|', array_slice($var['images'], 1)); //商品相册a.jpg|b.jpg
                    $products[$k]['original_images'] = implode('|', $var['images']); //商品相册a.jpg|b.jpg
                    $products[$k]['cron_time'] = strtotime($var['cron_time']); //定时刊登
                    $products[$k]['warehouse'] = @$post['warehouse'];

                    $variants = [];
                    //更新时，每个商品的variant都已经存在，不存在variant为空的情况
                    if (isset($var['variant']) && !empty($var['variant'])) {
                        //$variant = json_decode($var['variant'],true);
                        $variants = $var['variant'];

                        foreach ($variants as &$v) {
                            unset($v['sell_status']);
                            $v['add_time'] = time();
                            $v['status'] = 0;
                        }
                    } else {
                        $variants = array(
                            // 'sku'=>$post['parent_sku'],
                            'pid' => $post['id'],
                            'main_image' => $var['images'][0],
                            'price' => $var['price'],
                            'msrp' => $var['msrp'],
                            'inventory' => $var['inventory'],
                            'shipping' => $var['shipping'],
                            'color' => '',
                            'size' => '',
                            'status' => 0,
                            'shipping_time' => $var['shipping_time'],
                        );
                    }
                    $products[$k]['variants'] = $variants;
                }
            }

            $productModel = new WishWaitUploadProduct();

            $variantModel = new WishWaitUploadProductVariant();
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
                            $res = $productModel->updateData($p);
                            (new WishWaitUploadProductInfo())->allowField(true)->isUpdate(true)->save($p);
                            //商品信息更新成功
                            if ($res['state'] === true)
                            {
                                foreach ($variants as $variant)
                                {

                                    if(isset($variant['vid']) && isset($variant['pid']))
                                    {
                                        $map['vid'] = ['=', $variant['vid']]; //没有sku时，根据pid更新
                                        $map['pid'] = ['=', $variant['pid']];
                                        if (!$variantModel->update($variant, $map, 'sku,main_image,color,size,price,shipping,shipping_time,inventory,msrp,status'))
                                        {
                                            $message = $variantModel->getError();
                                            $return = ['result' => false, 'message' => $message];
                                            return $return;
                                        }
                                    }else{
                                        $variant['pid'] = $queue;
                                        (new WishWaitUploadProductVariant())->isUpdate(false)->allowField(true)->save($variant);
                                    }
                                }

                                $findWhere = [
                                    'pid' => ['=', $queue],
                                    'status' => ['<>', 1],
                                ];

                                //如果存在没有刊登成功的加入队列
                                if ($variantModel->where($findWhere)->find())
                                {
                                    $variantModel->update(['status' => 0, 'message' => '', 'run_time' => ''], $findWhere);
                                } else {
                                    //设置状态为已更新
                                    $productModel->update(['lock_product' => 1, 'lock_update' => 1], ['id' => $queue]);
                                    $variantModel->update(['lock_variant' => 1], ['pid' => $queue]);
                                }
                            } else {
                                $return = ['result' => false, 'message' => $res['message']];
                                return $return;
                                //$message =  $res['message'];
                            }
                            Db::commit();
                            if ($p['cron_time'] <= time()) {
                                (new UniqueQueuer(WishQueueJob::class))->push($queue);
                            } else {
                                (new UniqueQueuer(WishQueueJob::class))->push($queue,$p['cron_time']);
                            }
                            $return = ['result' => true, 'message' => '更新成功'];
                        }catch (PDOException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (DbException $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }catch (\Exception $exp){
                            Db::rollback();
                            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
                        }
                    }
                }
            }
            return $return;
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }

    }

    /**
     * @node 获取所有待刊登商品列表
     * @access public
     * @param array $post
     * @param int $page
     * @param int $pageSize
     * @param string $fields
     * @param int $cron
     * @return array
     */
    public function lists($post = array(), $page = 1, $pageSize = 20, $fields = "*", $cron = 0)
    {
        $model = new WishWaitUploadProduct();

        $where = $model->getWhere($post, $cron); //传入1则表示定时刊登

        $count = $model->getCount($where);

        $data = $model->getAll($where, $page, $pageSize, $fields);

        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];
    }


    /**
     * @node 获取所有待刊登商品列表
     * @access public
     * @param array $post
     * @param int $page
     * @param int $pageSize
     * @param string $fields
     * @param int $cron
     * @return array
     */
    public function getLists($post = array(), $page = 1, $pageSize = 20, $fields = "*", $cron = 0)
    {
        if (isset($post['order_by']) && $post['order_by']) {
            $order = 'p.' . $post['order_by'];
        } elseif(isset($post['status']) && $post['status']==1) {
            $order = ' p.number_sold  ';
        }else{
            $order = ' addtime ';
        }

        if (isset($post['order']) && $post['order']) {
            $sort = '  ' . $post['order'];
        } else {
            $sort = ' DESC ';
        }

        $order_by = $order . $sort;

        $wheres = $this->getWhere($post);

        $count = $this->getListCount($wheres);

        $data = $this->getListData($wheres, $page, $pageSize, $fields, $order_by);


        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];
    }


    /**
     * 统计总数
     * @param type $wheres
     */
    public function getListCount($wheres)
    {
        $model = new WishWaitUploadProduct;
        if (empty($wheres['join'])) {
            $count = $model
                ->alias('p')
                //->join('wish_account a', 'p.accountid=a.id', 'LEFT')
                //->join('user u', 'p.uid=u.id', 'LEFT')
                ->where($wheres['where'])
//                ->count('distinct(p.id)');
                ->count($wheres['countField']);
        } else {
            $count = $model
                ->alias('p')
                ->join($wheres['join'])
                //->join('wish_account a', 'p.accountid=a.id', 'LEFT')
                //->join('user u', 'p.uid=u.id', 'LEFT')
                ->where($wheres['where'])->where($wheres['sqlWhere'])
//                ->count('distinct(p.id)');
                ->count($wheres['countField']);
        }

        return $count;
    }

    /**
     * 查询
     * @param type $wheres
     */
    public function getListData($wheres, $page = 1, $pageSize = 20, $fields = "*",$order_by)
    {
        $model = new WishWaitUploadProduct;
//        $accountCache = Cache::store('WishAccount');
//        $userCache= Cache::store('User');
//
//        $rule = [
//            'type' => 'mod', // 分表方式
//            'num' => 50     // 分表数量
//        ];
//        $partition = [['addtime' => '1498838400'], 'addtime', $rule];

        if (empty($wheres['join'])) {
            $data = $model->alias('p')
                ->where($wheres['where'])
                ->with(['skus'=>function($query)use($wheres){$query->where($wheres['map'])->alias('v')->join($wheres['filter']['join'])->where($wheres['filter']['where']);},'account'=>function($query){$query->field('id,code');}])
                ->field($fields)
                ->page($page, $pageSize)->order($order_by)
                ->select();
        } else {
            $data = $model
                ->alias('p')
                ->join($wheres['join'])
                ->where($wheres['where'])->where($wheres['sqlWhere'])
                ->field($fields)
                ->with(['skus'=>function($query)use($wheres){$query->alias('v')->where($wheres['map'])->where($wheres['filter']['where'])->join($wheres['filter']['join']);},'account'=>function($query){$query->field('id,code');}])
                ->page($page, $pageSize)->order($order_by)
                ->select();
        }

        foreach ($data as &$d){
            $user = User::where('id',$d->uid)->field('realname')->find();
            $d->username = $user ? $user->realname:'';
        }
        //生成侵权标的
        if(!empty($data)){
            $goods_ids = array_column($data,'goods_id');
            $tort_ids = GoodsTortDescription::whereIn('goods_id',$goods_ids)->group('goods_id')->field('goods_id')->column('goods_id');

            foreach ($data as $k=>$v){
                if (empty($v['goods_id'])) {
                    $data[$k]['is_tort'] = 0;
                } else {
                    $data[$k]['is_tort'] = (int)in_array($v['goods_id'], $tort_ids);
                }
            }
        }
        return $data;
    }


    public function getWhere($post, $joinType = 'LEFT')
    {
        $wheres = [];
        $countField = 'distinct(p.id)';
        $where = [];
        //$joinG=[];
        $join = [];
        $hasJoin = false;
        $map = $filter['where']=$filter['join'] =[];
        $sqlWhere="";
        //刊登状态
        if (isset($post['status'])) {
            if ($post['status'] == 1) //已刊登
            {
                $where['p.publish_status'] = ['eq', 1];
                $map['v.status'] = ['=', 1];
                if (isset($adjust_range)) {
                    if ($adjust_range > 0) {//涨价
                        $map['v.current_cost'] = ['exp', '>v.pre_cost+'.$adjust_range];
                    } else if ($adjust_range < 0) {//降价
                        $map['v.current_cost'] = ['exp', '>v.pre_cost'.$adjust_range];
                    } else if ($adjust_range == 0) {
                        $map['v.current_cost'] = ['exp', '=v.pre_cost'];
                    }
                }
                //搜索重量
                if (isset($post['min_weight']) && isset($post['max_weight'] ) && $post['min_weight'] && $post['max_weight']) {
                    $join[] = ['wish_wait_upload_product_variant v', 'p.id=v.pid', $joinType];
                    $hasJoin = true;
                    $where['v.weight'] = ['between', [$post['min_weight'], $post['max_weight']]];
                    $map['v.weight'] = ['between', [$post['min_weight'], $post['max_weight']]];
                } else {
                    if (isset($post['min_weight']) && $post['min_weight']) {
                        $join[] = ['wish_wait_upload_product_variant v', 'p.id=v.pid', $joinType];
                        $hasJoin = true;
                        $where['v.weight'] = ['>=', $post['min_weight']];
                        $map['v.weight'] = ['>=', $post['min_weight']];
                    }
                    if (isset($post['max_weight'] )&& $post['max_weight']) {
                        $join[] = ['wish_wait_upload_product_variant v', 'p.id=v.pid', $joinType];
                        $hasJoin = true;
                        $where['v.weight'] = ['<=', $post['max_weight']];
                        $map['v.weight'] = ['<=', $post['max_weight']];
                    }

                }

            } elseif ($post['status'] == 0) {//刊登队列,非定时刊登
                $where['p.publish_status'] = ['eq', 0];
                $where['v.status'] = ['eq', 0];
                $map['v.status'] = ['=', 0];
                $join[] = ['wish_wait_upload_product_variant v', 'p.id=v.pid', $joinType];
                $hasJoin = true;
                //$where['p.cron_time']=['eq',0];
                //$where['v.status']=['eq',0];
            } elseif ($post['status'] == 2) { //定时刊登
                $where['p.product_id'] = ['eq', ''];
                $where['p.cron_time'] = ['neq', 0];
                $map['v.status'] = ['=', 0];
            } elseif ($post['status'] == 3) { //异常刊登
                $where['p.publish_status'] = ['neq', 1];
                $where['v.status'] = ['eq', 2];
                $map['v.status'] = ['=', 2];
                $join[] = ['wish_wait_upload_product_variant v', 'p.id=v.pid', $joinType];
                $hasJoin = true;
            }
        }


        //账号全称
        if (isset($post['accountType']) && $post['accountType'] == 'full' && !empty($post['accountVal'])) {
            $where['p.accountid'] = array('eq', $post['accountVal']);
        }

        //账号简码
        if (isset($post['accountType']) && $post['accountType'] == 'short' && !empty($post['accountVal'])) {
            $where['p.accountid'] = array('eq', $post['accountVal']);
        }


        //平台spu
        if (isset($post['nType']) && $post['nType'] == 'parent_sku' && $post['nContent']) {
            //$post['nContent'] = $this->managerBatchSearch($post['nContent']);
            //$sqlWhere = " p.parent_sku contact({$post['nContent']}) ";
            $where['p.parent_sku'] = array('like', "{$post['nContent']}%");
        }

        //商品spu
        if (isset($post['nType']) && $post['nType'] == 'spu' && $post['nContent']) {
            //$post['nContent'] = $this->managerBatchSearch($post['nContent']);
            //$where['p.local_spu'] = array('IN', $post['nContent']);
//            $filterWhere['s.sku'] = array('IN', "{$post['nContent']}");
//            $filterJoin[] = ['goods_sku s', 'v.sku_id=s.id', $joinType];
//
//            $filter['where']=$filterWhere;
//            $filter['join']=$filterJoin;

            //$where['combine_sku'] = array('like', "{$post['nContent']}%");
            $where['g.spu'] = array('IN', "{$post['nContent']}");
            if($hasJoin == false){
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
            $join[] = ['goods g', 'p.goods_id=g.id', $joinType];
        }

        //是否上下架
        if (isset($post['enabled']) && is_numeric($post['enabled'])) {

//            if ($post['enabled'] == 1) {
//                $where['v.enabled'] = array('eq', $post['enabled']);
//            } else {
//                $where['v.enabled'] = array('eq', $post['enabled']);
//            }
            $where['v.enabled'] = array('eq', $post['enabled']);
            $filterWhere['enabled'] =  array('eq', $post['enabled']);
            $filter['where']=$filterWhere;
            if (empty($hasJoin)) {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }

        //应用，刊登来源
        if (isset($post['application']) && is_numeric($post['application']))
        {
            if($post['application']==1)
            {
                $where['p.application'] = array('eq', "rondaful");
            }else{
                $where['p.application'] = array('neq', "rondaful");
            }
        }

        if (isset($post['number_sold']) && is_numeric($post['number_sold']))
        {
            if($post['number_sold']==0)
            {
                $where['p.number_sold'] = array('eq', 0);
            }else{
                $where['p.number_sold'] = array('gt', 0);
            }
        }

        //是否上下架
        if (isset($post['sell_status']) && is_numeric($post['sell_status'])) {
            $where['v.sell_status'] = array('eq', $post['sell_status']);
            if (empty($hasJoin)) {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }


        //是否wish express
        if (isset($post['wish_express']) && is_numeric($post['wish_express'])) {
            if ($post['wish_express'] == 1) {
                $where['p.wish_Express_Countries'] = ['neq', ''];
            } else {
                $where['p.wish_Express_Countries'] = ['eq', ""];
            }
        }

        if(isset($post['searchPrice']) && $post['searchPrice'])
        {
            if($post['searchPrice']=='total')
            {
                if (isset($post['minPrice']) && isset($post['maxPrice']) && $post['minPrice'] && $post['maxPrice'])
                {
                    $sqlWhere=" (v.price +  v.shipping) >= ".$post['minPrice']." AND  (v.price +  v.shipping) <= ".$post['maxPrice'];
                }elseif( isset($post['minPrice']) && $post['minPrice']){
                    $sqlWhere=" (v.price +  v.shipping) >= ".$post['minPrice'] ;
                }elseif(isset($post['maxPrice']) && $post['maxPrice']){
                    $sqlWhere="  (v.price +  v.shipping) <= ".$post['maxPrice'];
                }

            }elseif($post['searchPrice']=='price'){
                if (isset($post['minPrice']) && isset($post['maxPrice']) && $post['minPrice'] && $post['maxPrice'])
                {
                    $sqlWhere=" v.price >= ".$post['minPrice']." AND  v.price <= ".$post['maxPrice'];
                }elseif( isset($post['minPrice']) && $post['minPrice']){
                    $sqlWhere=" v.price >= ".$post['minPrice'] ;
                }elseif(isset($post['maxPrice']) && $post['maxPrice']){
                    $sqlWhere="  v.price <= ".$post['maxPrice'];
                }
            }elseif($post['searchPrice']=='shipping'){
                if (isset($post['minPrice']) && isset($post['maxPrice']) && $post['minPrice'] && $post['maxPrice'])
                {
                    $sqlWhere=" v.shipping >= ".$post['minPrice']." AND  v.shipping <= ".$post['maxPrice'];
                }elseif( isset($post['minPrice']) && $post['minPrice']){
                    $sqlWhere=" v.shipping >= ".$post['minPrice'] ;
                }elseif(isset($post['maxPrice']) && $post['maxPrice']){
                    $sqlWhere="  v.shipping <= ".$post['maxPrice'];
                }
            }
        }

        if($sqlWhere!=="")
        {
            if (empty($hasJoin))
            {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }

        //是否更新了资料
        if (isset($post['lock_update']) && is_numeric($post['lock_update'])) {
            $where['p.lock_update'] = ['eq', $post['lock_update']];
        }


        //审核状态
        if (isset($post['review_status']) && is_numeric($post['review_status'])) {
            $where['p.review_status'] = array('eq', $post['review_status']);
        }

        if (isset($post['status']) && ($post['status'] == 3 || $post['status'] == 0)) {
            //$join[]=['wish_wait_upload_product_variant v','v.pid=p.id','LEFT'];
            if (empty($hasJoin)) {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }

        //搜索sku
        if (isset($post['nType']) && $post['nType'] == 'sku' && $post['nContent']) {
            $post['nContent'] = $this->managerBatchSearch($post['nContent']);
            $where['v.sku'] = array('like', $post['nContent'].'%');
            if (empty($hasJoin)) {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }

        //本地sku
        if (isset($post['nType']) && $post['nType'] == 'local_sku' && $post['nContent']) {
            $filterWhere['s.sku'] = array('IN', "{$post['nContent']}");
            $filterJoin[] = ['goods_sku s', 'v.sku_id=s.id', $joinType];

            $filter['where']=$filterWhere;
            $filter['join']=$filterJoin;

            //$where['combine_sku'] = array('like', "{$post['nContent']}%");
            $where['s.sku'] = array('IN', "{$post['nContent']}");
            if($hasJoin == false){
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
            }
            $join[] = ['goods_sku s', 'v.sku_id=s.id', $joinType];

        }

        //是否定时刊登
        if (isset($post['cron_time']) && is_numeric($post['cron_time'])) {
            if ($post['cron_time'] == 1) {
                $where['p.cron_time'] = array('neq', 0);
            } else {
                $where['p.cron_time'] = array('eq', 0);
            }
        }


        //是否定时刊登
        if (isset($post['uid']) && is_numeric($post['uid']))
        {
            //$where['p.uid'] = array('eq',$post['uid']);
            $accounts = $this->getAllMyAccounts($post['uid']);
            $where['p.accountid']=['IN',$accounts];
        }

        //是否定时刊登
        if (isset($post['is_promoted']) && is_numeric($post['is_promoted']))
        {
            $where['p.is_promoted'] = array('eq',$post['is_promoted']);
        }

        //商品id
        if (isset($post['nType']) && $post['nType'] == 'product_id' && $post['nContent']) {
            $where['p.product_id'] = array('IN', $post['nContent']);
        }
        //中文标题
        if (isset($post['nType']) && $post['nType'] == 'zh_name' && $post['nContent']) {
            $where['p.zh_name'] = array('like', '%' . $post['nContent'] . '%');
        }
        //刊登标题
        if (isset($post['nType']) && $post['nType'] == 'name' && $post['nContent']) {
            $where['p.name'] = array('like', '%' . $post['nContent'] . '%');
        }
        //刊登账号
        if (isset($post['account']) && $post['account']) {
            $where['p.accountid'] = array('eq', $post['account']);
        }

        //更新时间
        if (isset($post['ntime']) && $post['ntime'] == 'last_updated')
        {
            if (isset ($post['start_time']) && isset ($post['end_time']) && $post['end_time'] && $post['start_time'])
            {

                $post['start_time'] = $post['start_time'].'00:00:00';
                $post['end_time'] = $post['end_time'].'23:59:59';
                $where['last_updated'] = ['between time', [strtotime($post['start_time']), strtotime($post['end_time'])]];
            } elseif (isset ($post['end_time']) && $post['end_time']) {
                $where['last_updated'] = array('<=', strtotime($post['end_time'] . '23:59:59'));
            } elseif (isset($post['start_time']) && $post['start_time']) {
                $where['last_updated'] = array('>=', strtotime($post['start_time'] . '00:00:00'));
            }
        }

        //更新时间
        if (isset($post['ntime']) && $post['ntime'] == 'date_uploaded')
        {
            if (isset ($post['start_time']) && isset ($post['end_time']) && $post['end_time'] && $post['start_time'])
            {
                $post['start_time'] = $post['start_time'].'00:00:00';
                $post['end_time'] = $post['end_time'].'23:59:59';

                $where['date_uploaded'] = ['between time', [strtotime($post['start_time']), strtotime($post['end_time'])]];

            } elseif (isset ($post['end_time']) && $post['end_time']) {
                $where['date_uploaded'] = array('<=', strtotime($post['end_time'] . '23:59:59'));
            } elseif (isset($post['start_time']) && $post['start_time']) {
                $where['date_uploaded'] = array('>=', strtotime($post['start_time'] . '00:00:00'));
            }
        }

        //运行时间
        if (isset($post['ntime']) && $post['ntime'] == 'run_time')
        {
            if (isset ($post['start_time']) && isset ($post['end_time']) && $post['end_time'] && $post['start_time']) {
                $post['start_time'] = $post['start_time'].'00:00:00';
                $post['end_time'] = $post['end_time'].'23:59:59';

                $where[$post['ntime']] = ['between time', [strtotime($post['start_time']), strtotime($post['end_time'])]];
            } elseif (isset ($post['end_time']) && $post['end_time']) {

                $where[$post['ntime']] = array('<=', strtotime($post['end_time']));

            } elseif (isset($post['start_time']) && $post['start_time']) {
                $where[$post['ntime']] = array('>=', strtotime($post['start_time']));

            }
            if (empty($hasJoin)) {
                $join[] = ['wish_wait_upload_product_variant v', 'v.pid=p.id', $joinType];
                $hasJoin = true;
            }
        }

        if (isset($post['ntime']) && $post['ntime'] == 'cron_time') {
            if (isset ($post['start_time']) && isset ($post['end_time']) && $post['end_time'] && $post['start_time']) {
                $post['start_time'] = $post['start_time'].'00:00:00';
                $post['end_time'] = $post['end_time'].'23:59:59';
                $where[$post['ntime']] = ['between time', [strtotime($post['start_time']), strtotime($post['end_time'])]];
            } elseif (isset ($post['end_time']) && $post['end_time']) {
                $where[$post['ntime']] = array('<=', strtotime($post['end_time']));
            } elseif (isset($post['start_time']) && $post['start_time']) {
                $where[$post['ntime']] = array('>=', strtotime($post['start_time']));
            }
        }

        //WE是否自动匹配的-pan
        if (isset($post['auto_sp']) && is_numeric($post['auto_sp'])) {
            if ($post['auto_sp'] == 1) {
                $where['p.auto_sp'] = array('neq', 0);
            } else {
                $where['p.auto_sp'] = array('eq', 0);
            }
        }

        //侵权选项
        if (isset($post['tort_platform']) && $post['tort_platform'] !== '') {
            $join[] = ['goods_tort_description gd', 'gd.goods_id=p.goods_id'];
            if ($post['tort_platform'] > 0) {
                $where['gd.channel_id'] =$post['tort_platform'];
            }
//            $countField = 'Distinct p.goods_id';
        }

        $wheres = [
            'where' => $where,
            'join' => $join,
            'map' => $map,
            'sqlWhere'=>$sqlWhere,
            'filter'=>$filter,
            'countField' => $countField
        ];

        return $wheres;
    }
    private function managerBatchSearch($string){
        $contents = explode(',',$string);
        $return = [];
        foreach ($contents as $content){
            $return[]=$content."%";
        }
        return implode(",",$return);
    }
    public function getAllMyAccounts($uid,$channel_id=3)
    {
        $where=[
            'channel_id'=>['=',$channel_id],
            'seller_id'=>['=',$uid],
        ];
        $accounts = ChannelUserAccountMap::where($where)->field('account_id')->select();

        return array_column($accounts,'account_id');
    }


    /**
     * @node 获取商品数据
     * @access public
     * @param int $goods_id 商品id
     * @return array
     */
    public function getGoodsData($goods_id = 0)
    {
        $model = new Goods();

        if (!$model->isHas($goods_id)) {
            return json(['msg' => '商品不存在',], 400);
        }

        $where['goods_id'] = array('eq', $goods_id);

        $goods_info = $model->getOne(goods_id);

        return $goods_info;


    }

    /**
     * @node 获取sku属性
     * @access public
     * @param array $skus sku数组
     * @param ind $goods_id 商品id
     * @return array $skus
     */
    public function getSkuAttr($skus, $goods_id)
    {
        try{
            if ($skus && is_array($skus)) {
                $colorModel = new WishColor;
                foreach ($skus as $k => &$sku)
                {
                    $sku = is_object($sku)?$sku->toArray():$sku;

                    $sku_attributes = json_decode($sku['sku_attributes'], true);

                    $sku['size'] = '';

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
                                    if(strlen($sku['size']))
                                    {
                                        $sku['size']= $sku['size'].' & '.$goodsAttribute['alias'];
                                    }else{
                                        $sku['size']= $sku['size'].$goodsAttribute['alias'];
                                    }

                                }
                            } elseif ($attrKeyVal['code'] == 'color') {

                                //匹配wish platform color values
                                $wishColorValue = (new WishColor())->where(['color_value' => $attrKeyVal['value']])->find();
                                if ($wishColorValue) {
                                    $sku['color'] = $wishColorValue['color_value'];
                                } else {
                                    $sku['color'] = $attrKeyVal['vcode'];
                                }

                            } else {
                                if (count($sku_attributes) > 2)
                                {
                                    if(strlen($sku['size'])>0)
                                    {
                                        $sku['size'] = $sku['size'] . ' & ' . $attrKeyVal['value'];
                                    }else{
                                        $sku['size'] = $attrKeyVal['value'];
                                    }

                                } else {
                                    $sku['size'] = $attrKeyVal['value'];
                                }
                            }
                        }
                    }

                    $sku['inventory'] = self::skuInventory($sku, $goods_id) ? self::skuInventory($sku, $goods_id) : 0;
                    $sku['price'] = 0;
                    $sku['shipping_time'] = '';
                    $sku['shipping'] = 0;
                    $sku['msrp'] = 0;

                    unset($sku['goods_id']);
                    unset($sku['alias_sku']);
                    unset($sku['retail_price']);
                    unset($sku['create_time']);
                    unset($sku['update_time']);
                    unset($sku['sku_attributes']);

                    if (!isset($sku['color'])) {
                        $sku['color'] = '';
                    }

                    if (!isset($sku['size'])) {
                        $sku['size'] = '';
                    }
                    //$sku['initSize']=$sku['size'];
                    //$sku['initColor']=$sku['color'];
                }
            }
            return $skus;
        }catch (Exception $exp){
            throw new Exception($exp->getMessage());
        }

    }

    /**
     * @node 获取sku库存
     * @access private
     * @param array $sku
     * @param int $goods_id 商品id
     * @return array
     */
    public static function skuInventory($sku, $goods_id)
    {
        if ($sku && $goods_id) {
            return GoodsInventoryHold::where(['sku_id' => $sku['id'], 'goods_id' => $goods_id])->find();
        }
    }

    /**
     * @node 获取品牌列表
     * @access public
     * @return array
     */
    public static function getBrands()
    {
        return (new Brand())->select();
    }

    /**
     * @node 获取wish销售人员账号
     * @access public
     * @return array
     */
    public static function sellers($warehouse_type)
    {
        if ($warehouse_type) {
            //$where['b.warehouse_type'] = ['eq', $warehouse_type];
        }

        $where['a.wish_enabled'] = ['eq', 1];
        $where['b.channel_id'] = ['eq', 3];
        $where['a.is_invalid'] = ['eq', 1];
        $seller = (new WishAccount())->group('a.id')->alias('a')->field('a.id,a.code,a.account_name,u.realname,u.id uid,b.warehouse_type')->join('channel_user_account_map b', 'a.id=b.account_id', 'LEFT')->join('user u', 'b.seller_id=u.id', 'LEFT')->where($where)->order('a.id ASC')->select();
        return $seller;
        //return $seller->where(['wish_enabled'=>1])->order('id ASC')->select();
    }

    public static function filterSeller(array $sellers, $spu)
    {

        foreach ($sellers as $k => &$seller)
        {
            $where['accountid'] = $seller['id'];
            $where['parent_sku'] = $spu;
            $cache = GoodsPublishMap::where(['channel'=>3,'spu'=>$spu])->value('publish_status');
            $cache = json_decode($cache, true);
            if ($cache && in_array($seller['id'],$cache ))
            {
                //self::array_remove($sellers, $k);
                $seller['publish']=1;
            }else{
                $seller['publish']=0;
            }
        }
        return $sellers;
    }

    private static function array_remove(&$arr, $offset)
    {
        array_splice($arr, $offset, 1);
    }


    /**
     * @node 获取商品相册
     * @access public
     * @param array $where
     * @return array
     */
    public static function gallery($where)
    {

        $gallerys = GoodsGallery::where($where)->select();
        if ($gallerys) {
            foreach ($gallerys as &$gallery) {
                $gallery['path'] = GoodsImage::getThumbPath($gallery['path'], 200, 200);
            }

        }
        return $gallerys;
    }

    /**
     * @node 获取产品描述信息
     * @access public
     * @praam int $goods_id 产品Id
     * @param int $lang_id 语言Id
     * @return array
     */
    public function getProductDescription($goods_id, $lang_id = 2)
    {
        $where['goods_id'] = ['EQ', $goods_id];
        $where['lang_id'] = ['EQ', $lang_id];

        $lists = GoodsLang::where($where)->find();

        return $lists;
    }

    /**
     * @node 添加商品
     * @access public
     * @param array $where
     * @return void
     */
    public static function addProduct(array $where)
    {
        set_time_limit(0);
        $model = new WishWaitUploadProduct();

        $page = 1;
        $pageSize = 30;
        $fields = "a.*,b.*,c.refresh_token,c.access_token";

        do {
            $products = $model->getAll($where, $page, $pageSize, $fields);

            if (empty($products)) {
                break;
            } else {
                self::startAddProduct($products);
                $page++;
            }

        } while (count($products) == $pageSize);

    }

    /**
     * @node 定时器启动上传，执行上传商品到平台
     * @access public
     * @param array $products
     * @return  void
     */
    public static function startAddProduct(array $products)
    {
        set_time_limit(0);
        try {
            foreach ($products as $key => $product) {
                $config['access_token'] = $product['access_token'];
                $api = WishApi::instance($config)->loader('Product');
                $data = [];
                $data['name'] = $product['name'];
                $data['description'] = $product['description'];
                $data['parent_sku'] = $product['parent_sku'];
                $data['tags'] = $product['tags'];
                $data['sku'] = $product['sku'];
                $data['inventory'] = $product['inventory'];
                $data['price'] = $product['price'];
                $data['shipping'] = $product['shipping'];
                $data['shipping_time'] = $product['shipping_time'];
                $data['color'] = $product['color'];
                $data['size'] = $product['size'];
                $data['main_image'] = $product['main_image'];
                $data['extra_images'] = $product['extra_images'];
                $data['msrp'] = $product['msrp'];
                $data['brand'] = $product['brand'];
                $data['landing_page_url'] = $product['landing_page_url'] ? $product['landing_page_url'] : '';
                $data['upc'] = $product['upc'];

                $response = $api->createProduct($data);

                if ($response['state'] === true && $response['code'] == 0) {
                    $updatev['status'] = 1;
                } elseif ($response['code'] == 1000) { //变体产品
                    $variant = [];
                    $variant['parent_sku'] = $product['parent_sku'];
                    $variant['sku'] = $product['sku'];
                    $variant['inventory'] = $product['inventory'];
                    $variant['price'] = $product['price'];
                    $variant['shipping'] = $product['shipping'];
                    $variant['color'] = $product['color'];
                    $variant['size'] = $product['size'];
                    $variant['msrp'] = $product['msrp'];
                    $variant['shipping_time'] = $product['shipping_time'];
                    $variant['main_image'] = $product['main_image'];

                    $response = $api->variantProduct($variant);

                    if ($response['state'] === true) {
                        $updatev['status'] = 1; //刊登成功
                    } else {
                        $updatev['status'] = 2; //刊登失败
                    }
                } else {
                    $updatev['status'] = 2;//刊登失败
                }

                if (isset($response['data']['Product'])) {
                    $product_id = $response['data']['Product']['id']; //产品id
                    $review_status = self::deal_review_status($response['data']['Product']['review_status']); //审核状态
                    $number_saves = $response['data']['Product']['number_saves']; //收藏量
                    $number_sold = $response['data']['Product']['number_sold']; //销售量
                    $last_updated = str2time($response['data']['Product']['last_updated']);
                    $is_promoted = $response['data']['Product']['is_promoted']; //是否促销
                    $updatep = [
                        'is_promoted' => $is_promoted,
                        'number_saves' => $number_saves,
                        'product_id' => $product_id,
                        'review_status' => $review_status,
                        'number_sold' => $number_sold,
                        'last_updated' => $last_updated,
                        // 'id'=>$product['id']
                    ];
                    Db::startTrans();
                    try{
                        WishWaitUploadProduct::where(['id' => $product['id']])->update($updatep);
                        Db::commit();
                    }catch (\Exception $exp){
                        Db::rollback();
                        throw new JsonErrorException($exp->getMessage());
                    }

                    if (isset($response['data']['Product']['variants']))
                    {
                        $variants = $response['data']['Product']['variants'];
                        foreach ($variants as $key => $V)
                        {
                            if ($V['Variant']['sku'] == $product['sku'])
                            {
                                $updatev['variant_id'] = $V['Variant']['id'];//变体Id
                                $updatev['product_id'] = $V['Variant']['product_id'];//
                                $updatev['enabled'] = $V['Variant']['enabled'];
                                Db::startTrans();
                                try{
                                    WishWaitUploadProductVariant::where(['vid' => $product['vid']])->update($updatev);
                                    Db::commit();
                                }catch (\Exception $exp){
                                    Db::rollback();
                                    throw new JsonErrorException($exp->getMessage());
                                }
                            }
                        }
                    }
                }

                //使用add/Variant接口返回数据
                if (isset($response['data']['Variant'])) {
                    $updatev['variant_id'] = $response['data']['Variant']['id'];//变体Id
                    $updatev['product_id'] = $response['data']['Variant']['product_id'];//
                    $updatev['enabled'] = $response['data']['Variant']['enabled'];
                    $updatev['vid'] = $product['vid'];


                    Db::startTrans();
                    try{
                        WishWaitUploadProductVariant::where(['vid' => $product['vid']])->update($updatev);
                        Db::commit();
                    }catch (\Exception $exp){
                        Db::rollback();
                        throw new JsonErrorException($exp->getMessage());
                    }

                }


                $updatev['message'] = $response['message'];
                $updatev['code'] = $response['code'];
                $updatev['run_time'] = date('Y-m-d H:i:s', time());


                Db::startTrans();
                try{
                    WishWaitUploadProductVariant::where(['vid' => $product['vid']])->update($updatev);
                    Db::commit();
                }catch (\Exception $exp){
                    Db::rollback();
                    throw new JsonErrorException($exp->getMessage());
                }
            }
        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }


    }

    public static function deal_review_status($str)
    {
        if ($str == 'approved') //approved
        {
            $res = 1;
        } elseif ($str == 'rejected') { //rejected
            $res = 2;
        } elseif ($str == 'pending') { //pending
            $res = 3;
        } else {
            $res = 0;
        }
        return $res;
    }

    /**
     * @node 浏览器启动上传，执行上传一个账号刊登多个商品到平台
     * @access public
     * @param array $products
     * @return  void
     */
    public function startRsynAddManyProduct(array $vars, $account_id)
    {
        set_time_limit(0);
        $msg = '';
        $fields = "a.*,b.*,c.access_token";
        foreach ($vars as $var) {
            $where['accountid'] = array('=', $account_id); //账号id

            $where['parent_sku'] = array('=', $var['parent_sku']); //商品id

            $products = $this->getSkus($where, $fields);

            $result = $this->rsyncProductOnline($products);
            if ($result == '')//没有错误信息
            {
                $accountInfo = WishAccount::where(['id' => $account_id])->find();
                $msg .= $accountInfo['account_name'] . '刊登成功[' . $var['parent_sku'] . ']';
            } else { //有错误信息
                $msg .= $result;
            }
        }
        return $msg;
    }

    /**
     * @node 浏览器启动上传，执行上传商品到平台
     * @access public
     * @param array $products
     * @return  void
     */
    public function startRsynAddProduct(array $vars, $parent_sku)
    {
        set_time_limit(0);
        $msg = '';
        $fields = "a.*,b.*,c.access_token";
        foreach ($vars as $var) {
            $where['accountid'] = array('=', $var['accountid']); //账号id

            $where['parent_sku'] = array('=', $parent_sku); //商品id

            $products = $this->getSkus($where, $fields);

            $result = $this->rsyncProductOnline($products);
            if ($result == '') {
                $msg = $msg . $var['account_name'] . '刊登成功';
            } else {
                $msg = $msg . $result;
            }
        }
        return $msg;
    }

    /**
     * 同步执行刊登
     * @param array $products
     * @return boolean
     */
    public function rsyncProductOnline(array $products)
    {
        set_time_limit(0);
        try {
            $message = '';
            foreach ($products as $key => $product) {
                $config['access_token'] = $product['access_token'];

                $api = WishApi::instance($config)->loader('Product');

                if ($key == 0) {
                    $response = $this->addWishProduct($product, $api);
                } else {
                    $response = $this->addWishVariant($product, $api);
                }
                $message = $message . $response['message'];
            }
            return $message;
        } catch (Exception $exp) {
            return $exp->getMessage();
        }
    }

    /**
     * 上传wish商品信息
     * @param array $product
     * @param type $api
     *
     */
    public function addWishProduct($product, $api)
    {

        try {
            $data = [];
            $data['name'] = $product['name'];
            $data['description'] = $product['description'];
            $data['parent_sku'] = $product['parent_sku'];
            $data['tags'] = $product['tags'];
            $data['sku'] = $product['sku'];
            $data['inventory'] = $product['inventory'];
            $data['price'] = $product['price'];
            $data['shipping'] = $product['shipping'];
            $data['shipping_time'] = $product['shipping_time'];
            $data['color'] = $product['color'];
            $data['size'] = $product['size'];
            $data['main_image'] = $product['main_image'];
            $data['extra_images'] = $product['extra_images'];
            $data['msrp'] = $product['msrp'];
            $data['brand'] = $product['brand'];
            $data['landing_page_url'] = $product['landing_page_url'];
            $data['upc'] = $product['upc'];
            $response = $api->createProduct($data);

            if ($response['state'] === true && $response['code'] == 0) {
                $updatev['status'] = 1; //上传成功
                $message = '';
            } else {
                $updatev['status'] = 2; //上传失败
                $message = $response['message'];
            }

            if (isset($response['data']['Product']['variants'])) {
                $variants = $response['data']['Product']['variants'];
                foreach ($variants as $key => $V) {
                    if ($V['Variant']['sku'] == $product['sku']) //与当前sku相同
                    {
                        $updatev['variant_id'] = $V['Variant']['id'];//变体Id
                        $updatev['product_id'] = $V['Variant']['product_id'];//
                        $updatev['enabled'] = $V['Variant']['enabled'];
                        //$updatev['vid'] = $product['vid'];
                    }
                }
            }

            $updatev['message'] = $response['message'];
            $updatev['code'] = $response['code'];
            $updatev['run_time'] = date('Y-m-d H:i:s', time());

            //启动事务
            Db::startTrans();
            try{
                WishWaitUploadProductVariant::where(['vid' => $product['vid']])->update($updatev);
                Db::commit();
            }catch (\Exception $exp){
                Db::rollback();
                throw new JsonErrorException($exp->getMessage());
            }

            if (isset($response['data']['Product'])) {
                $product_id = $response['data']['Product']['id']; //产品id
                $review_status = WishListingHelper::deal_review_status($response['data']['Product']['review_status']); //审核状态
                $number_saves = $response['data']['Product']['number_saves']; //收藏量
                $number_sold = $response['data']['Product']['number_sold']; //销售量
                $last_updated = str2time($response['data']['Product']['last_updated']);
                $is_promoted = $response['data']['Product']['is_promoted']; //是否促销
                $updatep = [
                    'is_promoted' => $is_promoted,
                    'number_saves' => $number_saves,
                    'product_id' => $product_id,
                    'review_status' => $review_status,
                    'number_sold' => $number_sold,
                    'last_updated' => $last_updated,
                    // 'id'=>$product['id']
                ];
                //启动事务
                Db::startTrans();
                try{
                    WishWaitUploadProduct::where('id', '=', $product['id'])->update($updatep);
                    Db::commit();
                }catch (\Exception $exp){
                    Db::rollback();
                    throw new JsonErrorException( $exp->getMessage());
                }

            }

            return $response;
        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
        }
    }

    /**
     * Create a Product Variation
     * @param array $product
     * @param type $api
     */
    public function addWishVariant($product, $api)
    {

        try {
            $variant = [];
            $variant['parent_sku'] = $product['parent_sku'];
            $variant['sku'] = $product['sku'];
            $variant['inventory'] = $product['inventory'];
            $variant['price'] = $product['price'];
            $variant['shipping'] = $product['shipping'];
            $variant['color'] = $product['color'];
            $variant['size'] = $product['size'];
            $variant['msrp'] = $product['msrp'];
            $variant['shipping_time'] = $product['shipping_time'];
            $variant['main_image'] = $product['main_image'];

            $response = $api->variantProduct($variant);

            if ($response['state'] === true && $response['code'] == 0) {
                $updatev['status'] = 1; //上传成功
                $message = '';
            } else {
                $updatev['status'] = 2; //上传失败
                $message = $response['message'];
            }

            $updatev['message'] = $response['message'];
            $updatev['code'] = $response['code'];
            $updatev['run_time'] = date('Y-m-d H:i:s', time());

            //使用add/Variant接口返回数据
            if (isset($response['data']['Variant'])) {
                $updatev['variant_id'] = $response['data']['Variant']['id'];//变体Id
                $updatev['product_id'] = $response['data']['Variant']['product_id'];//
                $updatev['enabled'] = $response['data']['Variant']['enabled'];
                //$updatev['vid'] = $product['vid'];
            }

            WishWaitUploadProductVariant::where('vid', '=', $product['vid'])->update($updatev);
            return $response;
        } catch (Exception $exp) {
            throw new Exception($exp->getFile() . $exp->getLine() . $exp->getMessage());
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
        $WishValidate = new WishValidate();

        $error = $WishValidate->checkData($post);

        if ($error) {
            return $error;
        }

        if (isset($post['vars'])) {

            $vars = json_decode($post['vars'], true);

            if (is_array($vars) && !empty($vars)) {
                $error = $WishValidate->checkVars($vars, 'var');
                if ($error) {
                    return $error;
                }
            }
        }
    }

    /**
     * @node 获取wish刊登商品sku
     * @access public
     * @param array $where
     * @return array
     */
    public function getSkus(array $where, $fields = '*')
    {
        $model = new WishWaitUploadProduct();

        $products = $model->getSkus($where, $fields);

        return $products;

    }

    /***
     * 将要导出的字段加入缓存，从缓存中取出数据，然后导出，导出成功，更新文件状态
     * @param $fields
     */
    public function downloadAll($fields,$uid,$channel='wish'){

        $channel_id = array_flip(GoodsPublishMapService::CHANNEL)[$channel];
        $filename = $channel.'刊登批量导出' . date('YmdHis');
        $data = [
            'applicant_id'=>$uid,
            'apply_time'=>time(),
            'export_file_name'=>$filename,
            'download_url'=>'',
        ];
        Db::startTrans();
        try{
            $model = new ReportExportFiles();
            $model->allowField(true)->save($data);
            Db::commit();
            $id =$model->getLastInsID();
            $queue = $channel_id.'|'.$id;
            Cache::store('PublishProductDownload')->setCacheData($queue,$fields);
            return ['message'=>'导出成功,前往报表导出管理查看'];
        }catch (PDOException $exp){
            Db::rollback();
            throw new Exception($exp->getMessage());
        }
    }
    private function getExportData($ids)
    {
        try {
            $WishWaitUploadProduct = new WishWaitUploadProduct();
            $wishGoods = $WishWaitUploadProduct->field('id,goods_id,name,main_image,parent_sku,tags,wish_express_countries,number_sold,review_status,number_saves,inventory,last_updated,date_uploaded')->where('id', 'in', $ids)->select();
            $WishWaitUploadProductVariant = new WishWaitUploadProductVariant();
            $wishGoodsSku = $WishWaitUploadProductVariant->field('pid,sku,main_image,color,size,shipping,shipping_time,msrp,price,weight,sku_id')->where('pid', 'in', $ids)->select();
            $aGoods = [];
            $aSku = [];
            if ($wishGoods) {
                foreach ($wishGoods as $wish) {
                    $row = $wish->toArray();
                    $row['parent_sku'] = preg_replace('/^MU(.*?)-\w{3}-\w$/','$1',$row['parent_sku']);
                    $row['parent_sku'] = preg_replace('/^(.*?)-\w{3}-\w$/','$1',$row['parent_sku']);
                    $row['parent_sku'] = preg_replace('/^(.*?)-\w{3}$/','$1',$row['parent_sku']);
                    $row['parent_sku'] = preg_replace('/^MU(.*?)$/','$1',$row['parent_sku']);
                    $row['description'] = $wish->info?$wish->info->description:'';
                    $row['extra_images'] = $wish->info?$wish->info->extra_images:'';
                    if (!$row['goods_id']) {
                        $alias = GoodsSkuAlias::where('alias', $row['parent_sku'])->field('sku_id')->find();
                        if ($alias) {
                            $sku_id = $alias->sku_id;
                            $cacheGoodsSku = Cache::store('goods')->getSkuInfo($sku_id);
                            $row['goods_id'] = $cacheGoodsSku['goods_id'];
                        }
                        if (!$row['goods_id']) {
                            $oGoodsSku = GoodsSku::where('sku', $row['parent_sku'])->field('goods_id')->find();
                            if ($oGoodsSku) {
                                $row['goods_id'] = $oGoodsSku->goods_id;
                            }
                        }
                        if (!$row['goods_id']) {
                            $oGoods = Goods::where('spu', $row['parent_sku'])->whereOr('alias')->field('id')->find();
                            if ($oGoods) {
                                $row['goods_id'] = $oGoods->id;
                            }
                        }
                    }
                    if ($row['goods_id']) {
                        $cacheGoods = Cache::store('goods')->getGoodsInfo($row['goods_id']);
                        if ($cacheGoods) {
                            $row['hs_code'] = $cacheGoods['hs_code'];
                            $row['width'] = $cacheGoods['width']/10;
                            $row['height'] = $cacheGoods['height']/10;
                            $row['length'] = $cacheGoods['depth']/10;
                        }
                    }
                    $row['wish_express']=$row['wish_express_countries']?'是':'否';
                    $row['last_updated']=date('Y-m-d H:i:s',$row['last_updated']);
                    $row['date_uploaded']=date('Y-m-d H:i:s',$row['date_uploaded']);
                    $aGoods[$row['id']] = $row;
                }
            }
            if ($wishGoodsSku) {
                foreach ($wishGoodsSku as $v) {
                    $aGood = $aGoods[$v->pid];
                    $v->sku = preg_replace('/^(.*?)-\d{3}-\d$/', '$1', $v->sku);
                    $v->sku = preg_replace('/^(.*?)-+$/', '$1', $v->sku);
                    $row = \app\goods\service\GoodsHelp::createExportData($aGood['parent_sku'], $v->sku);
                    $v = $v->toArray();
                    $row['name'] = $aGood['name'];
                    if (strlen($row['name']) > 100) {
                        $row['titleLenMoreThan100'] = '是';
                    }
                    $row['titleLenMoreThan100'] = mb_convert_encoding($row['titleLenMoreThan100'],'gb2312','utf-8');
                    $row['description'] = $aGood['description'];
                    $row['tags'] = $aGood['tags'];
                    $preg = "/[^".chr(1)."-".chr(126)."]+/u";
                    preg_match_all($preg,$row['description'],$pregResult);
                    if($pregResult[0]){
                        $errResult = [];
                        foreach ($pregResult[0] as $errWord){
                            $errResult[$errWord] = isset($errResult[$errWord])?($errResult[$errWord]+1):1;
                        }
                        $errReport = [];
                        foreach ($errResult as $kResult=>$vNum){
                            $errReport[] = '"'.$kResult.'",一共出现'.$vNum.'处';
                        }
                        if($errReport){
                            $row['errorDescription']= implode("\n",$errReport);
                        }
                    }
                    $row['description'] = mb_convert_encoding($row['description'],'gb2312','utf-8');
                    $row['errorDescription'] = mb_convert_encoding($row['errorDescription'],'gb2312','utf-8');
                    if($aGood['main_image']){
                        $pos  = strpos($aGood['main_image'],'?');
                        if($pos!==false){
                            $aGood['main_image'] = substr($aGood['main_image'],0,$pos);
                        }
                        $aGood['main_image'] =  str_replace('-medium.','-original.',$aGood['main_image']);
                    }
                    $row['main_image'] = $aGood['main_image'];
                    isset($aGood['hs_code']) && $aGood['hs_code'] && $row['hs_code'] = $aGood['hs_code'];
                    isset($aGood['width']) && $aGood['width'] && $row['width'] = $aGood['width'];
                    isset($aGood['height']) && $aGood['height'] && $row['height'] = $aGood['height'];
                    isset($aGood['length']) && $aGood['length'] && $row['length'] = $aGood['length'];
                    $row['variantThumb'] = $v['main_image'];
                    $row['sku'] = $v['sku'];
                    $row['color'] = $v['color'];
                    $row['size'] = $v['size'];
                    $row['shipping'] = $v['shipping'];
                    $row['msrp'] = $v['msrp'];
                    $row['price'] = $v['price'];
                    $sku_id = $v['sku_id'];
                    if(!$sku_id){
                        $alias = GoodsSkuAlias::where('alias', $v['sku'])->field('sku_id')->find();
                        if ($alias) {
                            $sku_id = $alias->sku_id;
                        }
                        if (!$sku_id) {
                            $oGoodsSku = GoodsSku::where('sku', $v['sku'])->field('id')->find();
                            if ($oGoodsSku) {
                                $sku_id = $oGoodsSku->id;
                            }
                        }
                    }
                    if($sku_id){
                        $cacheGoodsSku = Cache::store('goods')->getSkuInfo($sku_id);
                        $row['weight'] = $cacheGoodsSku['weight']/1000;
                    }
                    if ($aGood['extra_images']) {
                        $extra_images = explode('|', $aGood['extra_images']);
                        $extra_images = array_slice($extra_images, 0, 11);
                        foreach ($extra_images as $k => $thumb) {
                            $row['thumb' . $k] = $thumb;
                        }
                    }
                    $row = array_merge($row,$aGood);
                    $aSku[] = $row;
                }
            }
            return $aSku;
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage().$ex->getFile().$ex->getLine());
        }
    }


    public function export($ids)
    {
        try {
            $header = [
                ['title' => 'Parent Unique ID', 'key' => 'spu', 'width' => 10],
                ['title' => '*Product Name', 'key' => 'name', 'width' => 35],
                ['title' => '*title长度大于100', 'key' => 'titleLenMoreThan100', 'width' => 35],
                ['title' => 'Description', 'key' => 'description', 'width' => 15],
                ['title' => 'Description特殊字符检测', 'key' => 'errorDescription', 'width' => 15],
                ['title' => '*Tags', 'key' => 'tags', 'width' => 25],
                ['title' => '*Unique ID', 'key' => 'sku', 'width' => 20],
                ['title' => 'Color', 'key' => 'color', 'width' => 20],
                ['title' => 'Size', 'key' => 'size', 'width' => 25],
                ['title' => '*Quantity', 'key' => 'quantity', 'width' => 20],
                ['title' => '*Price', 'key' => 'price', 'width' => 40],
                ['title' => 'MSRP', 'key' => 'msrp', 'width' => 20],
                ['title' => '*Shipping', 'key' => 'shipping', 'width' => 20],
                ['title' => 'Shipping Time(enter without " ", just the estimated days )', 'key' => 'ShippingTime', 'width' => 20],
                ['title' => 'Shipping Weight', 'key' => 'weight', 'width' => 20],
                ['title' => 'Shipping Length', 'key' => 'length', 'width' => 20],
                ['title' => 'Shipping Width', 'key' => 'width', 'width' => 20],
                ['title' => 'Shipping Height', 'key' => 'height', 'width' => 20],
                ['title' => 'HS Code', 'key' => 'hs_code', 'width' => 20],
                ['title' => '*Product Main Image URL', 'key' => 'main_image', 'width' => 20],
                ['title' => 'Variant Main Image URL', 'key' => 'variantThumb', 'width' => 20],
                ['title' => 'Extra Image URL', 'key' => 'thumb0', 'width' => 20],
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
                ['title' => '是否WE', 'key' => 'wish_express', 'width' => 20],
                ['title' => '审核状态', 'key' => 'review_status', 'width' => 20],
                ['title' => '可售量', 'key' => 'inventory', 'width' => 20],
                ['title' => '已收藏', 'key' => 'number_saves', 'width' => 20],
                ['title' => '已售量', 'key' => 'number_sold', 'width' => 20],
                ['title' => '更新时间', 'key' => 'last_updated', 'width' => 20],
                ['title' => '上传时间', 'key' => 'date_uploaded', 'width' => 20],

            ];
            $headers=[];
            foreach ($header as $item){
                $encode = mb_detect_encoding($item['title'], array("ASCII",'UTF-8','GB2312',"GBK",'BIG5'));
                if($encode=='GBK' || $encode=='GB2312'){
                    $item =  mb_convert_encoding($item['title'],'utf-8',$encode);
                }
                $header['width']=20;
                $header['need_merge']=0;
                $header['title']=$item['title'];
                $header['key']=$item['key'];
                array_push($headers,$header);
            }

            $lists = $this->getExportData($ids);
            $file = [
                'name' => '导出wish刊登joom商品',
                'path' => 'goods'
            ];
            $result=Excel::exportExcel2007($headers,$lists,$file);
            return $result;
            //            $ExcelExport = new DownloadFileService();
//            return $ExcelExport->export($lists, $header, $file);
        } catch (Exception $e) {
            throw new Exception($e->getMessage().$e->getFile().$e->getLine());
        }
    }


    /**
     * 调整成本价
     * @param $data
     * @throws Exception
     */
    public function adjustCostPrice($data)
    {
        try {
            foreach ($data as &$datum) {
                $datum['pre_cost'] = $datum['current_cost'];
            }
            (new WishWaitUploadProductVariant())->saveAll($data);
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 保存物流模版
     * @param $post
     * @param int $uid
     * @throws Exception
     *
     */
    public function saveExpressTemplate($post,$uid=0,$where=null)
    {
        try {

            $data = [];
            $data['name'] = $post['name'];

            $data['from_price'] = $post['from_price'];
            $data['to_price'] = $post['to_price'];

            $shipping_arr= json_decode($post['all_country_shipping'],true);
            $shipping=[];
            foreach($shipping_arr as $arr)
            {
                if(@$arr['use_product_shipping']==1)
                {
                    $arr['shipping_price']='Use Product Shipping Price';
                    $arr['use_product_shipping']=1;
                }
                $tmp['ProductCountryShipping']=$arr;
                $shipping[] = $tmp;
            }
            $data['all_country_shipping']= json_encode($shipping);

            $goodsImp=new \app\goods\service\GoodsImport();
            $data['transport_property'] =$goodsImp->getTransportProperty($post['transport_property']);


            $model=new WishExpressTemplate();
            if ($where) {
                $data['update_time'] = time();
                $data['updater_id'] = $uid;
                $result =  $model->isUpdate(true)->save($data, $where);
            } else {
                $data['create_time'] = time();
                $data['creator_id'] = $uid;
                $result = $model->isUpdate(false)->save($data);
            }

            if ($result)
            {
                return ['message'=>'保存成功！'];
            } else {
                return ['message'=>'保存失败！'];
            }



        } catch(\Exception $e)
        {
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 批量删除物流模版
     * @param $where
     * @param $user_id
     * @return int
     */
    public function deleteExpressTemplate($where,$user_id)
    {
        Db::startTrans();
        try{
            $num=WishExpressTemplate::destroy($where);
            Db::commit();
            return $num;
        }catch (PDOException $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }catch (DbException $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }catch (Exception $exp){
            Db::rollback();
            throw new JsonErrorException("F:{$exp->getFile()};L:{$exp->getLine()};M:{$exp->getMessage()}");
        }
    }

    /**
     * 匹配对应的模版
     * @param $attr
     * @param $from_price
     * @param $to_price
     */
    public function likeExpress($transport_property,$price )
    {
        if(intval($transport_property)==0 || @floatVal($price)==0)
        {
            return null;
        }

        $where = [];
        $where['from_price']=['<=',$price];
        $where['to_price']=['>=',$price];
        $where['transport_property']=['eq',$transport_property];
        $data=(new WishExpressTemplate())->where($where)
            ->field(['all_country_shipping'])
            ->find();
        //echo WishExpressTemplate::getLastSql();
        if ($data)
        {
            return json_decode($data->all_country_shipping,true);
        } else {
            return null;
        }

    }

    /**
     * 物流模版列表
     * @param $param
     * @param $page
     * @param $pageSize
     * @param $fields
     * @return array
     */
    public function searchExpressTemplate($param, $page, $pageSize, $fields)
    {

        $where = [];

        //$join = [];
        if(isset($param['transport_property']) && $param['transport_property'])
        {
            $goodsImp=new \app\goods\service\GoodsImport();
            $val =$goodsImp->getTransportProperty($param['transport_property']);
            $where['e.transport_property']=['eq',$val];
        }

        if (@floatVal($param['from_price'])>0 && @floatVal($param['to_price'])>0)
        {
            $where['e.from_price']=['>=',$param['from_price']];
            $where['e.to_price']=['<=',$param['to_price']];
        }
        if (@floatVal($param['from_price'])==0 && @floatVal($param['to_price'])>0)
        {

            $where['e.to_price']=['<=',$param['to_price']];
        }
        if (@floatVal($param['from_price'])>0 && @floatVal($param['to_price'])==0)
        {
            $where['e.from_price']=['>=',$param['from_price']];
        }

        if(isset($param['creator_name']) && $param['creator_name'])
        {
            //$where['creator_id']=['eq',$param['creator_id']];
            $where['u.realname']=['eq',$param['creator_name']];
        }

        $order_str = 'e.id DESC';
        if(isset($param['order_type'])&&!empty($param['order_type'])){
            $sort = $param['order_sort']?$param['order_sort']:'desc';
            switch ($param['order_type']){
                case 'create_at':
                    $order_str = 'e.create_time '.$sort;
                    break;
            }
        }

        $model=new WishExpressTemplate();
        $count=$model->alias('e')->where($where)
            ->join('user u','e.creator_id=u.id','LEFT')
            ->count('e.id');

        $fields='e.*,u.realname AS creator_name';
        $data=$model->alias('e')->where($where)
            ->join('user u','e.creator_id=u.id','LEFT')
            ->field($fields)
            ->order($order_str)
            ->page($page,$pageSize)
            ->select();
        //echo $model::getLastSql();


        $goodsHelp=new \app\goods\service\GoodsHelp();
        foreach ($data as $k => &$d) {
            $d['all_country_shipping']=json_decode($d['all_country_shipping'],true);
            $d['transport_property']=$goodsHelp->getProTransPropertiesTxt($d['transport_property']);
            $d['from_price']=vsprintf("%1\$.2f",$d['from_price']);
            $d['to_price']=vsprintf("%1\$.2f",$d['to_price']);

            //$user = User::where('id',$d['creator_id'])->field('realname')->find();
            //$d['creator_name'] = $user?$user->realname:'';
        }

        return ['data' => $data, 'count' => $count, 'page' => $page, 'pageSize' => $pageSize];


    }



    public function getExpressTemplate($id)
    {

        $where = [];
        $where['id']=['=',$id];

        $data=(new WishExpressTemplate())->where($where)
            ->field(['*'])
            ->find();
        //echo WishExpressTemplate::getLastSql();
        $goodsHelp=new \app\goods\service\GoodsHelp();
        if ($data)
        {
            $data->all_country_shipping=json_decode($data->all_country_shipping,true);
            $data->transport_property=$goodsHelp->getProTransPropertiesTxt($data->transport_property);
            $data->from_price=vsprintf("%1\$.2f",$data->from_price);
            $data->to_price=vsprintf("%1\$.2f",$data->to_price);
            $user = User::where('id',$data->creator_id)->field('realname')->find();
            $data->creator_name = $user?$user->realname:'';

            return $data;

        } else {
            return null;
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
            $wh['publish_status'] = 1;
            $salesmenIds = WishWaitUploadProduct::where($wh)->column('uid');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }


    /**
     * 设置虚拟仓发货
     * @param $data
     * @return bool
     */
    public static function setListingVirtualSend($data)
    {
        try {
            $wh = [
                'sku' => $data['channel_sku'],
            ];
            $pid = WishWaitUploadProductVariant::where($wh)->value('pid');
            if ($pid) {
                WishWaitUploadProduct::update(['is_virtual_send' => $data['is_virtual_send']], ['id' => $pid]);
                $accountId = WishWaitUploadProduct::where('id',$pid)->value('account_id');
                $skus = WishWaitUploadProductVariant::where('pid',$pid)->column('sku');
                //同时修改映射表里面的其他变体的虚拟仓发货标志
                $condition = [
                    'channel_id' => 3,
                    'account_id' => $accountId,
                    'goods_id' => $data['goods_id'],
                    'channel_sku' => ['in',$skus]
                ];
                GoodsSkuMap::update(['is_virtual_send'=>$data['is_virtual_send']],$condition);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function goodsTortInfo($goods_id)
    {
        //1.eBay平台,2.亚马逊平台,3.Wish平台,4.速卖通平台,5.CD平台,6.Lazada平台,7.Joom平台,8.MyMall平台,9.Shopee
        $channel_config = [1, 2, 3, 4, 5, 6, 7, 8, 9];

        $channelModel = new Channel();
        $channelList = $channelModel->field('id as channel, name')->whereIn('id', $channel_config)->select();

        $channelList = array_column($channelList, 'name', 'channel');

        $goods_tort = (new GoodsHelpService)->getGoodsTortDescriptionByGoodsId($goods_id);

        if ($goods_tort) {
            $goods_tort = $goods_tort[$goods_id];

            $ebayAccount = new EbayAccount();
            $amazonAccount = new AmazonAccount();
            $wishAccount = new WishAccount();
            $aliAccount = new AliexpressAccount();

            foreach ($goods_tort as $key => $val) {
                $goods_tort[$key]['channel'] = isset($channelList[$val['channel_id']]) ? $channelList[$val['channel_id']] : '';

                $account_id = $val['account_id'];
                $where = ['id' => $account_id];

                $account_name = '';
                switch ($val['channel_id']) {
                    case 1://eBay平台
                        $account_name = $ebayAccount->field('account_name')->where($where)->value('account_name');
                        break;
                    case 2://亚马逊平台
                        $account_name = $amazonAccount->field('account_name')->where($where)->value('account_name');
                        break;

                    case 3://Wish平台
                        $account_name = $wishAccount->field('account_name')->where($where)->value('account_name');
                        break;

                    case 4://速卖通平台
                        $account_name = $aliAccount->field('account_name')->where($where)->value('account_name');
                        break;

                    case 5://CD平台

                        break;
                    case 6://Lazada平台

                        break;
                    case 7://Joom平台

                        break;

                    case 8://MyMall平台

                        break;

                    case 9://Shopee

                        break;
                }

                $goods_tort[$key]['account_name'] = (string)$account_name;
            }
        }

        return $goods_tort;
    }
    /**
     * 根据商品id获取刊登过该商品的销售员
     * @return array
     */
    public static function getSalesmenByGoodsId($goodsId)
    {
        try {
            $wh['goods_id'] = $goodsId;
            $wh['publish_status'] = 1;
            $salesmenIds = WishWaitUploadProduct::distinct(true)->where($wh)->column('uid');
            return $salesmenIds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取平台所有账号的 昨天刊登数量，昨天在线listing数量，昨天在线ASIN数量
     * @return array
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPublishDaily(array $accountIds)
    {
        set_time_limit(0);
        $dateline = date("Y-m-d",strtotime("-1 day"));
        $startTime = strtotime($dateline.' 00:00:00');
        $endTime = $startTime+86400;

        $accountIds = WishAccount::whereIn('id',$accountIds)->column('id');//过滤有效id

        $sellerWhere = [
            'channel_id' => $this->channelId,
            'account_id' => ['IN',$accountIds]
            ];
        $sellers = ChannelUserAccountMap::where($sellerWhere)->distinct('account_id')->column('seller_id','account_id');

        $spuWhere= [
            'date_uploaded' => ['LT',$endTime],
            'publish_status' => 1,
            'review_status' => ['IN',[1,3]],
            'accountid' => ['IN',$accountIds]
        ];
        $published = WishWaitUploadProduct::where('date_uploaded','EGT',$startTime)->where($spuWhere)->group('accountid')->column('COUNT(id)','accountid');
        $onlineSpu = WishWaitUploadProduct::where($spuWhere)->group('accountid')->column('COUNT(id)','accountid');

        $skuWhere = [
            'a.date_uploaded' => ['LT',$endTime],
            'a.publish_status' => 1,
            'a.review_status' => ['IN',[1,3]],
            'a.accountid' => ['IN',$accountIds],
            'b.enabled' => 1,
        ];
        $onlineSku = WishWaitUploadProduct::alias('a')->join('wish_wait_upload_product_variant b','b.pid=a.id','LEFT')
            ->where($skuWhere)->group('a.accountid')->column('COUNT(b.vid)','accountid');

        $result = [];
        foreach ($accountIds as $k=>$accountId){
            $result[$k]['account_id'] = $accountId;
            $result[$k]['dateline'] = $dateline;
            $result[$k]['site'] = '';
            $result[$k]["channel_id"] = $this->channelId;
            $result[$k]["seller_id"] = isset($sellers[$accountId]) ? $sellers[$accountId] :'0';
            $result[$k]['publish_quantity'] = isset($published[$accountId]) ? $published[$accountId] :'0';
            $result[$k]['online_listing_quantity'] = isset($onlineSpu[$accountId]) ? $onlineSpu[$accountId] :'0';
            $result[$k]['online_asin_quantity'] = isset($onlineSku[$accountId]) ? $onlineSku[$accountId] :'0';

        }

        return $result;

    }

}
