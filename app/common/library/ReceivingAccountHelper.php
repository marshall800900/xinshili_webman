<?php

namespace app\common\library;

use app\queue\redis\Notify;
use support\think\Db;
use Webman\RedisQueue\Redis;

class ReceivingAccountHelper
{
    const TYPE_DEFAULT = 0;

    const TYPE_CHARGE_ING = 1;

    const TYPE_CHARGE_SUCCESS = 2;
    const TYPE_CHARGE_FAIL = 3;
    const TYPE_CHARGE_REFUND = 4;

    const RECEIVING_ACCOUNT_TYPE_DEFAULT = 0;
    const RECEIVING_ACCOUNT_TYPE_CHARGE_ING = 1;
    const RECEIVING_ACCOUNT_TYPE_SUCCESS = 2;
    const RECEIVING_ACCOUNT_TYPE_FAIL = 3;


    public static function getLockKey($id)
    {
        return __CLASS__ . '_' . __METHOD__ . '_' . $id;
    }

    /**
     * 补单
     * @param $id
     * @param $memo
     * @return true
     * @throws \Exception
     */
    public static function budan($id, $memo = ''){
        try {
            Db::startTrans();
            $redis_key = self::getLockKey($id);
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');
            $receiving_account_pay_url = Db::name('receiving_account_pay_url')
                ->field('receiving_account_id, receiving_account_code, rate, amount, pay_channel_number, order_number, status, admin_id')
                ->where('id', $id)
                ->find();
            if ($receiving_account_pay_url['status'] != self::TYPE_CHARGE_ING && $receiving_account_pay_url['status'] != self::TYPE_DEFAULT && $receiving_account_pay_url['status'] != self::TYPE_CHARGE_FAIL)
                throw new \Exception('订单状态异常');

            if (!$receiving_account_pay_url['pay_channel_number'])
                throw new \Exception('订单状态异常');

            $receiving_account_info = Db::name('receiving_account')
                ->field('real_charge_amount,charge_amount')
                ->where('id', $receiving_account_pay_url['receiving_account_id'])
                ->find();


            if ($receiving_account_pay_url['status'] == self::TYPE_CHARGE_FAIL)
                AdminHelper::changeBalance(
                    $receiving_account_pay_url['admin_id'],
                    AdminHelper::BALANCE,
                    -$receiving_account_pay_url['amount'],
                    100 - $receiving_account_pay_url['rate'],
                    0,
                    $receiving_account_pay_url['pay_channel_number'],
                    $memo,
                    1
                );


            $line = Db::name('admin')
                ->where('type', AdminHelper::MASHANG)
                ->where('id', $receiving_account_pay_url['admin_id'])
                ->value('line');
            $line = $line ? array_filter(explode(',', $line)) : [];

            if ($line) {
                foreach ($line as $key => $pid) {
                    $rate = Db::name('mashang_product')
                        ->where('admin_id', $pid)
                        ->where('receiving_account_code', $receiving_account_pay_url['receiving_account_code'])
                        ->value('rate');

                    if (isset($line[$key+1])){
                        $from_rate = Db::name('mashang_product')
                            ->where('admin_id', $line[$key+1])
                            ->where('receiving_account_code', $receiving_account_pay_url['receiving_account_code'])
                            ->value('rate');
                    }else{
                        $from_rate = $receiving_account_pay_url['rate'];
                    }


                    $real_rate = (($rate * 100) - ($from_rate * 100)) / 100;
                    AdminHelper::changeBalance($pid, AdminHelper::REBATE_AMOUNT, $receiving_account_pay_url['amount'], $real_rate, 0, $receiving_account_pay_url['pay_channel_number'], '下级佣金');
                }
            }
            //更细收款账号数据
            Db::name('receiving_account')
                ->where('id', $receiving_account_pay_url['receiving_account_id'])
                ->inc('real_charge_amount', $receiving_account_pay_url['amount'])
                ->dec('charge_amount_ing', $receiving_account_pay_url['amount'])
                ->update([
                    'status' => (
                        $receiving_account_info['charge_amount'] > 0 &&
                        ($receiving_account_info['real_charge_amount'] + $receiving_account_pay_url['amount']) > $receiving_account_info['charge_amount']
                    ) ? self::RECEIVING_ACCOUNT_TYPE_SUCCESS : self::RECEIVING_ACCOUNT_TYPE_CHARGE_ING
                ]);

            //更新支付数据
            Db::name('receiving_account_pay_url')
                ->where('id', $id)
                ->update([
                    'status' => self::TYPE_CHARGE_SUCCESS,
                ]);


            if ($receiving_account_pay_url['pay_channel_number']) {
                //更新支付订单
                Db::name('pay_order')
                    ->where('order_number', $receiving_account_pay_url['order_number'])
                    ->update([
                        'success_time' => time(),
                        'status' => OrderHelper::ORDER_TYPE_PAY_SUCCESS
                    ]);
            }

            Redis::send((new Notify())->queue, ['order_number' => $receiving_account_pay_url['order_number']]);

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write($id, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 订单成功
     * @param $id
     * @return true
     * @throws \Exception
     */
    public static function success($id)
    {
        try {
            Db::startTrans();
            $redis_key = self::getLockKey($id);
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');
            $receiving_account_pay_url = Db::name('receiving_account_pay_url')
                ->field('receiving_account_id, receiving_account_code, rate, amount, pay_channel_number, order_number, status, admin_id')
                ->where('id', $id)
                ->find();
            if ($receiving_account_pay_url['status'] != self::TYPE_CHARGE_ING && $receiving_account_pay_url['status'] != self::TYPE_DEFAULT)
                throw new \Exception('订单状态异常');

            $receiving_account_info = Db::name('receiving_account')
                ->field('real_charge_amount,charge_amount')
                ->where('id', $receiving_account_pay_url['receiving_account_id'])
                ->find();

            $line = Db::name('admin')
                ->where('type', AdminHelper::MASHANG)
                ->where('id', $receiving_account_pay_url['admin_id'])
                ->value('line');
            $line = $line ? array_filter(explode(',', $line)) : [];


            if ($line) {
                foreach ($line as $key => $pid) {
                    $rate = Db::name('mashang_product')
                        ->where('admin_id', $pid)
                        ->where('receiving_account_code', $receiving_account_pay_url['receiving_account_code'])
                        ->value('rate');

                    if (isset($line[$key+1])){
                        $from_rate = Db::name('mashang_product')
                            ->where('admin_id', $line[$key+1])
                            ->where('receiving_account_code', $receiving_account_pay_url['receiving_account_code'])
                            ->value('rate');
                    }else{
                        $from_rate = $receiving_account_pay_url['rate'];
                    }


                    $real_rate = (($rate * 100) - ($from_rate * 100)) / 100;
                    AdminHelper::changeBalance($pid, AdminHelper::REBATE_AMOUNT, $receiving_account_pay_url['amount'], $real_rate, 0, $receiving_account_pay_url['pay_channel_number'], '下级佣金');
                }
            }
            //更细收款账号数据
            Db::name('receiving_account')
                ->where('id', $receiving_account_pay_url['receiving_account_id'])
                ->inc('real_charge_amount', $receiving_account_pay_url['amount'])
                ->dec('charge_amount_ing', $receiving_account_pay_url['amount'])
                ->update([
                    'status' => (
                        $receiving_account_info['charge_amount'] > 0 &&
                        ($receiving_account_info['real_charge_amount'] + $receiving_account_pay_url['amount']) > $receiving_account_info['charge_amount']
                    ) ? self::RECEIVING_ACCOUNT_TYPE_SUCCESS : self::RECEIVING_ACCOUNT_TYPE_CHARGE_ING
                ]);

            //更新支付数据
            Db::name('receiving_account_pay_url')
                ->where('id', $id)
                ->update([
                    'status' => self::TYPE_CHARGE_SUCCESS,
                ]);


            if ($receiving_account_pay_url['pay_channel_number']) {
                //更新支付订单
                Db::name('pay_order')
                    ->where('order_number', $receiving_account_pay_url['order_number'])
                    ->update([
                        'success_time' => time(),
                        'status' => OrderHelper::ORDER_TYPE_PAY_SUCCESS
                    ]);
            }

            Redis::send((new Notify())->queue, ['order_number' => $receiving_account_pay_url['order_number']]);

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write($id, $e->getMessage(), 'error_log');

            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 订单超时
     * @param $id
     * @return true
     * @throws \Exception
     */
    public static function timeout($id)
    {
        try {
            Db::startTrans();
            $redis_key = self::getLockKey($id);
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $receiving_account_pay_url = Db::name('receiving_account_pay_url')
                ->field('receiving_account_id, receiving_account_code, rate, amount, pay_channel_number, order_number, status, admin_id')
                ->where('id', $id)
                ->find();
            if ($receiving_account_pay_url['status'] != self::TYPE_CHARGE_ING && $receiving_account_pay_url['status'] != self::TYPE_DEFAULT)
                throw new \Exception('订单状态异常');

            $pay_channel_number = $receiving_account_pay_url['pay_channel_number'] ? $receiving_account_pay_url['pay_channel_number'] : $receiving_account_pay_url['order_number'];

            AdminHelper::changeBalance($receiving_account_pay_url['admin_id'], AdminHelper::UNLOCK_BALANCE, $receiving_account_pay_url['amount'], 100 - $receiving_account_pay_url['rate'], 0, $pay_channel_number, '订单超时');


            Db::name('receiving_account')
                ->where('id', $receiving_account_pay_url['receiving_account_id'])
                ->dec('charge_amount_ing', $receiving_account_pay_url['amount'])
                ->update([
                    'id' => $receiving_account_pay_url['receiving_account_id']
                ]);

            Db::name('receiving_account_pay_url')
                ->where('id', $id)
                ->update([
                    'status' => self::TYPE_CHARGE_FAIL,
                ]);


            $where = [
                'pay_channel_number' => $receiving_account_pay_url['pay_channel_number']
            ];
            if (!$receiving_account_pay_url['pay_channel_number']) {
                $where['order_number'] = $receiving_account_pay_url['order_number'];
            }
            //更新支付订单
            Db::name('pay_order')
                ->where($where)
                ->update([
                    'status' => OrderHelper::ORDER_TYPE_TIME_OUT
                ]);

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write($id, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    public static function refund()
    {

    }
}