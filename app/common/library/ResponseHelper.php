<?php

namespace app\common\library;


class ResponseHelper
{
    const SUCCESS_CODE = 0;
    const FAIL_CODE = 1;

    /**
     * 成功响应
     * @param $msg
     * @param $data
     * @param $code
     * @return \support\Response|\think\response\Json
     */
    public static function success($msg = 'success', $data = [], $code = '')
    {
        if (!$code) $code = self::SUCCESS_CODE;

        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data
        ]);
    }


    /**
     * 失败响应
     * @param $msg
     * @param $data
     * @param $code
     * @return \support\Response|\think\response\Json
     */
    public static function error($msg, $data = [], $code = '')
    {
        if (!$code) $code = self::FAIL_CODE;
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data
        ]);
    }
}