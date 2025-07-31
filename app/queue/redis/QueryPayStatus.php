<?php

namespace app\queue\redis;

use app\common\library\LogHelper;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

/**
 * 查询支付状态
 */
class QueryPayStatus implements Consumer
{
    // 要消费的队列名
    public $queue = 'query-pay-status-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'query-pay-status';

    // 消费
    public function consume($data)
    {
        try {
            //创建任务
            if ($data['task_type'] == 'build_task') {
                $list = Db::name('receiving_account_pay_url')->alias('rap')
                    ->field('rap.id, rap.api_code, rap.receiving_account_code, rap.cookie_id, rap.pay_channel_number')
                    ->join('pay_channel pc', 'rap.pay_channel_id = pc.id', 'left')
                    ->where('rap.status', ReceivingAccountHelper::TYPE_CHARGE_ING)
                    ->where('rap.order_expired_time', '>=', time())
                    ->where('rap.type', 'receiving')
                    ->where('pc.auto_query', 'query')
                    ->limit($data['limit'])
                    ->select();

                if (count($list)) {
                    foreach ($list as $key => $value) {
                        Redis::send($this->queue, [
                            'task_type' => 'do_task',
                            'method' => 'queryPay',
                            'id' => $value['id'],
                            'cookie_id' => $value['cookie_id'],
                            'task_api_class' => $value['api_code'],
                            'pay_channel_number' => $value['pay_channel_number']
                        ], ceil($key / 50));
                    }
                }
            } else {
                $obj = new self();
                return call_user_func([$obj, $data['method']], $data);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 查询支付状态
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function queryPay($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');
            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
            $result = $class_name::query($data , 0);
            if ($result){
                ReceivingAccountHelper::success($data['id']);
            }
            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            LogHelper::write($data, $e->getMessage(), 'error_log');
        }
    }
}