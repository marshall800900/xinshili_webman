<?php

namespace app\queue\redis;

use app\common\library\LogHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class ReceivingAccountBill implements Consumer
{
    // 要消费的队列名
    public $queue = 'receiving_account_bill-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'receiving_account_bill';

    // 消费
    public function consume($data)
    {
        try {
            $list = Db::table('fa_receiving_account')
                ->field('id,admin_id, receiving_account_code,cookie, proxy_ip')
                ->where('receiving_account_code', $data['receiving_account_code'])
                ->where('update_time', '>=', strtotime('-1 day'))
                ->select();
            if (count($list) > 0) {
                foreach ($list as $row) {
                    Redis::send((new ReceivingAccountBillInfo())->queue, $row, rand(1,5));
                }
            }
        } catch (\Exception $e) {
            LogHelper::write($data, $e->getMessage(), 'error_msg');
            throw new \Exception($e->getMessage());
        }
    }
}