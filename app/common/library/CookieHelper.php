<?php

namespace app\common\library;

use support\think\Db;

class CookieHelper
{
    //初始
    const COOKIE_TYPE_DEFAULT = 0;
    //正常
    const COOKIE_TYPE_NORMAL = 1;
    //待登录
    const COOKIE_TYPE_NEED_LOGIN = 2;
    //失效
    const COOKIE_TYPE_INVALID = 3;
    //失效
    const COOKIE_TYPE_NEED_CHECK = 4;

    /**
     * 获取cookie
     * @param $type
     * @param $cookie_id
     * @param $sort
     * @return array|mixed
     * @throws \Exception
     */
    public static function getCookie($type, $cookie_id = 0, $sort = 'asc')
    {
        try {
            $where = [
                'type' => $type,
                'status' => self::COOKIE_TYPE_NORMAL
            ];
            if ($cookie_id)
                $where['id'] = $cookie_id;
            $cookie_info = Db::name('pay_channel_cookie')
                ->where($where)
                ->order('last_get_pay_url_time', $sort)
                ->find();
            if (!$cookie_info)
                throw new \Exception('获取cookie失败');

            Db::name('pay_channel_cookie')
                ->where('id', $cookie_info['id'])
                ->update([
                    'last_get_pay_url_time' => time()
                ]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $cookie_info;
    }

    /**
     * 获取姓名
     * @return array|mixed
     * @throws \Exception
     */
    public static function getIdCard()
    {
        try {
            $info = Db::name('id_card_list')
                ->order('last_time asc')
                ->find();
            if ($info) {
                Db::name('id_card_list')
                    ->where('id', $info['id'])
                    ->update([
                        'last_time' => time()
                    ]);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $info;
    }
}