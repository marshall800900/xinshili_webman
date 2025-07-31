<?php

namespace app\common\library;

use app\queue\redis\CachePayUrl;
use support\think\Db;
use Webman\RedisQueue\Redis;

class PayBackend
{
    //任意金额
    const PAY_CHANNEL_TYPE_DEFAULT = 1;
    //固定金额
    const PAY_CHANNEL_TYPE_FIX = 2;
    //非整十金额
    const PAY_CHANNEL_TYPE_NOT_SHI = 3;
    //整十
    const PAY_CHANNEL_TYPE_SHI = 4;
    //整百
    const PAY_CHANNEL_TYPE_BAI = 5;

    const PAY_CHANNEL_GET_PAY_URL_TYPE_TONGBU = 0;
    const PAY_CHANNEL_GET_PAY_URL_TYPE_YIBU = 1;


    /**
     * 异步获取支付链接
     * @param $order_info
     * @return array
     * @throws \Exception
     */
    public static function getPayUrl($order_info)
    {
        try {
            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $order_info['api_code'])));

            $expired_time = $class_name::PAY_TIME;

            if (!$order_info['create_success_time']) {
                $expired_time += $order_info['create_time'];
            } else {
                $expired_time += $order_info['create_success_time'];
            }
            if ($expired_time < time())
                throw new \Exception('订单已过期');

            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $order_info['order_number'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $redis_queue_key = __CLASS__ . '_' . __METHOD__ . '_queue_' . $order_info['order_number'];
            if (!RedisLockHelper::getLock($redis_queue_key)) {
                Redis::send((new CachePayUrl())->queue, $order_info);
                RedisLockHelper::lock($redis_queue_key, 1, 3600);
            } else {
                if ($order_info['pay_channel_number']) {
                    $receiving_account_pay_info = Db::name('receiving_account_pay_url')
                        ->field('status, pay_url, receiving_account_id')
                        ->where('pay_channel_number', $order_info['pay_channel_number'])
                        ->find();
                } else {
                    $receiving_account_pay_info = self::goPay($order_info);
                }
            }

            if (!isset($receiving_account_pay_info['status']) || $receiving_account_pay_info['status'] != ReceivingAccountHelper::TYPE_CHARGE_ING)
                throw new \Exception('暂无支付链接1');

            if (!isset($receiving_account_pay_info['pay_url']) || empty($receiving_account_pay_info['pay_url']))
                throw new \Exception('暂无支付链接2');

            $charge_account = Db::name('receiving_account')
                ->where('id', $receiving_account_pay_info['receiving_account_id'])
                ->value('charge_account');

            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            LogHelper::write($order_info, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing' && isset($redis_key))
                RedisLockHelper::unlock($redis_key);

            throw new \Exception($e->getMessage());
        }
        return [
            'expired_time' => $expired_time,
            'pay_url' => $receiving_account_pay_info['pay_url'],
            'charge_account' => $charge_account
        ];
    }

    /**
     * 获取订单超时时间
     * @param $order_number
     * @return int|mixed
     */
    public static function getOrderExpiredYime($order_number){
        try{
            $pay_channel_number = Db::name('pay_order')
                ->where('order_number', $order_number)
                ->value('pay_channel_number');
            $order_expired_time = Db::name('receiving_account_pay_url')
                ->where('pay_channel_number', $pay_channel_number)
                ->value('order_expired_time');
        }catch (\Exception $e){
            return 1800;
        }
        return intval($order_expired_time - time());
    }

    /**
     * 同步获取支付链接
     * @param $order_info
     * @return mixed
     * @throws \Exception
     */
    public static function qrPay($order_info)
    {
        try {
            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $order_info['order_number'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $order_info['api_code'])));
            if (($class_name::PAY_TIME + $order_info['create_time']) < time())
                throw new \Exception('订单已过期');

            if ($order_info['pay_channel_number']) {
                $result = Db::name('receiving_account_pay_url')
                    ->field('status, pay_url, receiving_account_id')
                    ->where('pay_channel_number', $order_info['pay_channel_number'])
                    ->find();
            } else {
                //获取支付通道信息
                $pay_channel_info = Db::name('pay_channel')->alias('pc')
                    ->field('pc.id, pa.api_code, pc.pay_type, pc.shop_receiving_account_code, pc.receiving_account_code, srat.is_guahao srat_is_guahao, rat.is_guahao rat_is_guahao, rat.need_check_shop_amount rat_need_check_shop_amount, srat.need_check_shop_amount srat_need_check_shop_amount')
                    ->join('pay_api pa', 'pc.pay_api_id = pa.id', 'left')
                    ->join('receiving_account_types rat', 'pc.receiving_account_code = rat.code', 'left')
                    ->join('receiving_account_types srat', 'pc.shop_receiving_account_code = srat.code', 'left')
                    ->where('pc.id', $order_info['pay_channel_id'])
                    ->find();


                $sort = $class_name::SORT;
                $lock_time = $class_name::CHARGE_ACCOUNT_LOCK_TIME;

                $charge_account_info = self::getReceivingAccount($order_info, $pay_channel_info, $sort, $lock_time, 0);

                $result = $class_name::getPayUrl($charge_account_info, $order_info);

                self::createSuccess(
                    $charge_account_info,
                    $pay_channel_info,
                    $result['pay_url'],
                    $order_info['amount'],
                    $result['real_pay_amount'] ?? $order_info['amount'],
                    $result['pay_channel_number'],
                    $result['order_expired_time'],
                    $result['expired_time'],
                    $result['extra_params'] ?? '',
                    $result['cookie_id'] ?? '',
                    $order_info['order_number']
                );

                $result = self::goPay($order_info, $result['pay_channel_number']);
            }
            RedisLockHelper::unlock($redis_key);
        } catch (\Exception $e) {
            LogHelper::write($order_info, $e->getMessage(), 'error_log');
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);
            throw new \Exception($e->getMessage());
        }
        return $result['pay_url'];
    }

    /**
     * 匹配支付链接
     * @param $order_info
     * @return mixed
     * @throws \Exception
     */
    public static function goPay($order_info, $pay_channel_number = '')
    {
        try {
            $pay_channel_info = self::getPayChannelInfo($order_info['pay_channel_id']);

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('_', $order_info['api_code'])));

            $where = [
                'order_number' => ''
            ];
            if ($pay_channel_number) {
                $where['pay_channel_number'] = $pay_channel_number;
                $where['order_number'] = $order_info['order_number'];
            }

            $list = Db::name('receiving_account_pay_url')
                ->field('status, pay_url, id, admin_id, receiving_account_code, receiving_account_id, amount, real_pay_amount, pay_channel_number')
                ->where('status', ReceivingAccountHelper::TYPE_DEFAULT)
                ->where('pay_channel_id', $order_info['pay_channel_id'])
                ->where('amount', $order_info['amount'])
                ->where('pay_type', $pay_channel_info['pay_type'])
                ->where('api_code', $order_info['api_code'])
                ->where('expired_time', '>=', time() + $class_name::PAY_TIME)
                ->order('create_time asc')
                ->where($where)
                ->select();
            if (count($list) < 1)
                throw new \Exception('暂无支付链接');

            $receiving_account_pay_info = [];

            $redis_pay_url_key = __CLASS__ . '_' . __METHOD__ . '_receiving_account_pay_url_';
            foreach ($list as $row) {
                if (RedisLockHelper::lock($redis_pay_url_key . $row['id'], 1, 60)) {
                    $receiving_account_pay_info = $row;
                    break;
                }
            }

            if (!$receiving_account_pay_info)
                throw new \Exception('获取支付链接失败');

            Db::startTrans();
            $line = Db::name('admin')
                ->where('id', $receiving_account_pay_info['admin_id'])
                ->value('line');
            $line = $line ? array_filter(explode(',', $line)) : 0;
            $top_id = $line ? current($line) : $receiving_account_pay_info['admin_id'];
            $rate = Db::name('mashang_product')
                ->where('admin_id', $top_id)
                ->where('receiving_account_code', $receiving_account_pay_info['receiving_account_code'])
                ->value('rate');

            Db::name('pay_order')
                ->where('order_number', $order_info['order_number'])
                ->update([
                    'receiving_account_id' => $receiving_account_pay_info['receiving_account_id'],
                    'admin_id' => $receiving_account_pay_info['admin_id'],
                    'create_success_time' => time(),
                    'pay_channel_number' => $receiving_account_pay_info['pay_channel_number'],
                    'status' => OrderHelper::ORDER_TYPE_WAIT_PAY,
                    'real_amount' => $receiving_account_pay_info['real_pay_amount'] ?? $receiving_account_pay_info['amount'],
                    'cost_rate' => $rate,
                    'cost_rate_amount' => number_format($rate * $receiving_account_pay_info['amount'] / 100, 2, '.', '')
                ]);

            Db::name('receiving_account_pay_url')
                ->where('id', $receiving_account_pay_info['id'])
                ->update([
                    'status' => ReceivingAccountHelper::TYPE_CHARGE_ING,
                    'order_number' => $order_info['order_number']
                ]);

            $receiving_account_pay_info['status'] = ReceivingAccountHelper::TYPE_CHARGE_ING;
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write([$order_info, $pay_channel_number], $e->getMessage(), 'error_log');
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $receiving_account_pay_info;
    }

    /**
     * 获取充值账号
     * @param $pay_channel_info
     * @param $order_info
     * @param $is_shop
     * @return void
     * @throws \Exception
     */
    public static function getReceivingAccount($order_info, $pay_channel_info, $sort = 'desc', $lock_time = 0, $is_shop = 0)
    {
        try {
            //根据收款账号为分账还是收款类型赋值
            $pay_channel_info['is_guahao'] = $is_shop ? $pay_channel_info['srat_is_guahao'] : $pay_channel_info['rat_is_guahao'];
            $pay_channel_info['need_check_shop_amount'] = $is_shop ? $pay_channel_info['srat_need_check_shop_amount'] : $pay_channel_info['rat_need_check_shop_amount'];
            $pay_channel_info['receiving_account_code'] = $is_shop ? $pay_channel_info['shop_receiving_account_code'] : $pay_channel_info['receiving_account_code'];

            //获取码商列表
            $mashang_list = self::getMashangList($pay_channel_info['receiving_account_code'], $pay_channel_info['is_guahao'], $order_info['amount'], [], 0, $pay_channel_info['need_check_shop_amount']);

            $charge_account_info = [];
            $redis_key = '_getReceivingAccount_';

            $fail_ids = [];
            foreach ($mashang_list as $value) {
                if (in_array($value['id'], $fail_ids))
                    continue;

                //检测余额有没有被锁定
                if (AdminHelper::getLock(AdminHelper::BALANCE, $value['id'])) {
                    $fail_ids[] = $value['id'];
                    continue;
                }

                //获取余额
                $balance = Db::name('admin_balance')
                    ->whereIn('type', [
                        AdminHelper::BALANCE,
                        AdminHelper::LOCK_BALANCE,
                        AdminHelper::UNLOCK_BALANCE,
                    ])
                    ->where('admin_id', $value['id'])
                    ->sum('balance');


                //如果余额小于订单金额不接受订单
                if ($balance < $order_info['amount']) {
                    $fail_ids[] = $value['id'];
                    continue;
                }

                $where = [];
                if ($pay_channel_info['need_check_shop_amount']) {
                    $where['shop_amount'] = $order_info['amount'];
                }

                //获取收款账号列表
                $charge_account_list = Db::name('receiving_account')
                    ->where($where)
                    ->where('is_open', '1')
                    ->where('is_del', '0')
                    ->where('receiving_account_code', $pay_channel_info['receiving_account_code'])
                    ->where('system_open', '1')
                    ->whereIn('status', [ReceivingAccountHelper::TYPE_DEFAULT, ReceivingAccountHelper::TYPE_CHARGE_ING])
                    ->where(function ($query) use ($pay_channel_info, $order_info) {
                        if ($pay_channel_info['is_guahao'] != 1) {
                            $query->whereOr('charge_amount', $order_info['amount']);
                        } else {
                            $query->whereOr('charge_amount', 0);
                            $query->whereOr('charge_amount >= (charge_amount_ing+real_charge_amount+' . $order_info['amount'] . ')');
                        }
                    })
                    ->where('admin_id', $value['id'])
                    ->order('update_time', $sort)
                    ->select();

                if (count($charge_account_list) < 1) {
                    $fail_ids[] = $value['id'];
                    continue;
                }

                foreach ($charge_account_list as $charge_account) {
                    //需要锁定账号
                    if ($lock_time > 0) {
                        if (RedisLockHelper::lock($redis_key . $charge_account['id'], 1, $lock_time)) {
                            $charge_account_info = $charge_account;
                            break;
                        }
                        //不需要锁定账号
                    } else {
                        if (RedisLockHelper::lock($redis_key . $charge_account['id'], 1, 60)) {
                            $charge_account_info = $charge_account;
                            RedisLockHelper::unlock($redis_key . $charge_account['id']);
                            break;
                        }
                    }
                }

                if ($charge_account_info) {
                    $charge_account_info['rate'] = $value['rate'];
                    break;
                }
            }

            if (!$charge_account_info)
                throw new \Exception('获取充值账号失败');

        } catch (\Exception $e) {
            LogHelper::write([$order_info, $pay_channel_info, $sort, $is_shop], $e->getMessage(), 'error_log');

            throw new \Exception($e->getMessage());
        }

        return $charge_account_info;
    }

    /**
     * 拉单成功
     * @param $charge_account_info
     * @return int|string
     * @throws \Exception
     */
    public static function createSuccess($charge_account_info, $pay_channel_info, $pay_url, $amount, $real_pay_amount, $pay_channel_number, $order_expired_time, $expired_time, $extra_params, $cookie_id = 0, $order_number = '', $num = 5)
    {
        try {
            Db::startTrans();
//            $redis_key = '_createSuccess_' . $charge_account_info['id'];
//            if (!RedisLockHelper::lock($redis_key, 1, 60))
//                throw new \Exception('lock ing');

            Db::name('receiving_account_pay_url')
                ->insert([
                    'type' => 'receiving',
                    'order_number' => $order_number,
                    'api_code' => $pay_channel_info['api_code'],
                    'pay_type' => $pay_channel_info['pay_type'],
                    'pay_channel_id' => $pay_channel_info['id'],
                    'admin_id' => $charge_account_info['admin_id'],
                    'receiving_account_code' => $charge_account_info['receiving_account_code'],
                    'receiving_account_id' => $charge_account_info['id'],
                    'pay_url' => $pay_url,
                    'amount' => $amount,
                    'order_expired_time' => $order_expired_time,
                    'expired_time' => $expired_time,
                    'extra_params' => $extra_params,
                    'cookie_id' => $cookie_id,
                    'real_pay_amount' => $real_pay_amount,
                    'pay_channel_number' => $pay_channel_number,
                    'rate' => $charge_account_info['rate'],
                    'rate_amount' => number_format($charge_account_info['rate'] * $amount / 100, 2, '.', ''),
                    'status' => ReceivingAccountHelper::TYPE_DEFAULT,
                    'create_time' => time()
                ]);

            Db::name('receiving_account')
                ->where('id', $charge_account_info['id'])
                ->inc('charge_amount_ing', $amount)
                ->update([
                    'update_time' => time()
                ]);

            AdminHelper::changeBalance(
                $charge_account_info['admin_id'],
                AdminHelper::LOCK_BALANCE,
                $amount,
                100 - $charge_account_info['rate'],
                0,
                $pay_channel_number,
                '生成支付订单'
            );

//            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write([$charge_account_info, $pay_channel_info, $pay_url, $amount, $real_pay_amount, $pay_channel_number, $order_expired_time, $expired_time, $extra_params, $cookie_id, $order_number, $num], $e->getMessage(), 'error_log');

//            if ($e->getMessage() != 'lock ing') {
//                RedisLockHelper::unlock($redis_key);
//            } else
            if ($num > 0) {
                $num--;
                return self::createSuccess($charge_account_info, $pay_channel_info, $pay_url, $amount, $real_pay_amount, $pay_channel_number, $order_expired_time, $expired_time, $extra_params, $cookie_id, $order_number, $num);
            }
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 获取码商列表
     * @param $receiving_account_code
     * @param $top_mashang_ids
     * @return void
     */
    public static function getMashangList($receiving_account_code, $is_guahao, $order_amount, $top_mashang_ids = [], $is_for = 0, $need_check_shop_amount = 0)
    {
        try {
            if (!$top_mashang_ids && $is_for)
                throw new \Exception('暂无顶级码商1');
            if (!$top_mashang_ids && !$is_for) {
                $top_admin_list = Db::name('admin')->alias('a')
                    ->field('a.id, mp.width')
                    ->join('mashang_product mp', 'a.id = mp.admin_id', 'left')
                    ->where('a.type', AdminHelper::MASHANG)
                    ->where('a.status', 'normal')
                    ->where('a.pid', 0)
                    ->where('mp.is_open', '1')
                    ->where('mp.receiving_account_code', $receiving_account_code)
                    ->select();
                if (count($top_admin_list) < 1)
                    throw new \Exception('暂无顶级码商2');

                foreach ($top_admin_list as $value) {
                    $top_mashang_ids[$value['id']] = $value['width'];
                }
            }

            $top_ids = [];
            foreach ($top_mashang_ids as $key => $value) {
                for ($i = 0; $i < $value; $i++) {
                    $top_ids[] = $key;
                }
            }

            $top_id = $top_ids[rand(0, count($top_ids) - 1)];

            $redis_key = __CLASS__ . '_' . __METHOD__ . '_' . $top_id;
            $cache = RedisHelper::get($redis_key);
            if (!$cache) {
                $admin_balance_sql = Db::name('admin_balance')
                    ->field('admin_id, sum(balance) balance')
                    ->whereIn('type', [
                        AdminHelper::BALANCE,
                        AdminHelper::LOCK_BALANCE,
                        AdminHelper::UNLOCK_BALANCE,
                        AdminHelper::REBATE_AMOUNT
                    ])
                    ->group('admin_id')
                    ->buildSql();

                $where = [];
                if ($need_check_shop_amount) {
                    $where['shop_amount'] = $order_amount;
                }

                $receiving_account = Db::name('receiving_account')
                    ->field('admin_id, count(id) count')
                    ->where('is_open', '1')
                    ->where('is_del', '0')
                    ->where($where)
                    ->where('system_open', '1')
                    ->where('receiving_account_code', $receiving_account_code)
                    ->whereIn('status', [ReceivingAccountHelper::TYPE_DEFAULT, ReceivingAccountHelper::TYPE_CHARGE_ING])
                    ->where(function ($query) use ($is_guahao, $order_amount) {
                        if ($is_guahao != 1) {
                            $query->whereOr('charge_amount', $order_amount);
                        } else {
                            $query->whereOr('charge_amount', 0);
                            $query->whereOr('charge_amount >= (charge_amount_ing+real_charge_amount+' . $order_amount . ')');
                        }
                    })
                    ->group('admin_id')
                    ->buildSql();

                $mashang_list = Db::name('admin')->alias('a')
                    ->field('a.id, mp.width, mp.rate')
                    ->join($admin_balance_sql . ' ab', 'a.id = ab.admin_id', 'left')
                    ->join($receiving_account . ' ra', 'a.id = ra.admin_id', 'left')
                    ->join('mashang_product mp', 'a.id = mp.admin_id', 'left')
                    ->where('a.type', AdminHelper::MASHANG)
                    ->where('a.status', 'normal')
                    ->where(function ($query) use ($top_id) {
                        $query->whereOr('a.id', $top_id);
                        $query->whereOr('a.line', 'like', ',' . $top_id . '%');
                    })
                    ->where('ra.count', '>', 0)
                    ->where('ab.balance', '>', 0)
                    ->where('mp.is_open', '1')
                    ->where('mp.receiving_account_code', $receiving_account_code)
                    ->select();
                if (!count($mashang_list)) {
                    unset($top_mashang_ids[$top_id]);
                    return self::getMashangList($receiving_account_code, $is_guahao, $order_amount, $top_mashang_ids, 1, $need_check_shop_amount);
                }

                $mashang_ids = [];
                foreach ($mashang_list as $value) {
                    for ($i = 0; $i < $value['width']; $i++) {
                        $mashang_ids[] = $value;
                    }
                }


                RedisHelper::set($redis_key, json_encode($mashang_ids), 5);
            } else {
                $mashang_ids = json_decode($cache, true);
            }

            shuffle($mashang_ids);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $mashang_ids;
    }

    /**
     * 获取系统默认支付链接
     * @return mixed
     * @throws \Exception
     */
    public static function getSystemPayUrl($pay_channel_info = [])
    {
        try {
            $redis_key = 'cache_system_pay_url' . ($pay_channel_info['api_code'] ?? '');
            $pay_url = RedisHelper::get($redis_key);
            if (!$pay_url) {
                $pay_url = Db::name('config')
                    ->where('name', 'pay_url')
                    ->value('value');
                if (isset($pay_channel_info['api_code']) && $pay_channel_info['api_code'] == 'dougong')
                    $pay_url  = $pay_url .'wechat/index/wechatQrcode';

                RedisHelper::set($redis_key, $pay_url, 60);
            }

        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $pay_url;
    }


    /**
     * 获取接口信息
     * @param $api_code
     * @return array|mixed
     * @throws \Exception
     */
    public static function getPayApiInfo($api_code)
    {
        try {
            $api_code = explode('\\', $api_code);
            $api_code = strtolower(end($api_code));
            $redis_key = __CLASS__ . '_' . __FUNCTION__ . '_' . $api_code;
            $api_info = RedisHelper::get($redis_key);
            if (!$api_info) {
                $api_info = Db::name('pay_api')
                    ->field('api_code, api_name, merchant_id, api_url, api_key, private_key')
                    ->where('api_code', $api_code)
                    ->find();
                RedisHelper::set($redis_key, json_encode($api_info, JSON_UNESCAPED_UNICODE), 60);
            } else {
                $api_info = json_decode($api_info, true);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $api_info;
    }

    /**
     * 获取接口信息
     * @param $api_code
     * @return array|mixed
     * @throws \Exception
     */
    public static function getPayChannelInfo($pay_channel_id)
    {
        try {
            $redis_key = __CLASS__ . '_' . __FUNCTION__ . '_' . $pay_channel_id;

            $pay_channel_info = RedisHelper::get($redis_key);
            if (!$pay_channel_info) {
                $pay_channel_info = Db::name('pay_channel')
                    ->where('id', $pay_channel_id)
                    ->find();
                RedisHelper::set($redis_key, json_encode($pay_channel_info, JSON_UNESCAPED_UNICODE), 60);
            } else {
                $pay_channel_info = json_decode($pay_channel_info, true);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $pay_channel_info;
    }

    /**
     * 检查地区
     * @param $user_ip
     * @return string
     * @throws \Exception
     */
    public static function checkUserIp($user_ip)
    {
        try {
            $ip_result = CommonHelper::curlRequest('https://pro.ip-api.com/php/' . $user_ip . '?lang=zh-CN&key=l547tmQXbqso2qM', [], [], 'get');
            $ip_result_array = unserialize($ip_result);
            $ip_area = ($ip_result_array['regionName'] ?? '') . '-' . ($ip_result_array['city'] ?? '');

            if (isset($ip_result_array['country']) && $ip_result_array['countryCode'] != 'CN')
                throw new \Exception('不允许境外IP拉单');

            if (!isset($err_msg) && (strstr($ip_area, '新疆') || strstr($ip_area, '内蒙') || strstr($ip_area, '西藏')))
                throw new \Exception('不允许【' . $ip_result_array['regionName'] . '】支付');
        } catch (\Exception $e) {
            return [
                'code' => ResponseHelper::FAIL_CODE,
                'msg' => $e->getMessage(),
                'data' => [
                    'ip_area' => $ip_area
                ]
            ];
        }

        return [
            'code' => ResponseHelper::SUCCESS_CODE,
            'msg' => 'success',
            'data' => [
                'ip_area' => $ip_area
            ]
        ];
    }
}