<?php

namespace app\controller;

use app\common\library\AdminHelper;
use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\LogHelper;
use app\common\library\OrderHelper;
use app\common\library\PayBackend;
use app\common\library\RedisHelper;
use app\common\library\ResponseHelper;
use support\Request;
use support\think\Db;

class IndexController
{
    public function notifyPay(Request $request){
        $params = $request->all();
        LogHelper::write($params, '', 'request_log');

        return '00';
    }


    public function notify(Request $request)
    {
        try {
            $params = $request->all();

            if (!isset($params['uid']) || !isset($params['appAuthToken']))
                throw new \Exception('参数错误');

            $redis_key = 'jintiao_' . $params['uid'];
            RedisHelper::set($redis_key, json_encode($params), 86400);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }

        return ResponseHelper::success();
    }

    /**
     * 拉单
     * @param Request $request
     * @return \support\Response|\think\response\Json|\think\response\View
     */
    public function createPayUrl(Request $request)
    {
        $amount = $request->post('amount');
        $params = $request->all();
        if (!isset($params['params']) || !$params['params'])
            exit('参数错误');

        if ($amount) {
            $params['params'] = urldecode($params['params']);
            $params = DataEncryptHelper::decrypt($params['params']);
            $params = json_decode($params, true);

            if (!isset($params['merchant_id']) || !isset($params['product_code']))
                exit('参数错误');

            try {
                if (!is_numeric($amount))
                    throw new \Exception('参数错误');

                $md5_key = Db::name('admin')
                    ->where('type', AdminHelper::MERCHANT)
                    ->where('id', $params['merchant_id'])
                    ->value('md5_key');

                if (!$md5_key)
                    throw new \Exception('参数错误');

                $request_data = [
                    'amount' => $amount,
                    'product_code' => $params['product_code'],
                    'merchant_order_number' => CommonHelper::getOrderNumber(''),
                    'merchant_id' => $params['merchant_id'],
                    'notify_url' => 'https://www.baidu.com',
                    'request_time' => intval(microtime(true) * 1000),
//                    'extra_params' => '李白'
                ];

                $request_data['sign'] = CommonHelper::getMd5Sign($request_data, DataEncryptHelper::decrypt($md5_key));
                if (getenv('APP_DEBUG') == 'true'){
                    $url = 'http://127.0.0.1:8787/merchant/index/create?' . http_build_query($request_data);
                }else{
                    $result_json = CommonHelper::curlRequest('http://127.0.0.1:8787/merchant/index/create', $request_data);
                    $result_array = json_decode($result_json, true);
                    if (!isset($result_array['code']) || $result_array['code'] != 0)
                        throw new \Exception($result_array['msg'] ?? '拉单失败，请重新尝试');
                }
            } catch (\Exception $e) {
                return ResponseHelper::error($e->getMessage());
            }

            return ResponseHelper::success('success', [
                'pay_url' => $url ?? $result_array['data']['pay_url']
            ]);
        }
        return view('index/create_pay_url');
    }

    public function index(Request $request)
    {
        try {
            $order_number = $request->get('order_number');
            if (!$order_number)
                throw new \Exception('订单不存在');

            $order_info = Db::name('pay_order')
                ->field('user_device, order_number, amount, api_code, pay_channel_id, pay_channel_number, system_extra_params, create_success_time, create_time, status, user_ip, user_ip_area')
                ->where('order_number', $order_number)
                ->find();
            if (!$order_info)
                throw new \Exception('订单不存在');

            if ($order_info['status'] != OrderHelper::ORDER_TYPE_DEFAULT && $order_info['status'] != OrderHelper::ORDER_TYPE_WAIT_PAY){
                throw new \Exception('拉单失败');
            }

            $user_ip = CommonHelper::getUserRealIp($request->header(), $request->getRealIp());
            $user_device = CommonHelper::getUserDevice($request->header()['user-agent']);

            $referer = $request->header()['referer'] ?? '';
            if ($referer){
                $referer = parse_url($referer);
                $referer = $referer['scheme'] . '://' . $referer['host'];
            }

            Db::name('pay_order')
                ->where('order_number', $order_number)
                ->update([
                    'user_ip' => $user_ip,
                    'user_device' => $user_device,
                    'referer' => $referer
                ]);

            $pay_channel_info = PayBackend::getPayChannelInfo($order_info['pay_channel_id']);

            $view_path = 'index/' . $pay_channel_info['pay_type'];
            if (file_exists(app_path() . '/view/index/' . $order_info['api_code'] . '.html')) {
                $view_path = 'index/' . $order_info['api_code'];
            }

            if ($pay_channel_info['get_pay_url_type'] == 0)
            {
                $ip_info = PayBackend::checkUserIp($user_ip);
                Db::name('pay_order')
                    ->where('order_number', $order_number)
                    ->update([
                        'user_ip_area' => $ip_info['data']['ip_area'] ?? '',
                    ]);

                if ($ip_info['code'] != ResponseHelper::SUCCESS_CODE){
                    Db::name('pay_order')
                        ->where('order_number', $order_number)
                        ->update([
                            'create_fail_msg' => $ip_info['msg'],
                            'status' => OrderHelper::ORDER_TYPE_TIME_OUT
                        ]);
                    throw new \Exception('拉单失败');
                }

                $pay_url = PayBackend::qrPay($order_info);
                return view($view_path, [
                    'amount' => $order_info['amount'],
                    'tradeno' => $order_number,
                    'pay_url' => $pay_url,
                    'order_expired_time' => PayBackend::getOrderExpiredYime($order_number),
                    'pay_type' => $pay_channel_info['pay_type'],
                ]);
            }

        } catch (\Exception $e) {
            LogHelper::write($order_number, $e->getMessage(), 'error_log');
            return view('index/error', [
                'err_msg' => '网页走丢了'
            ]);
        }
        return view($view_path);
    }

    /**
     * 获取支付链接
     * @param Request $request
     * @return \support\Response|\think\response\Json
     */
    public function getPayUrl(Request $request)
    {
        try {
            $order_number = $request->get('order_number');

            $screen_params = $request->post('screenParams');
            $navigator_params = $request->post('navigatorParams');
            $window_params = $request->post('windowParams');

            $screen_params['width'] = intval($screen_params['width']);
            $screen_params['height'] = intval($screen_params['height']);
            $screen_params['availWidth'] = intval($screen_params['availWidth']);
            $screen_params['availHeight'] = intval($screen_params['availHeight']);
            $screen_params['colorDepth'] = intval($screen_params['colorDepth']);
            $screen_params['pixelRatio'] = floatval($screen_params['pixelRatio']);

            $window_params['innerWidth'] = intval($window_params['innerWidth']);
            $window_params['innerHeight'] = intval($window_params['innerHeight']);
            $window_params['outerWidth'] = intval($window_params['outerWidth']);
            $window_params['outerHeight'] = intval($window_params['outerHeight']);
            $window_params['screenX'] = intval($window_params['screenX']);
            $window_params['screenY'] = intval($window_params['screenY']);
            $window_params['pageYOffset'] = intval($window_params['pageYOffset']);

            $order_info = Db::name('pay_order')
                ->field('user_device, order_number, amount, api_code, pay_channel_id, pay_channel_number, system_extra_params, create_success_time, create_time, status, user_ip, user_ip_area')
                ->where('order_number', $order_number)
                ->find();
            if (!$order_info)
                throw new \Exception('订单不存在');

            if (!$order_info['system_extra_params']) {
                $system_extra_params = json_encode([
                    'screen_params' => $screen_params,
                    'navigator_params' => $navigator_params,
                    'window_params' => $window_params,
                ], JSON_UNESCAPED_UNICODE);

                Db::name('pay_order')
                    ->where('order_number', $order_number)
                    ->update([
                        'system_extra_params' => $system_extra_params
                    ]);

                $order_info['system_extra_params'] = $system_extra_params;
            }

            $user_ip = CommonHelper::getUserRealIp($request->header(), $request->getRealIp());

            if ($user_ip != $order_info['user_ip'])
                $err_msg = '拉单IP异常';

            if (!$order_info['user_ip_area'] && !isset($err_msg)) {
                $ip_info = PayBackend::checkUserIp($user_ip);

                Db::name('pay_order')
                    ->where('order_number', $order_number)
                    ->update([
                        'user_ip_area' => $ip_info['data']['ip_area'] ?? '',
                    ]);
            }

            if (isset($ip_info['code'] ) && $ip_info['code'] != ResponseHelper::SUCCESS_CODE){
                Db::name('pay_order')
                    ->where('order_number', $order_number)
                    ->update([
                        'create_fail_msg' => $ip_info['msg'],
                        'status' => OrderHelper::ORDER_TYPE_TIME_OUT
                    ]);
                throw new \Exception('拉单失败1');
            }elseif ($order_info['status'] != OrderHelper::ORDER_TYPE_DEFAULT && $order_info['status'] != OrderHelper::ORDER_TYPE_WAIT_PAY){
                throw new \Exception('拉单失败2');
            }

            if (isset($err_msg))
                throw new \Exception('拉单失败3');

            $result = PayBackend::getPayUrl($order_info);
        } catch (\Exception $e) {
            LogHelper::write([$request->all()], $e->getMessage(), 'error_log');
            return ResponseHelper::error('暂无支付链接');
        }
        return ResponseHelper::success('', $result);
    }
}
