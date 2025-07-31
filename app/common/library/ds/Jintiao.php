<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\CookieHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ProxyIpHelper;
use app\common\library\RedisLockHelper;
use Campo\UserAgent;
use support\think\Db;

class Jintiao
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
            $extra_params = '给你的-' . rand(10000000, 99999999);
            $real_pay_amount = $order_info['amount'];
            $redis_key = __CLASS__ . '_' . __METHOD__ . $charge_account_info['charge_account'];
            for ($i = 1; $i < 100; $i++) {
                $item_amount = number_format($real_pay_amount - ($i / 100), 2, '.', '');
                $redis_key = __CLASS__ . '_' . __METHOD__ . $charge_account_info['charge_account'] . '_' . $item_amount;
                if (RedisLockHelper::lock($redis_key, 1, 300)) {
                    $real_pay_amount = $item_amount;
                    break;
                }
            }

            $real_pay_amount = number_format($real_pay_amount, 2, '.', '');


            $url = 'alipays://platformapi/startapp?appId=20000067&url=' .
                urlencode('https://ds.alipay.com/?from=mobilecodec&scheme=' .
                    urlencode('alipays://platformapi/startapp?appId=20000218&url=' .
                        urlencode(
                            'data:text/html;base64,' . base64_encode('
                        <html lang="en"><head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta http-equiv="X-UA-Compatible" content="ie=edge">
            <title></title>
            <script src="https://gw.alipayobjects.com/as/g/h5-lib/alipayjsapi/3.1.1/alipayjsapi.min.js"></script>
        </head>
        <body>
        <script>
            var userId = "' . $charge_account_info['charge_account'] . '";
            var money = "' . $real_pay_amount . '";
            var remark = "' . $extra_params . '";       
            function returnApp() {
                AlipayJSBridge.call("exitApp")
            }
            function ready(a) {
                window.AlipayJSBridge ? a && a() : document.addEventListener("AlipayJSBridgeReady", a, !1)
            }
            ready(function () {
                try {
                    var a = {
                        actionType: "scan",
                        u: userId,
                        a: money,
                        m: remark,
                        biz_data: {
                            s: "money",
                            u: userId,
                            a: money,
                            m: remark
                        }
                    }
                } catch (b) {
                    returnApp()
                }
                AlipayJSBridge.call("startApp", {
                    appId: "20000123",
                    param: a
                }, function (a) { })
            });
            document.addEventListener("resume", function (a) {
                returnApp()
            });
        </script>
        
        <div></div></body></html>
                        ')
                        ))
                );


        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => CommonHelper::getOrderNumber('jt'),
            'extra_params' => $extra_params,
            'real_pay_amount' => $real_pay_amount,
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $url
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
                ->field('pay_channel_number, create_time, real_pay_amount, receiving_account_id, extra_params, pay_url, order_expired_time, order_number, status')
                ->where('pay_channel_number', $row['pay_channel_number'])
                ->find();
            if (!$receiving_account_pay_info)
                throw new \Exception('数据不存在');

            $extra_params = Db::name('receiving_account')
                ->field('extra_params')
                ->where('id', $receiving_account_pay_info['receiving_account_id'])
                ->value('extra_params');

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $extra_params = json_decode($extra_params, true);
            $api_url = $api_info['api_url'] . $extra_params['uid'] . '/' . $extra_params['appAuthToken'];

//            $api_url = 'http://103.251.112.18:19357/api/dmf/apiAccountBillQuery/C1725170016/2088632306246990/202504BB77d3d4fec62644cba2bd90d43a69fA99';
            $result_json = CommonHelper::curlRequest($api_url, [], [], 'get');
            $result_array = json_decode($result_json, true);
            LogHelper::write([$api_url, $result_json, $result_array], '', 'request_log');

            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception($result_array['msg'] ?? '查询失败');

            $order_list = json_decode($result_array['data'], true);

            if (!isset($order_list['alipay_data_bill_accountlog_query_response']['detail_list']))
                throw new \Exception('未支付');

            $is_pay = 0;
            foreach ($order_list['alipay_data_bill_accountlog_query_response']['detail_list'] as $value) {
                if (isset($value['direction']) && $value['direction'] == '收入' && $value['trans_amount'] == $receiving_account_pay_info['real_pay_amount']) {
                    $time = strtotime($value['trans_dt']);

                    if ($time > $row['create_time'] && $time <= ($row['create_time'] + self::PAY_TIME + self::LOCK_TIME)) {
                        $is_pay = 1;
                        break;
                    }
                }
            }

            if (!$is_pay)
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