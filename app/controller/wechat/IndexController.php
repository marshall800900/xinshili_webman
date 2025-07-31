<?php

namespace app\controller\wechat;

use app\common\library\CommonHelper;
use app\common\library\LogHelper;
use app\common\library\OrderHelper;
use app\common\library\PayBackend;
use app\common\library\ResponseHelper;
use support\Request;
use support\think\Db;

//微信公众号支付
class IndexController
{
    protected $token = '0AbkZ57GirnM80OW9';

    //7873fa1085ffa40ef7d3a10ed59e1a47
    public function checkAccessToken(Request $request)
    {
        try {
            $params = $request->all();
            LogHelper::write($params, '', 'request_log');

            if (!isset($params['timestamp']) || !isset($params['nonce']) || !isset($params['signature']))
                return ResponseHelper::error('参数错误');

            $temp_array = [
                $this->token,
                $params['timestamp'],
                $params['nonce'],
            ];

            sort($temp_array, SORT_STRING);
            $tmpStr = implode($temp_array);
            $tmpStr = sha1($tmpStr);

            if ($tmpStr != $params["signature"])
                throw new \Exception('验证失败');
        } catch (\Exception $e) {
            LogHelper::write($params, $e->getMessage(), 'error_log');
            return ResponseHelper::error($e->getMessage());
        }
        return $params['echostr'];
    }

    public function wechatQrcode(Request $request)
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

            $pay_url = PayBackend::getSystemPayUrl();

            $pay_url = $pay_url . 'wechat/index/pay/?order_number=' . $order_number;

        } catch (\Exception $e) {
            return view('index/error', [
                'err_msg' => '网页走丢了'
            ]);
        }
        return view('wechat_qrcode/qrcode', [
            'pay_url' => 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=wxb3c19f2ba620fd75&redirect_uri=' . urlencode($pay_url) . '&response_type=code&scope=snsapi_base&state=STATE#wechat_redirect',
            'order_number' => $order_number,
            'amount' => $order_info['amount'],
            'add_time' => date('Y-m-d H:i:s', $order_info['create_time'])
        ]);
    }


    public function pay(Request $request)
    {
        try {
            $order_number = $request->get('order_number');
            if (!$order_number)
                throw new \Exception('订单不存在');

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

            $order_info['system_extra_params'] = $request->get('code');
            Db::name('pay_order')
                ->where('order_number', $order_number)
                ->update([
                    'user_ip' => $user_ip,
                    'user_device' => $user_device,
                    'referer' => $request->header()['referer'] ?? '',
                    'system_extra_params' => $order_info['system_extra_params']
                ]);


            $pay_channel_info = PayBackend::getPayChannelInfo($order_info['pay_channel_id']);
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
                return view('wechat_qrcode/pay', [
                    'pay_url' => $pay_url,
                ]);
            }

        } catch (\Exception $e) {
            LogHelper::write($order_number, $e->getMessage(), 'error_log');
            return view('index/error', [
                'err_msg' => '网页走丢了'
            ]);
        }
//        return view($view_path);
    }
}