<?php

namespace app\queue\redis;

use app\common\library\LogHelper;
use app\common\library\PayBackend;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;

/**
 * 缓存支付链接
 */
class CachePayUrl implements Consumer
{
    // 要消费的队列名
    public $queue = 'cache-pay-url-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'cache-pay-url';

    // 消费
    public function consume($data)
    {
        try {
            //获取支付通道信息
            $pay_channel_info = Db::name('pay_channel')->alias('pc')
                ->field('pc.id, pa.api_code, pc.pay_type, pc.shop_receiving_account_code, pc.receiving_account_code, srat.is_guahao srat_is_guahao, rat.is_guahao rat_is_guahao, rat.need_check_shop_amount rat_need_check_shop_amount, srat.need_check_shop_amount srat_need_check_shop_amount')
                ->join('pay_api pa', 'pc.pay_api_id = pa.id', 'left')
                ->join('receiving_account_types rat', 'pc.receiving_account_code = rat.code', 'left')
                ->join('receiving_account_types srat', 'pc.shop_receiving_account_code = srat.code', 'left')
                ->where('pc.id', $data['pay_channel_id'])
                ->find();

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $data['api_code'])));

            $sort = $class_name::SORT;
            $lock_time = $class_name::CHARGE_ACCOUNT_LOCK_TIME;

            $charge_account_info = PayBackend::getReceivingAccount($data, $pay_channel_info, $sort, $lock_time, 0);

            $result = $class_name::getPayUrl($charge_account_info, $data);

            PayBackend::createSuccess(
                $charge_account_info,
                $pay_channel_info,
                $result['pay_url'],
                $data['amount'],
                $result['real_pay_amount'] ?? $data['amount'],
                $result['pay_channel_number'],
                $result['order_expired_time'],
                $result['expired_time'],
                $result['extra_params'] ?? '',
                $result['cookie_id'] ?? ''
            );

        } catch (\Exception $e) {
            LogHelper::write($data, $e->getMessage(), 'error_log');
            throw new \Exception($e->getMessage());
        }
    }


}