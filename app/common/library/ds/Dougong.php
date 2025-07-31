<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use app\common\library\ReceivingAccountHelper;
use support\think\Db;

class Dougong
{
    const PAY_TIME = 180;
    const LOCK_TIME = 120;

    const CHARGE_ACCOUNT_LOCK_TIME = 0;
    const SORT = 'desc';

    /**
     * 私钥签名
     * @param $plainText
     * @param $private_key
     * @return string
     * @throws \Exception
     */
    public static function shaWithRsaSign($data, $rsaPrivateKey, $alg = OPENSSL_ALGO_SHA256)
    {
        $key = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($rsaPrivateKey, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $signature = '';
        try {
            ksort($data);
            $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            openssl_sign($data, $signature, $key, $alg);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return base64_encode($signature);
    }

    /**
     * 获取支付链接
     * @param $charge_account_info
     * @param $shop_charge_account_info
     * @return array|false
     */
    public static function getPayUrl($charge_account_info, $order_info)
    {
        try {
            //获取openid start
            $wechat_config = Db::name('wechat_config')
                ->where('is_open', '1')
                ->find();
            if (!$wechat_config)
                throw new \Exception('暂未配置公众号');

            $request_url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . $wechat_config['app_id'] .
                '&secret=' . $wechat_config['app_secret'] .
                '&code=' . $order_info['system_extra_params'] . '&grant_type=authorization_code';

            $result_json = CommonHelper::curlRequest($request_url, [], [], 'get', 0, json_decode($wechat_config['proxy_ip'], true));
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['openid']) || !$result_array['openid'])
                throw new \Exception('获取openid失败');
            //获取openid end

            //获取支付参数start
            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $order_number = CommonHelper::getOrderNumber('');
            $real_pay_amount = number_format(($order_info['amount'] * 100 - rand(1, 3)) / 100, 2, '.', '');

            $request_params = [
                'sys_id' => $charge_account_info['charge_account'],
                'product_id' => $charge_account_info['extra_params'],
                'data' => [
                    'req_date' => date('Ymd'),
                    'req_seq_id' => $order_number,
                    'huifu_id' => $charge_account_info['charge_account'],
                    'goods_desc' => '会员充值',
                    'trade_type' => 'T_JSAPI',
                    'trans_amt' => $real_pay_amount,
                    'time_expire' => date('YmdHis', time() + self::PAY_TIME),
                    'notify_url' => $charge_account_info['notify_url'],
                    'wx_data' => json_encode([
                        'sub_appid' => $wechat_config['app_id'],
                        'sub_openid' => $result_array['openid']
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]
            ];
            $request_params['sign'] = self::shaWithRsaSign($request_params['data'], $charge_account_info['private_key']);

            $request_pay_url = $api_info['api_url'] . '/v2/trade/payment/jspay';
            $result_pay_json = CommonHelper::curlRequest($request_pay_url, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], 'post', 0, json_decode($charge_account_info['proxy_ip'], true));
            $result_pay_array = json_decode($result_pay_json, true);
            LogHelper::write([$request_pay_url, $request_params, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result_pay_json, $result_pay_array], '', 'request_log');
            //获取支付参数end
            if (!isset($result_pay_array['data']['bank_code']) || $result_pay_array['data']['bank_code'] != 'SUCCESS')
                throw new \Exception($result_pay_array['data']['bank_message'] ?? '下单失败');
        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => $result_pay_array['data']['req_seq_id'],
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $result_pay_array['data']['pay_info'],
            'real_pay_amount' => number_format($real_pay_amount / 100, 2, '.', ''),
        ];
    }

    /**
     * 支付通知
     * @param $params
     * @param $header
     * @return string
     * @throws \Exception
     */
    public static function payNotify($params, $header)
    {
        try {
            $params['resp_data'] = json_decode($params['resp_data'], true);

            $receiving_account_pay_info = Db::name('receiving_account_pay_url')
                ->field('id, pay_channel_number, status')
                ->where('pay_channel_number', $params['resp_data']['req_seq_id'])
                ->find();
            if (!$receiving_account_pay_info)
                throw new \Exception('数据不存在');

            if ($receiving_account_pay_info['status'] != ReceivingAccountHelper::RECEIVING_ACCOUNT_TYPE_SUCCESS) {
                if (self::query($receiving_account_pay_info))
                    ReceivingAccountHelper::success($receiving_account_pay_info['id']);
            }
        } catch (\Exception $e) {
            LogHelper::write([$params, $header], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return 'RECV_ORD_ID_' . $params['resp_data']['req_seq_id'];
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
                ->field('id, proxy_ip, proxy_ip_invalid_time, area, charge_account, private_key, extra_params')
                ->where('id', $receiving_account_pay_info['receiving_account_id'])
                ->find();

            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_params = [
                'sys_id' => $charge_account_info['charge_account'],
                'product_id' => $charge_account_info['extra_params'],
                'data' => [
                    'org_req_date' => date('Ymd'),
                    'huifu_id' => $charge_account_info['charge_account'],
                    'org_req_seq_id' => $row['pay_channel_number'],
                ]
            ];
            $request_params['sign'] = self::shaWithRsaSign($request_params['data'], $charge_account_info['private_key']);

            $request_url = $api_info['api_url'] . '/v3/trade/payment/scanpay/query';
            $result_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], 'post', 0, json_decode($charge_account_info['proxy_ip'], true));
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $request_params, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result_json, $result_array], '', 'request_log');

            if (!isset($result_array['data']['resp_code']) || $result_array['data']['resp_code'] != '00000000')
                throw new \Exception($result_array['data']['resp_desc'] ?? '查询失败');

            if (!isset($result_array['data']['trans_stat']) || $result_array['data']['trans_stat'] != 'S')
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