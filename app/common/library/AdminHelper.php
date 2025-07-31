<?php

namespace app\common\library;


use support\think\Db;

class AdminHelper
{

    const MASHANG = 'mashang';
    const MERCHANT = 'merchant';

    const ADMIN = 'admin';

    const BALANCE = 'balance';
    const LOCK_BALANCE = 'lock_balance';
    const UNLOCK_BALANCE = 'unlock_balance';
    const SUCCESS_AMOUNT = 'success_amount';
    const REBATE_AMOUNT = 'rebate_amount';

    const LOCK_KEY = 'admin_balance_';

    /**
     * 获取锁的key
     * @param $type
     * @param $admin_id
     * @return string
     */
    public static function getLockKey($type, $admin_id)
    {
        return self::LOCK_KEY . $type . '_' . $admin_id;
    }

    /**
     * 获取锁
     * @param $type
     * @param $admin_id
     * @return false|int|mixed|\Redis|string
     */
    public static function getLock($type, $admin_id)
    {
        return RedisLockHelper::getLock(self::getLockKey($type, $admin_id));
    }

    /**
     * 变更积分
     * @param $admin_id
     * @param $type
     * @param $amount
     * @param $rate
     * @param $fix_rate_amount
     * @param $original_table_id
     * @param $memo
     * @return true
     * @throws \Exception
     */
    public static function changeBalance($admin_id, $type, $amount, $rate, $fix_rate_amount = 0, $original_table_id = '', $memo = '', $is_budan = 0)
    {
        try {
            Db::startTrans();
            $redis_key = self::getLockKey($type, $admin_id);
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $amount = $type == self::LOCK_BALANCE ? -$amount : $amount;

            $update_amount = number_format($amount * $rate / 100 + $fix_rate_amount, 2, '.', '');

            $balance_info = Db::name('admin_balance')
                ->where('admin_id', $admin_id)
                ->where('type', $type)
                ->find();

            if ($type == self::BALANCE || $type == self::LOCK_BALANCE) {
                $balance = Db::name('admin_balance')
                    ->where('admin_id', $admin_id)
                    ->whereIn('type', [
                        self::BALANCE,
                        self::LOCK_BALANCE,
                        self::UNLOCK_BALANCE,
                        self::REBATE_AMOUNT
                    ])
                    ->sum('balance');

                if (($balance + $amount) < 0 && !$is_budan)
                    throw new \Exception('积分不足');
            }

            $after_amount = number_format($update_amount + $balance_info['balance'], 2, '.', '');

            Db::name('admin_balance_log')
                ->insert([
                    'admin_id' => $admin_id,
                    'type' => $type,
                    'amount' => $amount,
                    'rate' => $rate,
                    'fix_rate_amount' => $fix_rate_amount,
                    'before_amount' => $balance_info['balance'],
                    'update_amount' => $update_amount,
                    'after_amount' => $after_amount,
                    'original_table_id' => $original_table_id,
                    'memo' => $memo,
                    'create_time' => time(),
                    'change_balance_status' => ($type == self::BALANCE || $type == self::LOCK_BALANCE) ? 0 : 1
                ]);

            if ($type == self::BALANCE || $type == self::LOCK_BALANCE) {
                $result = Db::name('admin_balance')
                    ->where('admin_id', $admin_id)
                    ->where('type', $type)
                    ->where('version', $balance_info['version'])
                    ->inc('version', 1)
                    ->update([
                        'balance' => $after_amount,
                    ]);
                if (!$result)
                    throw new \Exception('数据锁定中');
            }

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write([$admin_id, $type, $amount, $rate, $fix_rate_amount, $original_table_id, $memo], $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }
}