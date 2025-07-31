<?php

namespace app\common\library;


use support\think\Db;

class CommonHelper
{

    /**
     * 验证签名
     * @param $row
     * @param $md5_key
     * @param $sign
     * @return bool
     */
    public static function verifyMd5Sign($row, $md5_key, $sign_key = 'sign')
    {
        return $row[$sign_key] === self::getMd5Sign($row, $md5_key) ? true : false;
    }

    /**
     * 获取MD5签名
     * @param $row
     * @param $md5_key
     * @return string
     */
    public static function getMd5Sign($row, $md5_key)
    {
        $str = '';
        ksort($row);
        foreach ($row as $k => $v) {
            if ((!empty($v) || $v === '0' || $v === 0) && $k != 'sign') {
                $str .= $k . '=' . $v . '&';
            }
        }
        $str .= 'key=' . $md5_key;
        return strtoupper(md5($str));
    }

    /**
     * 获取订单号
     * @param $prefix
     * @param $merchant_id
     * @return string
     */
    public static function getOrderNumber($prefix, $merchant_id = '')
    {
        $redis_key = 'order_number_prefix';
        if (!$prefix) {
            $prefix = RedisHelper::get($redis_key);
            if (!$prefix) {
                $prefix = Db::name('config')
                    ->where('name', 'order_number_prefix')
                    ->value('value');
                RedisHelper::set($redis_key, $prefix, 60);
            }
        }

        return $prefix . $merchant_id . date('YmdHis') . rand(100000, 999999);
    }

    /**
     * curl请求
     * @param $url
     * @param $data
     * @param array $header
     * @param string $method
     * @param int $verify_ssl
     * @param string $proxy_ip
     * @return bool|string
     */
    public static function curlRequest($url, $data, $header = [], $method = 'post', $verify_ssl = 0, $proxy_ip = '', $out_time = 30)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $out_time);

        if (!$verify_ssl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        }
        //post请求
        if ($method == 'post') {
            curl_setopt($ch, CURLOPT_POST, 1);            //使用post请求
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  //提交数据
        }

        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }

        if ($proxy_ip) {
            if (is_array($proxy_ip)){
                curl_setopt($ch, CURLOPT_PROXY, $proxy_ip['proxy_ip']);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_ip['proxy_auth']);
            }else{
                curl_setopt($ch, CURLOPT_PROXY, $proxy_ip);
            }
        }

        $result = curl_exec($ch); //得到返回值
        $err_msg = curl_error($ch);

        curl_close($ch);          //关闭
        unset($ch);
        return $result ? $result : $err_msg;
    }

    /**
     * 获取用户IP
     * @param $header
     * @param $ip
     * @return mixed|string
     */
    public static function getUserRealIp($header, $ip){
        $real_ip = '';

        if (isset($header['x-forwarded-for']) && !empty($header['x-forwarded-for'])){
            $header['x-forwarded-for'] = array_filter(explode(',', $header['x-forwarded-for']));
            $real_ip = $header['realip-wts-x'] ??  current($header['x-forwarded-for']);
        }

        if (!$real_ip && isset($header['x-real-ip']) && !empty($header['x-real-ip'])){
            $header['x-real-ip'] = array_filter(explode(',', $header['x-real-ip']));
            $real_ip = current($header['x-real-ip']);
        }


        return empty($real_ip) ? $ip : $real_ip;
    }

    /**
     * 获取用户设备
     * @param $user_agent
     * @return string
     */
    public static function getUserDevice($user_agent){
        $agent = strtolower($user_agent);

        if (strpos($agent, 'windows nt')) {
            $platform = 'pc';
        } elseif (strpos($agent, 'macintosh')) {
            $platform = 'pc';
        } elseif (strpos($agent, 'ipod')) {
            $platform = 'android';
        } elseif (strpos($agent, 'ipad')) {
            $platform = 'iphone';
        } elseif (strpos($agent, 'iphone')) {
            $platform = 'iphone';
        } elseif (strpos($agent, 'android')) {
            $platform = 'android';
        } elseif (strpos($agent, 'unix')) {
            $platform = 'other';
        } elseif (strpos($agent, 'linux')) {
            $platform = 'other';
        } else {
            $platform = 'other';
        }
        return $platform;
    }
}