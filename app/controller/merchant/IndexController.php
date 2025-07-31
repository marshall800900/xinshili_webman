<?php

namespace app\controller\merchant;

use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\OrderHelper;
use app\common\library\PayBackend;
use app\common\library\ResponseHelper;
use support\Request;
use support\think\Db;

class IndexController
{
    /**
     * 创建订单
     * @param Request $request
     * @return \support\Response|\think\response\Json
     */
    public function create(Request $request)
    {

        try {
            $params = $request->all();
            LogHelper::write($params, '', 'request_log');
            if (!is_array($params))
                $params = json_decode($params, true);
            //判断订单金额
            if (!isset($params['amount']) || !is_numeric($params['amount']))
                throw new \Exception('参数错误【amount】');

            $rule = '/^[A-Za-z0-9]+$/';
            //判断请求时间
            if (!isset($params['request_time']) || !$params['request_time'] || !is_numeric($params['request_time']) || strlen($params['request_time']) != 13)
                throw new \Exception('参数错误【request_time】');

            //判断产品编号
            if (!isset($params['product_code']) || !$params['product_code'] || !preg_match($rule, $params['product_code']))
                throw new \Exception('参数错误【product_code】');

            //判断订单号
            if (!isset($params['merchant_order_number']) || !$params['merchant_order_number'] || !preg_match($rule, $params['merchant_order_number']))
                throw new \Exception('参数错误【merchant_order_number】');

            //判断商户ID
            if (!isset($params['merchant_id']) || !$params['merchant_id'] || !preg_match($rule, $params['merchant_id']))
                throw new \Exception('参数错误【merchant_id】');

            //判断签名
            if (!isset($params['sign']) || !$params['sign'] || !preg_match($rule, $params['sign']))
                throw new \Exception('参数错误【sign】');

            //判断异步回调地址
            if (!isset($params['notify_url']) || !$params['notify_url'])
                throw new \Exception('参数错误【sign】');

            if (isset($params['extra_params']) && is_array($params['extra_params']))
                throw new \Exception('参数格式错误，extra_params只支持json或字符串');

            //验证签名
            $md5_key = Db::name('admin')
                ->where('id', $params['merchant_id'])
                ->where('status', 'normal')
                ->value('md5_key');

            if (!$md5_key)
                throw new \Exception('商户不存在');

            $md5_key = DataEncryptHelper::decrypt($md5_key);
            if (!CommonHelper::verifyMd5Sign($params, $md5_key))
                throw new \Exception('签名验证失败');


            $product_info = Db::name('merchant_pay_product')->alias('mp')
                ->field('
                    mp.pay_channel_info mp_pay_channel_info, mp.min_amount mp_min_amount, mp.max_amount mp_max_amount, mp.is_open mp_is_open,mp.rate,
                    pp.pay_channel_info pp_pay_channel_info, pp.min_amount pp_min_amount, pp.max_amount pp_max_amount, pp.is_open pp_is_open
                ')
                ->join('pay_product pp', 'mp.product_code = pp.product_code')
                ->where('mp.product_code', $params['product_code'])
                ->where('mp.merchant_id', $params['merchant_id'])
                ->find();

            if (!$product_info)
                throw new \Exception('产品已关闭或未授权');

            if ($product_info['mp_is_open'] != 1 || $product_info['pp_is_open'] != 1)
                throw new \Exception('产品已关闭');

            $product_info['min_amount'] = $product_info['mp_min_amount'] > 0 ? $product_info['mp_min_amount'] : $product_info['pp_min_amount'];
            $product_info['max_amount'] = $product_info['mp_max_amount'] > 0 ? $product_info['mp_max_amount'] : $product_info['pp_max_amount'];
            $product_info['pay_channel_info'] = $product_info['mp_pay_channel_info'] ? $product_info['mp_pay_channel_info'] : $product_info['pp_pay_channel_info'];
            $product_info['pay_channel_info'] = json_decode($product_info['pay_channel_info'], true);

            if ($params['amount'] < $product_info['min_amount'] || $params['amount'] > $product_info['max_amount'])
                throw new \Exception('产品限额【' . $product_info['min_amount'] . '-' . $product_info['max_amount'] . '】');

            $pay_channel_list = Db::name('pay_channel')->alias('pc')
                ->field('pc.id, pc.pay_type, pa.api_code, pc.amount_type,pc.fix_amount')
                ->join('pay_api pa', 'pc.pay_api_id = pa.id', 'left')
                ->whereIn('pc.id', array_keys($product_info['pay_channel_info']))
                ->where('pa.is_open', 1)
                ->where('pc.is_open', 1)
                ->select();
            if (count($pay_channel_list) < 1)
                throw new \Exception('产品已关闭或未授权');


            $width_list = [];
            foreach ($pay_channel_list as $key => $pay_channel) {
                //固定金额
                if ($pay_channel['amount_type'] == PayBackend::PAY_CHANNEL_TYPE_FIX) {
                    $amount_list = explode(',', $pay_channel['fix_amount']);
                    if (!in_array(intval($params['amount']), $amount_list)) {
                        unset($pay_channel_list[$key]);
                        continue;
                    }
                } //非整十
                elseif ($pay_channel['amount_type'] == PayBackend::PAY_CHANNEL_TYPE_NOT_SHI && ($params['amount'] % 10) == 0) {
                    unset($pay_channel_list[$key]);
                    continue;
                } //整十
                elseif ($pay_channel['amount_type'] == PayBackend::PAY_CHANNEL_TYPE_SHI && ($params['amount'] % 10) != 0) {
                    unset($pay_channel_list[$key]);
                    continue;
                } //整百
                elseif ($pay_channel['amount_type'] == PayBackend::PAY_CHANNEL_TYPE_BAI && ($params['amount'] % 100) != 0) {
                    unset($pay_channel_list[$key]);
                    continue;
                }

                $width_list[$pay_channel['id']] = [
                    'width' => $product_info['pay_channel_info'][$pay_channel['id']],
                    'id' => $pay_channel['id'],
                    'api_code' => $pay_channel['api_code'],
                    'pay_type' => $pay_channel['pay_type'],
                ];
            }

            if (!$width_list)
                throw new \Exception('暂无合适支付通道');

            $pay_channel_ids = [];
            foreach ($width_list as $value) {
                for ($i = 0; $i < $value['width']; $i++) {
                    $pay_channel_ids[] = $value['id'];
                }
            }

            $pay_channel_id = rand(0, count($pay_channel_ids) - 1);
            $pay_channel_info = $width_list[$pay_channel_ids[$pay_channel_id]];

            $insert_data = [
                'api_code' => $pay_channel_info['api_code'] ?? '',
                'pay_channel_id' => $pay_channel_info['id'] ?? 0,
                'merchant_id' => $params['merchant_id'],
                'product_code' => $params['product_code'],
                'order_number' => CommonHelper::getOrderNumber('', $params['merchant_id']),
                'merchant_number' => $params['merchant_order_number'],
                'amount' => $params['amount'],
                'merchant_rate' => $product_info['rate'],
                'merchant_rate_amount' => number_format($params['amount'] * $product_info['rate'] / 100, 2, '.', ''),
                'notify_url' => DataEncryptHelper::encrypt($params['notify_url']),
                'extra_params' => DataEncryptHelper::encrypt($params['extra_params'] ?? ''),
                'request_time' => $params['request_time'],
                'create_time' => time(),
            ];

            Db::name('pay_order')
                ->insert($insert_data);

            $pay_url = PayBackend::getSystemPayUrl($pay_channel_info);
            $pay_url = $pay_url . '?order_number=' . $insert_data['order_number'] . '&amount=' . $insert_data['amount'] . '&pay_type=' . $pay_channel_info['pay_type'];
        } catch (\Exception $e) {
            LogHelper::write($params, $e->getMessage(), 'error_log');
            return ResponseHelper::error($e->getMessage());
        }

        return ResponseHelper::success('success', [
            'pay_url' => $pay_url,
            'order_number' => $insert_data['order_number']
        ]);
    }

    public function query(Request $request)
    {
        try {
            $params = $request->all();
            LogHelper::write($params, '', 'request_log');
            if (!is_array($params))
                $params = json_decode($params, true);

            $rule = '/^[A-Za-z0-9]+$/';

            if (!isset($params['request_time']) || !$params['request_time'] || !is_numeric($params['request_time']) || strlen($params['request_time']) != 13)
                throw new \Exception('参数错误【request_time】');

            //判断订单号
            if (!isset($params['merchant_order_number']) || !$params['merchant_order_number'] || !preg_match($rule, $params['merchant_order_number']))
                throw new \Exception('参数错误【merchant_order_number】');

            //判断商户ID
            if (!isset($params['merchant_id']) || !$params['merchant_id'] || !preg_match($rule, $params['merchant_id']))
                throw new \Exception('参数错误【merchant_id】');

            //判断签名
            if (!isset($params['sign']) || !$params['sign'] || !preg_match($rule, $params['sign']))
                throw new \Exception('参数错误【sign】');

            $md5_key = Db::name('admin')
                ->where('id', $params['merchant_id'])
                ->where('status', 'normal')
                ->value('md5_key');

            if (!$md5_key)
                throw new \Exception('商户不存在');

            $md5_key = DataEncryptHelper::decrypt($md5_key);
            if (!CommonHelper::verifyMd5Sign($params, $md5_key))
                throw new \Exception('签名验证失败');

            $order_info = Db::name('pay_order')
                ->field('status')
                ->where('merchant_number', $params['merchant_order_number'])
                ->find();

            if (!$order_info)
                throw new \Exception('订单不存在');

            $status = 2;

            if (in_array($order_info['status'], [OrderHelper::ORDER_TYPE_DEFAULT, OrderHelper::ORDER_TYPE_WAIT_PAY]))
                $status = 0;

            if (in_array($order_info['status'], [OrderHelper::ORDER_TYPE_PAY_SUCCESS]))
                $status = 1;
        } catch (\Exception $e) {
            LogHelper::write($params, $e->getMessage(), 'error_log');
            return ResponseHelper::error($e->getMessage());
        }

        return ResponseHelper::success('success', [
            'status' => $status
        ]);
    }
}
