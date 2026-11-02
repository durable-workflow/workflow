<?php

declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode([
    'traceparent' => $_SERVER['HTTP_TRACEPARENT'] ?? null,
    'tracestate' => $_SERVER['HTTP_TRACESTATE'] ?? null,
    'baggage' => $_SERVER['HTTP_BAGGAGE'] ?? null,
    'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
], JSON_THROW_ON_ERROR);
