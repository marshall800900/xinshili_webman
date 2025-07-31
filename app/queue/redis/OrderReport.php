<?php

namespace app\queue\redis;

use app\common\library\LogHelper;
use app\common\library\OrderHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;

class OrderReport implements Consumer
{
    // 要消费的队列名
    public $queue = 'order-report-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'order-report';

    // 消费
    public function consume($data)
    {
        try {
            Db::startTrans();
            $redis_key = $this->queue . '_' . $data['type'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $where = [];
            $update = [];
            if ($data['type'] == 'create') {
                $update['create_report_status'] = 1;

                $where['create_report_status'] = 0;
            } elseif ($data['type'] == 'create_success') {
                $update['create_success_report_status'] = 1;

                $where['create_success_report_status'] = 0;
                $where['status'] = [
                    OrderHelper::ORDER_TYPE_PAY_SUCCESS,
                    OrderHelper::ORDER_TYPE_REFUND_ING,
                    OrderHelper::ORDER_TYPE_REFUND_SUCCESS,
                    OrderHelper::ORDER_TYPE_WAIT_PAY,
                ];
            } elseif ($data['type'] == 'success') {
                $update['success_report_status'] = 1;

                $where['success_report_status'] = 0;
                $where['status'] = [
                    OrderHelper::ORDER_TYPE_PAY_SUCCESS,
                    OrderHelper::ORDER_TYPE_REFUND_ING,
                ];
            } elseif ($data['type'] == 'refund') {
                $update['refund_report_status'] = 1;

                $where['refund_report_status'] = 0;
                $where['status'] = OrderHelper::ORDER_TYPE_REFUND_SUCCESS;
            }

            $order_list = Db::name('pay_order')
                ->field('
                    id,product_code, pay_channel_id, merchant_id, user_device,cost_rate_amount, merchant_rate_amount, amount,create_time
                ')
                ->where($where)
                ->order('id asc')
                ->limit($data['limit'])
                ->select();

            $list = [];
            if (count($order_list) > 0) {
                foreach ($order_list as $value) {
                    $value['cost_rate_amount'] = intval($value['cost_rate_amount'] * 100);
                    $value['merchant_rate_amount'] = intval($value['merchant_rate_amount'] * 100);
                    $value['amount'] = intval($value['amount'] * 100);

                    $value['date_key'] = date('Y-m-d', $value['create_time']);
                    $value['date_key_h'] = date('Y-m-d H', $value['create_time']);
                    $key =
                        $value['date_key'] . '_' .
                        $value['date_key_h'] . '_' .
                        $value['product_code'] . '_' .
                        $value['pay_channel_id'] . '_' .
                        $value['merchant_id'] . '_' .
                        $value['user_device'];

                    $list[$key] = [
                        'date_key' => $list[$key]['date_key'] ?? $value['date_key'],
                        'date_key_h' => $list[$key]['date_key_h'] ?? $value['date_key_h'],
                        'product_code' => $list[$key]['product_code'] ?? $value['product_code'],
                        'pay_channel_id' => $list[$key]['pay_channel_id'] ?? $value['pay_channel_id'],
                        'merchant_id' => $list[$key]['merchant_id'] ?? $value['merchant_id'],
                        'device' => $list[$key]['device'] ?? (empty($value['user_device']) ? 'other' : $value['user_device']),
                        'order_number' => isset($list[$key]['order_number']) ? $list[$key]['order_number'] + 1 : 1,
                        'order_amount' => isset($list[$key]['order_amount']) ? $list[$key]['order_amount'] + $value['amount'] : $value['amount'],
                        'cost_rate_amount' => isset($list[$key]['cost_rate_amount']) ? $list[$key]['cost_rate_amount'] + $value['cost_rate_amount'] : $value['cost_rate_amount'],
                        'merchant_rate_amount' => isset($list[$key]['merchant_rate_amount']) ? $list[$key]['merchant_rate_amount'] + $value['merchant_rate_amount'] : $value['merchant_rate_amount'],
                    ];

                    Db::name('pay_order')
                        ->where('id', $value['id'])
                        ->update($update);
                }
            }

            if ($list) {
                foreach ($list as $value) {
                    $value['profit_amount'] = number_format(($value['merchant_rate_amount'] - $value['cost_rate_amount']) / 100, 2, '.', '');
                    $value['cost_rate_amount'] = number_format($value['cost_rate_amount'] / 100, 2, '.', '');
                    $value['merchant_rate_amount'] = number_format($value['merchant_rate_amount'] / 100, 2, '.', '');
                    $value['order_amount'] = number_format($value['order_amount'] / 100, 2, '.', '');

                    $key_array = [
                        'date_key' => $value['date_key'],
                        'date_key_h' => $value['date_key_h'],
                        'product_code' => $value['product_code'],
                        'pay_channel_id' => $value['pay_channel_id'],
                        'merchant_id' => $value['merchant_id'],
                        'device' => $value['device'],
                    ];
                    $info = Db::name('pay_channel_report')
                        ->where($key_array)
                        ->find();
                    if (!$info) {
                        Db::name('pay_channel_report')
                            ->insert($key_array);
                    }

                    if ($data['type'] == 'create') {
                        Db::name('pay_channel_report')
                            ->where($key_array)
                            ->inc('create_order_number', $value['order_number'])
                            ->inc('create_order_amount', $value['order_amount'])
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    } elseif ($data['type'] == 'create_success') {
                        Db::name('pay_channel_report')
                            ->where($key_array)
                            ->inc('success_create_order_number', $value['order_number'])
                            ->inc('success_create_order_amount', $value['order_amount'])
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    } elseif ($data['type'] == 'success') {
                        Db::name('pay_channel_report')
                            ->where($key_array)
                            ->inc('success_order_number', $value['order_number'])
                            ->inc('success_order_amount', $value['order_amount'])
                            ->inc('cost_rate_amount', $value['cost_rate_amount'])
                            ->inc('merchant_rate_amount', $value['merchant_rate_amount'])
                            ->inc('profit_amount', $value['profit_amount'])
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    } elseif ($data['type'] == 'refund') {
                        Db::name('pay_channel_report')
                            ->where($key_array)
                            ->inc('refund_order_number', $value['order_number'])
                            ->inc('refund_order_amount', $value['order_amount'])
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    }
                }
            }

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();

            LogHelper::write($data, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            throw new \Exception($e->getMessage());
        }
    }

}