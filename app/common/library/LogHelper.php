<?php

namespace app\common\library;

/**
 * 日志助手类
 *
 * @author ameng
 *
 */
class LogHelper
{

    /**
     * 删除目录
     * @param $path
     * @return bool
     */
    public static function rmdirs($path)
    {
        //如果是目录则继续
        if (is_dir($path)) {
            //扫描一个文件夹内的所有文件夹和文件并返回数组
            $p = scandir($path);
            //如果 $p 中有两个以上的元素则说明当前 $path 不为空
            if (count($p) > 2) {
                foreach ($p as $val) {
                    //排除目录中的.和..
                    if ($val != "." && $val != "..") {
                        //如果是目录则递归子目录，继续操作
                        if (is_dir($path . $val)) {
                            //子目录中操作删除文件夹和文件
                            deldir($path . $val . '/');
                        } else {
                            //如果是文件直接删除
                            unlink($path . $val);
                        }
                    }
                }
            }
        }
        //删除目录
        return rmdir($path);
    }


    /**
     * 日志帮助类
     * @param $data
     * @param $msg
     * @return void
     */
    public static function write($data, $msg = "", $type = 'log')
    {
        $debug_data = debug_backtrace();
        $class_name = implode('_', array_filter(explode('\\', $debug_data[1]['class'])));
        $function_name = $debug_data[1]['function'];

        @self::rmdirs(runtime_path() . "/logs/" . $class_name . "/" . $function_name . "/" . $type . "/" . date("Ymd", strtotime('-5 day')) . "/");


        $path = runtime_path() . "/logs/" . $class_name . "/" . $function_name . "/" . $type . "/" . date("Ymd") . "/";

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }


        $file_name = date("H") . ".log";

        $real_file_path = $path . $file_name;

        $handle = fopen($real_file_path, "a+");
        $string = "\n=======================================" . date("Y-m-d H:i:s") . "==================================================" . PHP_EOL;
        $string .= PHP_EOL;
        if ($msg) {
            $string .= $msg . PHP_EOL;
        }
        if (is_array($data)) {
            $string .= var_export($data, true);
        } else {
            $string .= $data;
        }
        fwrite($handle, $string);
        fclose($handle);
    }
}