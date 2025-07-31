<?php

namespace app\common\library;

use support\Redis;

class RedisHelper
{
    /**
     * 设置缓存
     * @param $redis_key
     * @param $value
     * @param $expire_time
     * @return int
     * @throws \Exception
     */
    public static function set($redis_key, $value, $expire_time = 0)
    {
        $result = 0;
        try {
            if (Redis::connection()->set($redis_key, $value)) {
                if ($expire_time) {
                    Redis::connection()->expire($redis_key, $expire_time);
                }

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
     * 获取缓存
     * @param $redis_key
     * @return mixed
     * @throws \Exception
     */
    public static function get($redis_key)
    {
        try {
            $result = Redis::connection()->get($redis_key);
        }
        catch (\Exception $e) {
            LogHelper::write([$redis_key], $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
        return $result;
    }
}