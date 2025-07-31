<?php

namespace app\queue\redis;

use app\common\library\CookieHelper;
use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\RedisLockHelper;
use app\common\library\SmsHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class ReceivingAccountBillInfo implements Consumer
{
    // 要消费的队列名
    public $queue = 'receiving_account_bill_info-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'receiving_account_bill_info';

    // 消费
    public function consume($data)
    {
        try {
            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['receiving_account_code'])));
            $class_name::billInfo( $data);
        } catch (\Exception $e) {
            LogHelper::write($data, $e->getMessage(), 'error_msg');
            throw new \Exception($e->getMessage());
        }
    }
}