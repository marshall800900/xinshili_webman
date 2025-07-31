<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;

class Fxsh
{
    const PAY_TIME = 1800;
    const LOCK_TIME = 1;

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
            $redis_key = 'getPayUrl_fxsh_' . $charge_account_info['charge_account'];

            for ($i = 0; $i < 100; $i++) {
                $real_pay_amount = intval($order_info['amount'] * 100);
                $real_pay_amount -= $i;
                if (RedisLockHelper::lock($redis_key . $charge_account_info['charge_account'] . '_' . $real_pay_amount, 1, self::PAY_TIME)) {
                    break;
                }
            }

            $charge_account_info['cookie'] = json_decode($charge_account_info['cookie'], true);
            $charge_account_info['proxy_ip'] = json_decode($charge_account_info['proxy_ip'], true);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_params = [
                'ck' => $charge_account_info['cookie']['ck'],
                'amount' => $real_pay_amount,
                'deviceNo' => $charge_account_info['cookie']['deviceNo'],
                'ip' => $charge_account_info['proxy_ip']['proxy_ip'],
                'proxyUser' => $charge_account_info['proxy_ip']['proxy_auth'][0],
                'proxyPass' => $charge_account_info['proxy_ip']['proxy_auth'][1],
            ];
            $request_url = $api_info['api_url'] . 'payQr';

            $result_pay_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], 'post');
//            $result_pay_json = '{"code":"200","errmsg":"","data":{"msg":"获取成功","dataJson":{"qrCodeUrl":"https://www.fxshuo.com.cn/p/?_t=1M472OBO8GC05?time=2099"}}}';
            $result_pay_array = json_decode($result_pay_json, true);
            LogHelper::write([$request_url, $request_params, $result_pay_json, $result_pay_array], '', 'request_log');

            if (!isset($result_pay_array['code']) || $result_pay_array['code'] != '200') {
                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'is_open' => 0,
                        'create_fail_msg' => $result_pay_array['errmsg']
                    ]);

                throw new \Exception($result_pay_array['errmsg'] ?? '获取失败');
            }

            if (!isset($result_pay_array['data']['dataJson']) || !$result_pay_array['data']['dataJson'])
                throw new \Exception('获取支付链接失败');

            parse_str($result_pay_array['data']['dataJson']['qrCodeUrl'], $str);

        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => implode('_', explode('?time=', $str['https://www_fxshuo_com_cn/p/?_t'])),
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $result_pay_array['data']['dataJson']['qrCodeUrl'],
            'real_pay_amount' => number_format($real_pay_amount / 100, 2, '.', ''),
        ];
    }

    public static function billInfo($charge_account_info)
    {
        try {
            $charge_account_info['cookie'] = json_decode($charge_account_info['cookie'], true);
            $charge_account_info['proxy_ip'] = json_decode($charge_account_info['proxy_ip'], true);

            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            $request_params = [
                'ck' => $charge_account_info['cookie']['ck'],
                'deviceNo' => $charge_account_info['cookie']['deviceNo'],
                'pageSize' => 1,
                'pageNo' => 500,
                'startTime' => date('Y-m-d', strtotime('-1 day')),
                'endTime' => date('Y-m-d'),
                'ip' => $charge_account_info['proxy_ip']['proxy_ip'],
                'proxyUser' => $charge_account_info['proxy_ip']['proxy_auth'][0],
                'proxyPass' => $charge_account_info['proxy_ip']['proxy_auth'][1],
            ];

            $request_url = $api_info['api_url'] . 'queryList';

            $result_pay_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], 'post');
            $result_pay_array = json_decode($result_pay_json, true);
            LogHelper::write([$request_url, $request_params, $result_pay_json, $result_pay_array], '', 'request_log');

            if (!isset($result_pay_array['code']) || $result_pay_array['code'] != '200') {

                if (isset($result_pay_array['errmsg']) && $result_pay_array['errmsg'] == '登录已失效，请重新登录')
                    Db::name('receiving_account')
                        ->where('id', $charge_account_info['id'])
                        ->update([
                            'is_open' => 0,
                            'create_fail_msg' => $result_pay_array['errmsg']
                        ]);

                throw new \Exception($result_pay_array['errmsg'] ?? '查询失败');
            }
            if (!isset($result_pay_array['data']['dataJson']['datas']) || count($result_pay_array['data']['dataJson']['datas']) < 1)
                throw new \Exception('查询账单为空');

            $types = [
                '转账' => 0
            ];

            foreach ($result_pay_array['data']['dataJson']['datas'] as $row) {
                $id = Db::table('fa_receiving_account_bill_list')
                    ->where('bill_number', $row['orderNo'])
                    ->value('id');
                if (!$id)
                    Db::table('fa_receiving_account_bill_list')
                        ->insert([
                            'receiving_account_code' => $charge_account_info['receiving_account_code'],
                            'receiving_account_id' => $charge_account_info['id'],
                            'admin_id' => $charge_account_info['admin_id'],
                            'type' => $types[$row['tradeRemark']],
                            'bill_number' => $row['orderNo'],
                            'amount' => $row['orderAmount'],
                            'remark' => $row['tradeSubject'],
                            'create_time' => strtotime($row['createTime']),
                            'add_time' => time(),
                            'status' => '0'
                        ]);
            }
        } catch (\Exception $e) {
            LogHelper::write($charge_account_info, $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return true;
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
                ->field('pay_channel_number, order_number, receiving_account_id, extra_params, pay_url, order_expired_time, order_number, status, real_pay_amount, create_time')
                ->where('pay_channel_number', $row['pay_channel_number'])
                ->find();
            if (!$receiving_account_pay_info)
                throw new \Exception('数据不存在');

            $extra_params = Db::name('pay_order')
                ->where('order_number', $receiving_account_pay_info['order_number'])
                ->value('extra_params');
            $extra_params = DataEncryptHelper::decrypt($extra_params);
            $name = mb_substr($extra_params, -1, 1, 'UTF-8');

            $bill_list = Db::table('fa_receiving_account_bill_list')
                ->where('type', '0')
                ->where('receiving_account_id', $receiving_account_pay_info['receiving_account_id'])
                ->where('amount', $receiving_account_pay_info['real_pay_amount'])
                ->where('create_time', 'BETWEEN TIME', [$receiving_account_pay_info['create_time'], $receiving_account_pay_info['create_time'] + self::PAY_TIME])
                ->order('id asc')
                ->select();

            if (count($bill_list) < 1)
                throw new \Exception('未支付');

            if (count($bill_list) > 1) {
                $is_pay = 0;
                foreach ($bill_list as $key => $value) {
                    if ($name) {
                        if (strstr($value['remark'], $name)) {
                            Db::table('fa_receiving_account_bill_list')
                                ->where('id', $value['id'])
                                ->update([
                                    'pay_channel_number' => $receiving_account_pay_info['pay_channel_number'],
                                    'status' => '1'
                                ]);
                            $is_pay = 1;
                        } else {
                            Db::table('fa_receiving_account_bill_list')
                                ->where('id', $value['id'])
                                ->update([
                                    'status' => '3'
                                ]);
                        }
                    } else {
                        if ($key > 0) {
                            Db::table('fa_receiving_account_bill_list')
                                ->where('id', $value['id'])
                                ->update([
                                    'status' => '2'
                                ]);
                        } else {
                            $is_pay = 1;
                            Db::table('fa_receiving_account_bill_list')
                                ->where('id', $value['id'])
                                ->update([
                                    'pay_channel_number' => $receiving_account_pay_info['pay_channel_number'],
                                    'status' => '1'
                                ]);
                        }
                    }
                }
            }else{
                Db::table('fa_receiving_account_bill_list')
                    ->where('id', $bill_list[0]['id'])
                    ->update([
                        'pay_channel_number' => $receiving_account_pay_info['pay_channel_number'],
                        'status' => '1'
                    ]);
                $is_pay = 1;
            }
            if (!$is_pay)
                throw new \Exception('订单异常，请人工核实');
        } catch (\Exception $e) {
            LogHelper::write($row, $e->getMessage(), 'error_log');
            if (!$is_ret_fail)
                return 0;

            throw new \Exception($e->getMessage());
        }

        return 1;
    }
}