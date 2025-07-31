<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use support\think\Db;

class Dypc
{
    const PAY_TIME = 900;
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

            if (!$charge_account_info['extra_params']){
                $charge_account_info['extra_params'] = json_encode($order_info['system_extra_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'extra_params' => $charge_account_info['extra_params']
                    ]);
            }else{
                $order_info['system_extra_params'] = json_decode($charge_account_info['extra_params'], true);
            }

            $charge_account_info['proxy_ip'] = explode(':', $charge_account_info['proxy_ip']);
            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_url = $api_info['api_url'] . 'get_a_bogus_v2_pc_init';
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

//            $result_json = '{"code":0,"data":{"data":{"order_id":"10000017493565091724563507","params":"https://tp-pay.snssdk.com/cashdesk/?app_id=800095745677&encodeType=base64&exts={}&merchant_id=1200009574&out_order_no=10000017493565091724563507&return_scheme=&return_url=aHR0cHM6Ly93d3cuZG91eWluLmNvbS9wYXk=&sign=4e84aecc4971302a1a33060c02c86748&sign_type=MD5&switch=00&timestamp=1744731591&total_amount=1000&trade_no=2001002504150100689876850566&trade_type=H5&uid=3358388780533947","pay_type":"0"},"extra":{"allow_bank_payment":[1,2],"bdturing_verify":"","iap_fail":false,"ios_show_recharge":false,"now":1744731591791,"source":"","two_factory_auth_info":""},"status_code":0}}';

            $result_json = CommonHelper::curlRequest($request_url, http_build_query($request_data), $request_header);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $request_data, $request_header, $result_json, $result_array, $date], '', 'request_log');
            if (strstr($result_json, 'milliseconds') || strstr($result_json, 'BrotliDecompress') || strstr($result_json, 'Request failed'))
                ProxyIpHelper::unsetProxyIp($charge_account_info);


            if (strstr($result_json, '违规') ||strstr($result_json, '建议你前往抖音APP完成充值'))
            {
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => '您涉及违规操作，暂时无法使用该功能'
                    ]);

                throw new \Exception('您涉及违规操作，暂时无法使用该功能');
            }

            if (strstr($result_json, '请登录后进入直播间')){
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => '请登录后进入直播间'
                    ]);

                throw new \Exception('请登录后进入直播间');
            }

            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception('获取支付链接失败1');

            if (!isset($result_array['data']['data']['params']) || !$result_array['data']['data']['params'])
                throw new \Exception('获取支付链接失败2');

            $params = json_decode($result_array['data']['data']['params'], true);

            if (!isset($params['trade_no']) || !$params['trade_no'])
                throw new \Exception('获取支付链接失败3');

            $params['trade_no'] = $params['trade_no'].rand(1000,9999);
//https://tp-pay.snssdk.com/cashdesk/?app_id=800095745677&encodeType=base64&exts={}&merchant_id=1200009574&out_order_no=10000017493560577990874122&return_scheme=&return_url=aHR0cHM6Ly93d3cuZG91eWluLmNvbS9wYXk=&sign=b0600fb763acbba6e86acfdfafdf1502&sign_type=MD5&switch=00&timestamp=1744730539&total_amount=510000&trade_no=2001002504150100689976781925&trade_type=H5&uid=2958148265317117
            parse_str($params['cashdesk_url'], $url);

            if (!isset($url['papi_id']) || !$url['papi_id'])
                throw new \Exception('获取支付链接失败4');

            if (!isset($params['data']) || !$params['data'])
                throw new \Exception('获取支付链接失败5');

            $params['data'] = json_decode($params['data'], true);

        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => $result_array['data']['data']['order_id'],
            'extra_params' => $url['papi_id'],
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $params['data']['url']
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
                ->field('id, proxy_ip, proxy_ip_invalid_time, area')
                ->where('id', $receiving_account_pay_info['receiving_account_id'])
                ->find();

            $charge_account_info = ProxyIpHelper::getProxyIp($charge_account_info);
            $charge_account_info['proxy_ip'] = explode(':', $charge_account_info['proxy_ip']);

            $system_extra_params = Db::name('pay_order')
                ->where('order_number', $receiving_account_pay_info['order_number'])
                ->value('system_extra_params');


            $system_extra_params = json_decode($system_extra_params, true);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_data = [
                'screenParams' => json_encode($system_extra_params['screen_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'navigatorParams' => json_encode($system_extra_params['navigator_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'windowParams' => json_encode($system_extra_params['window_params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
                'papi' => $receiving_account_pay_info['extra_params'],
                'trade_no' => $receiving_account_pay_info['pay_channel_number'],
            ];

            $request_header = [
                'Content-Type: application/x-www-form-urlencoded',
                'ip:' . $charge_account_info['proxy_ip'][0],
                'port:' . $charge_account_info['proxy_ip'][1],
                'username:' . DataEncryptHelper::decrypt($api_info['api_key']),
                'password:' . DataEncryptHelper::decrypt($api_info['private_key']),
            ];

            $request_url = $api_info['api_url'] . 'trade_query';

            $result_json = CommonHelper::curlRequest($request_url, http_build_query($request_data), $request_header);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $request_data, $request_header, $result_json, $result_array], '', 'request_log');
            if (strstr($result_json, 'milliseconds'))
                ProxyIpHelper::unsetProxyIp($charge_account_info);

            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception('查询失败');

            if (!isset($result_array['data']['data']['trade_info']) || !$result_array['data']['data']['trade_info'])
                throw new \Exception('查询失败');

            if (!isset($result_array['data']['data']['trade_info']['status']) || $result_array['data']['data']['trade_info']['status'] != 'SUCCESS')
                throw new \Exception('未支付');
        } catch (\Exception $e) {
            LogHelper::write($row, $e->getMessage(), 'error_log');
            if (!$is_ret_fail)
                return 0;

            throw new \Exception($e->getMessage());
        }

        return 1;
    }
}