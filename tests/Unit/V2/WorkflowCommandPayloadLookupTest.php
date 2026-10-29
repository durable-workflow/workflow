<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\RecordingExternalPayloadStorage;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;

final class WorkflowCommandPayloadLookupTest extends TestCase
{
    public static function inlineReads(): iterable
    {
        yield 'target' => ['targetName', 'approve'];
        yield 'arguments' => ['payloadArguments', [7, 'value']];
        yield 'validation errors' => [
            'validationErrors', [
                'name' => ['Required.'],
            ]];
    }

    #[DataProvider('inlineReads')]
    public function testInlinePayloadReadsDoNotQueryTheRun(string $method, mixed $expected): void
    {
        $command = $this->command(Serializer::serializeWithCodec('avro', [
            'name' => 'approve',
            'arguments' => [7, 'value'],
            'validation_errors' => [
                'name' => ['Required.'],
            ],
        ]));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame($expected, $command->{$method}());
        $this->assertSame([], DB::getQueryLog(), 'Inline payloads do not depend on a run namespace.');
    }

    public function testExternalPayloadReadsUseTheRunNamespace(): void
    {
        $storage = new RecordingExternalPayloadStorage();
        $driver = $storage->driverFor(null);
        $policy = new class($driver) implements ExternalPayloadStoragePolicy {
            public array $namespaces = [];

            public function __construct(
                private readonly ExternalPayloadStorageDriver $driver
            ) {
            }

            public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
            {
                $this->namespaces[] = $namespace;

                return $this->driver;
            }

            public function thresholdBytesFor(?string $namespace): ?int
            {
                return 1;
            }
        };
        $this->app->instance(ExternalPayloadStoragePolicy::class, $policy);
        $stored = ExternalPayloads::externalize(Serializer::serializeWithCodec('avro', [
            'arguments' => [7, 'value'],
            'validation_errors' => [
                'name' => ['Required.'],
            ],
        ]), 'avro', $driver, 1);
        $command = $this->command($stored);
        ExternalPayloadStorage::flushVerifiedCache();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([7, 'value'], $command->payloadArguments());
        $this->assertSame([
            'name' => ['Required.'],
        ], $command->validationErrors());
        $this->assertSame(['payload-team', 'payload-team'], $policy->namespaces);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testAbsentPayloadDoesNotQueryTheRun(): void
    {
        $command = $this->command(null);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertNull($command->targetName());
        $this->assertSame([], $command->payloadArguments());
        $this->assertSame([], $command->validationErrors());
        $this->assertSame([], DB::getQueryLog());
    }

    public function testConfiguredModelsAndPayloadCastsRetainTheirLookupPath(): void
    {
        $attributes = [
            'workflow_run_id' => 'payload-run',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'arguments' => [7, 'value'],
            ]),
        ];
        $configured = new class($attributes) extends WorkflowCommand {
        };
        $casted = (new WorkflowCommand($attributes))->mergeCasts([
            'payload' => 'string',
        ]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([7, 'value'], $configured->payloadArguments());
        $this->assertSame([7, 'value'], $casted->payloadArguments());
        $this->assertCount(2, DB::getQueryLog());
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('workflow_runs', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
            $table->string('namespace');
        });
        DB::table('workflow_runs')->insert([
            'id' => 'payload-run',
            'namespace' => 'payload-team',
        ]);
    }

    private function command(?string $payload): WorkflowCommand
    {
        return new WorkflowCommand([
            'workflow_run_id' => 'payload-run',
            'payload_codec' => 'avro',
            'payload' => $payload,
        ]);
    }
}
