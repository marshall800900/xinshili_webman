<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use support\think\Db;

/**
 * 抖音淘宝
 */
class Dytb
{
    const PAY_TIME = 300;
    const LOCK_TIME = 120;

    const CHARGE_ACCOUNT_LOCK_TIME = 600;
    const SORT = 'asc';

    /**
     * 获取支付链接
     * @param $charge_account_info
     * @param $shop_charge_account_info
     * @return array|false
     */
    public static function getPayUrl($charge_account_info, $order_info)
    {
        try {

            $amounts = [
                '10.00' => 'https://item.taobao.com/item.htm?id=738621050629',
                '20.00' => 'https://item.taobao.com/item.htm?id=738751251252',
                '30.00' => 'https://item.taobao.com/item.htm?id=692138902358',
                '50.00' => 'https://item.taobao.com/item.htm?id=738751455243',
                '100.00' => 'https://item.taobao.com/item.htm?id=738751695114',
                '200.00' => 'https://item.taobao.com/item.htm?id=738335428076',
                '500.00' => 'https://item.taobao.com/item.htm?id=738521873352',
                '1000.00' => 'https://item.taobao.com/item.htm?id=738752747578',
            ];

            if (!isset($amounts[$order_info['amount']]))
                throw new \Exception('金额异常');

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

            $request_url = $api_info['api_url'] . 'dy_walletInfo';
            $request_data = [
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


            if (strstr($result_json, '请登录后进入直播间')) {
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => '请登录后进入直播间'
                    ]);

                throw new \Exception('请登录后进入直播间');
            }


            if (!isset($result_array['data']['data']['diamond']))
                throw new \Exception('获取余额失败');

             $pay_url = 'alipays://platformapi/startapp?appId=20000067&url=' . urlencode($amounts[$order_info['amount']]);
//            $pay_url = 'alipays://platformapi/startapp?appId=2021002147615737&page=' . urlencode('plugin-private://2021001185694031/pages/webview-redirect/webview-redirect?__appxPageId=18&url=' .  urlencode($amounts[$order_info['amount']] . md5(rand(10000000,999999999))));
        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => CommonHelper::getOrderNumber('DYTB'),
            'extra_params' => $result_array['data']['data']['diamond'],
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $pay_url,
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
                ->field('pay_channel_number, receiving_account_id, extra_params, pay_url, order_expired_time, order_number, status,amount, create_time')
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
                'cookie' => $charge_account_info['cookie']
            ];

            $request_header = [
                'Content-Type: application/x-www-form-urlencoded',
                'ip:' . $charge_account_info['proxy_ip'][0],
                'port:' . $charge_account_info['proxy_ip'][1],
                'username:' . DataEncryptHelper::decrypt($api_info['api_key']),
                'password:' . DataEncryptHelper::decrypt($api_info['private_key']),
            ];

            $request_url = $api_info['api_url'] . 'dy_walletInfo';

            $result_json = CommonHelper::curlRequest($request_url, http_build_query($request_data), $request_header);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$charge_account_info, $request_url, $request_data, $request_header, $result_json, $result_array], '', 'request_log');
            if (strstr($result_json, 'milliseconds'))
                ProxyIpHelper::unsetProxyIp($charge_account_info);

            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception('查询失败');

            if (!isset($result_array['data']['data']['diamond']))
                throw new \Exception('获取余额失败');

            $pre_money = $receiving_account_pay_info['extra_params'] + ($receiving_account_pay_info['amount'] * 10);
            if ($result_array['data']['data']['diamond'] < $pre_money)
                throw new \Exception('未支付');

            if ($receiving_account_pay_info['create_time'] < (time()-self::CHARGE_ACCOUNT_LOCK_TIME))
                throw new \Exception('订单已过期，请人工核对');
        } catch (\Exception $e) {
            LogHelper::write($row, $e->getMessage(), 'error_log');
            if (!$is_ret_fail)
                return 0;

            throw new \Exception($e->getMessage());
        }

        return 1;
    }
}