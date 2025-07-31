<?php

namespace app\common\library;


class DataEncryptHelper
{

    protected static $aes_key = 'TvxuetcgrpHFkOdJMNIf6UlRqiyDhPCY'; //此处填写前后端共同约定的秘钥


    /**
     * 加密
     * @param string $str 要加密的数据
     * @return bool|string   加密后的数据
     */
    public static function encrypt($data)
    {
        $str = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) : $data;
        $str = base64_encode($str);
        $data = openssl_encrypt($str, 'AES-128-ECB', self::$aes_key, OPENSSL_RAW_DATA);
        $data = base64_encode($data);

        return $data;
    }

    /**
     * 解密
     * @param string $str 要解密的数据
     * @return string        解密后的数据
     */
    public static function decrypt($str)
    {

        $decrypted = openssl_decrypt(base64_decode($str), 'AES-128-ECB', self::$aes_key, OPENSSL_RAW_DATA);
        $decrypted = base64_decode($decrypted);
        return $decrypted;
    }
}