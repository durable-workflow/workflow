<?php

return [
    'eager' => (bool) env('QUALIFICATION_EAGER', false),
    'storage_connection' => env('DW_STORAGE_CONNECTION'),
    'cli_query_metrics' => env('QUALIFICATION_CLI_QUERY_METRICS'),
    'users' => [
        'qualification-alpha' => ['user' => 'alpha', 'namespace' => 'qualification-alpha'],
        'qualification-beta' => ['user' => 'beta', 'namespace' => 'qualification-beta'],
    ],
];
