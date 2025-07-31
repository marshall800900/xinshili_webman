<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use support\think\Db;

class Dywap
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
    public static function getPayUrl($charge_account_info, $order_info)
    {
        try {
            $order_info['system_extra_params'] = json_decode($order_info['system_extra_params'], true);

            $charge_account_info = ProxyIpHelper::getProxyIp($charge_account_info);

            if (!$charge_account_info['extra_params']) {
                $system_extra_params_list = Db::name('pay_order')
                    ->where('user_device', 'iphone')
                    ->orderRaw('rand()')
                    ->limit(5)
                    ->column('system_extra_params');

                if (count($system_extra_params_list) > 0) {
                    $key = rand(0, count($system_extra_params_list) - 1);
                    $system_extra_params = $system_extra_params_list[$key];
                } else {
                    $system_extra_params = '{"screen_params":{"width":412,"height":915,"availWidth":412,"availHeight":915,"colorDepth":24,"pixelRatio":2.625},"navigator_params":{"userAgent":"Mozilla\/5.0 (Linux; Android 14; V2244A; wv) AppleWebKit\/537.36 (KHTML, like Gecko) Version\/4.0 Chrome\/123.0.6312.118 Mobile Safari\/537.36 VivoBrowser\/24.0.21.0","platform":"Linux aarch64"},"window_params":{"innerWidth":412,"innerHeight":821,"outerWidth":412,"outerHeight":821,"screenX":0,"screenY":0,"pageYOffset":0}}';
                }

                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'extra_params' => $system_extra_params
                    ]);

                $order_info['system_extra_params'] = json_decode($system_extra_params, true);
            } else {
                $order_info['system_extra_params'] = json_decode($charge_account_info['extra_params'], true);
            }

            $charge_account_info['proxy_ip'] = explode(':', $charge_account_info['proxy_ip']);
            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_url = $api_info['api_url'] . 'get_a_bogus_v2_init';
            $request_data = [
                'price' => intval($order_info['amount'] * 100),
                'screenParams' => json_encode($order_info['system_extra_params']['screen_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'navigatorParams' => json_encode($order_info['system_extra_params']['navigator_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'windowParams' => json_encode($order_info['system_extra_params']['window_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'cookie' => $charge_account_info['cookie']
            ];

            $request_header = [
                'Content-Type: application/x-www-form-urlencoded',
                'ip:' . $charge_account_info['proxy_ip'][0],
                'port:' . $charge_account_info['proxy_ip'][1],
                'username:' . DataEncryptHelper::decrypt($api_info['api_key']),
                'password:' . DataEncryptHelper::decrypt($api_info['private_key']),
            ];

            $date = date('Y-m-d H:i:s');

            $result_json = CommonHelper::curlRequest($request_url, http_build_query($request_data), $request_header);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $request_data, $request_header, $result_json, $result_array, $date], '', 'request_log');

            if (strstr($result_json, 'milliseconds') || strstr($result_json, 'BrotliDecompress') || strstr($result_json, 'Request failed'))
                ProxyIpHelper::unsetProxyIp($charge_account_info);


            if (strstr($result_json, '违规') || strstr($result_json, '建议你前往抖音APP完成充值') || strstr($result_json, '操作频繁，请稍后再试') || strstr($result_json, '你的账号存在异常充值行为')) {
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => '您涉及违规操作，暂时无法使用该功能'
                    ]);
                ProxyIpHelper::unsetProxyIp($charge_account_info);
                throw new \Exception('您涉及违规操作，暂时无法使用该功能');
            }

            if (strstr($result_json, '请登录后进入直播间')) {
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => '请登录后进入直播间'
                    ]);

                throw new \Exception('请登录后进入直播间');
            }

            if (strstr($result_json, '登录账号发生变化')) {
                throw new \Exception('登录账号发生变化，请刷新页面重试');
            }

            if (!isset($result_array['code']) || $result_array['code'] != 0) {
                if ($result_array['code'] == -1) {
                    Db::name('receiving_account')
                        ->where('id', $charge_account_info['id'])
                        ->update([
                            'is_open' => 0,
                            'create_fail_msg' => '您涉及违规操作，暂时无法使用该功能'
                        ]);
                }
                throw new \Exception('获取支付链接失败1');
            }

            if (!isset($result_array['data']['data']['params']) || !$result_array['data']['data']['params'])
                throw new \Exception('获取支付链接失败2');

            $params = json_decode($result_array['data']['data']['params'], true);

            if (!isset($params['trade_no']) || !$params['trade_no'])
                throw new \Exception('获取支付链接失败3');


            if (!isset($params['data']) || !$params['data'])
                throw new \Exception('获取支付链接失败5');

            $params['data'] = json_decode($params['data'], true);

        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => $result_array['data']['data']['order_id'],
//            'extra_params' => $url['papi_id'],
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $params['data']['url'],
            // 'pay_url' => 'alipays://platformapi/startapp?appId=20000067&url=' . urlencode($params['data']['url']),
//            'pay_url' => 'alipays://platformapi/startapp?appId=20000067&url=' .
//                urlencode('https://ds.alipay.com/?from=mobilecodec&scheme=' .
//                    urlencode('alipays://platformapi/startapp?appId=20000218&url=' .
//                        urlencode($params['data']['url'])
//                    )
//                )
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
            $receiving_account_pay_info = Db::name('receiving_account_pay_url')
                ->field('pay_channel_number, receiving_account_id, extra_params, pay_url, order_expired_time, order_number, status')
                ->where('pay_channel_number', $row['pay_channel_number'])
                ->find();
            if (!$receiving_account_pay_info)
                throw new \Exception('数据不存在');

            $charge_account_info = Db::name('receiving_account')
                ->field('id, proxy_ip, proxy_ip_invalid_time,charge_account, charge_account_name, extra_params, cookie, area')
                ->where('id', $receiving_account_pay_info['receiving_account_id'])
                ->find();

            $charge_account_info = ProxyIpHelper::getProxyIp($charge_account_info);
            $charge_account_info['proxy_ip'] = explode(':', $charge_account_info['proxy_ip']);


            $system_extra_params = json_decode($charge_account_info['extra_params'], true);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_data = [
                'screenParams' => json_encode($system_extra_params['screen_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'navigatorParams' => json_encode($system_extra_params['navigator_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'windowParams' => json_encode($system_extra_params['window_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'orderNo' => $receiving_account_pay_info['pay_channel_number'],
                'cookie' => $charge_account_info['cookie']
            ];

            $request_header = [
                'Content-Type: application/x-www-form-urlencoded',
                'ip:' . $charge_account_info['proxy_ip'][0],
                'port:' . $charge_account_info['proxy_ip'][1],
                'username:' . DataEncryptHelper::decrypt($api_info['api_key']),
                'password:' . DataEncryptHelper::decrypt($api_info['private_key']),
            ];

            $request_url = $api_info['api_url'] . 'get_a_bogus_v3_pc_query_order';

            $result_json = CommonHelper::curlRequest($request_url, http_build_query($request_data), $request_header);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$charge_account_info, $request_url, $request_data, $request_header, $result_json, $result_array], '', 'request_log');
            if (strstr($result_json, 'milliseconds'))
                ProxyIpHelper::unsetProxyIp($charge_account_info);

            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception('查询失败');

            if (!isset($result_array['data']['data']['status']) || $result_array['data']['data']['status'] != 1)
                throw new \Exception($result_array['data']['data']['prompts'] ?? '未支付');
        } catch (\Exception $e) {
            LogHelper::write($row, $e->getMessage(), 'error_log');
            if (!$is_ret_fail)
                return 0;

            throw new \Exception($e->getMessage());
        }

        return 1;
    }
}