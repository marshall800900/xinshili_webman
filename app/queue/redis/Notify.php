<?php

namespace app\queue\redis;

use app\common\library\AdminHelper;
use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\OrderHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class Notify implements Consumer
{
    // 要消费的队列名
    public $queue = 'notify-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'notify';

    // 消费
    public function consume($data)
    {
        try {
            $order_info = Db::name('pay_order')
                ->field('merchant_number, merchant_id, notify_url, extra_params, status, amount')
                ->where('order_number', $data['order_number'])
                ->find();

            $order_info['notify_url'] = DataEncryptHelper::decrypt($order_info['notify_url']);
            $order_info['extra_params'] = DataEncryptHelper::decrypt($order_info['extra_params']);

            $md5_key = Db::name('admin')
                ->where('id', $order_info['merchant_id'])
                ->value('md5_key');

            $md5_key = DataEncryptHelper::decrypt($md5_key);

            if (isset($data['status'])){
                $status = $data['status'];
            }else{
                $status = 2;

                if (in_array($order_info['status'], [OrderHelper::ORDER_TYPE_DEFAULT, OrderHelper::ORDER_TYPE_WAIT_PAY]))
                    $status = 0;

                if (in_array($order_info['status'], [OrderHelper::ORDER_TYPE_PAY_SUCCESS]))
                    $status = 1;
            }

            $request_array = [
                'request_time' => intval(microtime(true) * 1000),
                'merchant_order_number' => $order_info['merchant_number'],
                'merchant_id' => $order_info['merchant_id'],
                'extra_params' => $order_info['extra_params'],
                'amount' => $order_info['amount'],
                'status' => $status
            ];
            $request_array['sign'] = CommonHelper::getMd5Sign($request_array, $md5_key);
            $request_json = json_encode($request_array);

            $result = CommonHelper::curlRequest($order_info['notify_url'], $request_json, ['Content-Type: application/json']);

            LogHelper::write([$request_json, $request_array, $order_info['notify_url'], $result], '', 'request_log');
            if ($result != 'success') {
                Db::name('pay_order')
                    ->where('order_number', $data['order_number'])
                    ->inc('notify_number', 1)
                    ->update([
                        'id' => $data['id']
                    ]);
            } else {
                Db::name('pay_order')
                    ->where('order_number', $data['order_number'])
                    ->update([
                        'notify_status' => 1
                    ]);
            }

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}