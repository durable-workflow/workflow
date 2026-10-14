<?php

return [
    'eager' => (bool) env('QUALIFICATION_EAGER', false),
    'users' => [
        'qualification-alpha' => ['user' => 'alpha', 'namespace' => 'qualification-alpha'],
        'qualification-beta' => ['user' => 'beta', 'namespace' => 'qualification-beta'],
    ],
];
