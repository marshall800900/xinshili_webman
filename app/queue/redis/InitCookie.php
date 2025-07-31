<?php

namespace app\queue\redis;

use app\common\library\CookieHelper;
use app\common\library\LogHelper;
use app\common\library\RedisLockHelper;
use app\common\library\SmsHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;
use Webman\RedisQueue\Redis;

class InitCookie implements Consumer
{
    // 要消费的队列名
    public $queue = 'init-cookie-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'init-cookie';

    // 消费
    public function consume($data)
    {
        try {
            //创建任务
            if ($data['task_type'] == 'build_task') {
                switch ($data['method']) {
                    //获取手机号
                    case 'getPhone':
                        Db::name('pay_channel_cookie')
                            ->where('type', $data['task_api_class'])
                            ->where('login_fail_number', '>=', 3)
                            ->update([
                                'status' => CookieHelper::COOKIE_TYPE_INVALID
                            ]);

                        $cookie_count = Db::name('pay_channel_cookie')
                            ->where('type', $data['task_api_class'])
                            ->whereIn('status', [
                                CookieHelper::COOKIE_TYPE_DEFAULT,
                                CookieHelper::COOKIE_TYPE_NEED_LOGIN,
                                CookieHelper::COOKIE_TYPE_NORMAL,
                                CookieHelper::COOKIE_TYPE_NEED_CHECK,
                            ])
                            ->count();

                        if ($cookie_count < $data['need_limit']) {
                            for ($i = 0; $i < $data['limit']; $i++) {
                                Redis::send($this->queue, [
                                    'task_type' => 'do_task',
                                    'task_api_class' => $data['task_api_class'],
                                    'method' => 'getPhone',
                                ]);
                            }
                        }
                        break;
                    //发送验证码
                    case 'sendSmsCode':
                        $list = Db::name('pay_channel_cookie')
                            ->where('type', $data['task_api_class'])
                            ->where('status', CookieHelper::COOKIE_TYPE_DEFAULT)
                            ->limit($data['limit'])
                            ->select();

                        if (count($list) > 0)
                            foreach ($list as $value) {
                                $array = array_merge($value, [
                                    'task_type' => 'do_task',
                                    'task_api_class' => $data['task_api_class'],
                                    'method' => 'sendSmsCode',
                                ]);
                                Redis::send($this->queue, $array);
                            }
                        break;
                    //验证短信
                    case 'verifySmsCode':
                        $list = Db::name('pay_channel_cookie')
                            ->where('type', $data['task_api_class'])
                            ->where('status', CookieHelper::COOKIE_TYPE_NEED_LOGIN)
                            ->limit($data['limit'])
                            ->select();
                        if (count($list) > 0)
                            foreach ($list as $value) {
                                $array = array_merge($value, [
                                    'task_type' => 'do_task',
                                    'task_api_class' => $data['task_api_class'],
                                    'method' => 'verifySmsCode',
                                ]);
                                Redis::send($this->queue, $array);
                            }
                        break;
                    //检查cookie
                    case 'checkCookie':
                        $list = Db::name('pay_channel_cookie')
                            ->where('type', $data['task_api_class'])
                            ->where('status', CookieHelper::COOKIE_TYPE_NEED_CHECK)
                            ->limit($data['limit'])
                            ->select();
                        if (count($list) > 0)
                            foreach ($list as $value) {
                                $array = array_merge($value, [
                                    'task_type' => 'do_task',
                                    'task_api_class' => $data['task_api_class'],
                                    'method' => 'checkCookie',
                                ]);
                                Redis::send($this->queue, $array);
                            }
                        break;
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
     * 获取手机号
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function getPhone($data)
    {
        try {
            $phone = SmsHelper::getPhone($data['task_api_class']);

            Db::name('pay_channel_cookie')
                ->insert([
                    'type' => $data['task_api_class'],
                    'phone' => $phone,
                    'status' => 0,
                    'create_time' => time()
                ]);
        } catch (\Exception $e) {
        }
    }

    /**
     * 创建获取验证码任务
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function sendSmsCode($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');
            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
            $class_name::sendSmsCode($data);
            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            LogHelper::write($data, $e->getMessage(), 'error_log');
        }
    }

    /**
     * 创建获取验证码任务
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function verifySmsCode($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            if (($data['create_time'] + 90) < time())
                Db::name('pay_channel_cookie')
                    ->where('id', $data['id'])
                    ->update([
                        'status' => CookieHelper::COOKIE_TYPE_INVALID
                    ]);

            $sms_code = SmsHelper::getSmsCode($data['task_api_class'], $data['phone']);
            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
            $class_name::verifySmsCode($sms_code, $data);

            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            LogHelper::write($data, $e->getMessage(), 'error_log');
        }
    }

    /**
     * 登录cookie
     * @param $data
     * @return void
     * @throws \Exception
     */
    public function checkCookie($data)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $data['id'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['task_api_class'])));
            $class_name::checkCookie($data);

            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            LogHelper::write($data, $e->getMessage(), 'error_log');
        }
    }
}