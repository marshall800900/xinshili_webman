<?php

namespace app\process;


use support\think\Db;
use Webman\RedisQueue\Redis;
use Workerman\Crontab\Crontab;

class Task
{
    protected $class_list = [];

    public function onWorkerStart()
    {
        // 每秒钟执行一次
        new Crontab('*/1 * * * * *', function () {
            $task_list = Db::name('system_task')
                ->where('is_open', 1)
                ->select();
            if ($task_list) {
                foreach ($task_list as $value) {
                    $task_value = json_decode($value['task_value'], true);
                    if (
                        ($value['last_task_time'] + $value['time_interval']) < time() &&
                        (
                            ($value['task_time'] >  (time() - 30) && $value['task_time'] < (time()+30)) ||
                            $value['task_time'] < 1
                        )
                    )
                    {
                        if (!isset($this->class_list[$task_value['class_name']])) {
                            $class_name = '\\app\\queue\\redis\\' . $task_value['class_name'];
                            $this->class_list[$task_value['class_name']] = new $class_name();
                        }

                        Redis::send($this->class_list[$task_value['class_name']]->queue, $task_value['data']);
                        Db::name('system_task')
                            ->where('id', $value['id'])
                            ->update([
                                'last_task_time' => time()
                            ]);
                    }
                }
            }
            // echo date('Y-m-d H:i:s') . "\n";
        });
    }
}