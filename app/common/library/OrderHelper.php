<?php

namespace app\common\library;

class OrderHelper{
    //默认状态
    const ORDER_TYPE_DEFAULT = 0;
    //等待支付
    const ORDER_TYPE_WAIT_PAY = 1;
    //支付成功
    const ORDER_TYPE_PAY_SUCCESS = 2;
    //退款中
    const ORDER_TYPE_REFUND_ING = 3;
    //已退款
    const ORDER_TYPE_REFUND_SUCCESS= 4;

    //订单超时
    const ORDER_TYPE_TIME_OUT = 5;
}