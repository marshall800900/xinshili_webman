<?php
namespace app\common\library;
class SmsHelper{
    const TOKEN = '12979f4ebab7cfff3a27f5fcc61d75d26ecf950835ae9657f03ca7c1950559404f201cc80411b8717289251f665d43a602ac85737a7a020a156b3917b49e52bb31d075702adad7f054bc384d19f18f4c';
    const API_URL = 'https://api.haozhuyun.com/';

    /**
     * 获取项目ID
     * @param $type
     * @return string[]
     */
    public static function getSid($type){
        $types =  [
            'jym' => '20860'
        ];

        return $types[$type] ?? '';
    }

    /**
     * 获取手机号
     * @param $type
     * @return mixed
     * @throws \Exception
     */
    public static function getPhone($type){
        try{
            $url = self::API_URL . 'sms/?api=getPhone&token=' . self::TOKEN . '&sid=' . self::getSid($type);

            $result_json = file_get_contents($url);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$url, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception($result_array['msg'] ?? '获取失败');
        }catch (\Exception $e){
            throw new \Exception($e->getMessage());
        }
        return $result_array['phone'];
    }

    /**
     * 获取验证码
     * @param $type
     * @param $phone
     * @return mixed
     * @throws \Exception
     */
    public static function getSmsCode($type, $phone){
        try{
            $url = self::API_URL . 'sms/?api=getMessage&token=' . self::TOKEN . '&sid=' . self::getSid($type) . '&phone=' . $phone;

            $result_json = file_get_contents($url);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$url, $result_json, $result_array], '', 'request_log');
            if (!isset($result_array['code']) || $result_array['code'] != 0)
                throw new \Exception($result_array['msg'] ?? '获取失败');

            if (!isset($result_array['sms']) || !$result_array['sms'])
                throw new \Exception($result_array['msg'] ?? '获取失败');

            self::cancelRecv($type, $phone);
        }catch (\Exception $e){
            throw new \Exception($e->getMessage());
        }
        return $result_array['yzm'];
    }

    public static function cancelRecv($type, $phone){
        try{
            $url = self::API_URL . 'sms/?api=cancelRecv&token=' . self::TOKEN . '&sid=' . self::getSid($type) . '&phone=' . $phone;

            $result_json = file_get_contents($url);
            $result_array = json_decode($result_json, true);
            LogHelper::write([$url, $result_json, $result_array], '', 'request_log');
        }catch (\Exception $e){

        }
        return true;
    }
}