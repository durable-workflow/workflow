<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use ReflectionProperty;
use Tests\Fixtures\V2\TestConfiguredGreetingActivity;
use Tests\Fixtures\V2\TestConfiguredGreetingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Support\TypeRegistry;

final class TypeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any cached type resolutions between tests.
        $cache = new ReflectionProperty(TypeRegistry::class, 'cache');
        $cache->setValue(null, []);
    }

    // ---------------------------------------------------------------
    //  TypeRegistry::for() — class to type key resolution
    // ---------------------------------------------------------------

    public function testForReturnsAttributeTypeKey(): void
    {
        // TestGreetingWorkflow has #[Type('test-greeting-workflow')]
        $this->assertSame('test-greeting-workflow', TypeRegistry::for(TestGreetingWorkflow::class));
    }

    public function testForReturnsFqcnWhenNoAttributeAndNoConfig(): void
    {
        config()->set('workflows.v2.types.workflows', []);

        $this->assertSame(
            TestConfiguredGreetingWorkflow::class,
            TypeRegistry::for(TestConfiguredGreetingWorkflow::class),
        );
    }

    public function testForReturnsConfiguredKeyOverAttribute(): void
    {
        // Config registration takes precedence when present.
        config()
            ->set('workflows.v2.types.workflows', [
                'configured-greeting' => TestGreetingWorkflow::class,
            ]);

        $this->assertSame('configured-greeting', TypeRegistry::for(TestGreetingWorkflow::class));
    }

    public function testForReturnsConfiguredKeyForClassWithoutAttribute(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'my-greeting' => TestConfiguredGreetingWorkflow::class,
        ]);

        $this->assertSame('my-greeting', TypeRegistry::for(TestConfiguredGreetingWorkflow::class));
    }

    // ---------------------------------------------------------------
    //  TypeRegistry::resolveWorkflowClass()
    // ---------------------------------------------------------------

    public function testResolveWorkflowClassFromLoadableStoredClass(): void
    {
        $this->assertSame(
            TestGreetingWorkflow::class,
            TypeRegistry::resolveWorkflowClass(TestGreetingWorkflow::class, null),
        );
    }

    public function testResolveWorkflowClassFromConfiguredTypeKey(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'billing.invoice-sync' => TestConfiguredGreetingWorkflow::class,
        ]);

        $this->assertSame(
            TestConfiguredGreetingWorkflow::class,
            TypeRegistry::resolveWorkflowClass('billing.invoice-sync', 'billing.invoice-sync'),
        );
    }

    public function testResolveWorkflowClassFromDottedTypeKey(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.greeting-workflow' => TestConfiguredGreetingWorkflow::class,
        ]);

        $this->assertSame(
            TestConfiguredGreetingWorkflow::class,
            TypeRegistry::resolveWorkflowClass('tests.greeting-workflow', 'tests.greeting-workflow'),
        );
    }

    public function testResolveWorkflowClassThrowsWhenUnresolvable(): void
    {
        config()->set('workflows.v2.types.workflows', []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve workflow class');

        TypeRegistry::resolveWorkflowClass('App\\Missing\\Workflow', 'missing-workflow');
    }

    // ---------------------------------------------------------------
    //  TypeRegistry::resolveActivityClass()
    // ---------------------------------------------------------------

    public function testResolveActivityClassFromConfiguredTypeKey(): void
    {
        config()->set('workflows.v2.types.activities', [
            'payments.capture' => TestConfiguredGreetingActivity::class,
        ]);

        $this->assertSame(
            TestConfiguredGreetingActivity::class,
            TypeRegistry::resolveActivityClass('payments.capture', 'payments.capture'),
        );
    }

    public function testResolveActivityClassThrowsWhenUnresolvable(): void
    {
        config()->set('workflows.v2.types.activities', []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to resolve activity class');

        TypeRegistry::resolveActivityClass('App\\Missing\\Activity', 'missing-activity');
    }

    // ---------------------------------------------------------------
    //  TypeRegistry::validateTypeMap() — boot-time validation
    // ---------------------------------------------------------------

    public function testValidateTypeMapPassesWithEmptyConfig(): void
    {
        config()->set('workflows.v2.types.workflows', []);
        config()
            ->set('workflows.v2.types.activities', []);

        TypeRegistry::validateTypeMap();

        $this->assertTrue(true); // No exception thrown.
    }

    public function testValidateTypeMapPassesWithValidConfig(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
            'configured-greeting' => TestConfiguredGreetingWorkflow::class,
        ]);
        config()
            ->set('workflows.v2.types.activities', [
                'test-activity' => TestConfiguredGreetingActivity::class,
            ]);

        TypeRegistry::validateTypeMap();

        $this->assertTrue(true);
    }

    public function testValidateTypeMapRejectsDuplicateWorkflowClassMapping(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'key-a' => TestConfiguredGreetingWorkflow::class,
            'key-b' => TestConfiguredGreetingWorkflow::class,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registered under multiple type keys');
        $this->expectExceptionMessage(TestConfiguredGreetingWorkflow::class);

        TypeRegistry::validateTypeMap();
    }

    public function testValidateTypeMapRejectsDuplicateActivityClassMapping(): void
    {
        config()->set('workflows.v2.types.activities', [
            'activity-a' => TestConfiguredGreetingActivity::class,
            'activity-b' => TestConfiguredGreetingActivity::class,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registered under multiple type keys');
        $this->expectExceptionMessage(TestConfiguredGreetingActivity::class);

        TypeRegistry::validateTypeMap();
    }

    public function testValidateTypeMapRejectsConfigKeyThatDisagreesWithTypeAttribute(): void
    {
        // TestGreetingWorkflow has #[Type('test-greeting-workflow')] but config maps it to a different key.
        config()
            ->set('workflows.v2.types.workflows', [
                'wrong-key' => TestGreetingWorkflow::class,
            ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('type key conflict');
        $this->expectExceptionMessage('#[Type(\'test-greeting-workflow\')]');
        $this->expectExceptionMessage('wrong-key');

        TypeRegistry::validateTypeMap();
    }

    public function testValidateTypeMapAcceptsConfigKeyThatMatchesTypeAttribute(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'test-greeting-workflow' => TestGreetingWorkflow::class,
        ]);

        TypeRegistry::validateTypeMap();

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    //  TypeRegistry::resolveThrowableClass()
    // ---------------------------------------------------------------

    public function testResolveThrowableClassFromConfiguredType(): void
    {
        config()->set('workflows.v2.types.exceptions', [
            'test.runtime-error' => \RuntimeException::class,
        ]);

        $this->assertSame(
            \RuntimeException::class,
            TypeRegistry::resolveThrowableClass(\RuntimeException::class, 'test.runtime-error'),
        );
    }

    public function testResolveThrowableClassFromClassAlias(): void
    {
        config()->set('workflows.v2.types.exception_class_aliases', [
            'App\\Legacy\\OldException' => \RuntimeException::class,
        ]);

        $this->assertSame(
            \RuntimeException::class,
            TypeRegistry::resolveThrowableClass('App\\Legacy\\OldException', null),
        );
    }

    public function testResolveThrowableClassReturnsNullWhenUnresolvable(): void
    {
        config()->set('workflows.v2.types.exceptions', []);
        config()
            ->set('workflows.v2.types.exception_class_aliases', []);

        $this->assertNull(TypeRegistry::resolveThrowableClass('App\\Missing\\Exception', null));
    }
}
