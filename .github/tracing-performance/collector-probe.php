<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require_once __DIR__ . '/vendor/autoload.php';

$count = filter_var($argv[1] ?? '1', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 64],
]);
if ($count === false) {
    throw new InvalidArgumentException('Supply one to sixty-four synthetic calibration spans.');
}
$http = new HttpFactory();
$transport = (new PsrTransportFactory(
    new Client(['timeout' => 1, 'connect_timeout' => 1]), $http, $http,
))->create('http://collector:4318/v1/traces', ContentTypes::PROTOBUF, maxRetries: 0);
$processor = new BatchSpanProcessor(
    new SpanExporter($transport), Clock::getDefault(),
    maxQueueSize: 256, maxExportBatchSize: 64, autoFlush: false,
);
$provider = new TracerProvider([$processor]);
$tracer = $provider->getTracer('durable-workflow.collector-calibration', '1');
for ($index = 0; $index < $count; ++$index) {
    $tracer->spanBuilder('qualification.collector.seed')->startSpan()->end();
}
if (! $provider->shutdown()) {
    throw new UnexpectedValueException('Collector calibration did not drain successfully.');
}
echo json_encode(['synthetic_spans' => $count, 'provider_shutdown' => true], JSON_THROW_ON_ERROR), PHP_EOL;
