<?php

namespace app\queue\redis;

use app\common\library\AdminHelper;
use app\common\library\LogHelper;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisLockHelper;
use support\think\Db;
use Webman\RedisQueue\Consumer;

class MashangReport implements Consumer
{
    // 要消费的队列名
    public $queue = 'mashang-report-queue';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'mashang-report';

    // 消费
    public function consume($data)
    {
        try {
            Db::startTrans();
            $redis_key = $this->queue . '_' . $data['type'];
            if (!RedisLockHelper::lock($redis_key, 1, 60))
                throw new \Exception('lock ing');

            if ($data['type'] == 'create') {
                $pay_url_list = Db::name('receiving_account_pay_url')->alias('rapu')
                    ->field('rapu.create_time,rapu.admin_id, rapu.amount, rapu.receiving_account_code, a.line, a.pid, rapu.id')
                    ->join('admin a', 'rapu.admin_id = a.id', 'left')
                    ->where('create_report_status', '0')
                    ->order('rapu.id asc')
                    ->limit($data['limit'])
                    ->select();


                $list = [];
                if (count($pay_url_list)) {
                    foreach ($pay_url_list as $value) {
                        $line = $value['line'] ? array_filter(explode(',', $value['line'])) : [];
                        $line[] = $value['admin_id'];

                        $date_key = date('Y-m-d', $value['create_time']);
                        $date_key_h = date('Y-m-d H', $value['create_time']);

                        foreach ($line as $admin_id) {
                            $key = $date_key . '_' . $date_key_h . '_' . $value['receiving_account_code'] . '_' . $admin_id;

                            $item_row = [
                                'date_key' => $list[$key]['date_key'] ?? $date_key,
                                'date_key_h' => $list[$key]['date_key_h'] ?? $date_key_h,
                                'receiving_account_code' => $list[$key]['receiving_account_code'] ?? $value['receiving_account_code'],
                                'admin_id' => $list[$key]['admin_id'] ?? $admin_id,
                                'create_order_number' => $list[$key]['create_order_number'] ?? 0,
                                'create_order_amount' => $list[$key]['create_order_amount'] ?? 0,
                                'from_create_order_number' => $list[$key]['from_create_order_number'] ?? 0,
                                'from_create_order_amount' => $list[$key]['from_create_order_amount'] ?? 0,
                                'team_create_order_number' => $list[$key]['team_create_order_number'] ?? 0,
                                'team_create_order_amount' => $list[$key]['team_create_order_amount'] ?? 0,
                            ];

                            if ($admin_id == $value['admin_id']) {
                                $item_row['create_order_number'] = isset($list[$key]['create_order_number']) ? $list[$key]['create_order_number'] + 1 : 1;
                                $item_row['create_order_amount'] = isset($list[$key]['create_order_amount']) ? $list[$key]['create_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                            } elseif ($admin_id == $value['pid']) {
                                $item_row['from_create_order_number'] = isset($list[$key]['from_create_order_number']) ? $list[$key]['from_create_order_number'] + 1 : 1;
                                $item_row['from_create_order_amount'] = isset($list[$key]['from_create_order_amount']) ? $list[$key]['from_create_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                            } else {
                                $item_row['team_create_order_number'] = isset($list[$key]['team_create_order_number']) ? $list[$key]['team_create_order_number'] + 1 : 1;
                                $item_row['team_create_order_amount'] = isset($list[$key]['team_create_order_amount']) ? $list[$key]['team_create_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                            }

                            $list[$key] = $item_row;
                        }
                        Db::name('receiving_account_pay_url')
                            ->where('id', $value['id'])
                            ->update([
                                'create_report_status' => '1'
                            ]);

                    }

                    foreach ($list as $value) {
                        $key_array = [
                            'date_key' => $value['date_key'],
                            'date_key_h' => $value['date_key_h'],
                            'receiving_account_code' => $value['receiving_account_code'],
                            'admin_id' => $value['admin_id'],
                        ];

                        $count = Db::name('mashang_report')
                            ->where($key_array)
                            ->count();
                        if (!$count) {
                            $admin_info = Db::name('admin')
                                ->field('pid, line')
                                ->where('id', $value['admin_id'])
                                ->find();
                            Db::name('mashang_report')
                                ->insert(array_merge($key_array, [
                                    'pid' => $admin_info['pid'],
                                    'line' => $admin_info['line'],
                                ]));
                        }


                        Db::name('mashang_report')
                            ->where($key_array)
                            ->inc('create_order_number', $value['create_order_number'])
                            ->inc('create_order_amount', number_format($value['create_order_amount'] / 100, 2, '.', ''))
                            ->inc('from_create_order_number', $value['from_create_order_number'])
                            ->inc('from_create_order_amount', number_format($value['from_create_order_amount'] / 100, 2, '.', ''))
                            ->inc('team_create_order_number', $value['team_create_order_number'])
                            ->inc('team_create_order_amount', number_format($value['team_create_order_amount'] / 100, 2, '.', ''))
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    }
                }
            }
            elseif ($data['type'] == 'success') {
                $pay_url_list = Db::name('receiving_account_pay_url')->alias('rapu')
                    ->field('rapu.create_time, rapu.admin_id, rapu.amount, rapu.receiving_account_code, a.line, a.pid, rapu.id, rapu.rate_amount, rapu.pay_channel_number')
                    ->join('admin a', 'rapu.admin_id = a.id', 'left')
                    ->where('rapu.status', ReceivingAccountHelper::TYPE_CHARGE_SUCCESS)
                    ->where('rapu.success_report_status', '0')
                    ->order('rapu.id asc')
                    ->limit($data['limit'])
                    ->select();

                $list = [];
                if (count($pay_url_list)) {
                    foreach ($pay_url_list as $value) {
                        $line = $value['line'] ? array_filter(explode(',', $value['line'])) : [];
                        $line[] = $value['admin_id'];

                        $date_key = date('Y-m-d', $value['create_time']);
                        $date_key_h = date('Y-m-d H', $value['create_time']);

                        foreach ($line as $admin_id) {
                            $key = $date_key . '_' . $date_key_h . '_' . $value['receiving_account_code'] . '_' . $admin_id;

                            $item_row = [
                                'date_key' => $list[$key]['date_key'] ?? $date_key,
                                'date_key_h' => $list[$key]['date_key_h'] ?? $date_key_h,
                                'receiving_account_code' => $list[$key]['receiving_account_code'] ?? $value['receiving_account_code'],
                                'admin_id' => $list[$key]['admin_id'] ?? $admin_id,
                                'success_order_number' => $list[$key]['success_order_number'] ?? 0,
                                'success_order_amount' => $list[$key]['success_order_amount'] ?? 0,
                                'from_success_order_number' => $list[$key]['from_success_order_number'] ?? 0,
                                'from_success_order_amount' => $list[$key]['from_success_order_amount'] ?? 0,
                                'team_success_order_number' => $list[$key]['team_success_order_number'] ?? 0,
                                'team_success_order_amount' => $list[$key]['team_success_order_amount'] ?? 0,
                                'rate_amount' => $list[$key]['rate_amount'] ?? 0,
                                'from_rate_amount' => $list[$key]['from_rate_amount'] ?? 0,
                                'team_rate_amount' => $list[$key]['team_rate_amount'] ?? 0,
                            ];

                            $rate_amount = $admin_id == $value['admin_id'] ?
                                $value['rate_amount'] :
                                Db::name('admin_balance_log')->where('admin_id', $admin_id)->where('type', AdminHelper::REBATE_AMOUNT)->where('original_table_id', $value['pay_channel_number'])->value('update_amount');

                            if ($admin_id == $value['admin_id']) {
                                $item_row['success_order_number'] = isset($list[$key]['success_order_number']) ? $list[$key]['success_order_number'] + 1 : 1;
                                $item_row['success_order_amount'] = isset($list[$key]['success_order_amount']) ? $list[$key]['success_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                                $item_row['rate_amount'] = isset($list[$key]['rate_amount']) ? $list[$key]['rate_amount'] + ($rate_amount * 100) : ($rate_amount * 100);
                            } elseif ($admin_id == $value['pid']) {
                                $item_row['from_success_order_number'] = isset($list[$key]['from_success_order_number']) ? $list[$key]['from_success_order_number'] + 1 : 1;
                                $item_row['from_success_order_amount'] = isset($list[$key]['from_success_order_amount']) ? $list[$key]['from_success_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                                $item_row['from_rate_amount'] = isset($list[$key]['from_rate_amount']) ? $list[$key]['from_rate_amount'] + ($rate_amount * 100) : ($rate_amount * 100);
                            } else {
                                $item_row['team_success_order_number'] = isset($list[$key]['team_success_order_number']) ? $list[$key]['team_success_order_number'] + 1 : 1;
                                $item_row['team_success_order_amount'] = isset($list[$key]['team_success_order_amount']) ? $list[$key]['team_success_order_amount'] + ($value['amount'] * 100) : ($value['amount'] * 100);
                                $item_row['team_rate_amount'] = isset($list[$key]['team_rate_amount']) ? $list[$key]['team_rate_amount'] + ($rate_amount * 100) : ($rate_amount * 100);
                            }

                            $list[$key] = $item_row;
                        }
                        Db::name('receiving_account_pay_url')
                            ->where('id', $value['id'])
                            ->update([
                                'success_report_status' => '1'
                            ]);

                    }

                    foreach ($list as $value) {
                        $key_array = [
                            'date_key' => $value['date_key'],
                            'date_key_h' => $value['date_key_h'],
                            'receiving_account_code' => $value['receiving_account_code'],
                            'admin_id' => $value['admin_id'],
                        ];

                        $count = Db::name('mashang_report')
                            ->where($key_array)
                            ->count();
                        if (!$count) {
                            $admin_info = Db::name('admin')
                                ->field('pid, line')
                                ->where('id', $value['admin_id'])
                                ->find();
                            Db::name('mashang_report')
                                ->insert(array_merge($key_array, [
                                    'pid' => $admin_info['pid'],
                                    'line' => $admin_info['line'],
                                ]));
                        }


                        Db::name('mashang_report')
                            ->where($key_array)
                            ->inc('success_order_number', $value['success_order_number'])
                            ->inc('success_order_amount', number_format($value['success_order_amount'] / 100, 2, '.', ''))
                            ->inc('from_success_order_number', $value['from_success_order_number'])
                            ->inc('from_success_order_amount', number_format($value['from_success_order_amount'] / 100, 2, '.', ''))
                            ->inc('team_success_order_number', $value['team_success_order_number'])
                            ->inc('team_success_order_amount', number_format($value['team_success_order_amount'] / 100, 2, '.', ''))
                            ->inc('rate_amount', number_format($value['rate_amount'] / 100, 2, '.', ''))
                            ->inc('from_rate_amount', number_format($value['from_rate_amount'] / 100, 2, '.', ''))
                            ->inc('team_rate_amount', number_format($value['team_rate_amount'] / 100, 2, '.', ''))
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    }
                }
            }
            elseif ($data['type'] == 'receiving_account_create_report') {
                $pay_url_list = Db::name('receiving_account_pay_url')->alias('rapu')
                    ->field('rapu.create_time, rapu.receiving_account_id, rapu.admin_id, rapu.amount, rapu.receiving_account_code, a.line, a.pid, rapu.id')
                    ->join('admin a', 'rapu.admin_id = a.id', 'left')
                    ->where('rapu.receiving_account_create_report', '0')
                    ->order('rapu.id asc')
                    ->limit($data['limit'])
                    ->select();
                if (count($pay_url_list)) {
                    $list = [];
                    foreach ($pay_url_list as $value) {
                        $date_key = date('Y-m-d', $value['create_time']);
                        $date_key_h = date('Y-m-d H', $value['create_time']);

                        $value['amount']  = $value['amount']  * 100;

                        $key = $date_key . '_' . $date_key_h . '_' . $value['receiving_account_code'] . '_' . $value['receiving_account_id'] . '_' . $value['admin_id'];

                        $list[$key] = [
                          'date_key' => $list[$key]['date_key'] ?? $date_key,
                          'date_key_h' => $list[$key]['date_key_h'] ?? $date_key_h,
                          'receiving_account_code' => $list[$key]['receiving_account_code'] ?? $value['receiving_account_code'],
                          'receiving_account_id' => $list[$key]['receiving_account_id'] ?? $value['receiving_account_id'],
                          'pid' => $list[$key]['pid'] ?? $value['pid'],
                          'line' => $list[$key]['line'] ?? $value['line'],
                          'admin_id' => $list[$key]['admin_id'] ?? $value['admin_id'],
                          'create_order_number' =>  isset($list[$key]['create_order_number']) ? $list[$key]['create_order_number'] + 1 : 1,
                          'create_order_amount' =>  isset($list[$key]['create_order_amount']) ? $list[$key]['create_order_amount'] + $value['amount'] : $value['amount'] ,
                        ];

                        Db::name('receiving_account_pay_url')
                            ->where('id', $value['id'])
                            ->update([
                                'receiving_account_create_report' => '1'
                            ]);
                    }

                    foreach ($list as $value) {
                        $key_array = [
                            'date_key' => $value['date_key'],
                            'date_key_h' => $value['date_key_h'],
                            'receiving_account_code' => $value['receiving_account_code'],
                            'receiving_account_id' => $value['receiving_account_id'],
                            'admin_id' => $value['admin_id'],
                        ];

                        $count = Db::name('receiving_account_report')
                            ->where($key_array)
                            ->count();
                        if (!$count)
                            Db::name('receiving_account_report')
                                ->insert(array_merge($key_array,[
                                    'pid' => $value['pid'],
                                    'line' => $value['line']
                                ]));
                        Db::name('receiving_account_report')
                            ->where($key_array)
                            ->inc('create_order_number', $value['create_order_number'])
                            ->inc('create_order_amount', number_format($value['create_order_amount'] / 100 ,2, '.', ''))
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    }
                }
            }
            elseif ($data['type'] == 'receiving_account_success_report') {
                $pay_url_list = Db::name('receiving_account_pay_url')->alias('rapu')
                    ->field('rapu.create_time, rapu.receiving_account_id, rapu.admin_id, rapu.amount, rapu.receiving_account_code, a.line, a.pid, rapu.id, rapu.rate_amount')
                    ->join('admin a', 'rapu.admin_id = a.id', 'left')
                    ->where('rapu.receiving_account_success_report', '0')
                    ->where('rapu.status', ReceivingAccountHelper::TYPE_CHARGE_SUCCESS)
                    ->order('rapu.id asc')
                    ->limit($data['limit'])
                    ->select();
                if (count($pay_url_list)) {
                    $list = [];
                    foreach ($pay_url_list as $value) {
                        $date_key = date('Y-m-d', $value['create_time']);
                        $date_key_h = date('Y-m-d H', $value['create_time']);

                        $value['amount']  = $value['amount']  * 100;
                        $value['rate_amount']  = $value['rate_amount']  * 100;

                        $key = $date_key . '_' . $date_key_h . '_' . $value['receiving_account_code'] . '_' . $value['receiving_account_id'] . '_' . $value['admin_id'];

                        $list[$key] = [
                          'date_key' => $list[$key]['date_key'] ?? $date_key,
                          'date_key_h' => $list[$key]['date_key_h'] ?? $date_key_h,
                          'receiving_account_code' => $list[$key]['receiving_account_code'] ?? $value['receiving_account_code'],
                          'receiving_account_id' => $list[$key]['receiving_account_id'] ?? $value['receiving_account_id'],
                          'pid' => $list[$key]['pid'] ?? $value['pid'],
                          'line' => $list[$key]['line'] ?? $value['line'],
                          'admin_id' => $list[$key]['admin_id'] ?? $value['admin_id'],
                          'success_order_number' =>  isset($list[$key]['success_order_number']) ? $list[$key]['success_order_number'] + 1 : 1,
                          'success_order_amount' =>  isset($list[$key]['success_order_amount']) ? $list[$key]['success_order_amount'] + $value['amount'] : $value['amount'] ,
                          'rate_amount' =>  isset($list[$key]['rate_amount']) ? $list[$key]['rate_amount'] + $value['rate_amount'] : $value['rate_amount'] ,
                        ];

                        Db::name('receiving_account_pay_url')
                            ->where('id', $value['id'])
                            ->update([
                                'receiving_account_success_report' => '1'
                            ]);
                    }

                    foreach ($list as $value) {
                        $key_array = [
                            'date_key' => $value['date_key'],
                            'date_key_h' => $value['date_key_h'],
                            'receiving_account_code' => $value['receiving_account_code'],
                            'receiving_account_id' => $value['receiving_account_id'],
                            'admin_id' => $value['admin_id'],
                        ];

                        $count = Db::name('receiving_account_report')
                            ->where($key_array)
                            ->count();
                        if (!$count)
                            Db::name('receiving_account_report')
                                ->insert(array_merge($key_array,[
                                    'pid' => $value['pid'],
                                    'line' => $value['line']
                                ]));
                        Db::name('receiving_account_report')
                            ->where($key_array)
                            ->inc('success_order_number', $value['success_order_number'])
                            ->inc('success_order_amount', number_format($value['success_order_amount'] / 100 ,2, '.', ''))
                            ->inc('rate_amount', number_format($value['rate_amount'] / 100 ,2, '.', ''))
                            ->update([
                                'date_key' => $value['date_key']
                            ]);
                    }
                }
            }

            RedisLockHelper::unlock($redis_key);
            Db::commit();
        } catch (\Exception $e) {
            LogHelper::write($data, $e->getMessage(), 'error_log');
            Db::rollback();
            if ($e->getMessage() != 'lock ing')
                RedisLockHelper::unlock($redis_key);

            throw new \Exception($e->getMessage());
        }
    }
}