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
class CheckPayUrlTimeOut implements Consumer
{
    // 要消费的队列名
    public $queue = 'check-pay-url-time-out-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'check-pay-url-time-out';

    // 消费
    public function consume($data)
    {
        try {
            //创建任务
            if ($data['task_type'] == 'build_task') {
                //检查订单是否过期
                $list = Db::name('receiving_account_pay_url')
                    ->field('id, api_code, status, cookie_id, pay_channel_number')
                    ->whereIn('status', [
                        ReceivingAccountHelper::TYPE_DEFAULT,
                        ReceivingAccountHelper::TYPE_CHARGE_ING,
                    ])
                    ->where('expired_time', '<', time())
                    ->where('type', 'receiving')
                    ->order('id asc')
                    ->limit($data['limit'])
                    ->select();

                if (count($list)) {
                    foreach ($list as $key => $value) {
                        Redis::send($this->queue, [
                            'status' => $value['status'],
                            'task_type' => 'do_task',
                            'method' => 'checkTimeOut',
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
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function checkTimeOut($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            if ($data['status'] == ReceivingAccountHelper::TYPE_DEFAULT){
                $result = 0;
            }else{
                $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
                $result = $class_name::query($data, 0);
            }
            if (!$result) {
                ReceivingAccountHelper::timeout($data['id']);
            } else {
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