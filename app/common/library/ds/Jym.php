<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\CookieHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use Campo\UserAgent;
use support\think\Db;

class Jym
{
    const PAY_TIME = 180;
    const LOCK_TIME = 120;
    const CHARGE_ACCOUNT_LOCK_TIME = 0;
    const SORT = 'desc';

    /**
     * 获取支付链接
     * @param $charge_account_info
     * @param $shop_charge_account_info
     * @return array|false
     */
    public static function getPayUrl($charge_account_info, $shop_charge_account_info)
    {
        try {
            $cookie_info = CookieHelper::getCookie($charge_account_info['task_api_class']);

            $cookie_info = ProxyIpHelper::getProxyIp($cookie_info);
            $cookie_info['proxy_ip'] = explode(':', $cookie_info['proxy_ip']);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . '/api/jymWeb/createOrderForFrontend/' . $shop_charge_account_info['charge_account'] . '/' . $shop_charge_account_info['remark'] . '/1/' . $charge_account_info['charge_account'];

            $header = [
                'userAgent:' . $cookie_info['user_agent'],
                'cookie:' . $cookie_info['cookie'],
                'ip:' . $cookie_info['proxy_ip'][0],
                'port:' . $cookie_info['proxy_ip'][1]
            ];

            $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['data']['result']['orderId']) || empty($result_array['data']['result']['orderId']))
                throw new \Exception('下单失败');

        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $shop_charge_account_info, $cookie_info], $e->getMessage(), 'error_log');
            return false;
        }

        return [
            'pay_channel_number' => $result_array['data']['result']['orderId'],
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'cookie_id' => $cookie_info['id'],
            'pay_url' => $result_array['data']['jumpPayUrl']
        ];
    }

    /**
     * 查询支付状态
     * @param $row
     * @return void
     */
    public static function query($row, $is_ret_fail = 1)
    {
        try {
            $cookie_info = CookieHelper::getCookie($row['task_api_class'] ?? $row['api_code'], $row['cookie_id']);

            $cookie_info = ProxyIpHelper::getProxyIp($cookie_info);
            $cookie_info['proxy_ip'] = explode(':', $cookie_info['proxy_ip']);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . '/api/jymWeb/orderDetail/' . $row['pay_channel_number'];

            $header = [
                'userAgent:' . $cookie_info['user_agent'],
                'cookie:' . $cookie_info['cookie'],
                'ip:' . $cookie_info['proxy_ip'][0],
                'port:' . $cookie_info['proxy_ip'][1]
            ];

            $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_log');

            if (!strstr($result_json, '创建时间'))
                throw new \Exception('查询失败');

            if (!strstr($result_json, '付款时间'))
                throw new \Exception('未支付');

            if (strstr($result_json, '失败时间'))
                throw new \Exception('已退款');

            if (!strstr($result_json, '发货时间'))
                throw new \Exception('发货中');
        } catch (\Exception $e) {
            if (!$is_ret_fail && $e->getMessage() != '发货中')
                return 0;

            throw new \Exception($e->getMessage());
        }

        return 1;
    }

    /**
     * 获取验证码
     * @param $cookie_info
     * @return true
     * @throws \Exception
     */
    public static function sendSmsCode($cookie_info)
    {
        try {
            $cookie_info = ProxyIpHelper::getProxyIp($cookie_info);
            $cookie_info['proxy_ip'] = explode(':', $cookie_info['proxy_ip']);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . '/api/jymWeb/loginSendSmsCode/' . $cookie_info['phone'];

            $user_agent = UserAgent::random([
                'os_type' => 'iOS',
                'device_type' => 'Mobile'
            ]);

            $header = [
                'userAgent:' . $user_agent,
                'ip:' . $cookie_info['proxy_ip'][0],
                'port:' . $cookie_info['proxy_ip'][1]
            ];

            $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception($result_array['msg'] ?? '获取验证码失败');

            if (!isset($result_array['data']['data']['code']) || $result_array['data']['data']['code'] != 'SUCCESS')
                throw new \Exception($result_array['data']['data']['msg'] ?? '获取验证码失败');

            $cookie = '';
            $cookie_array = explode(';', $result_array['data']['nextCookie']);
            foreach ($cookie_array as $value) {
                if (strstr($value, 'ctoken')) {
                    $cookie = $value;
                    break;
                }
            }
            if (!$cookie)
                throw new \Exception('获取cookie失败');

            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->update([
                    'cookie' => $cookie . ';',
                    'status' => CookieHelper::COOKIE_TYPE_NEED_LOGIN,
                    'user_agent' => $user_agent
                ]);

        } catch (\Exception $e) {
            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->inc('login_fail_number', 1)
                ->update([
                    'id' => $cookie_info['id']
                ]);
            LogHelper::write($cookie_info, $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 验证短信验证码
     * @param $sms_code
     * @param $cookie_info
     * @return true
     * @throws \Exception
     */
    public static function verifySmsCode($sms_code, $cookie_info)
    {
        try {
            $cookie_info = ProxyIpHelper::getProxyIp($cookie_info);
            $cookie_info['proxy_ip'] = explode(':', $cookie_info['proxy_ip']);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . '/api/jymWeb/loginWithSmsCode/' . $cookie_info['phone'] . '/' . $sms_code;

            $header = [
                'userAgent:' . $cookie_info['user_agent'],
                'cookie:' . $cookie_info['cookie'],
                'ip:' . $cookie_info['proxy_ip'][0],
                'port:' . $cookie_info['proxy_ip'][1]
            ];

            $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_log');

            if (!isset($result_array['data']['code']) || $result_array['data']['code'] != 'SUCCESS')
                throw new \Exception($result_array['data']['msg'] ?? '验证短信验证码失败');

            if (!isset($result_array['data']['data']['sessionInfo']['sessionId']) || !$result_array['data']['data']['sessionInfo']['sessionId'])
                throw new \Exception('验证短信验证码失败');

            $cookie = $cookie_info['cookie'] . 'jym_session_id=' . $result_array['data']['data']['sessionInfo']['sessionId'] . ';';

            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->update([
                    'cookie' => $cookie,
                    'status' => CookieHelper::COOKIE_TYPE_NEED_CHECK
                ]);

        } catch (\Exception $e) {
            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->inc('login_fail_number', 1)
                ->update([
                    'id' => $cookie_info['id']
                ]);
            LogHelper::write($cookie_info, $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 检查cookie
     * @param $cookie_info
     * @return true
     * @throws \Exception
     */
    public static function checkCookie($cookie_info)
    {
        try {
            $cookie_info = ProxyIpHelper::getProxyIp($cookie_info);
            $cookie_info['proxy_ip'] = explode(':', $cookie_info['proxy_ip']);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . '/api/jymWeb/findRealName';

            $header = [
                'userAgent:' . $cookie_info['user_agent'],
                'cookie:' . $cookie_info['cookie'],
                'ip:' . $cookie_info['proxy_ip'][0],
                'port:' . $cookie_info['proxy_ip'][1]
            ];

            $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_log');

            if (!isset($result_array['data']['data']['data']['userRealNameInfo']['status']))
                throw new \Exception('获取实名状态失败');

            if (!$result_array['data']['data']['data']['userRealNameInfo']['status']) {
                $id_card_info = CookieHelper::getIdCard();
                $request_url = $api_info['api_url'] . '/api/jymWeb/applyRealName/' . urlencode($id_card_info['name']) . '/' . $id_card_info['id_crad'] . '/';

                $header = [
                    'userAgent:' . $cookie_info['user_agent'],
                    'cookie:' . $cookie_info['cookie'],
                    'ip:' . $cookie_info['proxy_ip'][0],
                    'port:' . $cookie_info['proxy_ip'][1]
                ];

                $result_json = CommonHelper::curlRequest($request_url, [], $header, 'get');
                $result_array = json_decode($result_json, true);
                LogHelper::write([$request_url, $header, $result_json, $result_array], '', 'request_apply_real_name_log');
                if (!isset($result_array['code']) || $result_array['code'] != 0)
                    throw new \Exception('实名失败');

                $result_array = json_decode($result_array['data'], true);
                if (!isset($result_array['data']['code']) || $result_array['data']['code'] != 'SUCCESS')
                    throw new \Exception('实名失败');
            }

            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->update([
                    'status' => CookieHelper::COOKIE_TYPE_NORMAL
                ]);

        } catch (\Exception $e) {
            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->inc('login_fail_number', 1)
                ->update([
                    'id' => $cookie_info['id']
                ]);
            LogHelper::write($cookie_info, $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return true;
    }
}