<?php
return [
    'default' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 5, // 消费失败后，重试次数
            'retry_seconds' => 5, // 重试间隔，单位秒
        ]
    ],
    //订单报表
    'order-report' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //初始化ck
    'init-cookie' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //缓存支付链接
    'cache-pay-url' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 3, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //缓存支付链接
    'query-pay-status' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //检测链接超时
    'check-pay-url-time-out' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //设置链接最终状态
    'check-pay-url-final' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //余额变更
    'change-balance' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //码队报表
    'mashang-report' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //异步通知
    'notify' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 2, // 消费失败后，重试次数
            'retry_seconds' => 10, // 重试间隔，单位秒
        ]
    ],
    //查询账单
    'receiving_account_bill' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
    //查询账单详情
    'receiving_account_bill_info' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => null,       // 密码，字符串类型，可选参数
            'db' => 0,            // 数据库
            'prefix' => '',       // key 前缀
            'max_attempts'  => 0, // 消费失败后，重试次数
            'retry_seconds' => 0, // 重试间隔，单位秒
        ]
    ],
];
