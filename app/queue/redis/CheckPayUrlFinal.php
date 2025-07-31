<?php

namespace app\queue\redis;

use app\common\library\LogHelper;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

/**
 * 检测支付链接是否过期
 */
class CheckPayUrlFinal implements Consumer
{
    // 要消费的队列名
    public $queue = 'check-pay-url-final-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'check-pay-url-final';

    // 消费
    public function consume($data)
    {
        try {
            //创建任务
            if ($data['task_type'] == 'build_task') {
                //检查订单是否过期
                $list = Db::name('receiving_account_pay_url')
                    ->field('id, pay_channel_number, receiving_account_id, extra_params, pay_url, expired_time, order_number, status,amount, create_time')
                    ->where('expired_time', '<', time())
                    ->where('type', 'receiving')
                    ->where('api_code', $data['api_code'])
                    ->where('is_final', '0')
                    ->order('id asc')
                    ->limit($data['limit'])
                    ->select();

                if (count($list)) {
                    foreach ($list as $key => $value) {
                        Redis::send($this->queue, array_merge([
                            'task_type' => 'do_task',
                            'method' => 'setFinal',
                            'task_api_class' => $data['api_code'],
                        ], $value), ceil($key / 50));
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
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function setFinal($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
            $result = $class_name::setFinal($data);

            Db::name('receiving_account_pay_url')
                ->where('id', $data['id'])
                ->update([
                    'is_final' => '1'
                ]);

            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            LogHelper::write($data, $e->getMessage(), 'error_log');
        }
    }
}