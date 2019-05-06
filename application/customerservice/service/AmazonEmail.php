<?php
// +----------------------------------------------------------------------
// | 邮件service
// +----------------------------------------------------------------------
// | File  : Email.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-07-19
// +----------------------------------------------------------------------

namespace app\customerservice\service;


use app\carrier\type\winit\ProductDataType;
use app\common\cache\Cache;
use app\common\model\amazon\AmazonAccount;
use app\common\model\amazon\AmazonOrder;
use app\common\model\ChannelUserAccountMap;
use app\common\model\customerservice\EmailAccounts;
use app\common\model\customerservice\AmazonEmail as AmazonEmailList;
use app\common\model\customerservice\AmazonEmailGroup;
use app\common\model\customerservice\AmazonEmailContent;
use app\common\model\customerservice\EmailSentContent;
use app\common\model\customerservice\EmailSentList;
use app\common\model\Department;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\OrderStatusConst;
use app\customerservice\controller\AmazonSentEmail;
use app\customerservice\controller\Email;
use app\customerservice\validate\AmazonEmailValidate;
use app\order\service\OrderService;
use erp\AbsServer;
use imap\EmailAccount;
use imap\MailReceiver;
use imap\MailSender;
use think\Db;
use think\db\Query;
use think\Exception;
use think\Log;
use think\Validate;
use app\common\exception\JsonErrorException;
use app\common\model\Order;
use app\common\model\OrderAddress;
use app\index\service\MemberShipService;
use app\common\traits\User as UserTraits;
use app\customerservice\service\ContentExtraction;
use app\common\model\Account as accountModel;
use app\common\model\Email as EmailModel;
use app\common\model\EmailServer as EmailServiceMode;
use app\common\service\Encryption;
use app\index\service\AccountUserMapService;
use app\common\model\Postoffice as ModelPostoffice;
use app\common\service\Report;
use think\Config;

class AmazonEmail extends AbsServer
{
    const AMAZON_CHANNEL_ID = 2;
    const TEST_USER_ID = 1;

    public $encryption;
    public $amazonAccount;
    private $uid = 0;
    public $channel_id = 0;
    use UserTraits;

    public static $tplFields = [
        '${buyerId}',
        '${buyerName}',
        '${platformOrderNumber}',
        '${shipper}',
        '${trackingNumber}'
    ];


    protected $mailSendError = null;

    public function __construct()
    {
        parent::__construct();
        $this->channel_id = ChannelAccountConst::channel_amazon;
        $this->encryption = new Encryption();
        $this->amazonAccount = new AmazonAccount();
    }


    /**
     * @param $prarms
     * @return array
     */
    public function getPageInfo(&$prarms)
    {
        $page = isset($prarms['page']) ? intval($prarms['page']) : 1;
        !$page && $page = 1;
        $pageSize = isset($prarms['pageSize']) ? intval($prarms['pageSize']) : 10;
        !$pageSize && $pageSize = 10;
        return [$page, $pageSize];
    }


    /**
     * 判断是否需要结合订单查询接口进行查询
     * @return bool
     */
    protected function isExtendReceivedMailSearch()
    {
        $orderStatus = [1, 2, 3];
        $optionFields = ['buyer_id', 'buyer_name', 'system_order_number'];
        if (in_array(input('order_status', ''), $orderStatus)) {
            return true;
        }
        if (in_array(input('option_field', ''), $optionFields) && !empty(input('option_value', ''))) {
            return true;
        }
        return false;
    }

    /**
     * @param $params
     * @return array
     */
    protected function getSearchReceivedMailCondition(array &$params)
    {
        $where = [];
        $userId = Common::getUserInfo()->user_id;
        $memberModel = new MemberShipService();
        $customerId = $userId;
        if ($userId == self::TEST_USER_ID && !empty($params['customer_id'])) {
             $customerId = $params['customer_id'];
        }

        $account_ids = $memberModel->getAccountIDByUserId($customerId, 2);

        $receiver = [];//EmailAccounts::where(['channel_id' => 2, 'account_id' => ['in', $account_ids]])->column('email_account');

        if (!empty($receiver)) {
            $where['receiver'] = ['in', $receiver];
        }


        //站点分类
        if (!empty($params['site'])) {
            $site = strtolower($params['site']);
            if($site == 'us'){
                $where['site'] = 'com';
            }else{
                $where['site'] = $site;
            }
        }

        //传过来的account_code 其实是亚马逊的帐号ID
        if (!empty($params['account_code'])) {
            $account_code = AmazonAccount::where(['id' => ['in', $params['account_code']]])
                        ->column('code');
            $sites = [];
            foreach ($account_code as $code){
                $site = strtolower(substr($code,-2));
                if($site == 'us'){
                    $sites = 'com';
                }else{
                    $sites = $site;
                }
            }
            $where['box_id'] = 2;
            $where['is_replied'] = 2;
            $where['site'] = ['in', $sites];
            $where['account_id'] = ['in', $params['account_code']];

        }

        //邮件分类
        if (!empty($params['box_id'])) {
            $where['box_id'] = $params['box_id'];
        }

        //标记
//        if (!empty($params['flag_id'])) {
//            $where['flag_id'] = $params['flag_id'];
//        }

        //回复状态
        if ($params['is_replied'] === '0' || !empty($params['is_replied'])) {
            if ($params['is_replied'] == 21) {
                $where['is_replied'] = 0;
                $where['last_receive_time'] = ['<', strtotime('-1 days')];
            } elseif ($params['is_replied'] == 22) {
                $where['is_replied'] = 0;
                $where['last_receive_time'] = ['>=', strtotime('-1 days')];
            } else {
                $where['is_replied'] = $params['is_replied'];
            }
        }

        //阅读状态
        if (isset($params['is_read']) && $params['is_read'] !== '') {
            $where['is_read'] = $params['is_read'];
        }

        //选择字段
        if (!empty($params['option_field']) && !empty($params['option_value'])) {
            switch ($params['option_field']) {
                case 'platform_order_no':
                    $where['order_no'] = $params['option_value'];
                    break;
                case 'customer_email':
                case 'buyer_id':
                    $where['sender'] = $params['option_value'];
                    break;
                case 'buyer_name' :
                    $amazonOrderModel = new AmazonOrder();
                    $order = $amazonOrderModel->field('email')->where(['user_name' => $params['option_value']])->find();
                    if ($order) {
                        $where['sender'] = $order['email'];
                    } else {
                        $where['id'] = -1;
                    }
                    break;
                case 'system_order_number':
                    $orderModel = new Order();
                    $order = $orderModel->field('channel_order_number')->where(['order_number' => $params['option_value']])->find();
                    if ($order) {
                        $where['order_no'] = $order['channel_order_number'];
                    } else {
                        $where['id'] = -1;
                    }
                    break;
                default:  /*不支持的查询字段，忽略*/
                    throw new Exception('未知查询参数 option_field');
            }
        }

        if(!empty($params['order_status']) && in_array($params['order_status'], [1, 2, 3, 4])) {
            //$statusArr = [1 => '', '', '', 'Canceled'];
            //$where['order_status'] = $statusArr[$params['order_status']];
            $where['id'] = -1;
        }

        //时间段
        if (!empty($params['time_field'])) {
            $timeTmp = [];
            if(!empty($params['time_start']) && empty($params['time_end'])) {
                $timeTmp = ['>', strtotime($params['time_start'])];
            } else if (empty($params['time_start']) && !empty($params['time_end'])) {
                $timeTmp = ['<=', strtotime($params['time_end']. '23:59:59')];
            } else if (!empty($params['time_start']) && !empty($params['time_end'])) {
                $timeTmp = ['between', [strtotime($params['time_start']), strtotime($params['time_end']. '23:59:59')]];
            }
            if (!empty($timeTmp)) {
                switch ($params['time_field']) {
                    case 'sync_time':
                        $where['last_receive_time'] = $timeTmp;
                        break;
                    case 'reply_time':
                        $where['is_replied'] = 1;
                        $where['last_replied_time'] = $timeTmp;
                        //邮件回复时间起
                        break;
                    default : /*不支持的时间查询字段，忽略*/
                        throw new Exception('未知查询时间参数 option_field');
                }
            }
        }

        return $where;
    }

    /**
     * @param array $records
     * @return array
     */
    protected function assemblySearchResult(&$records)
    {
        $data = [];
        foreach ($records as $record) {
            $listModel = new AmazonEmailList;
            $_data['address'] = $record->sender;
            $_data['sentTotal'] = $listModel->where('sender', $record->sender)->count();
            $_data['unread_qty'] = $listModel->where([
                'sender' => $record->sender,
                'is_read' => 0
            ])->count();
            $_data['last_email_time'] = $record->sync_time;
            $email = $this->recordModelToArray($record);
            if (is_array($email['attachments'])) {
                foreach ($email['attachments'] as $key => &$value) {
                    $value['path'] = $_SERVER["HTTP_HOST"] . $value['path'];
                }

            }
            $_data['emails'][] = $email;
            $data[] = $_data;
            unset($_data);
        }
        return $data;
    }

    /**
     * 获取指定邮箱的历史接收邮件
     * @param $emailAddr
     * @return array
     */
    public function getCustomerHistoerEmails($group_id, $page, $pageSize)
    {
        $flag = [1 => 'return_request', 'size_big', 'size_small', 'goods_quality'];
        $listModel = new AmazonEmailList();
        //查询出每个邮箱符合查询条件的记录的最大id
        $emailList = $listModel->alias('l')
//            ->join(['amazon_email_content' => 'c'], 'l.id=c.id')
            ->where(['l.group_id' => ['in', $group_id]])
            ->field('l.*')
            ->order('sync_time', 'desc')
            ->page($page, $pageSize)
            ->column('l.*');

        $list = array_column($emailList,'id');

        foreach ($list as $id)
        {
            $res = $this->getHtmlFromMongo($id);
            $emailList[$id]['body'] = $res['data'];
            if (empty($emailList[$id]['attachments']))
            {
                $emailList[$id]['attachments'] = [];
            }else{
                $emailList[$id]['attachments'] = json_decode($emailList[$id]['attachments'],true);
            }
        }

        $orderNos = array_column($emailList, 'order_no');

        //如果订单号不为空，则找出对应的订单，再根据订单找出对应的用户名；
        $orderArr = [];
        $accountIds = [];
        $orderList = $accountList = []; //邮件关系的订单列表，和帐号列表；
        if(!empty($orderNos)) {
            $orderList = AmazonOrder::where(['order_number' => ['in', $orderNos]])->column('order_number,site,account_id,order_status,platform_username,email', 'order_number');
            $accountIds = array_column($orderList, 'account_id');
        }
        if(!empty($accountIds)) {
            $where['id'] = array('in',$accountIds);
            $accountList = AmazonAccount::where($where)->column('code,account_name', 'id');
        }

        //最后组成数据；
        $index = 0;
        $returnData = [];
        $contentExtraction = new ContentExtraction();

        foreach($emailList as $key=>$val) {
//            if ($index == 0) {
//                $index++;
//                continue;
//            }
            //邮件；

            $val['body'] = $contentExtraction->contentExtraction($val['site'], $val['box_id'], $val['body']);

            $email = $val;
            $email['flag_code'] = $flag[$email['flag_id']] ?? '';
            $email['box_name'] = $email['box_id'] == 1? '系统' : '客服';

            //帐号和买家必段要跟根订单号来找；
            $email['buyer_id'] = $email['buyer_name'] = '';
            $email['priority'] = '';
            $email['account_id'] =  0;
            $email['account_code'] = '';
            $email['account_name'] = '';

            //订单号存在，则加上计单状态
            if(!empty($val['order_no']) && !empty($orderList[$val['order_no']])) {
                $email['priority'] = $orderList[$val['order_no']]['order_status'];
                //邮件获取的email 比 order 获取的多了一段，用正则去掉
                $email['buyer_id'] = preg_replace('/\+.*(?=@)/', '', $orderList[$val['order_no']]['email']);
                $email['buyer_name'] = $orderList[$val['order_no']]['platform_username'];
                $email['account_id'] = $orderList[$val['order_no']]['account_id'];
                $email['account_code'] = $accountList[$orderList[$val['order_no']]['account_id']]['code'] ?? '';
                $email['account_name'] = $accountList[$orderList[$val['order_no']]['account_id']]['account_name'] ?? '';
            }
            $returnData[] = $email;
        }

        $result = [
            'data' => $returnData,
            'page' => $page,
            'pageSize' => $pageSize
        ];
        return $result;
    }

    /**
     * 将邮件记录model转换成数组
     * @param $reocrd
     * @return array
     */
    public function recordModelToArray(&$reocrd)
    {
        $priorityText = '';
        if ($reocrd->systemOrder) {
            switch ($reocrd->systemOrder->status) {
                case OrderStatusConst::ForDistribution:
                    $priorityText = '待配货';
                    break;
                case OrderStatusConst::ToApplyForRefundGoods:
                    $priorityText = '退货申请';
                    break;
            }
            if ($priorityText == '' && $reocrd->systemOrder->distribution_time != 0) {
                $priorityText = '待发货';
            }
        }
        if ($reocrd->amazonOrder && $reocrd->amazonOrder->order_status == 'Canceled') {
            $priorityText = '取消订单';
        }
        return [
            'id' => $reocrd->id,
            'account_id' => $reocrd->account_id,
            'account_code' => $reocrd->amazonAccount ? $reocrd->amazonAccount->code : '',
            'account_name' => $reocrd->amazonAccount ? $reocrd->amazonAccount->account_name : '',
            'receiver' => $reocrd->receiver,
            'sender' => $reocrd->sender,
            'sync_time' => $reocrd->sync_time,
            'platform' => $reocrd->platform,
            'site' => $reocrd->site,
            'order_no' => $reocrd->order_no,
            'buyer_id' => $reocrd->amazonOrder ? $reocrd->amazonOrder->user_name : '',
            'buyer_name' => $reocrd->amazonOrder ? $reocrd->amazonOrder->platform_username : '',
            'box_id' => $reocrd->box_id,
            'box_code' => $reocrd->box_code,
            'box_name' => $reocrd->box_name,
            'is_read' => $reocrd->is_read,
            'is_replied' => $reocrd->is_replied,
            'flag_id' => $reocrd->flag_id,
            'flag_code' => $reocrd->flag ? $reocrd->flag->code : '',
            'flag_name' => $reocrd->flag ? $reocrd->flag->ch_name : '',
            'priority' => $priorityText,
            'subject' => $reocrd->subject,
            'body' => $reocrd->body,
            'attachments' => $reocrd->attachmentsArray
        ];
    }


    /**
     * @param array $params
     * @return array
     */
    protected function getExtendSearchCondition(array &$params)
    {
        $searchCondition = ['type' => '', 'snType' => '', 'snText' => ''];

        $optField = isset($params['option_field']) ? $params['option_field'] : '';
        if ($optField) {
            $optValue = isset($params['option_value']) ? $params['option_value'] : '';
            if ($optValue) {
                switch ($optField) {
                    case 'system_order_number':
                        $searchCondition['snType'] = 'system_order_number';
                        $searchCondition['snText'] = $optValue;
                        break;
                    case 'buyer_id':
                        $searchCondition['snType'] = 'buyer_id';
                        $searchCondition['snText'] = $optValue;
                        break;
                    case 'buyer_name':
                        $searchCondition['snType'] = 'buyer_name';
                        $searchCondition['snText'] = $optValue;
                        break;
                    default:  /*不支持的时间查询字段，忽略*/
                        ;
                }
            }
        }
        $orderStatus = isset($params['order_status']) ? $params['order_status'] : '';
        if (!empty($orderStatus)) {
            $searchCondition['type'] = $orderStatus;
        }
        return $searchCondition;
    }

    /**
     * 获取符合条件的单号
     * @param array $condition
     * @param array $params
     * @param bool $isExtend
     * @return array
     */
    private function getSearchReceivedMailOrderNumbers(array $condition, array &$params, $isExtend = false)
    {
        $listModel = new AmazonEmailList();
        //先从邮件表中查询出符合基本查询条件的记录的order_no
        $orderNums = $listModel->where($condition)->distinct('order_no')->column('order_no');
        if ($isExtend) {
            $orderServ = new OrderService();
            $orderNums = $orderServ->searchByInbox(
                $this->getExtendSearchCondition($params), $orderNums);

        } else {
            exit("ll");
            $amazOrdModel = new AmazonOrder();
            $orderNums = $amazOrdModel->where(['order_number' => ['in', $orderNums], 'order_status' => 'Canceled'])->column('order_number');
        }
        return $orderNums;
    }


    /**
     * 查询收件箱
     * @param $params
     * @return mixed
     */
    public function searchReceivedMail($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $result['page'] = $page;
        $result['pageSize'] = $pageSize;
        $result['count'] = 0;
        $result['data'] = [];
        $where = [];

        //排序；
        $sortType = 'DESC';
        if(!empty($params['time_sort']) && in_array($params['time_sort'], ['ASC', 'DESC'])) {
            $sortType = $params['time_sort'];
        }
        $orderStatus = $params['order_status'] ?? 0;
        //搜索条件；
        $where = $this->getSearchReceivedMailCondition($params);
//        $condition['channel_id'] = ChannelAccountConst::channel_amazon;

        //标记
//        if (!empty($params['flag_id'])) {
//            $where['flag_id'] = $params['flag_id'];
//        }

        //找出总数量，当前页码和分组数；
        $groupModel = new AmazonEmailGroup();
        $result['count'] = $groupModel->alias('g')
            ->where($where)
            ->count();
        $list = $groupModel->alias('g')
            ->where($where)
            ->field('g.*')
            ->page($page, $pageSize)
            ->order('last_receive_time', $sortType)
            ->select();
        if(empty($list)) {
            return $result;
        }

        //查出最后接收的邮件ID，找出对应邮件；
        $ids = [];
        $orderNos = [];
        foreach($list as $val) {
            $ids[] = $val['last_email_id'];
            if(!empty($val['order_no'])) {
                $orderNos[$val['last_email_id']] = $val['order_no'];
            }
        }
        $flag = [1 => 'return_request', 'size_big', 'size_small', 'goods_quality'];
        $listModel = new AmazonEmailList();
        //查询出每个邮箱符合查询条件的记录的最大id
        $emailList = $listModel->alias('l')
//            ->join(['amazon_email_content' => 'c'], 'l.id=c.id')
            ->where(['l.id' => ['in', $ids]])
            ->field('l.*')
            ->column('l.*', 'l.id');

        foreach ($ids as $id)
        {
            $res = $this->getHtmlFromMongo($id);
            $emailList[$id]['body'] = $res['data'];
            if (empty($emailList[$id]['attachments']))
            {
                $emailList[$id]['attachments'] = [];
            }else{
                $emailList[$id]['attachments'] = json_decode($emailList[$id]['attachments'],true);
            }
        }

        //如果订单号不为空，则找出对应的订单，再根据订单找出对应的用户名；
        $orderArr = [];
        $accountIds = [];
        $orderList = $accountList = []; //邮件关系的订单列表，和帐号列表；
        if(!empty($orderNos)) {
            $orderList = AmazonOrder::where(['order_number' => ['in', $orderNos]])->column('order_number,site,account_id,order_status,platform_username,email', 'order_number');
            $accountIds = array_column($orderList, 'account_id');
        }
        if(!empty($accountIds)) {
            $accountList = AmazonAccount::where(['id' => ['in', $accountIds]])->column('code,account_name', 'id');
        }

        $contentExtraction = new ContentExtraction();
        //最后组成数据；
        $returnData = [];
        foreach($list as $val) {
            //初始化；
            $tmp = [];
            $tmp['group_id'] = $val['id'];
            $tmp['address'] = $val['sender'];
            $tmp['last_email_time'] = $val['last_receive_time'];
            $tmp['sentTotal'] = $val['msg_count'];
            $tmp['unread_qty'] = $val['untreated_count'];

            //帐号和买家必段要跟根订单号来找；
            $email['buyer_id'] = $email['buyer_name'] = '';
            $email['priority'] = '';
            $email['account_id'] =  0;
            $email['account_code'] = '';
            $email['account_name'] = '';

            //邮件；
            $emailList[$val['last_email_id']]['body'] = $contentExtraction->contentExtraction($emailList[$val['last_email_id']]['site'], $emailList[$val['last_email_id']]['box_id'], $emailList[$val['last_email_id']]['body']);
            $email = $emailList[$val['last_email_id']];
            $email['flag_code'] = $flag[$email['flag_id']] ?? '';
            $email['box_name'] = $email['box_id'] == 1? '系统' : '客服';
//            $email['buyer_id'] = preg_replace('/\+.*(?=@)/', '', $email['sender']);

            //订单号存在，则加上计单状态
            if(!empty($val['order_no']) && !empty($orderList[$val['order_no']])) {
                $email['priority'] = $orderList[$val['order_no']]['order_status'];
                //邮件获取的email 比 order 获取的多了一段，用正则去掉
                $email['buyer_id'] = preg_replace('/\+.*(?=@)/', '', $orderList[$val['order_no']]['email']);
                $email['buyer_name'] = $orderList[$val['order_no']]['platform_username'];
                $account = empty($accountList[$orderList[$val['order_no']]['account_id']])? [] : $accountList[$orderList[$val['order_no']]['account_id']];
                $email['account_id'] = $orderList[$val['order_no']]['account_id'];
                $email['account_code'] = $account['code'] ?? '';
                $email['account_name'] = $account['account_name'] ?? '';
            }
            $tmp['emails'][] = $email;
            $returnData[] = $tmp;
        }
        $result['data'] = $returnData;

        return $result;
    }

    /**
     * @param $params
     * @return \think\response\Json
     */
    public function searchSentEmail($params)
    {
        list($page, $pageSize) = $this->getPageInfo($params);
        $result['page'] = $page;
        $result['pageSize'] = $pageSize;

        $sortType = 'DESC';
        if(isset($params['time_sort']) && in_array($params['time_sort'], ['ASC', 'DESC'])) {
            $sortType = $params['time_sort'];
        }
        $condition = $this->getSearchSentEmailCondition($params);
        //渠道
//        $condition['channel_id'] = ChannelAccountConst::channel_amazon;
        $condition['type'] = 2;

        $sentList = new AmazonEmailList();
        $result['count'] = $sentList->where($condition)->count();
        $records = $sentList->with('content')->where($condition)->order("create_time $sortType")->page($page, $pageSize)->select();
        foreach ($records as &$record) {
            $record['status_text'] = $record->status;
        }
        $result['data'] = $records;
        return $result;
    }


    /**
     * @param $params
     * @return array
     */
    protected function getSearchSentEmailCondition(&$params)
    {
        $where = [];
        if (isset($params['status']) && in_array($params['status'], ['0', '1', '2'])) {
            $where['status'] = (int)$params['status'];
        }

        if(!empty($params['option_field']) && !empty($params['option_value'])) {
            switch($params['option_field']) {
                case 'sender':
                    $where['sender'] = $params['option_value'];
                    break;
                case 'subject':
                    $where['subject'] = ['like', '%'. $params['option_value']. '%'];
                    break;
                case 'order_number':
                    $where['order_no'] = $params['option_value'];
                    break;
                case 'receiver_email':
                case 'buyer_email':
                    $where['receiver'] = $params['option_value'];
                    break;
                case 'buyer_id':
                    $where['buyer_id'] = $params['option_value'];
                    break;
                default:
                    $where['id'] = 0;
            }
        }

        //传过来的account_code 其实是亚马逊的帐号ID
        if (!empty($params['account_code'])) {
            $account_code = AmazonAccount::where(['id' => ['eq', $params['account_code']]])
                ->value('code');

            $site = strtolower(substr($account_code,-2));
            if($site == 'us'){
                $sites = 'com';
            }else{
                $sites = $site;
            }


            $where['site'] = ['eq', $sites];
            $where['account_id'] = ['eq', $params['account_code']];

        }
        return $where;
    }


    /**
     * 更新接收邮件的查看、是否需要回复、标志状态
     * @param $id
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function updateReceivedMail($id, $params)
    {
        $record = AmazonEmailList::get($id);
        if (!$record) {
            throw new Exception('邮件id不存在', 404);
        }

        $isRead = isset($params['is_read']) ? $params['is_read'] : null;
        if (!is_null($isRead)) {
            if (Validate::in($isRead, '0,1')) {
                $record->is_read = $isRead;
            } else {
                throw new Exception('is_read值不合法', 400);
            }
        }

        $isReplied = isset($params['is_replied']) ? $params['is_replied'] : null;
        if (!is_null($isReplied)) {
            if ($isReplied == 2) {
                $record->is_replied = $isReplied;
            } else {
                throw new Exception('is_replied状态值不合法', 400);
            }
        }

        $flagId = isset($params['flag_id']) ? $params['flag_id'] : null;
        if (!is_null($flagId)) {
            $flags = Db::table('email_flags')->column('id');
            if (in_array($flagId, $flags) || $flagId == 0) {
                $record->flag_id = $flagId;
            } else {
                throw new Exception('flag_id值不合法', 400);
            }
        }
        try {
            $record->isUpdate()->save();
            return true;
        } catch (\Exception $ex) {
            Log::error($ex->getTraceAsString());
            throw new Exception('程序内部错误', 500);
        }
    }


    /**
     * @param $params
     * @param $attachRootDir
     * @return array|bool
     * @throws Exception
     */
    public function senMail($params, $attachRootDir)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $userInfo = Common::getUserInfo();
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];

        $info = $this->getSendMailInfo($params);
        //替换模板内容
        $replace = [
            $info['buyer_id'],
            $info['buyer_name'],
            $info['channel_order_number'],
            $info['shipping_name'],
            $info['shipping_number']
        ];
        $content = str_replace(static::$tplFields, $replace, $info['content']);
        $attachFile = $this->getUploadAttachment($params, $attachRootDir, $info['email_account']);

        /**
         * 保存邮件数组分2部
         * 1. 保存邮件
         * 2. 更新分组的最后邮件ID
         */
/*
 * 从outlook发件箱抓取的邮件会和本地保存的邮件重复，故本地不保存
 *
        Db::startTrans();
        try {
            $group_status = 0;
            $groupModel = new AmazonEmailGroup();
            $groupWhere['box_id'] = 2;
            $groupWhere['sender'] = $info['buyer_email'];
            $groupWhere['receiver'] = $info['email_account'];
            //拿缓存数据
//              $groupOld = Cache::store('AmazonEmail')->getGroupData($groupWhere);
            //缓存不存在，再去数据库里找；
            if (empty($groupOld)) {
                $groupOld = $groupModel->where($groupWhere)->find();
            }


            //如果分组不存在
            if(empty($groupOld)) {
                $group_status = 1;
                $group['msg_count'] = 1;
                $group['untreated_count'] = 1;
                $group['first_receive_time'] = time();
                $group['last_receive_time'] = time();
                $group['created_time'] = time();
                $group['update_time'] = time();
                $group['account_id'] = $info['account_id'];
                //是否已回复 0不需回，1已回，2未回复
                $group['is_replied'] = 1;
                $group['id'] = $groupModel->insert($group, false, true);
            } else {
                $group_status = 2;
                $group['msg_count'] = $groupOld['msg_count'] + 1;
                $group['untreated_count'] = $groupOld['untreated_count'] + 1;
                $group['first_receive_time'] = $groupOld['first_receive_time'];
                $group['last_receive_time'] = time();
                $group['created_time'] = $groupOld['created_time'];
                $group['update_time'] = time();
                $group['account_id'] = $info['account_id'];
                //是否已回复 0不需回，1已回，2未回复
                $group['is_replied'] = 1;
                $groupModel->update($group, ['id' => $groupOld['id']]);
                $group['id'] = $groupOld['id'];
            }


            //创建发送记录
            $recordId = $this->addSendRecord([
                'email_account_id' => $info['email_account_id'],
                'group_id' => $group['id'],
                'account_id' => $info['account_id'],
                'buyer_name' => $info['buyer_name'],
                'buyer_id' => $info['buyer_id'],
                'sender' => $info['email_account'],
                'receiver' => $info['buyer_email'],
                'creater_id' => $info['creator_id'],
                'create_time' => time(),
                'sync_time' => time(),
                'order_no' => $info['channel_order_number'],
                'subject' => $info['subject'],
                'attachment' => str_replace(ROOT_PATH . 'public', '', $attachFile),
                'content' => $content,
            ]);

            //再次更新分组数据ID；新建分组和已有分组更新数据，没有分组不更新数据
            if ($group_status == 1) {
                $groupModel->update(['first_email_id' => $recordId, 'last_email_id' => $recordId], ['id' => $group['id']]);
            } else if ($group_status == 2) {
                $groupModel->update(['first_email_id' => $groupOld['first_email_id'], 'last_email_id' => $recordId], ['id' => $group['id']]);
            }
            Db::commit();

            //存缓存数据
//              Cache::store('AmazonEmail')->setGroupData($groupWhere, $group);
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
*/
        //进行邮件发送
        $account = new EmailAccount(
            $info['account_id'],
            $info['email_account'],
            $info['email_password'],
            $info['imap_url'],
            $info['imap_ssl_port'],
            $info['smtp_url'],
            $info['smtp_ssl_port'], 'Amazon');
        $return = $this->sendMail($account, $info['buyer_email'], 0, $info['subject'], $content, $attachFile);
        if ($return['status']==1) {
            //统计数据；
            Report::statisticMessage(ChannelAccountConst::channel_amazon, $user_id, time(), [
                'buyer_qauntity' => 1
            ]);

        }
        return $return;
    }

    /**
     * @param EmailAccount $account
     * @param $customerEmail
     * @param $sentId
     * @param $subject
     * @param $content
     * @param v
     * @return bool
     * @throws Exception
     */
    public function sendMail(EmailAccount $account, $customerEmail, $sentId, $subject, $content, $attachFile)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $sender = MailSender::getInstance();
        $sender->setAccount($account);
        if (AmazonSentEmail::IS_TEST_SEND) { //测试发送
            $customerEmail = AmazonSentEmail::TEST_SEND_RECEIVER;
        }
        $return = $sender->send($customerEmail, $subject, $content, $attachFile);

//        从outlook发件箱抓取的邮件会和本地保存的邮件重复，故本地不保存

//        $email = AmazonEmailList::where(['id' => $sentId])->find();
        if ($return['status']==1) {
//            $email->status = 1;                 //发送成功
//            $email->sync_time = time();
        } else {
//            $email->status = 2;                 //发送失败
//            $email->sync_time = time();
            $this->mailSendError = $sender->getLastErrorInfo();
        }
//        $email->isUpdate()->save();
        return $return;
    }


    /**
     * 回复邮件
     * @param $params
     * @param $attachRootDir
     * @return bool
     * @throws Exception
     */
    public function replyEmail($params, $attachRootDir)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $userInfo = Common::getUserInfo();
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];

        $validate = new AmazonEmailValidate();
        if (!$validate->scene('reply')->check($params)) {
            throw new Exception($validate->getError(), 400);
        }

        $receivedMail = AmazonEmailList::where(['id' => $params['reply_email_id']])->find();
        if (!$receivedMail) {
            throw new Exception('未找到被回复邮件,请确认是否存在', 404);
        }
//        if ($receivedMail->is_replied == 1) {
//            throw new Exception('该邮件已被回复过', 400);
//        }
        $accountId = $receivedMail->email_account_id;
//        $accountInfo = EmailAccounts::where(['id' => $accountId])->find();

        $amamzonAccount = AmazonAccount::where(['id' => $receivedMail['account_id']])->find();
        if (!$amamzonAccount) {
            throw new Exception('对应的亚马逊平台单号未找到', 404);
        }

        $site = substr($amamzonAccount['code'], -2);
        $site = (new AccountUserMapService())->amazonSiteRule($site);
        $account = substr($amamzonAccount['code'], 0, -2);
        $code = $account . $site;


        $accountInfo = accountModel::where(['a.channel_id' => $this->channel_id, 'a.account_code' => $code])->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')
            ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();


        if (empty($accountInfo)) {
            throw new Exception('被回复邮件对应的平台账号未找到，请检查是否被移除', 404);
        } elseif ($accountInfo['status'] == 0 || $accountInfo['is_send'] == 0) {
            throw new Exception('被回复邮件对应的平台账号不具有发送邮件的权限', 401);
        }

        $accountInfo['email_password'] = $this->encryption->decrypt($accountInfo['password']); //密码解密

        $emailService = ModelPostoffice::where([ 'id' => $accountInfo['post_id'] ])->find();

        $order = (new OrderService())->searchOrder(['order_number_type' => 'channel', 'order_number' => $receivedMail->order_no]);
        if ($order) {
            $replace = [
                $order['buyer_id'] ?: '',
                $order['buyer'] ?: '',
                $order['channel_order_number'] ?: '',
                $order['shipping_name'] ?: '',
                $order['shipping_number'] ?: '',
            ];
        } else {
            $replace = '';
        }

        $subject = 'RE: ' . $receivedMail->subject;
        $content = str_replace(static::$tplFields, $replace, $params['content']);
        if (empty($content)) {
            throw new Exception('回复内容未设置', 400);
        }
        $attachFile = $this->getUploadAttachment($params, $attachRootDir, $accountInfo['email']);
        $createUserId = Common::getUserInfo()['user_id'];


        /**
         * 保存邮件数组分2部
         * 1. 保存邮件
         * 2. 更新分组的最后邮件ID
         */
//        Db::startTrans();
        try {

            $groupModel = new AmazonEmailGroup();
            $groupWhere['box_id'] = 2;
            $groupWhere['sender'] = $receivedMail['sender'];
            $groupWhere['receiver'] = $accountInfo['email'];

            if ($groupWhere['sender'] == $groupWhere['receiver']) {
                throw new Exception('不能回复给自己！', 404);
            }
            //拿缓存数据
//        $groupOld = Cache::store('AmazonEmail')->getGroupData($groupWhere);
            //缓存不存在，再去数据库里找；
            if (empty($groupOld)) {
                $groupOld = $groupModel->where($groupWhere)->find();
            }
/*
 *
 * 从outlook发件箱抓取的邮件会和本地保存的邮件重复，故本地不保存
 *
 *
            $recordId = $this->addSendRecord([
                'email_account_id' => $accountId,
                'group_id' => $groupOld['id'],
                'account_id' => $receivedMail['account_id'],
                'buyer_name' => isset($order['buyer']) ? $order['buyer'] : '',
                'buyer_id' => isset($order['buyer_id']) ? $order['buyer_id'] : '',
                'reply_email_id' => $receivedMail->id,
                'sender' => $accountInfo['email'],
                'receiver' => $receivedMail['sender'],
                'creater_id' => $createUserId,
                'create_time' => time(),
                'sync_time' => time(),
                'order_no' => $receivedMail->order_no,
                'subject' => $subject,
                'attachment' => str_replace(ROOT_PATH, '', $attachFile),
                'content' => $content,
            ]);

            $group['msg_count'] = $groupOld['msg_count'] + 1;
            $group['untreated_count'] = $groupOld['untreated_count'] + 1;
            $group['first_receive_time'] = $groupOld['first_receive_time'];
            $group['last_receive_time'] = time();
            $group['created_time'] = time();
            $group['update_time'] = time();
            $group['account_id'] = $receivedMail['account_id'];
            $group['last_email_id'] = $recordId;
            //是否已回复 0不需回，1已回，2未回复
//            $group['is_replied'] =  1;
            $groupModel->save($group, ['id' => $groupOld['id']]);
            Db::commit();
*/
            //存缓存数据
//        Cache::store('AmazonEmail')->setGroupData($groupWhere, $group);
        } catch (Exception $e) {
//            Db::rollback();
            throw $e;
        }


        //发送邮件的帐号和smtp服务器；
        $account = new EmailAccount(
            $receivedMail['account_id'],
            $accountInfo['email'],
            $accountInfo['email_password'],
            $emailService['imap_url'],
            $emailService['imap_port'],
            $emailService['smtp_url'],
            $emailService['smtp_port'],
            'Amazon'
        );
        $return = $this->sendMail($account, $receivedMail['sender'], 0, $subject, $content, $attachFile);
        //发送成功
        if ($return['status']==1) {
            $receivedMail->is_replied = 1;      //更新被回复邮件的回复状态
            $receivedMail->isUpdate()->save();
            $group['is_replied'] =  1;
            $groupModel->save($group, ['id' => $groupOld['id']]);

            //统计数据；
            Report::statisticMessage(ChannelAccountConst::channel_amazon, $user_id, time(), [
                'buyer_qauntity' => 1,
                'message_quantity' => 1
            ]);

        }
//        else {
//            throw new Exception($this->mailSendError, 400);
//        }
        return $return;
    }


    /**
     * 添加发送记录
     * @param $params
     * @return int
     * @throws Exception
     */
    protected function addSendRecord($params)
    {
        Db::startTrans();
        try {
            $contentModel = new AmazonEmailContent();
            $contentModel->content = $params['content'];
            $email = new AmazonEmailList();
            $email->email_account_id = $params['email_account_id'];
            $email->account_id = $params['account_id'];
            $email->reply_email_id = $params['reply_email_id']?? 0;
            $email->buyer_name = $params['buyer_name'];
            $email->buyer_id = $params['buyer_id'];
            $email->sender = $params['sender'];
            $email->group_id = $params['group_id'];
            $email->type = 2;
            $email->box_id = 2;
            $email->receiver = $params['receiver'];
            $email->creater_id = $params['creater_id'];
            $email->create_time = $params['create_time'];
            $email->sync_time = $params['sync_time'];
            $email->order_no = $params['order_no'];
            $email->subject = $params['subject'];
            $email->attachments = $params['attachment'];
//            $email->content = $contentModel;
//            $email->together('content')->save();
            $email->save();
            $contentModel->id = $email->id;

            $contentModel->save();
            Db::commit();
            return $email->id;
        } catch (\Exception $ex) {
            Db::rollback();
            throw new Exception($ex->getMessage(), 500);
        }
    }

    /**
     * @param $params
     * @param $attachRootDir
     * @param $accountDir
     * @return string
     * @throws Exception
     */
    protected function getUploadAttachment(&$params, $attachRootDir, $accountDir)
    {
        $atthData = isset($params['file_data']) ? $params['file_data'] : '';
        $attachFile = '';
        if (!empty($atthData)) {
            $fileName = isset($params['file_name']) ? $params['file_name'] : '';
            if (empty($fileName)) {
                throw new Exception('附件名称未设置', 400);
            }
            $attachDir = $attachRootDir . '/' . $accountDir;
            if (!is_dir($attachDir) && !mkdir($attachDir, 0777, true)) {
                throw new Exception('附件上传目录创建失败', 401);
            }
            $attachFile = $attachDir . DIRECTORY_SEPARATOR . $fileName;
            AmazonEmailHelper::saveFile($atthData, $attachFile);
        }
        return $attachFile;
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    protected function getSendMailInfo(&$params)
    {
        $orderNo = isset($params['order_number']) ? $params['order_number'] : '';
        $customer_id = isset($params['customer_id']) ? $params['customer_id'] : '';
        $validate = new AmazonEmailValidate();
        //基于订单发送
        if ($orderNo) {
            if (!$validate->scene('send_by_order')->check($params)) {
                throw new Exception($validate->getError(), 400);
            }
            //获取订单信息
            $order = (new OrderService())->searchOrder([
                'order_number_type' => $params['order_number_type'],
                'order_number' => $params['order_number']
            ]);
            if (empty($order)) {
                throw new Exception('订单不存,请检查单号是否正确', 404);
            } elseif (empty($order->channel_order_number)) {
                throw new Exception('未找到对应的亚马逊订单,无法获取必要信息', 404);
            }

            $amamzonAccount = AmazonAccount::where(['id' => $order->channel_account_id])->find();
            if (!$amamzonAccount) {
                throw new Exception('对应的亚马逊平台单号未找到', 404);
            }

            //弃用 EmailAccount
//            $accountInfo = EmailAccounts::where(['channel_id' => 2, 'account_id' => $amamzonAccount['id']])->find();

            $site = substr($amamzonAccount['code'], -2);
            $site = (new AccountUserMapService())->amazonSiteRule($site);
            $account = substr($amamzonAccount['code'], 0, -2);
            $code = $account . $site;


            $accountInfo = accountModel::where(['a.channel_id' => $this->channel_id, 'a.account_code' => $code])->alias('a')
                ->join('email e','e.id=a.email_id','LEFT')
                ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

            if (empty($accountInfo)) {
                throw new Exception('该订单对应的平台账号未设置邮件功能', 404);
            } elseif ($accountInfo['status'] == 0 || $accountInfo['is_send'] == 0) {
                throw new Exception('该订单对应的平台账号不具有发送邮件的权限', 401);
            }

            $accountInfo['email_password'] = $this->encryption->decrypt($accountInfo['password']); //密码解密

            $emailService = ModelPostoffice::where([ 'id' => $accountInfo['post_id'] ])->find();

            $buyer = AmazonOrder::where('order_number', $order->channel_order_number)->find();
            $info = [
                'email_account_id' => $amamzonAccount['id'],
                'account_id' => $amamzonAccount['id'],
                'account_code' => $amamzonAccount['code'],
                'account_name' => $amamzonAccount['account_name'],
                'creator_id' => $customer_id,
                'email_account' => $accountInfo['email'],
                'email_password' => $accountInfo['email_password'],
                'imap_url' => $emailService['imap_url'],
                'imap_ssl_port' => $emailService['imap_port'],
                'smtp_url' => $emailService['smtp_url'],
                'smtp_ssl_port' => $emailService['smtp_port'],
                'buyer_id' => $buyer['user_name'],
                'buyer_name' => $buyer['platform_username'],
                'buyer_email' => $buyer['email'],
                'channel_order_number' => $order->channel_order_number,
                'shipping_id' => $order->shipping_id ?: '',
                'shipping_name' => $order->shipping_name ?: '',
                'shipping_number' => $order->shipping_number ?: '',
                'subject' => $params['subject'],
                'content' => $params['content'],
            ];
        } //不选择订单直接发送
        else {
            if (!$validate->scene('send_without_order')->check($params)) {
                throw new Exception($validate->getError(), 400);
            }

            $amamzonAccount = AmazonAccount::where(['id' => $params['account_id']])->find();
            if (!$amamzonAccount) {
                throw new Exception('对应的亚马逊平台单号未找到', 404);
            }

            //弃用 emailAccount
//            $accountInfo = EmailAccounts::where(['channel_id' => 2, 'account_id' => $params['account_id']])->find();

            $site = substr($amamzonAccount['code'], -2);
            $site = (new AccountUserMapService())->amazonSiteRule($site);
            $account = substr($amamzonAccount['code'], 0, -2);
            $code = $account . $site;

            $accountInfo = accountModel::where(['a.channel_id' => $this->channel_id, 'a.account_code' => $code])->alias('a')
                ->join('email e','e.id=a.email_id','LEFT')
                ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

            if (empty($accountInfo)) {
                throw new Exception('该账号未设置邮件功能', 404);
            } elseif ($accountInfo['status'] == 0 || $accountInfo['is_send'] == 0) {
                throw new Exception('该平台账号不具有发送邮件的权限', 401);
            }

            $accountInfo['email_password'] = $this->encryption->decrypt($accountInfo['password']); //密码解密

            $emailService = ModelPostoffice::where([ 'id' => $accountInfo['post_id'] ])->find();

            $info = [
                'email_account_id' => $amamzonAccount['id'],
                'account_id' => $amamzonAccount['id'],
                'account_code' => $amamzonAccount['code'],
                'account_name' => $amamzonAccount['account_name'],
                'creator_id' => $customer_id,
                'email_account' => $accountInfo['email'],
                'email_password' => $accountInfo['email_password'],
                'imap_url' => $emailService['imap_url'],
                'imap_ssl_port' => $emailService['imap_port'],
                'smtp_url' => $emailService['smtp_url'],
                'smtp_ssl_port' => $emailService['smtp_port'],
                'buyer_id' => $params['buyer_name'],
                'buyer_name' => '',
                'buyer_email' => $params['buyer_email'],
                'channel_order_number' => '',
                'shipping_id' => '',
                'shipping_name' => '',
                'shipping_number' => '',
                'subject' => $params['subject'],
                'content' => $params['content'],
            ];
        }
        return $info;
    }

    /**
     * @param EmailAccount $emailAccount
     * @return int
     * @throws Exception
     */
    public function receiveEmail(EmailAccount $emailAccount, $email_account_id)
    {
        try {
            $syncQty = 0;
            $mailReceiver = MailReceiver::getInstance();
            $mailReceiver->setEmailAccount($emailAccount);
            $mailsIds = $mailReceiver->searchMailbox('ALL');

            if (empty($mailsIds) && MailReceiver::$isError) {
                throw new Exception(MailReceiver::$lastError, 500);
            }

            //查出上一次保存的UID，比这个UID小的，就可以跳过了；
            $amazon_account_id = $emailAccount->getPlatformAccount();
//            $email = $emailAccount->getEmailAccount();
            $maxUid = Cache::store('AmazonEmail')->getMaxUid($email_account_id,$emailAccount->mailBox);

            $time = time();
            $mailRecord = new AmazonEmailList();
            $contentModel = new AmazonEmailContent();
            $groupModel = new AmazonEmailGroup();
            $keywordMatching = new KeywordMatching();

            foreach ($mailsIds as $id) {
                if ($id <= $maxUid) {
                    //比保存的邮件uid小的肯定是下载过的，直接跳过；
                    continue;
                }
                //$mail = $mailReceiver->getMail($id, false);
                $mail = null;
                for ($i = 0; $i <= 3; $i++) {
                    try {
                        $mail = $mailReceiver->getMail($id, false);
                        break;
                    } catch (Exception $e) {
                        if($i  >= 3) {
                            throw new Exception($e->getMessage());
                        }
                    }
                }

                if (!$mail) {
                    continue;
                }

                $syncQty++;
                $attachs = $mail->getAttachments();

                $json = [];
                if ($attachs) {
                    foreach ($attachs as $attach) {
                        $json[] = [
                            'name' => $attach->name,
                            'path' => mb_substr($attach->filePath, mb_strlen(ROOT_PATH) - 1),
                        ];
                    }
                }

                /**
                 * 保存邮件数组分3部
                 * 1. 分组：
                 *    正则匹配看需不需要分组，再分组或不分组
                 * 2. 保存邮件
                 * 3. 更新分组的最后邮件ID
                 */
                Db::startTrans();
                try {
                    //发件箱邮件标志
                    $sent_box_mail = false;
                    //系统转发的客服邮件标志
                    $system_forwarding_mail = false;
                    //分组状态0无需分组，1新建分组，2已有分组；
                    $group_status = 0;
                    $group = [];

                    //是否发件箱
                    if (strtolower($emailAccount->mailBox) == 'sent')
                    {
                        $sent_box_mail = true;

                        $siteRegular = '/^.*\.([a-z0-9]+)$/i';
                        if(preg_match($siteRegular, $mail->toString,$match)){
                            $mail->site = $match[1];
                        }
                    }

                    //匹配出需要进入分组统计的邮件；@marketplace.amazon 的邮相才是用户主动发送的邮件；
                    $group['box_id'] = $groupWhere['box_id'] = $mail->box;
//                    $group['order_no'] = $groupWhere['order_no'] = $mail->orderNo;
                    $group['order_no'] = $mail->orderNo;
//                    $group['sender'] = $groupWhere['sender'] = $mail->fromAddress;
                    $this->get_group_sender_param($group,$groupWhere,$mail,$system_forwarding_mail);
                    $group['receiver'] = $groupWhere['receiver'] = $mail->toString;
                    //拿缓存数据
//                    $groupOld = Cache::store('AmazonEmail')->getGroupData($groupWhere);

                    //如果是发件箱邮件，发件人和收件人反过来
                    if ($sent_box_mail)
                    {
                        //邮件获取的email 比 order 获取的多了一段，用正则去掉
                        $buyer_email = preg_replace('/\+.*(?=@)/', '', $mail->toString);
                        $group['receiver'] = $groupWhere['receiver'] = $buyer_email;

                        $temp = $groupWhere['sender'];
                        $groupWhere['sender'] = $groupWhere['receiver'];
                        $groupWhere['receiver'] = $temp;

                        $group['sender'] = $groupWhere['sender'];
                        $group['receiver'] = $groupWhere['receiver'];
                    }

                    //缓存不存在，再去数据库里找；
                    $groupOld = [];
                    if (empty($groupOld)) {
                        $groupOld = $groupModel->where($groupWhere)->find();
                    }

                    //如果分组不存在
                    if(empty($groupOld)) {
                        $group_status = 1;
                        $group['msg_count'] = 1;
                        $group['untreated_count'] = 1;
                        $group['first_receive_time'] = $mail->mailTime;
                        $group['last_receive_time'] = $mail->mailTime;
                        $group['created_time'] = $time;
                        $group['update_time'] = $time;
                        $group['site'] = $mail->site;
                        //是否已回复 0不需回，1已回，2未回复
                        $group['is_replied'] = ($group['box_id'] == 1 || $sent_box_mail || $system_forwarding_mail)? 0 : 2;
                        $group['is_read'] = 0;
                        $group['account_id'] = $this->convert_to_own_account_id($emailAccount->getPlatformAccount(), $mail->site);
                        $group['id'] = $groupModel->insert($group, false, true);
                    } else {
                        $group_status = 2;
                        $group['msg_count'] = $groupOld['msg_count'] + 1;
                        $group['untreated_count'] = $groupOld['untreated_count'] + 1;
                        $group['first_receive_time'] = $groupOld['first_receive_time'];
                        $group['last_receive_time'] = $mail->mailTime;
                        $group['created_time'] = $groupOld['created_time'];
                        $group['update_time'] = $time;
                        $group['site'] = $mail->site;
                        //是否已回复 0不需回，1已回，2未回复
                        $group['is_replied'] = ($group['box_id'] == 1 || $sent_box_mail || $system_forwarding_mail)? 0 : 2;
                        $group['is_read'] = 0;
                        $group['account_id'] = $this->convert_to_own_account_id($emailAccount->getPlatformAccount(), $mail->site);
                        $groupModel->update($group, ['id' => $groupOld['id']]);
                        $group['id'] = $groupOld['id'];
                    }


                    //插入邮件数据；
                    $data = [];
                    $data['group_id'] = $group['id'];
                    $data['email_account_id'] = $email_account_id;
                    $data['account_id'] = $this->convert_to_own_account_id($emailAccount->getPlatformAccount(), $mail->site);
                    $data['email_uid'] = $id;
                    if ($system_forwarding_mail){
                        $data['receiver'] = $group['sender'];
                        $data['sender'] = $group['receiver'];
                    }else{
                        $data['receiver'] = $mail->toString;
                        $data['sender'] = $mail->fromAddress;
                    }
                    $data['sync_time'] = $mail->mailTime;
                    $data['platform'] = $mail->platformName;
                    $data['site'] = $mail->site;
                    $data['order_no'] = $mail->orderNo;
                    $data['box_id'] = $mail->box;
                    $data['is_read'] = 0;
                    $data['type'] = 1;
                    $data['subject'] = mb_substr($this->convertToUtf8($mail->subject), 0, 1000, 'utf-8');
                    $data['attachments'] = json_encode($json);
                    $data['create_time'] = $time;
                    if ($sent_box_mail)
                    {
                        $data['buyer_id'] = preg_replace('/\+.*(?=@)/', '', $data['receiver']);
                        $data['status'] = 1;
                        $data['type'] = 2;
                    }
                    $mailRecord->insert($data);
                    $last_id = $mailRecord->getLastInsID();

                    $content = $this->convertToUtf8($mail->getBody());
                    //插入邮件内容表数据；
//                    $contentModel->insert([
//                        'id' => $last_id,
//                        'content' => $content,
//                    ]);

                    $this->saveHtmlToMongo($last_id,$content);

                    //再次更新分组数据ID；新建分组和已有分组更新数据，没有分组不更新数据
                    if ($group_status == 1) {
                        $group['first_email_id'] = $group['last_email_id'] = $last_id;
                        $groupModel->update(['first_email_id' => $last_id, 'last_email_id' => $last_id], ['id' => $group['id']]);
                    } else if ($group_status == 2) {
                        $group['first_email_id'] = $groupOld['first_email_id'];
                        $group['last_email_id'] = $last_id;
                        $groupModel->update(['last_email_id' => $last_id], ['id' => $group['id']]);
                    }

                    Db::commit();

                    /**
                     * 触发第一封邮件事件
                     */
                    if ($group_status == 1)
                    {
                        $this->trigger_first_email_event($data['account_id'], $data['order_no'], $last_id, $data['receiver']);
                    }


                    /**
                     * 关键词匹配
                     */
                    $param = [
                        'channel_id'=>2,
                        'message_id'=>$last_id,
                        'account_id'=>$data['account_id'],
                        'message_type'=>1,
                        'buyer_id'=>$data['sender'],
                        'receive_time'=>$data['sync_time'],
                    ];
                    $keywordMatching->keyword_matching($content,$param);

                    //存缓存数据
//                    Cache::store('AmazonEmail')->setGroupData($groupWhere, $group);
                    //echo $group['id'], '   ', $last_id,"\n";

                    unset($mail, $groupOld, $data, $group);
                    //$mailReceiver->markMailAsRead($id);//下载成功后，把邮件标记为已读，防止下次下载；
                    Cache::store('AmazonEmail')->setMaxUid($email_account_id, $id, $emailAccount->mailBox);//保存下载的邮件ID
                } catch (Exception $e) {
                    Db::rollback();
                    throw $e;
                }
            }

            unset($mailReceiver);
            unset($amazon_account_id);
            unset($email);
            unset($maxUid);
            gc_collect_cycles();
            return $syncQty;
        } catch (\Exception $ex) {
//            Cache::handler()->hSet('hash:email_sync_log:' . MailReceiver::$syncingEmailAccount, date('YmdHis'), $ex->getMessage());
            throw new Exception('Message:'. $ex->getMessage(). '; File:'. $ex->getFile(). ';Line:'. $ex->getLine(). ';');
        }
    }

    /**
     * @param $group
     * @param $groupWhere
     * @param $mail
     */
    private function get_group_sender_param(&$group, &$groupWhere, $mail, &$system_forwarding_mail)
    {
        $patten = '/auto-communication@amazon/';

        if (preg_match($patten, $mail->fromAddress)) {
            $system_forwarding_mail = true;
            $buyer_email = $this->get_email_from_order_no($mail);
            $group['sender'] = $groupWhere['sender'] = $buyer_email;
        }else {
            //邮件获取的email 比 order 获取的多了一段，用正则去掉
            $buyer_email = preg_replace('/\+.*(?=@)/', '', $mail->fromAddress);
            $group['sender'] = $groupWhere['sender'] = $buyer_email;
        }
    }

    /**
     * @param $mail
     * @return mixed
     */
    private function get_email_from_order_no($mail)
    {
        $order_no = $mail->orderNo;
        if (!empty($order_no)) {
            $amazonOrderModel = new AmazonOrder();
            $buyer_email = $amazonOrderModel->where('order_number', $mail->orderNo)->value('email');
            return $buyer_email ?: $mail->fromAddress;
        } else {
            return $mail->fromAddress;
        }
    }

    /*
     * 1.亚马逊邮件有部分是由一个账号统一收取的
     * 2.存邮件的时候，根据账号id和站点找到当前邮件的account_id
     */
    public function convert_to_own_account_id($account_id, $site){
        if(strtolower($site) == 'com'){
            $site = 'us';
        }
        $account_code = $this->amazonAccount->where('id', $account_id)->value('code');
        if(empty($account_code)){
            return $account_id;
        }
        $part_account_code = substr($account_code,0,strlen($account_code)-2);
        $new_account_code = $part_account_code . $site;
        $res = $this->amazonAccount->where('code', $new_account_code)->value('id');
        if(empty($res)){
            return $account_id;
        }
        return $res;

    }

    protected function convertToUtf8($string = '')
    {
        $encode = mb_detect_encoding($string, array("ASCII","UTF-8","GB2312","GBK","BIG5","Shift-JIS","SJIS","EUC-JP","ISO-2022-JP","ISO-8859-1"));
        if ($encode){
            $string = iconv($encode,"UTF-8",$string);
        }else{
            $string = iconv("UTF-8","UTF-8//IGNORE",$string);   //识别不了的编码就截断输出
        }
        return $string;
    }

    /**
     * 正常情况下，根据邮件UID肯定是可以拿到邮件，但是有时因为网络或服务器问题会出现异常导致获取邮件失败，出现异则重试几次
     * @param $mailReceiver
     * @param $mail_uid
     * @param int $testnum 出现异常的重试次数;
     * @return mixed
     */
    public function getMail($mailReceiver, $mail_uid, $testnum = 3) {
        try {
            $mail = $mailReceiver->getMail($mail_uid, false);
            return $mail;
        } catch (Exception $e) {
            $testnum--;
            if($testnum  < 0) return false;
            return $this->getMail($mailReceiver, $mail_uid, $testnum);
        }
    }

    /**
     * 重新发送邮件,或队列发送邮件
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function reSendMail($params)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $userInfo = Common::getUserInfo();
        $user_id = empty($userInfo) ? 0 : $userInfo['user_id'];

        //传数组
        if(is_array($params)) {
            $mailId = isset($params['mail_id']) ? $params['mail_id'] : '';
        } else if (is_string($params) || is_int($params)) {
            $mailId = (int)$params;
        } else {
            throw new Exception('邮件id类型不正确', 400);
        }
        if (empty($mailId)) {
            throw new Exception('邮件id未设置', 400);
        }
        //根据mailId找出邮件；
        $mail = AmazonEmailList::where(['id' => $mailId])->find();
        if (empty($mail)) {
            throw new Exception('未找到需要发送的邮件', 404);
        } elseif ($mail['status'] == 1) {
            throw new Exception('该邮件已经发送成功，无需重发', 404);
        }
        $content = AmazonEmailContent::where('id', $mailId)->find();

        //弃用 EmailAccount
//        $emailAccount = EmailAccounts::where('id', $mail['email_account_id'])->find();

        $amamzonAccount = AmazonAccount::where(['id' => $mail['account_id']])->find();
        if (!$amamzonAccount) {
            throw new Exception('对应的亚马逊平台单号未找到', 404);
        }

        $site = substr($amamzonAccount['code'], -2);
        $site = (new AccountUserMapService())->amazonSiteRule($site);
        $account = substr($amamzonAccount['code'], 0, -2);
        $code = $account . $site;

        $emailAccount = accountModel::where(['a.channel_id' => $this->channel_id, 'a.account_code' => $code])->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')
            ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

        if (empty($emailAccount)) {
            throw new Exception('未找到邮件发送账号,请检查是否被删除', 404);
        } elseif ($emailAccount['status'] == 0 || $emailAccount['is_send'] == 0) {
            throw new Exception('邮件发送账号不具有发送邮件的权限,请检查是否被修改', 401);
        }

        $emailAccount['email_password'] = $this->encryption->decrypt($emailAccount['password']); //密码解密

        $emailService = ModelPostoffice::where([ 'id' => $emailAccount['post_id'] ])->find();


        if (!empty($mail['attachments'])) {
            $mail['attachments'] = ROOT_PATH . $mail['attachments'];
        }
        $account = new EmailAccount(
            $mail['account_id'],
            $emailAccount['email'],
            $emailAccount['email_password'],
            $emailService['imap_url'],
            $emailService['imap_port'],
            $emailService['smtp_url'],
            $emailService['smtp_port'], 'amazon');
        $return = $this->sendMail($account, $mail['receiver'], $mailId, $mail->subject, $content->content, $mail->attachments);
        if ($return['status']==1) {
            $mail->is_replied = 1;      //更新被回复邮件的回复状态
            $mail->isUpdate()->save();
            $groupModel = new AmazonEmailGroup();
            $group['is_replied'] =  1;
            $groupModel->save($group, ['id' => $mail['group_id']]);

            //统计数据；
            Report::statisticMessage(ChannelAccountConst::channel_amazon, $user_id, time(), [
                'buyer_qauntity' => 1,
                'message_quantity' => 1
            ]);
        }
//        else {
//            throw new Exception($this->mailSendError, 400);
//        }

        return $return;
    }


    /**
     * 标记邮件为已读
     * 把当前邮件标记为已读，然后把分组检查本分组的数量，已读和未读的数据更新到分组表
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function markReadEmail($id = 0)
    {
        //查看邮件是否存在；
        $listModel = new AmazonEmailList();
        $email = $listModel->where(['id' => $id])->find();
        if (empty($email)) {
            throw new JsonErrorException('数据不存在！');
        }
        $groupModel = new AmazonEmailGroup();

        try {
            Db::startTrans();
            //先更新邮件；
            $listModel->update(['is_read' => 1], ['id' => $id]);
            $numArr = $listModel->where(['group_id' => $email['group_id']])->field('count(id) total,is_read')->group('is_read')->select();

            //更新group数据；
            $msg_ount = 0;
            $unread_count = 0;
            foreach($numArr as $val) {
                $msg_ount += $val['total'];
                if($val['is_read'] == 0) {
                    $unread_count = $val['total'];
                }
            }
            //当未读消息为0时，把分组标记为已读；
            if ($unread_count == 0) {
                $groupModel->update(['msg_count' => $msg_ount, 'untreated_count' => 0, 'is_read' => 1], ['id' => $email['group_id']]);
            } else {
                $groupModel->update(['msg_count' => $msg_ount, 'untreated_count' => $unread_count], ['id' => $email['group_id']]);
            }

            Db::commit();
            return true;
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 匹配模板内容
     * @param number $id 邮件信息id
     * @param number $template_id 模板id
     * product_name
     */
    function matchFieldData($id = 0)
    {
        $data = [];
        //查找邮件
        $EmailListModel = new AmazonEmailList();
        $email_info = $EmailListModel->where(['id' => $id])->find();
        if (empty($email_info)) {
            return $data;
        }
        //获取卖家email
        $seller_id = '';
        if (param($email_info, 'account_id')) {
            $account = Cache::store('AmazonAccount')->getTableRecord($email_info['account_id']);
            $seller_id = $account['account_name'];
        }
        //获取订单数据
        $order = [];
        $order_address = [];
        if (param($email_info, 'order_no')) {
            $OrderModel = new Order();
            $orderAddressModel = new OrderAddress();
            //$field = 'payment_time,actual_total,user_name,skuList.*';
            $field = '*';
            $order = $OrderModel->field($field)->where(['channel_order_number' => $email_info['order_no']])->find();
            if ($order) {
                $order_address = $orderAddressModel->field($field)->where(['order_id' => $order['id']])->find();
            }
        }

        $data = [
            'order_id' => $email_info['order_no'], //平台订单号
            'seller_id' => $seller_id,  //卖家id
            'seller_email' => $email_info['receiver'],     //卖家email
            'buyer_id' => $email_info['receiver'], //买家id

            'buyer_name' => param($order, 'buyer'),//买家名称
            'amount' => param($order, 'order_amount'), //订单金额
            'payment_date' => param($order, 'pay_time') ? date('Y-m-d H:i:s', $order['pay_time']) : '', //支付时间
            'delivery_date' => param($order, 'shipping_time') ? date('Y-m-d H:i:s', $order['shipping_time']) : '',//发货时间
            'carrier' => param($order, 'synchronize_carrier'), //物流商
            'shipping_number' => param($order, 'synchronize_tracking_number'),//跟踪号

            'recipient_name' => param($order_address, 'consignee'), //收货人
            'recipient_address' => param($order_address, 'country_code') . ' ' . param($order_address, 'city') . ' ' . param($order_address, 'province') . ' ' . param($order_address, 'address'),//收货人地址

        ];//填充匹配的字段数据

        return $data;
    }



    /**
     * amazon帐号数量；
     */
    public function getAmazonAccountMessageTotal($params) {

        $user = Common::getUserInfo();
        $this->uid = $user['user_id'];

        //不需要根据订单的类型来求值，会分别去查；
        if (isset($params['msg_type'])) {
            unset($params['msg_type']);
        }
        //要返回的数据；
        $returnData = [
            'accounts' => [],
            'types' => []
        ];
        $where=[];
        $where = $this->getAmazonCondition($params);

        //当不是test用户登录时；
        $accountIds = [];
        //测试用户和超级管理员用户；
        if ($this->uid == 0 || $this->isAdmin($this->uid)) {
            $accountIds = ChannelUserAccountMap::where([
                'channel_id' => $this->channel_id
            ])->column('account_id');
        } else {
            $uids = $this->getUnderlingInfo($this->uid);
            $accountIds = ChannelUserAccountMap::where([
                'customer_id' => ['in', $uids],
                'channel_id' => $this->channel_id
            ])->column('account_id');
        }

        if (empty($where['account_id'])) {
            $where['account_id'] = ['in', $accountIds];
        }

        //以下为amazon帐号；
        $groupModel = new AmazonEmailGroup();
        $groupList = $groupModel->where($where)
            ->group('account_id')
            ->field('count(id) count, account_id')
            ->order('count', 'desc')
            ->select();
//        if (!empty($groupList)) {
            $newAccounts = [];
            $sort = [];
            foreach ($accountIds as $accountId) {
                $sort[$accountId] = 0;
            }
            foreach ($groupList as $group) {
                $sort[$group['account_id']] = $group['count'];
            }
            arsort($sort);
            $cache = Cache::store('AmazonAccount');
            $allTotal = 0;
            foreach ($sort as $account_id => $total) {
                $account = $cache->getTableRecord($account_id);
                $tmp = [];
                $tmp['id'] = $account_id;
                $tmp['code'] = $account['code'] ?? $account_id;
                $tmp['count'] = $total;
                $newAccounts[] = $tmp;
                $allTotal += $total;
            }
            array_unshift($newAccounts, [
                'id' => 0,
                'code' => '全部',
                'count' => $allTotal,
            ]);
//        }

        return ['data' => $newAccounts];
    }

    /*
     * 返回amazon查询参数；
     * @param $params
     */
    public function getAmazonCondition($params)
    {
        $where = [];
//        $where['status'] = 0;

        //未回复，客服邮件
        $where['is_replied'] = 2;
        $where['box_id'] = 2;

        if (!empty($params['account_id'])) {
            $where['account_id'] = $params['account_id'];
        }
        if (!empty($params['customer_id'])) {
            $where['customer_id'] = $params['customer_id'];
        }

        //传过来的account_code 其实是亚马逊的帐号ID
        if (!empty($params['account_code'])) {
            $account_code = AmazonAccount::where(['id' => ['in', $params['account_code']]])
                ->column('code');
            $sites = [];
            foreach ($account_code as $code){
                $site = strtolower(substr($code,-2));
                if($site == 'us'){
                    $sites = 'com';
                }else{
                    $sites = $site;
                }
            }

            $where['site'] = ['in', $sites];
            $where['account_id'] = ['in', $params['account_code']];

        }

        //以下时间筛选；
        $time_start = empty($params['time_start']) ? 0 : strtotime($params['time_start']);
        $time_end = empty($params['time_end']) ? 0 : strtotime($params['time_end']);
        if (!empty($time_start) && empty($time_start)) {
            $where['last_receive_time'] = ['>', $time_start];
        }
        if (empty($time_end) && !empty($time_end)) {
            $where['last_receive_time'] = ['<', $time_end + 86400];
        }
        if (!empty($time_start) && !empty($time_end)) {
            $where['last_receive_time'] = ['between', [$time_start, $time_end + 86400]];
        }

        return $where;
    }


    /**
     * 获取所有站点
     * @return array
     */
    public function getAllSite()
    {
        $site=[
            ['label'=> 'CA'],
            ['label'=> 'US'],
            ['label'=> 'DE'],
            ['label'=> 'FR'],
            ['label'=> 'ES'],
            ['label'=> 'UK'],
            ['label'=> 'IT'],
            ['label'=> 'MX'],
            ['label'=> 'JP'],
        ];

        return $site;
    }

    /*
     * 获取所有可发送邮件的账号
     */
    public function emailAccount(){

        $where['a.channel_id'] = $this->channel_id;
        $where['e.status'] = 1;
        $where['e.is_send'] = 1;

        $accounts = Db::table('account')->where($where)->field('a.account_code')->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')->select();


        if(empty($accounts)){
            return false;
        }

        $account_code = array_column($accounts, 'account_code');

        $amazonAccount = new AmazonAccount();

        $amazonwhere['code'] = array('in',$account_code);
        $res = $amazonAccount->where($amazonwhere)->field('id,code')->select();
        return $res;
    }

    /**
     * 触发第一封邮件事件
     * @param array $order
     */
    public function trigger_first_email_event($account_id, $order_no, $last_id, $receiver)
    {
        $event_name = 'E14';
        $order_data = [
            'channel_id' => ChannelAccountConst::channel_amazon,//Y 渠道id
            'account_id' => $account_id,//Y 账号id
            'channel_order_number'=>$order_no,//Y 渠道订单号
            'receiver' => preg_replace('/\+.*(?=@)/', '', $receiver),//Y 收件人
            'amazon_message_data' => [ //N amazon消息特有字段
                'create_time'=> time(),
            ],
            'extra_params'=>[
                'message_id'=>$last_id,
            ],
        ];
        (new MsgRuleHelp())->triggerEvent($event_name, $order_data);
    }


    /**
     * 保存html到mongodb
     * @param $id
     * @param $html
     * @return array
     */
    private function saveHtmlToMongo($id, $html)
    {
        $return = [
            'status'=>0,
            'message'=>'save fail'
        ];

        if (empty($id) || !is_numeric($id)) {
            $return['message'] = 'params error';
            return $return;
        }

        try {
            $db = Db::connect(Config::get('mongodb'));
            $db->table('amazon_email_detail')->insert(['message_id' => intval($id), 'html' => $html]);
            $return['status'] = 1;
            $return['message'] = 'save success';
            return $return;
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /**
     * 从MongoDB获取html
     * @param $id
     * @return array
     */
    public function getHtmlFromMongo($id)
    {
        $return = [
            'status'=>0,
            'message'=>'get fail',
            'data'=>''
        ];

        if (empty($id) || !is_numeric($id)) {
            $return['message'] = 'params error';
            return $return;
        }

        try {
            $db = Db::connect(Config::get('mongodb'));
            $res = $db->table('amazon_email_detail')->where(['message_id'=>intval($id)])->field('html')->find();

            $return['status'] = 1;
            $return['message'] = 'get success';
            $return['data'] = $res['html'];
            return $return;
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    public function moveHtmlToMongo($start_id)
    {
        try{
            set_time_limit(0);

            $return = [
                'message'=>'move fail',
                'process_id'=>0,
                'success_count'=>0,
            ];

            $db = Db::connect(Config::get('mongodb'));

            //成功数
            $success_count = 0;

            $current_process_id = $start_id;

            $where['id'] = array(array('gt',$current_process_id),array('lt',$current_process_id+800));

            //循环处理
            while ($datas = Db::table('amazon_email_content')->where($where)->field('id,content')->order('id asc')->select())
            {
                foreach ($datas as $data)
                {
                    $current_process_id=$data['id'];
                    $db->table('amazon_email_detail')->insert(['message_id' => intval($data['id']), 'html' => $data['content']]);
                    $success_count++;
                }
                $where['id'] = array(array('gt',$current_process_id),array('lt',$current_process_id+800));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }


        //返回处理结果
        $return['message'] = 'move success';
        $return['process_id'] = $current_process_id;
        $return['success_count'] = $success_count;
        return $return;
    }

    public function getMaxEmailUid($type)
    {
        $max_uid_key = 'hash:AmazonEmailMaxUid';

        $where['type'] = $type;
        if ($type == 1){
            $lists = Db::table('amazon_email')->where($where)->field('email_account_id, max(email_uid) as max_uid')->group('email_account_id')->select();
        }else {
            $lists = Db::table('amazon_email')->where($where)->field('email_account_id, max(email_uid) as max_uid')->group('email_account_id')->select();
        }

        foreach ($lists as $list)
        {
            $email_account_id = $list['email_account_id'];
            $mail_box = ($type == 2)?'Sent':'INBOX';
            $uid = $list['max_uid'];

            $hashKey = $email_account_id. '-'. $mail_box;
            Cache::handler(true)->hset($max_uid_key, $hashKey, $uid);
        }
    }
}