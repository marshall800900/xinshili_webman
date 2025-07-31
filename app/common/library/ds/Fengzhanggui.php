<?php

namespace app\common\library\ds;

use app\common\library\CommonHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\RedisLockHelper;
use support\think\Db;

/**
 * 抖音淘宝
 */
class Fengzhanggui
{
    const PAY_TIME = 600;
    const LOCK_TIME = 300;

    const CHARGE_ACCOUNT_LOCK_TIME = 0;
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
            $charge_account_info['cookie'] = json_decode($charge_account_info['cookie'], true);
            $api_info = PayBackend::getPayApiInfo(__CLASS__);

            if (!isset($charge_account_info['cookie']['QrCodeID'])){
                $request_url = $api_info['api_url'] . 'getQrCodeUrl';
                $request_params = [
                    'StoreID' => $charge_account_info['cookie']['StoreID'],
                    'BodyID' => $charge_account_info['cookie']['BodyID'],
                    'MerchantID' => $charge_account_info['cookie']['MerchantID'],
                    'ip' => $charge_account_info['proxy_ip'],
                ];

                $result_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_UNICODE), ['Content-Type:application/json']);
                $result_array = json_decode($result_json, true);
                LogHelper::write([$request_url, $request_params, $result_json, $result_array], '', 'request_log');
                if (!isset($result_array['code']) || $result_array['code'] != 200)
                    throw new \Exception($result_array['error'] ?? '获取收款码失败');

                if (!isset($result_array['data'][0]['QrCodeID']) || empty($result_array['data'][0]['QrCodeID']))
                    throw new \Exception('获取收款码失败');

                $charge_account_info['cookie']['QrCodeID'] = $result_array['data'][0]['QrCodeID'];

                Db::name('receiving_account')
                    ->where('id', $charge_account_info['id'])
                    ->update([
                        'cookie' => json_encode($charge_account_info['cookie'])
                    ]);
            }

            $pay_channel_info = PayBackend::getPayChannelInfo($order_info['pay_channel_id']);

            $real_pay_amount = intval($order_info['amount'] * 100);
            $rand = rand(0, 1);

            if ($order_info['amount'] <= 100)
                $rand = 1;

//            if ($order_info['amount'] >= 1000)
//                $rand = 0;

            for ($i = 1; $i < 200; $i++) {
                $item_amount = $real_pay_amount;
                if ($rand == 0) {
                    $item_amount -= $i;
                } else {
                    $item_amount += $i;
                }
                $redis_key = __CLASS__ . $charge_account_info['charge_account'] . '_' . $item_amount;
                if (RedisLockHelper::lock($redis_key, 1, self::PAY_TIME + self::LOCK_TIME))
                    break;
            }

            $real_pay_amount = number_format($item_amount / 100, 2, '.', '');

//            $real_pay_amount = $order_info['amount'];
//            if ($order_info['amount'] % 10 == 0) {
//                $real_pay_amount = number_format(($order_info['amount'] * 100 - rand(1, 9)) / 100, 2, '.', '');
//            }
            $order_id = date('YmdHis') . rand(100, 999);
            $pay_url = 'https://irichpay-9gg4z5ig2c409a65-1307041520.tcloudbaseapp.com/jump-mp.html?merchantID='.$charge_account_info['cookie']['MerchantID'].
                '&money='.$real_pay_amount.
                '&createtime='.date('YmdHis').
                '&expiretime='.self::PAY_TIME.
                '&storeID=' . $charge_account_info['cookie']['StoreID'];
//            $pay_url = 'http://h5.irichpay.com/Web/JsPay.aspx?auth_code=' .
//                '&merchantID=' . $charge_account_info['cookie']['MerchantID'].
//                '&storeID=' .$charge_account_info['cookie']['StoreID'].
//                '&money=' . $real_pay_amount .
//                '&notifyUrl=' .
//                '&orderID=' .
//                '&createtime=' . date('YmdHis') .
//                '&expiretime=' . self::PAY_TIME .
//                '&QrCodeID=' .$charge_account_info['cookie']['QrCodeID'].
//                '&isOwnScanPay=' .
//                '&remark=' .
//                '&app_id=' .
//                '&source=' .
//                '&scope=';

            if ($pay_channel_info['pay_type'] == 'alipay'){
                $pay_url = 'alipays://platformapi/startapp?appId=20000067&url=' . urlencode($pay_url);
//                $pay_url = 'https://render.alipay.com/p/s/i?scheme=' . urlencode($pay_url);
            }

//            $request_url = $api_info['api_url'] . 'addReceivePayment';
//            $request_params = [
//                'BodyID' => $charge_account_info['cookie']['BodyID'],
//                'Orderid' => $order_id,
//                'DateBegin' => date('Y-m-d'),
//                'DateEnd' => date('Y-m-d', time() + 86400),
//                'describe' => $order_id,
//                'StoreID' => $charge_account_info['cookie']['StoreID'],
//                'amount' => $real_pay_amount,
//                'proxy_ip' => $charge_account_info['proxy_ip']
//            ];
//            $result_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_UNICODE), ['Content-Type:application/json']);
//            $result_array = json_decode($result_json, true);
//            LogHelper::write([$request_url, $request_params, $result_json, $result_array], '', 'request_log');
//            if (!isset($result_array['code']) || $result_array['code'] != 200)
//                throw new \Exception($result_array['error'] ?? '创建收款单失败');
//
//            $request_list_url = $api_info['api_url'] . 'getPayList';
//            $request_list_params = [
//                'BodyID' => $charge_account_info['cookie']['BodyID'],
//                'MerchantID' => $charge_account_info['cookie']['MerchantID'],
//                'proxy_ip' => $charge_account_info['proxy_ip']
//            ];
//            $result_list_json = CommonHelper::curlRequest($request_list_url, json_encode($request_list_params, JSON_UNESCAPED_UNICODE), ['Content-Type:application/json']);
//            $result_list_array = json_decode($result_list_json, true);
//            LogHelper::write([$request_list_url, $request_list_params, $result_list_json, $result_list_array], '', 'request_list_log');
//            if (!isset($result_list_array['code']) || $result_list_array['code'] != 200)
//                throw new \Exception($result_array['error'] ?? '获取收款单列表失败');
//
//            if (!isset($result_list_array['data']['ReceiptActivityInfo']) || count($result_list_array['data']['ReceiptActivityInfo']) < 1)
//                throw new \Exception($result_list_array['error'] ?? '获取收款单列表失败');
//
//            $receipt_activity_id = '';
//            foreach ($result_list_array['data']['ReceiptActivityInfo'] as $val) {
//                if ($val['Orderid'] == $order_id) {
//                    $receipt_activity_id = $val['ReceiptActivityID'];
//                    break;
//                }
//            }
//            if (!$receipt_activity_id)
//                throw new \Exception('获取收款单失败');

//            $pay_url = 'https://render.alipay.com/p/s/i/?scheme=' .
//                urlencode('alipays://platformapi/startapp?appId=2021003186622648&page=' .
//                    urlencode('/pages/shouyingtai/index?merid=' . $charge_account_info['cookie']['BodyID'] . '&urlStoreid=' . $charge_account_info['cookie']['StoreID'] . '&actid=' . $receipt_activity_id)
//                );
        } catch (\Exception $e) {
            Db::name('receiving_account')
                ->where('id', $charge_account_info['id'])
                ->update([
                    'is_open' => '0',
                    'create_fail_msg' => $e->getMessage()
                ]);
            LogHelper::write([$charge_account_info, $order_info], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return [
            'pay_channel_number' => $order_id,
            'extra_params' => json_encode([
                'BodyID' => $charge_account_info['cookie']['BodyID'],
                'Orderid' => $order_id,
                'MerchantID' => $charge_account_info['cookie']['MerchantID'],
//                'StoreID' => $charge_account_info['cookie']['StoreID'],
//                'ReceiptActivityID' => $receipt_activity_id,
                'ip' => $charge_account_info['proxy_ip'],
//                'BeginDate' => date('Y-m-d H:i:s', time()),
//                'EndDate' => date('Y-m-d H:i:s', self::PAY_TIME + self::LOCK_TIME + time()),
            ]),
            'order_expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'expired_time' => self::PAY_TIME + self::LOCK_TIME + time(),
            'pay_url' => $pay_url,
            'real_pay_amount' => $real_pay_amount
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
                ->field('pay_channel_number, real_pay_amount, receiving_account_id, extra_params, pay_url, expired_time, order_number, status,amount, create_time, order_expired_time')
                ->where('pay_channel_number', $row['pay_channel_number'])
                ->find();
            if (!$receiving_account_pay_info)
                throw new \Exception('数据不存在');

            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_url = $api_info['api_url'] . 'queryOrderAll';
            $request_params = json_decode($receiving_account_pay_info['extra_params'], true);
            $request_params['BeginDate'] = date('Y-m-d H:i:s', $receiving_account_pay_info['create_time']);
            $request_params['EndDate'] = date('Y-m-d H:i:s', $receiving_account_pay_info['order_expired_time']);

            if ($receiving_account_pay_info['order_expired_time'] < strtotime(date('Y-m-d 00:00:00')))
                $request_params['history'] = 'true';

            $result_json = CommonHelper::curlRequest($request_url, json_encode($request_params, JSON_UNESCAPED_UNICODE), ['Content-Type:application/json']);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$request_url, $request_params, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['code']) || $result_array['code'] != 200)
                throw new \Exception($result_array['error'] ?? '查询收款单失败');

            if (!isset($result_array['data']['OrderInfo']) || count($result_array['data']['OrderInfo']) < 1)
                throw new \Exception('未支付');

            $is_pay = 0;
            foreach ($result_array['data']['OrderInfo'] as $value) {
                $value['TradeTime'] = strtotime($value['TradeTime']);
                if (
                    $receiving_account_pay_info['real_pay_amount'] == $value['OrderMoney'] &&
                    $value['TradeTime'] > $receiving_account_pay_info['create_time'] &&
                    $value['TradeTime'] < $receiving_account_pay_info['order_expired_time'] &&
                    $value['OrderStatus'] == '交易成功'
                ) {
                    $is_pay = 1;
                }
            }
            if (!$is_pay)
                throw new \Exception('未支付');
        } catch (\Exception $e) {
            LogHelper::write($row, $e->getMessage(), 'error_log');
            if (!$is_ret_fail) {
                return 0;
            }

            throw new \Exception($e->getMessage());
        }

        return 1;
    }

    /**
     * 设置最终状态
     * @param $row
     * @return true
     * @throws \Exception
     */
    public static function setFinal($row)
    {
        try {
            $api_info = PayBackend::getPayApiInfo(__CLASS__);
            $request_del_url = $api_info['api_url'] . 'delePay';
            $request_del_params = json_decode($row['extra_params'], true);
            $result_del_json = CommonHelper::curlRequest($request_del_url, json_encode($request_del_params, JSON_UNESCAPED_UNICODE), ['Content-Type:application/json']);
            $result_del_array = json_decode($result_del_json, true);
            LogHelper::write([$request_del_url, $request_del_params, $result_del_json, $result_del_array], '', 'request_del_log');
            if (!isset($result_del_array['code']) || $result_del_array['code'] != 200)
                throw new \Exception($result_del_array['error'] ?? '删除收款单失败');
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return true;
    }
}