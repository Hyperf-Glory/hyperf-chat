<?php

declare(strict_types = 1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    'default' => [
        'enable' => true,
        'host'   => env('NSQ_HOST', 'localhost'),
        'port'   => 4150,
        'pool'   => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout'    => 3.0,
            'heartbeat'       => -1,
            'max_idle_time'   => 60.0,
        ],
        'nsqd'   => [
            'port'     => 4151,
            'options'  => [
                'base_uri' => env('NSQ_HOST', 'localhost'),
            ],
        ],
    ],
    'nsqd'   => [
        'host'   => env('NSQ_HOST', 'localhost'),
        'port'     => 4151,
        'options'  => [
            'base_uri' => env('NSQ_HOST', 'localhost'),
        ],
    ],
];
