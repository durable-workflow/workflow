<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\PortableLocalActivityPreparation;

final class PreparedLocalRetryDescriptorTest extends TestCase
{
    private mixed $priorFacadeApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        // WorkflowCommandNormalizer invokes Validator::make() via the facade.
        // Provide the minimal Laravel Validation factory manually so the test
        // runs outside a full Laravel application bootstrap.
        $container = new IlluminateContainer();

        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new ValidationFactory($translator, $container);

        $container->instance('translator', $translator);
        $container->instance('validator', $factory);
        $container->instance('app', $container);

        // Save and replace the facade application so downstream tests in the
        // same process are not affected by our minimal container.
        $this->priorFacadeApp = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->priorFacadeApp);
        $this->priorFacadeApp = null;

        parent::tearDown();
    }

    public function testPreparedRetryNormalizationRequiresTheExplicitVersionTwoEntry(): void
    {
        $descriptor = [
            'type' => 'record_local_activity',
            'activity_type' => 'local-worker-alias',
            'arguments' => Serializer::serializeWithCodec('avro', []),
            'payload_codec' => 'avro',
            'retry_policy' => [
                'contract_version' => 2,
                'mode' => 'explicit',
                'max_attempts' => 3,
                'backoff_seconds' => [2, 5],
            ],
            'start_to_close_timeout' => 30,
        ];
        $normalized = PortableLocalActivityPreparation::normalizeDescriptor($descriptor, 2);
        $this->assertSame(2, $normalized['local_activity_contract_version']);
        $this->assertSame(2, $normalized['retry_policy']['snapshot_version']);
        $this->assertSame([2, 5], $normalized['retry_policy']['backoff_seconds']);
        $this->assertSame(5, $normalized['retry_policy']['maximum_interval_seconds']);
        $this->assertSame(30, $normalized['retry_policy']['start_to_close_timeout']);
        try {
            PortableLocalActivityPreparation::normalizeDescriptor($descriptor);
            $this->fail('The v1 entry silently accepted a v2 policy.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $legacy = $descriptor;
        $legacy['retry_policy'] = [
            'max_attempts' => 3,
            'backoff_seconds' => [2, 5],
        ];
        $this->assertArrayNotHasKey(
            'local_activity_contract_version',
            PortableLocalActivityPreparation::normalizeDescriptor($legacy)
        );
        $this->expectException(\InvalidArgumentException::class);
        PortableLocalActivityPreparation::normalizeDescriptor($legacy, 2);
    }
}
