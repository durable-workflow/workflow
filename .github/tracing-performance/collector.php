<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
$request = new Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest();
$request->mergeFromString(file_get_contents('php://input'));
$count = 0;
foreach ($request->getResourceSpans() as $resource) {
    foreach ($resource->getScopeSpans() as $scope) {
        $count += count($scope->getSpans());
    }
}
file_put_contents(getenv('BENCH_STATE') . '/received.jsonl', json_encode([
    'spans' => $count, 'bytes' => strlen($request->serializeToString()),
], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
header('Content-Type: application/x-protobuf');
echo (new Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse())->serializeToString();
