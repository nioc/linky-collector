<?php
    return [
        // script configuration
        // false indicate the script will call Enedis API
        'mock' => false,

        // influxDB database configuration
        'host' => 'localhost',
        'port' => '8086',
        'database' => 'linky',
        'retentionDuration' => '1825d',

        // Power provider configuration
        // authentication
        'enedis_user' => '',
        'enedis_pass' => '',
        // off-peak time, set empty array if not used
        'off-peak' =>
        [
            // each off-peak period is an array element
            0 => [
                // off-peak start time
                'start' =>  [
                    'h' => 22,
                    'm' => 30
                ],
                // off-peak period duration (see https://en.wikipedia.org/wiki/ISO_8601#Durations)
                'duration' =>  'PT8H'
            ]
        ]
    ];
