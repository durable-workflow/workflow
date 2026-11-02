<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;

/** The fixture application's explicit, bounded OTLP configuration. */
final class TracingExporter
{
    public static function processor(string $endpoint): BatchSpanProcessor
    {
        $factory = new HttpFactory();
        $transport = (new PsrTransportFactory(
            new Client([
                'timeout' => 0.1,
                'connect_timeout' => 0.1,
            ]),
            $factory,
            $factory,
        ))->create($endpoint, ContentTypes::PROTOBUF, maxRetries: 0);

        // The processor captures this context for export. Never trace its own transport.
        $exportScope = Configurator::createNoop()->storeInContext(Context::getRoot())->activate();
        try {
            return new BatchSpanProcessor(
                new SpanExporter($transport),
                Clock::getDefault(),
                maxQueueSize: 4,
                maxExportBatchSize: 2,
                autoFlush: false,
            );
        } finally {
            $exportScope->detach();
        }
    }
}
