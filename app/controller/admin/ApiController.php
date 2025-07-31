<?php

namespace app\controller\admin;

use app\common\library\AdminHelper;
use app\common\library\CommonHelper;
use app\common\library\DataEncryptHelper;
use app\common\library\DataEncryptHelperCopy;
use app\common\library\LogHelper;
use app\common\library\ReceivingAccountHelper;
use app\common\library\RedisHelper;
use app\common\library\ResponseHelper;
use app\queue\redis\Notify;
use Campo\UserAgent;
use support\Request;
use support\think\Db;
use Webman\RedisQueue\Redis;

class ApiController
{
    /**
     * 查余额
     * @param Request $request
     * @return \support\Response|\think\response\Json
     */
    public function index(Request $request)
    {
        try {
            $content = $request->get('content');
            $method = $request->get('method');

            if (!$content)
                throw new \Exception('参数错误');

            if (!$method)
                throw new \Exception('参数错误');

            $content = DataEncryptHelper::decrypt($content);
            $content = json_decode($content, true);

            if (!is_array($content))
                throw new \Exception('参数错误');

            if (empty($method))
                throw new \Exception('参数错误');

            $obj = new self();
            if (!method_exists($obj, $method))
                throw new \Exception('参数错误');

            return $obj->$method($content);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success('success', []);
    }

    /**
     * 变更积分
     * @param $params
     * @return \support\Response|\think\response\Json
     */
    public function changeBalance($params)
    {
        try {
            Db::startTrans();
            foreach ($params as $param) {
                AdminHelper::changeBalance($param['admin_id'], AdminHelper::BALANCE, $param['balance'], 100, 0, CommonHelper::getOrderNumber(''), $param['memo']);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success();
    }

    /**
     * 查询订单
     * @param $params
     * @return \support\Response|\think\response\Json
     */
    public function queryOrder($params)
    {
        try {
            $row = Db::name('receiving_account_pay_url')
                ->field('api_code, cookie_id, pay_channel_number')
                ->where('pay_channel_number', $params['pay_channel_number'])
                ->where('type', 'receiving')
                ->find();

            $class_name = '\\app\\common\\library\\ds\\' . ucwords($row['api_code']);
            if (!$row)
                throw new \Exception('数据异常');
            $result = $class_name::query($row);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success();
    }

    /**
     * 补单
     * @param $params
     * @return \support\Response|\think\response\Json
     */
    public function budan($params){
        try {
            $id = Db::name('receiving_account_pay_url')
                ->where('pay_channel_number', $params['pay_channel_number'])
                ->value('id');

            ReceivingAccountHelper::budan($id, $params['remark']);
        }catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success();
    }

    /**
     * 查询订单
     * @param $params
     * @return \support\Response|\think\response\Json
     */
    public function notify($params)
    {
        try {
            $order_number = Db::name('pay_order')
                ->where('id', $params['id'])
                ->value('order_number');
            Redis::send((new Notify())->queue, [
                'order_number' => $order_number,
            ]);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success();
    }

    /**
     * 查询订单
     * @param $params
     * @return \support\Response|\think\response\Json
     */
    public function testNotify($params)
    {
        try {
            $order_number = Db::name('pay_order')
                ->where('id', $params['id'])
                ->value('order_number');
            Redis::send((new Notify())->queue, [
                'order_number' => $order_number,
                'status' => 1
            ]);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage());
        }
        return ResponseHelper::success();
    }

    public function getUserAgent($params){
        return ResponseHelper::success('success', [
            'user-agent' => UserAgent::random([
                'os_type' => $params['os_type'] ?? 'Windows',
                'device_type' => $params['device_type'] ?? 'Mobile',
            ])
        ]);
    }

    public function getCache($params){
        return ResponseHelper::success('success',[RedisHelper::get($params['redis_key'])]);
    }
}
