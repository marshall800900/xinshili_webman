<?php

namespace app\common\library;

use support\Redis;

class RedisLockHelper
{

    public static function ping(){
        Redis::connection();
    }


    /**
     * ����
     * @param $redis_key
     * @param $value
     * @param int $expire_time
     * @return int
     */
    public static function lock($redis_key, $value, $expire_time = 2)
    {
        $result = 0;
        try {
            if (Redis::connection()->setNx($redis_key, $value)) {
                Redis::connection()->expire($redis_key, $expire_time);

                $result = 1;
            }
        }
        catch (\Exception $e) {
            LogHelper::write([$redis_key, $value, $expire_time], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }

        return $result;
    }

    /**
     * ɾ��
     * @param $redis_key
     * @return bool
     */
    public static function unlock($redis_key)
    {
        try {
            Redis::connection()->del($redis_key);
        }
        catch (\Exception $e) {
            LogHelper::write([$redis_key], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    public static function getLock($redis_key){
        try {
            $result = Redis::connection()->get ( $redis_key );
        } catch ( \Exception $e ) {
        }
        return $result ?? 0;
    }
}