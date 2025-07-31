<?php

namespace app\common\library;

use support\think\Db;

class ProxyIpHelper
{
    const API_URL = 'https://dps.kdlapi.com/api/getdps/?secret_id=oeh8jzqzjoi7daygffp0&signature=dwqvbszvg4y5uh5z9cd64odbghafpmso&num=1&pt=1&format=json&sep=1&f_et=1&f_loc=1';

    /**
     * 删除代理IP
     * @param $data
     * @return true
     * @throws \Exception
     */
    public static function unsetProxyIp($data)
    {
        try {
            if (isset($data['id'])) {
                Db::name('pay_channel_cookie')
                    ->where('id', $data['id'])
                    ->update([
                        'proxy_ip' => '',
                        'proxy_ip_invalid_time' => '',
                    ]);

                Db::name('receiving_account')
                    ->where('id', $data['id'])
                    ->update([
                        'proxy_ip' => '',
                        'proxy_ip_invalid_time' => '',
                    ]);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    public static function getProxyIp($data, $city_code = '', $province_code = '', $num = 0)
    {
        try {
            if (!isset($data['proxy_ip_invalid_time']) || $data['proxy_ip_invalid_time'] < (time() + 30)) {
                if (isset($data['area']) && $num == 0) {
                    $data['area'] = json_decode($data['area'], true);
                    $city_code = Db::name('proxy_area')
                        ->where('city_name', 'like', '' . $data['area']['city'] . '%')
                        ->value('city_code');
                    if (!$city_code) {
                        $city_code = Db::name('proxy_area')
                            ->where('region_name', 'like', '' . $data['area']['city'] . '%')
                            ->value('region_code');
                    }

                    $province_code = Db::name('proxy_area')
                        ->where('province_name', 'like', '' . $data['area']['province'] . '%')
                        ->value('province_code');

                    return self::getProxyIp($data, $city_code, $province_code, 1);
                }

                $api_url = self::API_URL . '&area=' . ($city_code ? $city_code : ($province_code ? $province_code : ''));

                $result_json = file_get_contents($api_url);
                $result_array = json_decode($result_json, true);
                LogHelper::write([$data, [$city_code, $province_code, $num], $result_json, $result_array]);
                if (!isset($result_array['code']) || $result_array['code'] != 0 || !isset($result_array['data']['proxy_list'][0])) {
                    if ($num == 1) {
                        $city_code = '';
                    } elseif ($num == 2) {
                        $province_code = '';
                    }
                    throw new \Exception($result_array['msg'] ?? '获取代理IP失败');
                }

                $proxy_ip_info = $result_array['data']['proxy_list'][0];
                $proxy_ip_info = explode(',', $proxy_ip_info);

                $data['proxy_ip'] = $proxy_ip_info[0];
                $data['proxy_ip_invalid_time'] = time() + $proxy_ip_info[2];

                if (isset($data['id'])) {
                    Db::name('pay_channel_cookie')
                        ->where('id', $data['id'])
                        ->update([
                            'proxy_ip' => $data['proxy_ip'],
                            'proxy_ip_invalid_time' => $data['proxy_ip_invalid_time'],
                        ]);

                    Db::name('receiving_account')
                        ->where('id', $data['id'])
                        ->update([
                            'proxy_ip' => $data['proxy_ip'],
                            'proxy_ip_invalid_time' => $data['proxy_ip_invalid_time'],
                        ]);
                }
            }
        } catch (\Exception $e) {
            if ($num < 2) {
                $num++;
                return self::getProxyIp($data, $city_code, $province_code, $num);
            }

            throw new \Exception($e->getMessage());
        }
        return $data;
    }
}