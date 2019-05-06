<?php

namespace app\goods\service;

use app\common\exception\GoodsSkuMapException;
use app\common\exception\JsonErrorException;
use app\common\model\amazon\AmazonPublishProductDetail;
use app\common\model\Category;
use app\common\model\Goods;
use app\common\model\GoodsSku;
use app\common\model\GoodsSkuAlias;
use app\common\model\GoodsSkuMap;
use app\goods\service\GoodsSkuMapLog as GoodsSkuMapLogService;
use app\common\service\Common as CommonService;
use app\common\cache\Cache;
use app\order\service\OrderService;
use app\warehouse\service\WarehouseGoods;
use think\Db;
use think\Exception;
use app\common\service\ImportExport;
use app\index\service\ChannelAccount as ChannelAccountService;
use app\common\service\ChannelAccountConst;
use app\goods\service\GoodsSkuAlias as GoodsSkuAliasService;
use app\common\service\Excel;
use app\common\service\UniqueQueuer;
use app\publish\service\WishHelper;
use app\publish\helper\ebay\EbayPublish;
use app\publish\service\ExpressHelper;

/**
 * Created by PhpStorm.
 * User: phill
 * Date: 2017/6/14
 * Time: 20:00
 */
class GoodsSkuMapService
{
    protected $goodsSkuMapModel = null;

    public function __construct()
    {
        if (is_null($this->goodsSkuMapModel)) {
            $this->goodsSkuMapModel = new GoodsSkuMap();
        }
    }

    /** 列表
     * @param $params
     * @param $page
     * @param $pageSize
     * @return array
     * @throws \think\Exception
     */
    public function mapList($params, $page, $pageSize)
    {
        $where = [];
        if (isset($params['snType']) && isset($params['snText']) && $params['snText']!==''&& $params['snText']!=='[]') {
            $snText = $params['snText'];
            $aSnText = json_decode($snText, true);
            switch ($params['snType']) {
                case 'channel_sku':
                    if (is_array($aSnText)) {
                        $where['channel_sku'] = ['in', $aSnText];
                    } else {
                        $where['channel_sku'] = ['like', $snText . '%'];
                    }
                    break;
                case 'sku':
                    if (is_array($aSnText)) {
                        $sku_id = GoodsSkuAliasService::getSkuIdByAlias($aSnText);//别名 （不支持模糊匹配）
                        $where['sku_id'] = ['in', $sku_id];
                    } else {
                        $sku_id = GoodsSkuAliasService::getSkuIdByAlias($snText);//别名 （不支持模糊匹配）
                        if ($sku_id) {
                            $where['sku_id'] = ['=', $sku_id];
                        } else {
                            $where['sku_code'] = ['like', $snText . '%'];
                        }
                    }
                    break;
                default:
                    break;
            }
        }
        //平台
        if (isset($params['channel_id']) && !empty($params['channel_id'])) {
            if (is_numeric($params['channel_id'])) {
                $where['channel_id'] = ['=', $params['channel_id']];
            }
        }
        //账号
        if (isset($params['account_id']) && !empty($params['account_id'])) {
            if (is_numeric($params['account_id'])) {
                $where['account_id'] = ['=', $params['account_id']];
            }
        }
        //分类
        if (isset($params['category_id']) && $params['category_id'] != '') {
            if (is_numeric($params['category_id'])) {
                $goods_ids = [];
                $category_ids = [$params['category_id']];
                //求出分类
                $category = Cache::store('category')->getCategoryTree();
                if ($category[$params['category_id']]) {
                    array_merge($category_ids, $category[$params['category_id']]['child_ids']);
                }
                //查出所有的goods_id
                $goodsModel = new Goods();
                $goodsList = $goodsModel->field('id')->where('category_id', 'in', $category_ids)->select();
                if (!empty($goodsList)) {
                    foreach ($goodsList as $goods => $list) {
                        array_push($goods_ids, $list['id']);
                    }
                }
                $where['goods_id'] = ['in', $goods_ids];
            } else {
                throw new JsonErrorException('分类参数错误', 400);
            }
        }

        //更新人
        if (isset($params['update_user_id']) && !empty($params['update_user_id'])) {
            if (is_numeric($params['update_user_id'])) {
                $where['updater_id'] = ['=', $params['update_user_id']];
            }
        }
        if (isset($params['is_virtual_send'])) {
            if ($params['is_virtual_send'] == '0') {
                $where['is_virtual_send'] = ['=', 0];
            } else if ($params['is_virtual_send']) {
                $where['is_virtual_send'] = ['=', $params['is_virtual_send']];
            }
        }
        //时间
        $condition = timeCondition($params['date_b'], $params['date_e']);
        if (is_array($condition) && !empty($condition)) {
            $where['update_time'] = $condition;
        }
        $field = 'id,sku_code,channel_id as channel,account_id as account,channel_sku,quantity,updater_id as update_user,update_time,sku_code_quantity,is_virtual_send';
        $count = $this->goodsSkuMapModel->field($field)
            ->where($where)
            ->count();
        $goodsSkuList = $this->goodsSkuMapModel->field($field)
            ->where($where)
            ->page($page, $pageSize)
            ->select();
        $new_array = [];
        //账号
        $orderService = new OrderService();
        foreach ($goodsSkuList as $k => $v) {
            if ($v['channel'] == ChannelAccountConst::channel_Joom) {
                $account = Cache::store('JoomAccount')->getAccountById($v['account']);
                $v['account'] = $account['code'] ?? '';
            } else {
                $v['account'] = $orderService->getAccountName($v['channel'], $v['account']);
            }
            //渠道名称
            $v['channel'] = Cache::store('channel')->getChannelName($v['channel']);
            //更新人名称
            $userInfo = Cache::store('user')->getOneUser($v['update_user']);
            if (!empty($userInfo)) {
                $v['update_user'] = $userInfo['realname'];
            } else {
                $v['update_user'] = "";
            }
            $sku_code_quantity = json_decode($v['sku_code_quantity'], true);
            $sku = [];
            foreach ($sku_code_quantity as $key => $value) {
                array_push($sku, $value);
            }
            unset($v['sku_code_quantity']);
            $v['sku'] = $sku;
            array_push($new_array, $v);
        }
        $result = [
            'data' => $new_array,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }

    /**
     * 新增映射记录
     * @param $data
     * @return mixed
     * @throws Exception
     */
    public function add($data)
    {
        try {
            $data['sku'] = json_decode($data['sku'], true);
            $sku_code_quantity = [];
            $publishData = [];
            foreach ($data['sku'] as $key => $value) {
                $temp['sku_id'] = $value['sku_id'];
                $temp['quantity'] = $value['quantity'];
                //查出sku的名称
                $goodsSkuInfo = Cache::store('goods')->getSkuInfo($value['sku_id']);
                if (empty($goodsSkuInfo)) {
                    throw new JsonErrorException('该本地商品不存在！', 500);
                }
                $info = $this->isHas($data['account_id'], $data['channel_id'], $data['channel_sku']);
                if ($info) {
                    throw new JsonErrorException('该平台sk映射记录已存在');
                }
                $temp['sku_code'] = $goodsSkuInfo['sku'];
                $temp['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $data['sku_id'] = $temp['sku_id'];
                $data['sku_code'] = $temp['sku_code'];
                $data['quantity'] = $temp['quantity'];
                $sku_code_quantity[$value['sku_id']] = $temp;

                //为调用登刊系统api组装数据
                $element['channel_sku'] = $data['channel_sku'];
                $element['sku_id'] = $value['sku_id'];
                $element['is_virtual_send'] = $data['is_virtual_send']??0;
                $element['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $publishData[] = $element;
            }
            $data['sku_code_quantity'] = json_encode($sku_code_quantity);
            //获取操作人信息
            $user = CommonService::getUserInfo();
            if (!empty($user)) {
                $data['creator_id'] = $user['user_id'];
                $data['updater_id'] = $user['user_id'];
            }
            $data['create_time'] = time();
            $data['update_time'] = time();

            Db::startTrans();
            try {
                $this->goodsSkuMapModel->allowField(true)->isUpdate(false)->save($data);
                //记录操作日志
                (new GoodsSkuMapLogService())->add($data['channel_sku'])->save($this->goodsSkuMapModel->id, $user['user_id'], $user['realname']);
                if ($data['channel_id'] == ChannelAccountConst::channel_wish) {
                    foreach ($publishData as $publish) {
                        WishHelper::setListingVirtualSend($publish);
                    }
                } elseif ($data['channel_id'] == ChannelAccountConst::channel_ebay) {
                    foreach ($publishData as $publish) {
                        EbayPublish::setListingVirtualSend($publish);
                    }
                } elseif ($data['channel_id'] == ChannelAccountConst::channel_aliExpress) {
                    foreach ($publishData as $publish) {
                        $aliExpressPublish['channel_sku'] = $publish['channel_sku'];
                        $aliExpressPublish['sku_id'] = $publish['sku_id'];
                        $aliExpressPublish['is_virtual_send'] = $publish['is_virtual_send'];
                        (new ExpressHelper())->aliVirtualSendSync($aliExpressPublish);
                    }
                }
                Db::commit();
            } catch (GoodsSkuMapException $e) {
                Db::rollback();
                throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
            }
            //调整fbaSKU关联关系
            /*if ($data['channel_id'] == ChannelAccountConst::channel_amazon && count($sku_code_quantity)==1) {
                $params = [
                    'type' => 'update',
                    'account_id' => $data['account_id'],
                    'third_sku' => $data['channel_sku'],
                    'sku_id' => $temp['sku_id'],
                ];
                (new UniqueQueuer(ChangeFbaWarehouseRelate::class))->push($params);
            }*/
            return $this->goodsSkuMapModel->id;
        } catch (GoodsSkuMapException $e) {
            throw new JsonErrorException($e->getMessage());
        } catch (JsonErrorException $e) {
            throw new JsonErrorException($e->getMessage());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /** 获取映射信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     * @throws GoodsSkuMapException
     * @throws \think\Exception
     */
    public function info($id)
    {
        try {
            $goodsSkuInfo = $this->goodsSkuMapModel->field('id,channel_id,account_id,channel_sku,sku_code_quantity,is_virtual_send')->where(['id' => $id])->find();
            if (!empty($goodsSkuInfo)) {
                //账号
                $accountData = Cache::store('account')->getAccountByChannel($goodsSkuInfo['channel_id']);
                if (isset($accountData[$goodsSkuInfo['account_id']])) {
                    $goodsSkuInfo['account'] = $accountData[$goodsSkuInfo['account_id']]['code'];
                }
                //渠道名称
                $goodsSkuInfo['channel'] = Cache::store('channel')->getChannelName($goodsSkuInfo['channel_id']);
                $skuList = json_decode($goodsSkuInfo['sku_code_quantity'], true);
                $sku_list = [];
                foreach ($skuList as $ku => $list) {
                    array_push($sku_list, $list);
                }
                $goodsSkuInfo['sku'] = $sku_list;
                unset($goodsSkuInfo['sku_code_quantity']);
            } else {
                $goodsSkuInfo = [];
            }
            return $goodsSkuInfo;
        } catch (GoodsSkuMapException $e) {
            throw new GoodsSkuMapException($e->getMessage() . $e->getFile() . $e->getLine());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 判断是否已存在
     * @param $account_id
     * @param $channel_id
     * @param $channel_sku
     * @return bool
     */
    public function isHas($account_id, $channel_id, $channel_sku)
    {
        $where['account_id'] = ['=', $account_id];
        $where['channel_id'] = ['=', $channel_id];
        $where['channel_sku'] = ['=', $channel_sku];
        $result = $this->goodsSkuMapModel->where($where)->find();
        if (empty($result)) {
            return false;
        }
        return true;
    }

    /**
     * 判断是否已存在
     * @param $account_id
     * @param $channel_id
     * @param $channel_sku
     * @param $sku_id
     * @return bool
     */
    public function isExist($account_id, $channel_id, $channel_sku, $sku_id)
    {
        $where['account_id'] = ['=', $account_id];
        $where['channel_id'] = ['=', $channel_id];
        $where['channel_sku'] = ['=', $channel_sku];
        $where['sku_id'] = ['=', $sku_id];
        $result = $this->goodsSkuMapModel->where($where)->find();
        if (empty($result)) {
            return false;
        }
        return true;
    }

    /** 批量删除
     * @param $data
     */
    public function batch($data)
    {
        if (empty($data)) {
            throw new JsonErrorException('请至少选择一条记录', 400);
        }
        if (!is_array($data)) {
            $data = json_decode($data, true);
        }
        Db::startTrans();
        try {
            foreach ($data as $k => $v) {
                $info = $this->goodsSkuMapModel->where(['id' => $v])->find();
                if (!$info) {
                    throw new JsonErrorException('该记录不存在', 400);
                }

                $this->goodsSkuMapModel->where(['id' => $v])->delete();
                //亚马逊库存取消关联
                /*$sku_code_quantity = json_decode($info['sku_code_quantity'], true);
                $sku_ids = array_keys($sku_code_quantity);
                if ($info['channel_id'] == ChannelAccountConst::channel_amazon && count($sku_ids)==1) {
                    $params = [
                        'type' => 'delete',
                        'account_id' => $info['account_id'],
                        'third_sku' => $info['channel_sku'],
                        'sku_id' => $sku_ids[0],
                    ];
                    (new UniqueQueuer(ChangeFbaWarehouseRelate::class))->push($params);
                }*/
            }
            Db::commit();
        } catch (GoodsSkuMapException $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /** 更新
     * @param $data
     * @param $id
     */
    public function update($data, $id)
    {
        try {
            //判断名称是否重复
            if ($this->goodsSkuMapModel->isRepeat($data['account_id'], $data['channel_id'], $data['channel_sku'],
                $id)
            ) {
                throw new JsonErrorException('该平台channel_sku已存在', 500);
            }
            $data['sku'] = json_decode($data['sku'], true);
            $sku_code_quantity = [];
            $publishData = [];
            foreach ($data['sku'] as $key => $value) {
                $temp['sku_id'] = $value['sku_id'];
                $temp['quantity'] = $value['quantity'];
                //查出sku的名称
                $goodsSkuInfo = Cache::store('goods')->getSkuInfo($value['sku_id']);
                if (empty($goodsSkuInfo)) {
                    throw new JsonErrorException('该本地商品不存在！', 500);
                }
                $temp['sku_code'] = $goodsSkuInfo['sku'];
                $temp['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $sku_code_quantity[$value['sku_id']] = $temp;
                //为调用登刊系统api组装数据
                $element['channel_sku'] = $data['channel_sku'];
                $element['sku_id'] = $value['sku_id'];
                $element['is_virtual_send'] = $data['is_virtual_send'];
                $element['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $publishData[] = $element;
            }
            $data['sku_code_quantity'] = json_encode($sku_code_quantity);
            //获取操作人信息
            $user = CommonService::getUserInfo();
            if (!empty($user)) {
                $data['updater_id'] = $user['user_id'];
            }
            $data['update_time'] = time();

            Db::startTrans();
            try {
                $row = GoodsSkuMap::get($id);
                $oldRow = GoodsSkuMap::get($id)->toArray();
                $oldChannelSku = $row->channel_sku;
                if ($row->is_virtual_send != $data['is_virtual_send']) {
                    if ($data['channel_id'] == ChannelAccountConst::channel_wish) {
                        foreach ($publishData as $publish) {
                            WishHelper::setListingVirtualSend($publish);
                        }
                    } elseif ($data['channel_id'] == ChannelAccountConst::channel_ebay) {
                        foreach ($publishData as $publish) {
                            EbayPublish::setListingVirtualSend($publish);
                        }
                    } elseif ($data['channel_id'] == ChannelAccountConst::channel_aliExpress) {
                        foreach ($publishData as $publish) {
                            $aliExpressPublish['channel_sku'] = $publish['channel_sku'];
                            $aliExpressPublish['sku_id'] = $publish['sku_id'];
                            $aliExpressPublish['is_virtual_send'] = $publish['is_virtual_send'];
                            (new ExpressHelper())->aliVirtualSendSync($aliExpressPublish);
                        }
                    }
                }
                $row->allowField(true)->isUpdate(true)->save($data, ['id' => $id]);
                //记录操作日志
                (new GoodsSkuMapLogService())->mdf($row['channel_sku'], $oldRow, $data)->save($row->id, $user['user_id'], $user['realname']);
                Db::commit();
            } catch (GoodsSkuMapException $e) {
                Db::rollback();
                throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
            }

            //调整fbaSKU关联关系
            /*if ($data['channel_id'] == ChannelAccountConst::channel_amazon && count($sku_code_quantity)==1) {
                $params = [
                    'type' => 'update',
                    'account_id' => $data['account_id'],
                    'third_sku' => $data['channel_sku'],
                    'sku_id' => $temp['sku_id'],
                ];
                (new UniqueQueuer(ChangeFbaWarehouseRelate::class))->push($params);
            }*/
        } catch (GoodsSkuMapException $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /** 是否已经关联
     * @param $channel_sku 【渠道sku】
     * @param $sku_id 【本地sku_id】
     * @param $channel_id 【渠道id】
     * @param $account_id 【账号id】
     * @return bool
     */
    public function isMap($channel_sku, $sku_id, $channel_id, $account_id)
    {
        //匹配本地产品
        $sku_map = $this->goodsSkuMapModel->where([
            'channel_id' => $channel_id,
            'account_id' => $account_id,
            'channel_sku' => $channel_sku,
            'sku_id' => $sku_id
        ])->find();
        if (!empty($sku_map)) {
            $result['is_map'] = 1;
        } else {
            $result['is_map'] = 0;
        }
        return $result;
    }

    /**
     * 判断新增的sku是否为关联的sku
     * @param $channel_sku
     * @param $sku_id
     * @param $channel_id
     * @param $account_id
     * @return bool
     * @throws Exception
     */
    public function isNoRelation($channel_sku, $sku_id, $channel_id, $account_id)
    {
        try {
            //匹配本地产品
            $sku_map = $this->goodsSkuMapModel->where([
                'channel_id' => $channel_id,
                'account_id' => $account_id,
                'channel_sku' => $channel_sku
            ])->find();
            if (empty($sku_map)) {
                //查看别名
                $goodsSkuAliasModel = new GoodsSkuAlias();
                $sku_map = $goodsSkuAliasModel->field(true)->where(['alias' => $channel_sku])->find();
            }
            if (empty($sku_map)) {
                //查看订单表
                $goodsSkuModel = new GoodsSku();
                $sku_map = $goodsSkuModel->field(true)->where(['sku' => $channel_sku])->find();
                if (!empty($sku_map)) {
                    $sku_map['sku_id'] = $sku_map['id'];
                }
            }
            if (!empty($sku_map)) {
                if ($sku_map['sku_id'] == $sku_id) {
                    return true;
                } else {
                    return false;
                }
            }
            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 通过渠道SKU，账号信息超找关联的系统SKU信息
     * @param $channel_sku
     * @param $channel_id
     * @param $account_id
     * @return int|mixed
     */
    public function getSkuInfo($channel_sku, $channel_id, $account_id)
    {
        $sku_id = 0;
        //匹配本地产品
        $sku_map = $this->goodsSkuMapModel->field('sku_id')->where([
            'channel_id' => $channel_id,
            'account_id' => $account_id,
            'channel_sku' => $channel_sku,
        ])->find();
        if (!empty($sku_map)) {
            $sku_id = $sku_map['sku_id'];
        }
        return $sku_id;
    }

    /**
     * 生成sku
     * @author joy
     * @param string $sku sku
     * @param int $len 生成sku长度
     * @param string $separator 分隔符
     * @param string $charlist 字符集
     * @return string
     */
    public function createSku($sku, $separator = '|', $len = 20, $charlist = '0-9A-Z')
    {
        $skuArray = explode('|', $sku);

        if (count($skuArray) > 1) {
            $sku = $skuArray[0];
        }
        //生成随机码
        $timeHex = str_split(strupper(dechex(time())),1);
        $randChars = \Nette\Utils\Random::generate(2,$charlist);
        $randChars = str_split($randChars,1);
        $length = count($timeHex) + count($randChars);
        $rand = '';
        for ($i=0; $i<$length; $i++) {
            $flag = rand(0,1);
            if ($flag) {
                $rand .= $timeHex ? array_shift($timeHex) : array_shift($randChars);
            } else {
                $rand .= $randChars ? array_shift($randChars) : array_shift($timeHex);
            }
        }

        $left = $len - mb_strlen($sku . $separator);
        if ($left > $length) {
            $rand .= \Nette\Utils\Random::generate($left-$length, $charlist);
        } else {
            $rand = substr($rand,0,$left);
        }

        if ($left > 0) {
            $sku_code = $sku . $separator . $rand;
        } else {
            $sku_code = $sku . $separator;
        }
        return $sku_code;
    }

    /**
     * 生成一条不存在表里面的SKU；
     * @author 冬
     * @param string $sku sku
     * @param int $len 生成sku长度
     * @param string $separator 分隔符
     * @param string $charlist 字符集
     * @return string
     */
    public function createSkuNotInTable($sku, $account_id = 0, $channel_id = 2, $separator = '|', $len = 20, $charlist = '0-9')
    {
        $sku_code = $this->createSku($sku, $separator, $len, $charlist);
        $count = GoodsSkuMap::where(['account_id' => $account_id, 'channel_id' => $channel_id, 'channel_sku' => $sku_code])->count();
        if ($count > 0) {
            return $this->createSkuNotInTable($sku, $account_id, $channel_id, $separator, $len, $charlist);
        }
        return $sku_code;
    }

    /** 插入数据
     * @param $data =['sku_code'=>'sku编码','channel_id'=>'平台id','account_id'=>'账号id'];
     * @param  $uid [用户id]
     * @param string $separator
     * @param int $len
     * @param string $charlist
     * @return array
     */
    public function addSku($data, $uid = 1, $separator = '|', $len = 50, $charlist = '0-9')
    {
        //如果sku_code为空则返回空
        if (!isset($data['sku_code']) || empty($data['sku_code'])) {
            return ['result' => false, 'sku_code' => '', 'message' => 'sku为空'];
        }

        //分级sku长度来动态决定sku的长度
        if (strlen($data['sku_code']) < 20) {
            $len = 20;
        } elseif (strlen($data['sku_code']) < 30) {
            $len = 30;
        } elseif (strlen($data['sku_code']) < 40) {
            $len = 40;
        } elseif (strlen($data['sku_code']) < 50) {
            $len = 50;
        } elseif (strlen($data['sku_code']) < 10) {
            $len = 10;
        }

        if ($len > 50) {
            return ['result' => false, 'sku_code' => '', 'message' => 'sku长度溢出'];
        }

        if (!isset($data['sku_id'])) {
            $skuModel = new GoodsSku();

            $skuInfo = $skuModel->where(['sku' => $data['sku_code']])->find();

            if ($skuInfo) {
                $data['sku_id'] = $skuInfo['id'];
            } elseif ($goods_sku_map_data = $this->goodsSkuMapModel->get(['channel_sku' => $data['sku_code']])) {
                if (is_object($goods_sku_map_data)) {
                    $goods_sku_map_data = $goods_sku_map_data->toArray();
                }
                $channel_sku = $this->createSku($data['sku_code'], $separator, $len, $charlist);
                unset($goods_sku_map_data['id']);
                $goods_sku_map_data['channel_sku'] = $channel_sku;
                $goods_sku_map_data['channel_id'] = $data['channel_id'];
                $goods_sku_map_data['account_id'] = $data['account_id'];
                $goods_sku_map_data['create_time'] = time();
                isset($data['is_virtual_send']) && $goods_sku_map_data['is_virtual_send'] = $data['is_virtual_send'];
                $inserInfo = $this->goodsSkuMapModel->insert($goods_sku_map_data); //插入新记录
                if (is_numeric($inserInfo)) //如果是一个数字,则插入数据成功
                {
                    return ['result' => true, 'sku_code' => $channel_sku];
                } else {
                    return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                }
            } else {
                $channel_sku = $this->createSku($data['sku_code'], $separator, $len, $charlist);
                return ['result' => true, 'sku_code' => $channel_sku];
            }
        }


        if (isset($data['sku_code']) && isset($data['channel_id']) && isset($data['account_id'])) {

            $channel_sku = $this->createSku($data['sku_code'], $separator, $len, $charlist);
            if ($channel_sku) {
                $where = [
                    'sku_code' => ['=', $data['sku_code']],
                    'channel_id' => ['=', $data['channel_id']],
                    'account_id' => ['=', $data['account_id']],
                    'channel_sku' => ['=', $channel_sku],
                ];

                if ($res = $this->goodsSkuMapModel->isExists($where)) //如果已经存在
                {
                    $data['update_time'] = time();
                    $this->addSku($data);
                } else {
                    $sku_code_quantity[$data['sku_id']] = [
                        'sku_id' => $data['sku_id'],
                        'quantity' => 1,
                        'sku_code' => $data['sku_code']
                    ];
                    $data['channel_sku'] = $channel_sku;
                    $data['quantity'] = 1;
                    $data['creator_id'] = $uid;
                    $data['sku_code_quantity'] = json_encode($sku_code_quantity);
                    $data['create_time'] = time();
                    //$data['category'] = 'null';
                    $inserInfo = $this->goodsSkuMapModel->insert($data); //插入新记录
                    if (is_numeric($inserInfo)) //如果是一个数字
                    {
                        return ['result' => true, 'sku_code' => $channel_sku];
                    } else {
                        //throw new \app\common\exception\JsonErrorException($inserInfo);
                        return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                    }
                }
            } else {
                return ['result' => false, 'message' => '长度溢出'];
            }

        } else {
            return ['result' => false, 'message' => '数据格式错误'];
        }

    }

    /** 插入用户自定义SKU数据
     * @param $data =['sku_code'=>'sku编码', 'publish_sku' => '用户自定义SKU编码','channel_id'=>'平台id','account_id'=>'账号id'];
     * @param  $uid [用户id]
     * @param string $separator
     * @param int $len
     * @param string $charlist
     * @return array
     */
    public function addUserSku($data, $uid = 1)
    {
        //如果sku_code为空则返回空
        if (empty($data['sku_code']) || empty($data['publish_sku'])) {
            return ['result' => false, 'sku_code' => '', 'message' => 'sku或自定义sku为空'];
        }

        if (strlen($data['publish_sku']) > 50 || strlen($data['publish_sku']) < 20) {
            return ['result' => false, 'sku_code' => '', 'message' => 'sku长度不正确（20-50）'];
        }

        //先查找别的帐号是否有相同的,别的帐号有些SKU，则报错，本帐号有此SKU，则原样返回；
        $goods_sku_map_data = $this->goodsSkuMapModel->where(['channel_sku' => $data['publish_sku'], 'channel_id' => $data['channel_id']])->select();
        if (count($goods_sku_map_data) > 1) {
            unset($data['publish_sku']);
            return $this->addSku($data);
        } else if (count($goods_sku_map_data) == 1 && $goods_sku_map_data[0]['account_id'] == $data['account_id']) {
            if (AmazonPublishProductDetail::where(['publish_sku' => $data['publish_sku'], 'upload_product' => 1])->count()) {
                unset($data['publish_sku']);
                return $this->addSku($data);
            }
            return ['result' => true, 'sku_code' => $data['publish_sku']];
        } else if (count($goods_sku_map_data) == 1 && $goods_sku_map_data[0]['account_id'] != $data['account_id']) {
            unset($data['publish_sku']);
            return $this->addSku($data);
        }

        if (!isset($data['sku_id'])) {
            $skuModel = new GoodsSku();

            $skuInfo = $skuModel->where(['sku' => $data['sku_code']])->find();
            if ($skuInfo) {
                $data['sku_id'] = $skuInfo['id'];
                $data['goods_id'] = $skuInfo['goods_id'] ?? 0;
            } elseif ($goods_sku_map_data = $this->goodsSkuMapModel->where(['channel_sku' => $data['sku_code']])->find()) {
                if (is_object($goods_sku_map_data)) {
                    $goods_sku_map_data = $goods_sku_map_data->toArray();
                }
                $channel_sku = $data['publish_sku'];
                unset($goods_sku_map_data['id']);
                $goods_sku_map_data['channel_sku'] = $channel_sku;
                $goods_sku_map_data['channel_id'] = $data['channel_id'];
                $goods_sku_map_data['account_id'] = $data['account_id'];
                $goods_sku_map_data['create_time'] = time();
                $inserInfo = $this->goodsSkuMapModel->insert($goods_sku_map_data); //插入新记录
                if (is_numeric($inserInfo)) //如果是一个数字,则插入数据成功
                {
                    return ['result' => true, 'sku_code' => $channel_sku];
                } else {
                    return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                }
            } else {
                return ['result' => true, 'sku_code' => $data['publish_sku']];
            }
        }


        if (isset($data['sku_code']) && isset($data['channel_id']) && isset($data['account_id'])) {

            $channel_sku = $data['publish_sku'];
            unset($data['publish_sku']);
            $where = [
                'sku_code' => ['=', $data['sku_code']],
                'channel_id' => ['=', $data['channel_id']],
                'account_id' => ['=', $data['account_id']],
                'channel_sku' => ['=', $channel_sku],
            ];

            if ($res = $this->goodsSkuMapModel->isExists($where)) //如果已经存在
            {
                $data['update_time'] = time();
                return $this->addSku($data);
            } else {
                $sku_code_quantity[$data['sku_id']] = [
                    'sku_id' => $data['sku_id'],
                    'quantity' => 1,
                    'sku_code' => $data['sku_code']
                ];
                $data['channel_sku'] = $channel_sku;
                $data['quantity'] = 1;
                $data['creator_id'] = $uid;
                $data['sku_code_quantity'] = json_encode($sku_code_quantity);
                $data['create_time'] = time();
                //$data['category'] = 'null';
                $inserInfo = $this->goodsSkuMapModel->insert($data); //插入新记录
                if (is_numeric($inserInfo)) //如果是一个数字
                {
                    return ['result' => true, 'sku_code' => $channel_sku];
                } else {
                    //throw new \app\common\exception\JsonErrorException($inserInfo);
                    return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                }
            }

        } else {
            return ['result' => false, 'message' => '数据格式错误'];
        }

    }

    /**
     * 生成捆绑销售商品sku
     * @param $data $data='ZY-TYN-W-001*3|NVYK0299-BU-L*4|.....';
     * @return string
     */
    public function createSkuCodeWithQuantity($data)
    {
        $arr = explode('|', $data);
        $sku_code = '';
        foreach ($arr as $k => $v) {
            list($sku, $quantity) = explode('*', $v);
            if (strlen($sku_code) < 50) {
                $sku_code = '_' . $sku . $sku_code;
            }
        }
        return substr($sku_code, 1);
    }

    /**
     * 生成捆绑销售商品sku
     * @param $data $data='ZY-TYN-W-001*3|NVYK0299-BU-L*4|.....';
     * @return string
     */
    public function createCombineSkuCode($data)
    {
        $arr = explode('|', $data);
        $sku_code = '';
        foreach ($arr as $k => $v) {
            list($sku, $quantity) = explode('*', $v);
            if (strlen($sku) < 50) {
                $sku_code = '_' . $sku . $sku_code;
            }
        }
        return substr($sku_code, 1);
    }

    /**
     * 生成捆绑销售商品sku,并写入数据库
     * @param  $data =['sku_code'=>'sku编码','channel_id'=>'平台id','account_id'=>'账号id','combine_sku'=>'ZY-TYN-W-001*3|NVYK0299-BU-L*4|.....'];
     * @param $data
     * @param int $uid
     * @param string $separator
     * @param int $len
     * @param string $charaList
     * @return array
     * @throws Exception
     */
    public function addSkuCodeWithQuantity($data, $uid = 1, $separator = '|', $len = 20, $charaList = '0-9')
    {
        try {

            $arr = explode('|', $data['combine_sku']);

            $sku_code_quantity = [];
            $skuModel = new GoodsSku;
            foreach ($arr as $k => $v) {//捆绑的SKU
                list($sku, $quantity) = explode('*', $v);
                $skuInfo = $skuModel->where(['sku' => $sku])->find();
                if ($skuInfo) {
                    $sku_id = $skuInfo['id'];
                    $goods_id = $skuInfo['goods_id'];
                } else {
                    return ['result' => false, 'message' => 'sku不存在'];
                }
                $sku_code_quantity[$sku_id] = [
                    'sku_id' => $sku_id,
                    'quantity' => $quantity,
                    'sku_code' => $sku,
                ];
            }

            if (isset($data['sku_code']) && isset($data['channel_id']) && isset($data['account_id'])) {
                $where = [
                    'channel_id' => ['=', $data['channel_id']],
                    'account_id' => ['=', $data['account_id']],
                    'channel_sku' => ['=', $data['sku_code']],
                ];
                if ($res = $this->goodsSkuMapModel->where($where)->find()) {
                    $res = is_object($res) ? $res->toArray() : $res;
                    $res['goods_id'] = $goods_id;
                    $res['creator_id'] = $uid;
                    $res['sku_code_quantity'] = json_encode($sku_code_quantity);
                    isset($data['is_virtual_send']) && $res['is_virtual_send'] = $data['is_virtual_send'];
                    $this->goodsSkuMapModel->update($res, ['id' => $res['id']]);
                    return ['result' => true, 'sku_code' => $data['sku_code']];
                } else {
                    $channel_sku = $this->createSku($data['sku_code'], $separator, $len, $charaList);
                    if ($channel_sku) {
                        $where = [
                            'sku_code' => ['=', $data['sku_code']],
                            'channel_id' => ['=', $data['channel_id']],
                            'account_id' => ['=', $data['account_id']],
                            'channel_sku' => ['=', $channel_sku],
                            'sku_code_quantity' => ['=', json_encode($sku_code_quantity)],
                        ];
                        if ($res = $this->goodsSkuMapModel->isExists($where)) //如果已经存在
                        {
                            $data['update_time'] = time();
                            return $this->addSku($data);
                        } else {
                            $save['sku_id'] = $sku_id;
                            $save['goods_id'] = $goods_id;
                            $save['sku_code'] = $data['sku_code'];
                            $save['channel_sku'] = $channel_sku;
                            $save['quantity'] = 1;
                            $save['channel_id'] = $data['channel_id'];
                            $save['account_id'] = $data['account_id'];
                            $save['creator_id'] = $uid;
                            $save['sku_code_quantity'] = json_encode($sku_code_quantity);
                            $save['create_time'] = time();
                            isset($data['is_virtual_send']) && $save['is_virtual_send'] = $data['is_virtual_send'];
                            $insertInfo = $this->goodsSkuMapModel->insert($save); //插入新记录
                            if (is_numeric($insertInfo)) //如果是一个数字
                            {
                                return ['result' => true, 'sku_code' => $channel_sku];
                            } else {
                                return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                            }
                        }
                    } else {
                        return ['result' => false, 'message' => '长度溢出'];
                    }
                }
            } else {
                return ['result' => false, 'message' => 'sku不能为空'];
            }
        } catch (Exception $exp) {
            throw new Exception($exp->getMessage());
        }
    }

    /**
     * amazon式，可以自定义SKU，生成捆绑销售商品sku,并写入数据库
     * @param  $data =['sku_code'=>'sku编码','channel_sku'=>'用来生成，或已生成的平台渠道SKU','channel_id'=>'平台id','account_id'=>'账号id','combine_sku'=>'ZY-TYN-W-001*3|NVYK0299-BU-L*4|.....'];
     * @param $data
     * @param int $uid
     * @param string $separator
     * @param int $len
     * @param string $charaList
     * @return array
     * @throws Exception
     */
    public function amazonAddSkuCodeWithQuantity($data, $uid = 1, $separator = '|', $len = 20, $charaList = '0-9')
    {
        try {
            //1.先检查当前的sku_code 存不存在系统里面
            $skuModel = new GoodsSku;
            $skuInfo = $skuModel->where(['sku' => $data['sku_code']])->find();
            if ($skuInfo) {
                $sku_id = $skuInfo['id'];
                $goods_id = $skuInfo['goods_id'];
            } else {
                return ['result' => false, 'message' => 'sku不存在'];
            }

            //2.分隔出sku_code_quantity数据；
            $sku_quantity = 1;
            $arr = explode('|', $data['combine_sku']);
            $sku_code_quantity = [];
            foreach ($arr as $k => $v) {
                list($sku, $quantity) = explode('*', $v);
                $skuInfo = $skuModel->where(['sku' => $sku])->find();
                //如果SKU不存在，则返回错误
                if (empty($skuInfo)) {
                    return ['result' => false, 'message' => '捆绑打包销售sku：' . $sku . '不存在'];
                }
                if ($data['sku_code'] == $skuInfo) {
                    $sku_quantity = $quantity;
                }
                $sku_code_quantity[$skuInfo['id']] = [
                    'sku_id' => $skuInfo['id'],
                    'quantity' => $quantity,
                    'sku_code' => $sku,
                ];
            }

            //3.检查生成的必须参数；
            if (!isset($data['sku_code']) || !isset($data['channel_sku']) || !isset($data['channel_id']) || !isset($data['account_id'])) {
                return ['result' => false, 'message' => '生成渠道SKU时，缺少必需的参数'];
            }

            //4.进来先不去生成channel_sku，先用现有的查询一次，如果当前帐号存在此channel_sku,则更新一次，直接返回此channel_sku；
            $res = $this->goodsSkuMapModel->where([
                'channel_id' => ['=', $data['channel_id']],
                'account_id' => ['=', $data['account_id']],
                'channel_sku' => ['=', $data['channel_sku']],
            ])->find();
            //如果传进来的channel_sku存在，则直接更新了返回；
            if (!empty($res)) {
                $res = is_object($res) ? $res->toArray() : $res;
                $res['sku_id'] = $sku_id;
                $res['sku_code'] = $data['sku_code'];
                $res['goods_id'] = $goods_id;
                $res['sku_code_quantity'] = json_encode($sku_code_quantity);
                $res['updater_id'] = $uid;
                $res['update_time'] = time();
                $this->goodsSkuMapModel->isUpdate(true)->save($res, ['id' => $res['id']]);
                return ['result' => true, 'sku_code' => $data['channel_sku']];
            }

            //5.生成重复，则另外生成，最多重复10次；
            for ($i = 0; $i < 20; $i++) {
                //5.1只在第一次时判登自定义SKU符不符合自定义的规则，如果符哈，则使用自定义；
                if ($i === 0 && strpos($data['channel_sku'], $data['sku_code']) !== false && strlen($data['channel_sku']) - strlen($data['sku_code']) >= 4) {
                    $channel_sku = $data['channel_sku'];
                } else {
                    $channel_sku = $this->createSkuNotInTable($data['channel_sku'], $data['account_id'], $data['channel_id'], $separator, $len, $charaList);
                }

                $where = [
                    'sku_code' => ['=', $data['sku_code']],
                    'channel_id' => ['=', $data['channel_id']],
                    'account_id' => ['=', $data['account_id']],
                    'channel_sku' => ['=', $channel_sku],
                    'sku_code_quantity' => ['=', json_encode($sku_code_quantity)],
                ];
                //如果已经存在
                if ($res = $this->goodsSkuMapModel->isExists($where)) {
                    continue;
                } else {
                    $save['goods_id'] = $goods_id;
                    $save['sku_id'] = $sku_id;
                    $save['sku_code'] = $data['sku_code'];
                    $save['channel_id'] = $data['channel_id'];
                    $save['account_id'] = $data['account_id'];
                    $save['channel_sku'] = $channel_sku;
                    $save['quantity'] = $sku_quantity;
                    $save['sku_code_quantity'] = json_encode($sku_code_quantity);
                    $save['creator_id'] = $uid;
                    $save['create_time'] = time();
                    $res['updater_id'] = $uid;
                    $res['update_time'] = time();
                    $insertInfo = $this->goodsSkuMapModel->insert($save); //插入新记录
                    //如果是一个数字
                    if (is_numeric($insertInfo)) {
                        return ['result' => true, 'sku_code' => $channel_sku];
                    } else {
                        return ['result' => false, 'message' => $this->goodsSkuMapModel->getError()];
                    }
                }
            }
            return ['result' => false, 'message' => '生成渠道SKU时，重复次数超过20次，请更换自定义sku的值'];

        } catch (Exception $exp) {
            throw new Exception($exp->getMessage());
        }
    }

    /**
     * 检查头
     * @params array $result
     * @$throws Exception
     */
    private function checkHeader($result)
    {
        if (!$result) {
            throw new Exception("未收到该文件的数据");
        }
        $headers = [
            '平台', '账号简称', '平台SKU', '本地SKU', '关联数量', '是否虚拟仓发货'
        ];
        $row = reset($result);
        $aRowFiles = array_keys($row);
        $aDiffRowField = array_diff($headers, $aRowFiles);
        if (!empty($aDiffRowField)) {
            throw new Exception("缺少列名[" . implode(';', $aDiffRowField) . "]");
        }
    }

    /**
     * 导入sku映射关系
     * @param string $file
     * @return array
     * @throws Exception
     */
    public function saveExcelImportDataByFile($path)
    {
        set_time_limit(0);
        //$path  = 'download/sku.xlsx';
        $import_data = Excel::readExcel($path);
        if (empty($import_data)) {
            throw new JsonErrorException('导入数据为空！');
        }
        $this->checkHeader($import_data);
        $i = 1;
        $error_message = [];
        $check_repeat = [];
        foreach ($import_data as $key => $vo) {
            $i++;
            if (!param($vo, '平台') || !param($vo, '账号简称') || !param($vo, '平台SKU') || !param($vo, '本地SKU') || !param($vo, '关联数量')) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中，[ 平台、账号简称、平台SKU 、本地SKU、关联数量]：数据不能为空（注：格式正确才能导入）");
            }
            //判断平台数据正确性
            if (param($vo, '平台SKU') && in_array(strtoupper($vo['平台SKU']), ['ebay', 'amazon', 'wish', 'aliexpress'])) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中：" . param($vo, '平台SKU') . '不存在（注：格式正确才能导入）');
            }
            if (param($vo, '是否虚拟仓发货') && in_array($vo['是否虚拟仓发货'], ['是', '否'])) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中：" . param($vo, '是否虚拟仓发货') . '不正确（注：格式正确才能导入）');
            }
        }
        $import_result = [];
        $i = 1;
        foreach ($import_data as $key => $vo) {
            $i++;
            $local_sku = param($vo, '本地SKU');
            $channel_sku = param($vo, '平台SKU');
            $relate_num = param($vo, '关联数量');
            if (!$local_sku || !$channel_sku) {
                continue;
            }
            $temp = ['平台' => $vo['平台'], '账号简称' => $vo['账号简称']];
            $multi_local_sku = explode(',', $local_sku);
            $multi_channel_sku = explode(',', $channel_sku);
            $multi_num = explode(',', $relate_num);
            if (count($multi_channel_sku) == 1 && count($multi_local_sku) == 1) {
                if (count($multi_num) > 1) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                $import_result[] = array_merge($temp, array('关联数量' => $relate_num, '本地SKU' => $local_sku, '平台SKU' => $channel_sku));
                continue;
            }
            if (count($multi_channel_sku) > 1 && count($multi_local_sku) == 1) {
                if (count($multi_num) > 1 && count($multi_channel_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_channel_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $local_sku, '平台SKU' => $item));
                }
                continue;
            }
            if (count($multi_local_sku) > 1 && count($multi_channel_sku) == 1) {
                if (count($multi_num) > 1 && count($multi_local_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：本地sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_local_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $item, '平台SKU' => $channel_sku));
                }
            }
            if (count($multi_local_sku) > 1 && count($multi_channel_sku) > 1) {
                if (count($multi_local_sku) != count($multi_channel_sku)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与本地sku对应有问题（注：格式正确才能导入）");
                }
                if (count($multi_num) > 1 && count($multi_local_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_local_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $item, '平台SKU' => $multi_channel_sku[$k]));
                }
            }
        }
        foreach ($import_result as $key => $vo) {
            //Sku重复
            $repeat_key = $vo['平台'] . '-' . $vo['账号简称'] . '-' . $vo['平台SKU'] . '-' . $vo['本地SKU'];
            if (isset($check_repeat[$repeat_key])) {
                unset($import_result[$key]);
            }
            $check_repeat[$repeat_key][] = $i;
        }
        foreach ($check_repeat as $key => $cvo) {
            if (count($cvo) > 1) {
                $error_message['excel_repeat'][] = '第 ' . implode(',', $cvo) . ' 行重复';
            }
        }

        $ebayAccountList = Cache::store('EbayAccount')->getTableRecord();
        $amazonAccountList = Cache::store('AmazonAccount')->getTableRecord();
        $wishAccountList = Cache::store('WishAccount')->getAccount();
        $aliexpressAccountList = Cache::store('AliexpressAccount')->getTableRecord();
        $walmartAccountList = Cache::store('WalmartAccount')->getTableRecord();
        $lazadaAccountList = Cache::store('LazadaAccount')->getTableRecord();
        $GoodsSkuModel = new GoodsSku();
        $items = [];
        foreach ($import_result as $vo) {
            $accountList = [];
            $where = [];
            //获取数据
            $channel = trim(param($vo, '平台'));
            $account = trim(param($vo, '账号简称'));
            $channelSku = trim(param($vo, '平台SKU'));
            $localSku = trim(param($vo, '本地SKU'));
            $mapNum = intval(param($vo, '关联数量'));
            $is_virtual_send = param($vo, '是否虚拟仓发货') == '是' ? 1 : 0;
            //查询账号id
            switch (strtolower($channel)) {
                case 'ebay' :
                    $accountList = $ebayAccountList;
                    $channel_id = ChannelAccountConst::channel_ebay;
                    break;
                case 'amazon' :
                    $accountList = $amazonAccountList;
                    $channel_id = ChannelAccountConst::channel_amazon;
                    break;
                case 'wish' :
                    $accountList = $wishAccountList;
                    $channel_id = ChannelAccountConst::channel_wish;
                    break;
                case 'aliexpress' :
                    $accountList = $aliexpressAccountList;
                    $channel_id = ChannelAccountConst::channel_aliExpress;
                    break;
                case 'walmart' :
                    $accountList = $walmartAccountList;
                    $channel_id = ChannelAccountConst::channel_Walmart;
                    break;
                case 'lazada' :
                    $accountList = $lazadaAccountList;
                    $channel_id = ChannelAccountConst::channel_Lazada;
                    break;
            }
            $where[] = ["code", "==", $account];
            $accountList = Cache::filter($accountList, $where);
            $account_info = [];
            if ($accountList) {
                foreach ($accountList as $avo) {
                    $account_info = $avo;
                    break;
                }
            }

            if (empty($account_info) || !param($account_info, 'id')) {
                $error_message['empty_account'][$account] = $account;
                continue;
            }
            $account_id = $account_info['id'];

            //通过sku获取sku id 信息
            $goods_sku_info = $GoodsSkuModel->field('id')->where(['sku' => $localSku])->find();
            if (empty($goods_sku_info) || !param($goods_sku_info, 'id')) {
                //通过别名
                $sku_id = GoodsSkuAliasService::getSkuIdByAlias($localSku);
                if (!$sku_id) {
                    $error_message['empty_sku'][$localSku] = $localSku;
                    continue;
                }
            } else {
                $sku_id = $goods_sku_info['id'];
            }

            $items[$channel_id . '-' . $account_id . '-' . $channelSku][] = [
                'channel_id' => $channel_id,
                'account_id' => $account_id,
                'channel_sku' => $channelSku,
                'sku_id' => $sku_id,
                'sku_code' => $localSku,
                'quantity' => $mapNum,
                'is_virtual_send' => $is_virtual_send
            ];
        }
        $add_message = '';
        $i = 0;
        //保存数据
        if ($items) {
            foreach ($items as $item) {
                $sku = [];
                foreach ($item as $ivo) {
                    $params = [
                        'channel_id' => $ivo['channel_id'],
                        'account_id' => $ivo['account_id'],
                        'channel_sku' => $ivo['channel_sku'],
                    ];
                    $sku[] = [
                        'sku_id' => $ivo['sku_id'],
                        'sku_code' => $ivo['sku_code'],
                        'quantity' => $ivo['quantity']
                    ];
                }
                $params['sku'] = json_encode($sku);
                try {
                    //保存关联sku
                    $this->saveImport($params);
                    $i++;
                } catch (Exception $e) {
                    $errr['平台SKU'] = $params['channel_sku'];
                    $errr['本地sku'] = implode('/', array_column($sku, 'sku_code'));
                    $add_message .= '【' . json_encode($errr) . '映射失败，' . $e->getMessage() . '】';
                    continue;
                }
            }
        }
        if ($error_message) {
            if (param($error_message, 'excel_repeat')) {
                $add_message .= "【以下重复数据只会添加一条：" . implode('，', $error_message['excel_repeat']) . '。】';
            }
            if (isset($error_message['empty_account'])) {
                $add_message .= '【 以下账号：' . implode('，', $error_message['empty_account']) . ' 在系统中找不到对应“账号”数据，没有添加成功。 】';
            }
            if (isset($error_message['empty_sku'])) {
                $add_message .= '【 以下SKU：' . implode('，', $error_message['empty_sku']) . ' 在系统中找不到对应“SKU”数据，没有添加成功。 】';
            }
        }
        return [
            'status' => 1,
            'message' => '导入成功！' . $add_message
        ];
    }

    /**
     * 导入sku映射关系
     * @param string $file
     * @return array
     * @throws Exception
     */
    public function saveExcelImportData($file = '')
    {
        set_time_limit(0);
        $importService = new ImportExport();
        $path = $importService->uploadFile($file, 'sku_map_import');
        if (!$path) {
            return json(['message' => '文件上传失败'], 400);
        }
        $import_data = $importService->excelImport($path);
        if (empty($import_data)) {
            throw new JsonErrorException('导入数据为空！');
        }
        $this->checkHeader($import_data);
        $i = 1;
        $error_message = [];
        $check_repeat = [];
        foreach ($import_data as $key => $vo) {
            $i++;
            //处理单元格的空字符
            $import_data[$key]['平台'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '平台')));
            $import_data[$key]['账号简称'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '账号简称')));
            $import_data[$key]['平台SKU'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '平台SKU')));
            $import_data[$key]['本地SKU'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '本地SKU')));
            $import_data[$key]['关联数量'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '关联数量')));
            $import_data[$key]['是否虚拟仓发货'] = trim(preg_replace(["/^(\s|\&nbsp\;|　|\xc2\xa0)/", "/(\s|\&nbsp\;|　|\xc2\xa0)$/"], "", param($vo, '是否虚拟仓发货')));
            //过滤空行
            $rowTemp = array_filter($import_data[$key]);
            if (empty($rowTemp)) {
                unset($import_data[$key]);
                continue;
            }
            if (!param($vo, '平台') || !param($vo, '账号简称') || !param($vo, '平台SKU') || !param($vo, '本地SKU') || !param($vo, '关联数量')) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中，[ 平台、账号简称、平台SKU 、本地SKU、关联数量]：数据不能为空（注：格式正确才能导入）");
            }
            //判断平台数据正确性
            if (param($vo, '平台SKU') && in_array(strtoupper($vo['平台SKU']), ['ebay', 'amazon', 'wish', 'aliexpress'])) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中：" . param($vo, '平台SKU') . '不存在（注：格式正确才能导入）');
            }
            if (param($vo, '是否虚拟仓发货') && !in_array($vo['是否虚拟仓发货'], ['是','否'])) {
                throw new JsonErrorException("检查excel文件，第" . $i . "行中：是否虚拟仓发货的值：“" . param($vo, '是否虚拟仓发货') . '”不正确（注：格式正确才能导入）');
            }
        }
        $import_result = [];
        $i = 1;
        foreach ($import_data as $key => $vo) {
            $i++;
            $local_sku = param($vo, '本地SKU');
            $channel_sku = param($vo, '平台SKU');
            $relate_num = param($vo, '关联数量');
            if (!$local_sku || !$channel_sku) {
                continue;
            }
            $temp = ['平台' => $vo['平台'], '账号简称' => $vo['账号简称'], '是否虚拟仓发货' => $vo['是否虚拟仓发货']];
            $multi_local_sku = explode(',', $local_sku);
            $multi_channel_sku = explode(',', $channel_sku);
            $multi_num = explode(',', $relate_num);
            if (count($multi_channel_sku) == 1 && count($multi_local_sku) == 1) {
                if (count($multi_num) > 1) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                $import_result[] = array_merge($temp, array('关联数量' => $relate_num, '本地SKU' => $local_sku, '平台SKU' => $channel_sku));
                continue;
            }
            if (count($multi_channel_sku) > 1 && count($multi_local_sku) == 1) {
                if (count($multi_num) > 1 && count($multi_channel_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_channel_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $local_sku, '平台SKU' => $item));
                }
                continue;
            }
            if (count($multi_local_sku) > 1 && count($multi_channel_sku) == 1) {
                if (count($multi_num) > 1 && count($multi_local_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：本地sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_local_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $item, '平台SKU' => $channel_sku));
                }
            }
            if (count($multi_local_sku) > 1 && count($multi_channel_sku) > 1) {
                if (count($multi_local_sku) != count($multi_channel_sku)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与本地sku对应有问题（注：格式正确才能导入）");
                }
                if (count($multi_num) > 1 && count($multi_local_sku) != count($multi_num)) {
                    throw new JsonErrorException("检查excel文件，第" . $i . "行中：平台sku与关联数量对应有问题（注：格式正确才能导入）");
                }
                foreach ($multi_local_sku as $k => $item) {
                    $num = count($multi_num) > 1 ? $multi_num[$k] : $relate_num;
                    $import_result[] = array_merge($temp, array('关联数量' => $num, '本地SKU' => $item, '平台SKU' => $multi_channel_sku[$k]));
                }
            }
        }
        foreach ($import_result as $key => $vo) {
            //Sku重复
            $repeat_key = $vo['平台'] . '-' . $vo['账号简称'] . '-' . $vo['平台SKU'] . '-' . $vo['本地SKU'];
            if (isset($check_repeat[$repeat_key])) {
                unset($import_result[$key]);
            }
            $check_repeat[$repeat_key][] = $i;
        }
        foreach ($check_repeat as $key => $cvo) {
            if (count($cvo) > 1) {
                $error_message['excel_repeat'][] = '第 ' . implode(',', $cvo) . ' 行重复';
            }
        }
        $ebayAccountList = Cache::store('EbayAccount')->getTableRecord();
        $amazonAccountList = Cache::store('AmazonAccount')->getTableRecord();
        $wishAccountList = Cache::store('WishAccount')->getAccount();
        $aliexpressAccountList = Cache::store('AliexpressAccount')->getTableRecord();
        $walmartAccountList = Cache::store('WalmartAccount')->getTableRecord();
        $lazadaAccountList = Cache::store('LazadaAccount')->getTableRecord();
        $cdAccountList = Cache::store('CdAccount')->getTableRecord();
        $GoodsSkuModel = new GoodsSku();
        $items = [];
        foreach ($import_result as $vo) {
            $accountList = [];
            $where = [];
            //获取数据
            $channel = param($vo, '平台');
            $account = param($vo, '账号简称');
            $channelSku = param($vo, '平台SKU');
            $localSku = param($vo, '本地SKU');
            $mapNum = param($vo, '关联数量');
            $is_virtual_send = param($vo, '是否虚拟仓发货');
            $is_virtual_send = $is_virtual_send == '是' ? 1 : 0;
            //查询账号id
            switch (strtolower($channel)) {
                case 'ebay' :
                    $accountList = $ebayAccountList;
                    $channel_id = ChannelAccountConst::channel_ebay;
                    break;
                case 'amazon' :
                    $accountList = $amazonAccountList;
                    $channel_id = ChannelAccountConst::channel_amazon;
                    break;
                case 'wish' :
                    $accountList = $wishAccountList;
                    $channel_id = ChannelAccountConst::channel_wish;
                    break;
                case 'aliexpress' :
                    $accountList = $aliexpressAccountList;
                    $channel_id = ChannelAccountConst::channel_aliExpress;
                    break;
                case 'walmart' :
                    $accountList = $walmartAccountList;
                    $channel_id = ChannelAccountConst::channel_Walmart;
                    break;
                case 'lazada' :
                    $accountList = $lazadaAccountList;
                    $channel_id = ChannelAccountConst::channel_Lazada;
                    break;
                case 'cd' :
                    $accountList = $cdAccountList;
                    $channel_id = ChannelAccountConst::channel_CD;
                    break;
            }
            $where[] = ["code", "==", $account];
            $accountList = Cache::filter($accountList, $where);
            $account_info = [];
            if ($accountList) {
                foreach ($accountList as $avo) {
                    $account_info = $avo;
                    break;
                }
            }

            if (empty($account_info) || !param($account_info, 'id')) {
                $error_message['empty_account'][$account] = $account;
                continue;
            }
            $account_id = $account_info['id'];

            //通过sku获取sku id 信息
            $goods_sku_info = $GoodsSkuModel->field('id')->where(['sku' => $localSku])->find();
            if (empty($goods_sku_info) || !param($goods_sku_info, 'id')) {
                //通过别名
                $sku_id = GoodsSkuAliasService::getSkuIdByAlias($localSku);
                if (!$sku_id) {
                    $error_message['empty_sku'][$localSku] = $localSku;
                    continue;
                }
            } else {
                $sku_id = $goods_sku_info['id'];
            }
            $items[$channel_id . '-' . $account_id . '-' . $channelSku][] = [
                'channel_id' => $channel_id,
                'account_id' => $account_id,
                'channel_sku' => $channelSku,
                'sku_id' => $sku_id,
                'sku_code' => $localSku,
                'quantity' => $mapNum,
                'is_virtual_send' => $is_virtual_send
            ];
        }
        $add_message = '';
        $i = 0;
        //保存数据
        if ($items) {
            foreach ($items as $item) {
                $sku = [];
                foreach ($item as $ivo) {
                    $params = [
                        'channel_id' => $ivo['channel_id'],
                        'account_id' => $ivo['account_id'],
                        'channel_sku' => $ivo['channel_sku'],
                        'is_virtual_send' => $ivo['is_virtual_send']
                    ];
                    $sku[] = [
                        'sku_id' => $ivo['sku_id'],
                        'sku_code' => $ivo['sku_code'],
                        'quantity' => $ivo['quantity']
                    ];
                }
                $params['sku'] = json_encode($sku);
                try {
                    //保存关联sku
                    $this->saveImport($params);
                    $i++;
                } catch (Exception $e) {
                    $errr['平台SKU'] = $params['channel_sku'];
                    $errr['本地sku'] = implode('/', array_column($sku, 'sku_code'));
                    $add_message .= '【' . json_encode($errr) . '映射失败，' . $e->getMessage() . '（注，不会更新）】';
                    continue;
                }
            }
        }
        if ($error_message) {
            if (param($error_message, 'excel_repeat')) {
                $add_message .= "【以下重复数据只会添加一条：" . implode('，', $error_message['excel_repeat']) . '。】';
            }
            if (isset($error_message['empty_account'])) {
                $add_message .= '【 以下账号：' . implode('，', $error_message['empty_account']) . ' 在系统中找不到对应“账号”数据，没有添加成功。 】';
            }
            if (isset($error_message['empty_sku'])) {
                $add_message .= '【 以下SKU：' . implode('，', $error_message['empty_sku']) . ' 在系统中找不到对应“SKU”数据，没有添加成功。 】';
            }
        }
        return [
            'status' => 1,
            'message' => '导入成功！' . $add_message
        ];
    }

    /**
     * 新增映射记录
     * @param $data
     * @return mixed
     * @throws Exception
     */
    public function saveImport($data)
    {
        try {
            $goodsSkuMapModel = new GoodsSkuMap();
            $data['sku'] = json_decode($data['sku'], true);
            $sku_code_quantity = [];
            $publishData = [];
            foreach ($data['sku'] as $key => $value) {
                $temp['sku_id'] = $value['sku_id'];
                $temp['quantity'] = $value['quantity'];
                //查出sku的名称
                $goodsSkuInfo = Cache::store('goods')->getSkuInfo($value['sku_id']);
                if (empty($goodsSkuInfo)) {
                    throw new Exception('该本地商品不存在！');
                }
                $info = $this->isHas($data['account_id'], $data['channel_id'], $data['channel_sku']);
                if ($info) {
                    throw new Exception('该平台sku映射记录已存在');
                }
                $temp['sku_code'] = $goodsSkuInfo['sku'];
                $temp['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $data['goods_id'] = $temp['goods_id'];
                $data['sku_id'] = $temp['sku_id'];
                $data['sku_code'] = $temp['sku_code'];
                $data['quantity'] = $temp['quantity'];
                $sku_code_quantity[$value['sku_id']] = $temp;

                //为调用登刊系统api组装数据
                $element['channel_sku'] = $data['channel_sku'];
                $element['sku_id'] = $value['sku_id'];
                $element['is_virtual_send'] = $data['is_virtual_send'];
                $element['goods_id'] = $goodsSkuInfo['goods_id'] ?? 0;
                $publishData[] = $element;
            }
            $data['sku_code_quantity'] = json_encode($sku_code_quantity);
            //获取操作人信息
            $user = CommonService::getUserInfo();
            if (!empty($user)) {
                $data['creator_id'] = $user['user_id'];
                $data['updater_id'] = $user['user_id'];
            }
            $data['create_time'] = time();
            $data['update_time'] = time();
            unset($data['sku']);

            Db::startTrans();
            try {
                $goodsSkuMapModel->allowField(true)->isUpdate(false)->save($data);
                //增加操作日志
                (new GoodsSkuMapLogService())->add($data['channel_sku'])->save($goodsSkuMapModel->id, $user['user_id'], $user['realname']);

                if ($data['channel_id'] == ChannelAccountConst::channel_wish) {
                    foreach ($publishData as $publish) {
                        WishHelper::setListingVirtualSend($publish);
                    }
                } elseif ($data['channel_id'] == ChannelAccountConst::channel_ebay) {
                    foreach ($publishData as $publish) {
                        EbayPublish::setListingVirtualSend($publish);
                    }
                } elseif ($data['channel_id'] == ChannelAccountConst::channel_aliExpress) {
                    foreach ($publishData as $publish) {
                        $aliExpressPublish['channel_sku'] = $publish['channel_sku'];
                        $aliExpressPublish['sku_id'] = $publish['sku_id'];
                        $aliExpressPublish['is_virtual_send'] = $publish['is_virtual_send'];
                        (new ExpressHelper())->aliVirtualSendSync($aliExpressPublish);
                    }
                }
                Db::commit();
            } catch (GoodsSkuMapException $e) {
                Db::rollback();
                throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}