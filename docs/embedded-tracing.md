# Embedded tracing preparation

This document describes the optional embedded tracing implementation being
qualified in the [tracing proposal](https://github.com/durable-workflow/workflow/issues/773).
It is not a published-package support claim. The released OpenTelemetry baggage
parser still fails the encoded-value regression. Full qualification and a
compatible stable release are required before enabling this in production.

The application owns its OpenTelemetry providers, instrumentation, sampling,
exporters and process shutdown. Durable Workflow uses those providers when
`workflow.tracing.enabled` is true. It does not replace application providers or
flush an exporter after each task. Tracing is disabled by default.

## Keep collector outages outside business work

PHP exporters can perform synchronous HTTP requests. A batch processor alone
does not guarantee that ending a span is free of network waits. In the tested
SDK, the default batch processor can export from `onEnd()`. Its export-timeout
constructor argument does not impose a timeout on an arbitrary HTTP client.

The tested application profile uses the official OTLP exporter and an explicit
PSR HTTP client. The client limits each request to 100 milliseconds, transport
retries are disabled, and the processor buffers at most four completed spans.
It exports only when the application explicitly flushes or shuts down:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;

$http = new HttpFactory();
$transport = (new PsrTransportFactory(
    new Client(['timeout' => 0.1, 'connect_timeout' => 0.1]),
    $http,
    $http,
))->create($collectorEndpoint, ContentTypes::PROTOBUF, maxRetries: 0);

// The processor captures this context for its own export calls.
$exportScope = Configurator::createNoop()->storeInContext(Context::getRoot())->activate();
try {
    $processor = new BatchSpanProcessor(
        new SpanExporter($transport),
        Clock::getDefault(),
        maxQueueSize: 4,
        maxExportBatchSize: 2,
        autoFlush: false,
    );
} finally {
    $exportScope->detach();
}
```

Add the processor to the application's provider. The optional dependency profile
is in [`.github/tracing-http/composer.json`](../.github/tracing-http/composer.json).
It is separate from the package's required dependencies.
The temporary export context prevents client instrumentation from creating new
spans for collector requests. It restores the application's context immediately
after constructing the processor.

Arrange bounded worker recycling and call the provider's `shutdown()` from the
application's process teardown after its worker loop returns. Flushing and
shutdown belong outside workflow replay, activity callbacks and database
transactions. Reserve time for every queued export batch in the supervisor's
shutdown grace period. Transport deadlines, batch count, retries and other
application processors all contribute to that budget.

This small queue intentionally drops excess spans. Failed exports are reported
by OpenTelemetry's diagnostic logger and are not replayed as workflow work.
`forceFlush()` returning true alone is not proof that a collector accepted every
span. Observe exporter errors and collector ingestion separately. A slow or
unbounded custom processor can still block its caller.

See the [official PHP exporter documentation](https://opentelemetry.io/docs/languages/php/exporters/)
and the tested SDK's [batch processor](https://github.com/opentelemetry-php/sdk/blob/77e1aa73850154abb86937d52a70883edc3b4547/Trace/SpanProcessor/BatchSpanProcessor.php)
and [HTTP transport factory](https://github.com/opentelemetry-php/sdk/blob/77e1aa73850154abb86937d52a70883edc3b4547/Common/Export/Http/PsrTransportFactory.php).

## What this profile currently verifies

Real HTTP controls cover a collector returning 503, a collector that accepts
the request but stalls, and a refused connection. Independent database queue
workers still complete the original workflow and activity once with exact
typed output, unchanged admission context and deadlines, and zero repairs.
No export request occurs before process teardown in these controls.

Separate controls verify the four-span queue limit, overflow loss, failed-export
diagnostics, recovery through the same provider, original application exception
identity, context cleanup and repeated shutdown. The receiver decodes requests
with the official OTLP protobuf classes. These are functional checks, not
overhead, throughput or collector durability measurements.

## Current boundaries

Workflow processing and activity attempts receive separate spans. Persisted
admission context survives the qualified cold-worker, child, continuation and
signal-with-start paths. Existing runs keep their original admission context.
An untraced legacy admission does not borrow a worker's ambient parent.

The run carrier is separate observability metadata. It is not command, history,
payload or lease authority. The proposed nullable run column has not been
adopted by the native Server. Other admission paths, broader trigger links,
runtime coverage and performance qualification remain unfinished.
