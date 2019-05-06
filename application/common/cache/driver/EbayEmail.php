<?php
namespace app\common\cache\driver;

use app\common\cache\Cache;
use app\common\model\ebay\EbayEmail as EbayEmailList;

/**
 * Created by tanbin.
 * User: PHILL
 * Date: 2016/11/5
 * Time: 11:44
 */
class EbayEmail extends Cache
{

    protected $max_uid_key = 'hash:EbayEmailMaxUid';


    /**
     * 获取最大email_uid
     * @param $email
     * @param $email_account_id
     * @return int|mixed|string
     */
    public function getMaxUid($email_account_id)
    {
        $hashKey = $email_account_id;
        $result = Cache::handler(true)->hget($this->max_uid_key, $hashKey);
        if($result){
            return $result;
        }

        $data = EbayEmailList::where(['email_account_id' => $email_account_id])->field('id,email_uid')
            ->order('email_uid', 'desc')
            ->find();
        return empty($data)? 0 : $data->email_uid;
    }

    public function setMaxUid($email_account_id, $uid)
    {
        $hashKey = $email_account_id;
        if(Cache::handler(true)->hset($this->max_uid_key, $hashKey, $uid)) {
            return true;
        }
        return false;
    }
}
