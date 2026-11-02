<?php

declare(strict_types=1);

foreach (json_decode(getenv('DW_TEST_COLLECTOR_AUTOLOADS'), true, flags: JSON_THROW_ON_ERROR) as $autoload) {
    require_once $autoload;
}
$directory = getenv('DW_TEST_COLLECTOR_DIRECTORY');
$request = new Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest();
$request->mergeFromString(file_get_contents('php://input'));
$spans = [];
foreach ($request->getResourceSpans() as $resource) {
    foreach ($resource->getScopeSpans() as $scope) {
        foreach ($scope->getSpans() as $span) {
            $spans[] = [
                'name' => $span->getName(),
                'trace_id' => bin2hex($span->getTraceId()),
                'span_id' => bin2hex($span->getSpanId()),
            ];
        }
    }
}
$mode = trim(file_get_contents($directory . '/mode'));
file_put_contents($directory . '/requests.jsonl', json_encode([
    'mode' => $mode,
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'content_type' => $_SERVER['CONTENT_TYPE'],
    'spans' => $spans,
    'request' => $request->serializeToJsonString(),
], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
if ($mode === 'stall') {
    usleep(300_000);
}
http_response_code($mode === 'unavailable' ? 503 : 200);
header('Content-Type: application/x-protobuf');
echo (new Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse())->serializeToString();
