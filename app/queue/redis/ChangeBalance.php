<?php

namespace app\queue\redis;

use app\common\library\AdminHelper;
use app\common\library\LogHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class ChangeBalance implements Consumer
{
    // 要消费的队列名
    public $queue = 'change-balance-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'change-balance';

    // 消费
    public function consume($data)
    {
        try {
            //创建任务
            if ($data['task_type'] == 'build_task') {
                $list = Db::name('admin_balance_log')
                    ->field('id, type, admin_id')
                    ->where('change_balance_status', '1')
                    ->order('id asc')
                    ->limit($data['limit'])
                    ->select();

                if (count($list)) {
                    foreach ($list as $key => $value) {
                        Redis::send($this->queue, [
                            'task_type' => 'do_task',
                            'method' => 'doTask',
                            'id' => $value['id'],
                            'type' => $value['type'],
                            'admin_id' => $value['admin_id'],
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
     * 执行任务
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function doTask($data)
    {
        try {
            Db::startTrans();
            $redis_key = AdminHelper::getLockKey($data['type'], $data['admin_id']);
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $info_redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (RedisLockHelper::lock($info_redis_key, 1, 60))
                throw new \Exception('info lock ing');

            $info = Db::name('admin_balance_log')
                ->field('id,change_balance_status, admin_id, type, update_amount')
                ->where('id', $data['id'])
                ->find();

            if ($info['change_balance_status'] == 1){
                $version = Db::name('admin_balance')
                    ->where('admin_id', $info['admin_id'])
                    ->where('type', $info['type'])
                    ->value('version');

                $result = Db::name('admin_balance')
                    ->where('admin_id', $info['admin_id'])
                    ->where('type', $info['type'])
                    ->where('version', $version)
                    ->inc('balance', $info['update_amount'])
                    ->inc('version', 1)
                    ->update([
                        'admin_id' => $info['admin_id']
                    ]);
                if (!$result)
                    throw new \Exception('数据锁定中');

                Db::name('admin_balance_log')
                    ->where('id', $info['id'])
                    ->update([
                        'change_balance_status' => '0'
                    ]);
            }

            RedisLockHelper::unlock($info_redis_key ?? 'aaa');
            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write($data, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            if ($e->getMessage() != 'info lock ing')
                RedisLockHelper::unlock($info_redis_key ?? 'aaa');
            Db::rollback();
        }
    }

}