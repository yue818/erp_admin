<?php

namespace app\api\service;

use app\common\cache\Cache;
use app\common\model\ServerUserAccountInfo;
use app\common\model\User;
use app\index\service\ManagerServer;
use app\index\service\AutomationService;
use think\Exception;
use think\helper\Str;
use think\Config;
use Odan\Jwt\JsonWebToken;

/**
 * Created by PhpStorm.
 * User: phill
 * Date: 2019/1/15
 * Time: 10:28 PM
 */
class Automation extends Base
{

    /**
     * token 有效期
     */
    const TOKEN_EXPIRE = 86400;

    /**
     * 获取验证码
     */
    public function code()
    {
        $username = $this->requestData['username'];
        try {
            $where = [
                'username' => $username,
                'is_invalid' => 1
            ];
            $userId = (new User())->where($where)->value('id');
            if (!$userId) {
                $this->retData['status'] = -1;
                $this->retData['message'] = '用户不存在';
            } else {
                //1.生成codes
                $codes = $this->getRand();
                //2.保存缓存
                Cache::store('User')->userCodes($userId, $codes);
                //3.发送验证码
                $server = new Server();
                $server->sendMessage($userId,
                    '代理软件登录验证码为' . $codes . ',打死也不要告诉别人。',
                    '代理软件录验证码');
                $this->retData['message'] = '发送成功，请查收,5分钟内有效';
            }

        } catch (Exception $e) {
            $this->retData['status'] = -1001;
            $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
        }
        return $this->retData;
    }

    /**
     * 验证码登录
     */
    public function loginCode()
    {
        $username = $this->requestData['username'];
        $codes = $this->requestData['codes'];
        try {
            $where = [
                'username' => $username,
                'is_invalid' => 1
            ];
            $user = (new User())->where($where)->find();
            $userId = $user['id'] ?? '';
            if (!$userId) {
                $this->retData['status'] = -1;
                $this->retData['message'] = '用户不存在';
            } else {
                //1.拿缓存codes
                $cacheCodes = Cache::store('User')->userCodes($userId);
                //2.验证codes
                if (!$cacheCodes) {
                    $this->retData['status'] = -1;
                    $this->retData['message'] = '验证码已过期，请重新获取';
                } elseif ($cacheCodes != $codes) {
                    $this->retData['status'] = -1;
                    $this->retData['message'] = '验证码不正确，请查证后再次输入';
                } else {
                    //3.生成tokens
                    $tokens = (new \app\common\model\User())->createToken($user);
                    $this->retData['tokens'] = $tokens;
                    $this->retData['expire'] = self::TOKEN_EXPIRE;
                }
            }

        } catch (Exception $e) {
            $this->retData['message'] = 'ERP错误，请联系管理员';
            $this->retData['status'] = 'failed';
        }
        return $this->retData;
    }


    /**
     * 域名用户登录
     */
    public function loginUser()
    {
        $loginAccount = $this->requestData['login_account'];

        try {
            $user = (new ManagerServer())->getUserInfoByLogin($loginAccount);
            if ($user) {
                $tokens = (new \app\common\model\User())->createToken($user);
                $this->retData['tokens'] = $tokens;
                $this->retData['expire'] = self::TOKEN_EXPIRE;
            } else {
                $this->retData['status'] = -1;
                $this->retData['message'] = '不存在该用户';
            }
        } catch (\Exception $e) {
            $this->retData['status'] = -1;
            $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
        }
        return $this->retData;
    }


    /**
     * 服务器用户登录
     */
    public function loginServerUser()
    {
        $loginAccount = $this->requestData['login_account'];
        $where = [
            'name' => $this->requestData['computer'],
            'ip' => $this->requestData['ip'],
            'mac' => $this->requestData['mac'],
        ];
        try {
            $user = (new ManagerServer())->getServerUserInfoByLogin($loginAccount, $where);
            if ($user) {
                $tokens = (new \app\common\model\User())->createToken($user);
                $this->retData['tokens'] = $tokens;
                $this->retData['expire'] = self::TOKEN_EXPIRE;
            } else {
                $this->retData['status'] = -1;
                $this->retData['message'] = '不存在该用户';
            }
        } catch (Exception $e) {
            $this->retData['status'] = -1;
            $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
        }
        return $this->retData;
    }

    /**
     * 获取店铺列表
     */
    public function shopList()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        $where = [];
        if (isset($this->requestData['mac']) || isset($this->requestData['ip']) || isset($this->requestData['computer'])) {
            $where = [
                'name' => $this->requestData['computer'],
                'ip' => $this->requestData['ip'],
                'mac' => $this->requestData['mac'],
            ];
        }
        if ($userId) {
            $this->retData['accounts'] = (new ManagerServer())->getAgencyShopListByUserId($userId, $where);
            $this->retData['ip_url'] = [
                'times' => 0,
                'get_ip_url' => 'http://getip.rondaful.com:33666',
            ];
            $this->retData['message'] = '获取成功';
        }
        return $this->retData;
    }

    /**
     * 获取店铺列表新方式
     */
    public function shopListNew()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        $where = [];
        if (isset($this->requestData['mac']) || isset($this->requestData['ip']) || isset($this->requestData['computer'])) {
            $where = [
                'name' => $this->requestData['computer'],
                'ip' => $this->requestData['ip'],
                'mac' => $this->requestData['mac'],
            ];
        }
        if ($userId) {
            $this->retData['data'] = (new ManagerServer())->getAgencyListByUserId($userId, $where);
            $this->retData['ip_url'] = [
                'times' => 0,
                'get_ip_url' => 'http://getip.rondaful.com:33666',
            ];
            $this->retData['message'] = '获取成功';
        }
        return $this->retData;
    }


    /**
     * 拉取账号代理信息
     */
    public function shopProxy()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);

        if ($userId) {
            $id = $this->requestData['shop_id'];
            $module = $this->requestData['relation_module'] ?? 0;
            $proxy = (new ManagerServer())->getAgencyShopProxyByIds($id, $userId, $module);

            // 虚拟机服务器，不返回代理
            $isVirtual = $this->isVirtualMachine($token);
            if ($isVirtual && $proxy){
                    $proxy['proxy_ip'] =
                    $proxy['proxy_agent'] =
                    $proxy['proxy_port'] =
                    $proxy['proxy_user_name'] =
                    $proxy['proxy_user_password'] = '';
            }

            if ($proxy) {
                $this->retData['account'] = $proxy;
                $this->retData['message'] = '获取成功';
            } else {
                $this->retData['message'] = '店铺不属于你的';
                $this->retData['status'] = '1002';
            }
        }
        return $this->retData;
    }


    /**
     * 获取店铺详情
     */
    public function shopDetail()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        if ($userId) {
            $id = $this->requestData['shop_id'];
            $module = $this->requestData['relation_module'] ?? 0;
            $site = $this->requestData['site'] ?? '';
            try {
                $shop = (new ManagerServer())->getAgencyShopDetailByIds($id, $userId, $module, $site);
                if ($shop) {
                    $this->retData['account'] = $shop;
                    $this->retData['message'] = '获取成功';
                } else {
                    $this->retData['message'] = '店铺不属于你的';
                    $this->retData['status'] = '1002';
                }
            } catch (Exception $ex) {
                $this->retData['message'] = $ex->getMessage();
                $this->retData['status'] = '1002';
                $this->retData['file'] = $ex->getFile();
                $this->retData['line'] = $ex->getLine();
            }

        }
        return $this->retData;
    }

    private function isAccount($userId)
    {
        $ManagerServer = new ManagerServer();
        $job = $ManagerServer->getUserJob($userId);
        if ($job === 'account') {
            return 1;
        }
        return 0;
    }

    /**
     * 记录cookie
     */
    public function record()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        if ($userId) {
            $data = [
                'user_id' => $userId,
                'channel_id' => 0,
                'server_id' => 0,
                'account_id' => $this->requestData['shop_id'],
                'cookie' => $this->requestData['cookie'],
                'profile' => $this->requestData['profile'],
                'relation_module' => $this->requestData['relation_module'] ?? 0,
            ];
            $other = [
                'is_first' => $this->requestData['is_first'] ?? false,
                'site' => $this->requestData['site'] ?? '',
                'user_id' => $userId,
                'is_account' => $this->isAccount($userId)
            ];
            try {
                (new ServerUserAccountInfo())->add($data, $other);
                $this->retData['message'] = '记录成功';
            } catch (Exception $e) {
                $this->retData['status'] = -1003;
                $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
            }
        }
        return $this->retData;
    }

    /**
     * @title 清除登录信息
     * @return array
     * @author starzhan <397041849@qq.com>
     */
    public function cleanCookies()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        $account_id = $this->requestData['shop_id'] ?? 0;
        $site = $this->requestData['site'] ?? '';
        if ($userId) {
            try {
                if (empty($site)) {
                    throw new Exception('站点信息不能为空');
                }
                if (empty($account_id)) {
                    throw new Exception('店铺id不能为空');
                }
                $site = strtolower($site);
                (new AutomationService())->cleanCookies($userId, $account_id, $site);
                $this->retData['message'] = '删除成功';
            } catch (Exception $e) {
                $this->retData['status'] = -1003;
                $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
            }
        }
        return $this->retData;
    }

    /**
     * 记录服务器缓存
     */
    public function serverRedisData()
    {
        $data = $this->requestData['data'];
        if ($data) {
            try {
                (new ServerUserAccountInfo())->addRedis($data);
                $this->retData['message'] = '记录成功';
            } catch (Exception $e) {
                $this->retData['status'] = -1003;
                $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
            }
        }
        return $this->retData;
    }


    /**
     * 记录UA
     */
    public function recordUa()
    {
        $token = $this->requestData['tokens'];
        $userId = $this->checkUser($token);
        if ($userId) {
            $data = [
                'user_id' => $userId,
                'account_id' => $this->requestData['shop_id'],
                'user_agent' => $this->requestData['user_agent'],
            ];
            try {
                (new ManagerServer())->recordUa($data);
                $this->retData['message'] = '记录成功';
            } catch (Exception $e) {
                $this->retData['status'] = -1003;
                $this->retData['message'] = 'ERP错误，请联系管理员' . $e->getMessage();
            }
        }
        return $this->retData;
    }


    /**
     * 检查是否为合法用户
     * @param $auth
     * @return bool
     */
    public function checkUser($auth)
    {
        $key = Config::get('jwt_key');
        $jwt = new JsonWebToken();
        $payload = $jwt->decode($auth, $key);
        if (!$payload['valid']) {
            $this->retData['status'] = -101;
            $this->retData['message'] = 'Error: Token is not valid';
            return false;
        }
        return $payload['claimset']['user_id'];
    }

    /**
     * 当前请求访问的IP
     */
    public function infoIp()
    {

//        $this->retData['ip'] = (new ManagerServer())->getOnlineIp();
//        $this->retData['ip'] = $this->getVisitIp();
//        $this->retData['ip'] = $this->getIps();
        $this->retData['ip'] = $this->getIP();
        $this->retData['message'] = '获取成功';

        return $this->retData;
    }

    public function getRand($cs = 6)
    {
        $cs = min(6, $cs);
        return substr(strval(rand(1000000, 1999999)), 1, $cs);
    }


    public function getVisitIp()
    {
        $re = '';
        error_reporting(E_ALL);
//        $response= $this->getIp();
        $response = $this->httpReader('https://www.ip.cn/index.php');
//        echo $response;die;

        $pa = '%<span class="cf-footer-item">(.*?)<span class="cf-footer-separator">%sim';
        preg_match_all($pa, $response, $arr);
//        var_dump($arr);die;
        if (isset($arr[1][1])) {
            $re = $arr[1][1];
            $re = str_replace("<span>Your IP</span>: ", "", $re);
            $re = str_replace("</span>", "", $re);
            $re = trim($re);
        }

        return $re;

    }

    public function getIP($type = 0)
    {
//        $IP = '';
//        if(getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
//            $IP = getenv('HTTP_CLIENT_IP');
//        }else if(getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
//            $IP = getenv('HTTP_X_FORWARDED_FOR');
//        }else if(getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
//            $IP = getenv('REMOTE_ADDR');
//        }else if(isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
//            $IP = $_SERVER['REMOTE_ADDR'];
//        }
//        return $IP ? $IP : "unknow";

        $type = $type ? 1 : 0;
        static $ip = NULL;
        if ($ip !== NULL) return $ip[$type];
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {//nginx 代理模式下，获取客户端真实IP
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {//客户端的ip
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {//浏览当前页面的用户计算机的网关
            $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $pos = array_search('unknown', $arr);
            if (false !== $pos) unset($arr[$pos]);
            $ip = trim($arr[0]);
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];//浏览当前页面的用户计算机的ip地址
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? array($ip, $long) : array('0.0.0.0', 0);
        return $ip[$type];

    }

    /*获取客户端真实的IP*/
    public function getIps()
    {
        $realip = '';
        $unknown = 'unknown';
        if (isset($_SERVER)) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && strcasecmp($_SERVER['HTTP_X_FORWARDED_FOR'], $unknown)) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                foreach ($arr as $ip) {
                    $ip = trim($ip);
                    if ($ip != 'unknown') {
                        $realip = $ip;
                        break;
                    }
                }
            } else if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP']) && strcasecmp($_SERVER['HTTP_CLIENT_IP'], $unknown)) {
                $realip = $_SERVER['HTTP_CLIENT_IP'];
            } else if (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])
                && strcasecmp($_SERVER['REMOTE_ADDR'], $unknown)) {
                $realip = $_SERVER['REMOTE_ADDR'];
            } else {
                $realip = $unknown;
            }
        }
//        $realip = preg_match("/[d.]{7,15}/", $realip, $matches) ? $matches[0] : $unknown;
        return $realip;
    }


    /**
     * HTTP读取
     * @param string $url 目标URL
     * @param string $method 请求方式
     * @param array|string $bodyData 请求BODY正文
     * @param array $responseHeader 传变量获取请求回应头
     * @param int $code 传变量获取请求回应状态码
     * @param string $protocol 传变量获取请求回应协议文本
     * @param string $statusText 传变量获取请求回应状态文本
     * @param array $extra 扩展参数,可传以下值,不传则使用默认值
     * header array 头
     * host string 主机名
     * port int 端口号
     * timeout int 超时(秒)
     * proxyType int 代理类型; 0 HTTP, 4 SOCKS4, 5 SOCKS5, 6 SOCK4A, 7 SOCKS5_HOSTNAME
     * proxyAdd string 代理地址
     * proxyPort int 代理端口
     * proxyUser string 代理用户
     * proxyPass string 代理密码
     * caFile string 服务器端验证证书文件名
     * sslCertType string 安全连接证书类型
     * sslCert string 安全连接证书文件名
     * sslKeyType string 安全连接证书密匙类型
     * sslKey string 安全连接证书密匙文件名
     * @return string|array 请求结果;成功返回请求内容;失败返回错误信息数组
     * error string 失败原因简单描述
     * debugInfo array 调试信息
     */
    public function httpReader($url, $method = 'GET', $bodyData = [], $extra = [], &$responseHeader = null, &$code = 0, &$protocol = '', &$statusText = '')
    {
        $ci = curl_init();

        if (isset($extra['timeout'])) {
            curl_setopt($ci, CURLOPT_TIMEOUT, $extra['timeout']);
        }
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ci, CURLOPT_HEADER, true);
        curl_setopt($ci, CURLOPT_AUTOREFERER, true);
        curl_setopt($ci, CURLOPT_FOLLOWLOCATION, true);

        if (isset($extra['proxyType'])) {
            curl_setopt($ci, CURLOPT_PROXYTYPE, $extra['proxyType']);

            if (isset($extra['proxyAdd'])) {
                curl_setopt($ci, CURLOPT_PROXY, $extra['proxyAdd']);
            }

            if (isset($extra['proxyPort'])) {
                curl_setopt($ci, CURLOPT_PROXYPORT, $extra['proxyPort']);
            }

            if (isset($extra['proxyUser'])) {
                curl_setopt($ci, CURLOPT_PROXYUSERNAME, $extra['proxyUser']);
            }

            if (isset($extra['proxyPass'])) {
                curl_setopt($ci, CURLOPT_PROXYPASSWORD, $extra['proxyPass']);
            }
        }

        if (isset($extra['caFile'])) {
            curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, 2); //SSL证书认证
            curl_setopt($ci, CURLOPT_SSL_VERIFYHOST, true); //严格认证
            curl_setopt($ci, CURLOPT_CAINFO, $extra['caFile']); //证书
        } else {
            curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ci, CURLOPT_SSL_VERIFYHOST, false);
        }

        if (isset($extra['sslCertType']) && isset($extra['sslCert'])) {
            curl_setopt($ci, CURLOPT_SSLCERTTYPE, $extra['sslCertType']);
            curl_setopt($ci, CURLOPT_SSLCERT, $extra['sslCert']);
        }

        if (isset($extra['sslKeyType']) && isset($extra['sslKey'])) {
            curl_setopt($ci, CURLOPT_SSLKEYTYPE, $extra['sslKeyType']);
            curl_setopt($ci, CURLOPT_SSLKEY, $extra['sslKey']);
        }

        $method = strtoupper($method);
        switch ($method) {
            case 'GET':
                curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'GET');
                if (!empty($bodyData)) {
                    if (is_array($bodyData)) {
                        $url .= (stristr($url, '?') === false ? '?' : '&') . http_build_query($bodyData);
                    } else {
                        curl_setopt($ci, CURLOPT_POSTFIELDS, $bodyData);
                    }
                }
                break;
            case 'POST':
                curl_setopt($ci, CURLOPT_POST, true);
                if (!empty ($bodyData)) {
                    curl_setopt($ci, CURLOPT_POSTFIELDS, $bodyData);
                }
                break;
            case 'PUT':
                //                 curl_setopt ( $ci, CURLOPT_PUT, true );
                curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (!empty ($bodyData)) {
                    curl_setopt($ci, CURLOPT_POSTFIELDS, $bodyData);
                }
                break;
            case 'DELETE':
                curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'HEAD':
                curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'HEAD');
                break;
            default:
                throw new \Exception(json_encode(['error' => '未定义的HTTP方式']));
                return ['error' => '未定义的HTTP方式'];
        }

        if (!isset($extra['header']) || !isset($extra['header']['Host'])) {
            $urldata = parse_url($url);
            $extra['header']['Host'] = $urldata['host'];
            unset($urldata);
        }

        $header_array = array();
        foreach ($extra['header'] as $k => $v) {
            $header_array[] = $k . ': ' . $v;
        }

        curl_setopt($ci, CURLOPT_HTTPHEADER, $header_array);
        curl_setopt($ci, CURLINFO_HEADER_OUT, true);

        curl_setopt($ci, CURLOPT_URL, $url);

        $response = curl_exec($ci);

        if (false === $response) {
            $http_info = curl_getinfo($ci);
            throw new \Exception(json_encode(['error' => curl_error($ci), 'debugInfo' => $http_info]));
            return ['error' => curl_error($ci), 'debugInfo' => $http_info];
        }

        $responseHeader = [];
        $headerSize = curl_getinfo($ci, CURLINFO_HEADER_SIZE);
        $headerData = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $responseHeaderList = explode("\r\n", $headerData);

        if (!empty($responseHeaderList)) {
            foreach ($responseHeaderList as $v) {
                if (false !== strpos($v, ':')) {
                    list($key, $value) = explode(':', $v, 2);
                    $responseHeader[$key] = ltrim($value);
                } else if (preg_match('/(.+?)\s(\d+)\s(.*)/', $v, $matches) > 0) {
                    $protocol = $matches[1];
                    $code = $matches[2];
                    $statusText = $matches[3];
                }
            }
        }

        curl_close($ci);
        return $body;
    }

    /**
     * 获取手机验证码
     * @return array
     */
    public function phoneCode()
    {
        $token = $this->requestData['tokens'];
        $oldcode = $this->requestData['oldcode'] ?? '';
        $logintime = $this->requestData['logintime'];
        $userId = $this->checkUser($token);
        if ($userId) {
            $id = $this->requestData['shop_id'];
            $result = (new ManagerServer())->getAccountPhoneCode($id, $userId, $oldcode, $logintime);
            if ($result) {
                if ($result['code'] == 'success' && $result['result']) {
                    $this->retData['phone_code'] = $result['result'];
                    $this->retData['message'] = '获取成功';
                } else {
                    $this->retData['message'] = '验证码获取失败请重新获取';
                    $this->retData['status'] = '1003';
                }
            } else {
                $this->retData['message'] = '店铺不属于你的';
                $this->retData['status'] = '1002';
            }
        }
        return $this->retData;
    }


    /**
     * 检查用户是否登陆的是虚拟机
     * @param $token
     * @return bool
     */
    public function isVirtualMachine($token)
    {
        $key = Config::get('jwt_key');
        $payload = (new JsonWebToken())->decode($token, $key);
        return $payload['claimset']['server_type'] === ManagerServer::Virtual;
    }
}