<?php

return [
    'database' => env('DB_DATABASE'),

    'queue' => [
        'per_table' => (bool) env('MYSQL_OPTIMIZER_PER_TABLE', false),
        'connection' => env('MYSQL_OPTIMIZER_QUEUE_CONNECTION'),
        'name' => env('MYSQL_OPTIMIZER_QUEUE'),
        'timeout' => (int) env('MYSQL_OPTIMIZER_TIMEOUT', 3600),
        'tries' => (int) env('MYSQL_OPTIMIZER_TRIES', 1),
        'backoff' => (int) env('MYSQL_OPTIMIZER_BACKOFF', 3600),
        'unique_for' => (int) env('MYSQL_OPTIMIZER_UNIQUE_FOR', 0),
    ],
];
