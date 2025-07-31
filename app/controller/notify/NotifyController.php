<?php

namespace app\controller\notify;

use app\common\library\LogHelper;
use support\Request;

class NotifyController
{
    public function payNotify(Request $request, $api_code){
        try{
            $params = $request->all();
            $header = $request->header();
            LogHelper::write([$params, $header, $api_code], '', 'request_log');

            $class_name = '\\app\\common\\library\\ds\\' . ucwords(implode('', explode('/', $api_code)));
            $result = $class_name::payNotify($params, $header);
        }catch (\Exception $e){
            LogHelper::write($params, $e->getMessage(), 'error_log');

            return 'fail';
        }
        return $result;
    }
}
