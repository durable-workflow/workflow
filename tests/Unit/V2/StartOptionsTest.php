<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\WorkflowInstanceId;

final class StartOptionsTest extends TestCase
{
    // ---------------------------------------------------------------
    //  Defaults
    // ---------------------------------------------------------------

    public function testDefaultConstructorSetsRejectDuplicate(): void
    {
        $options = new StartOptions();

        $this->assertSame(DuplicateStartPolicy::RejectDuplicate, $options->duplicateStartPolicy);
        $this->assertNull($options->businessKey);
        $this->assertSame([], $options->labels);
        $this->assertSame([], $options->memo);
        $this->assertSame([], $options->searchAttributes);
        $this->assertNull($options->executionTimeoutSeconds);
        $this->assertNull($options->runTimeoutSeconds);
    }

    // ---------------------------------------------------------------
    //  Factory methods
    // ---------------------------------------------------------------

    public function testRejectDuplicateFactory(): void
    {
        $options = StartOptions::rejectDuplicate();

        $this->assertSame(DuplicateStartPolicy::RejectDuplicate, $options->duplicateStartPolicy);
    }

    public function testReturnExistingActiveFactory(): void
    {
        $options = StartOptions::returnExistingActive();

        $this->assertSame(DuplicateStartPolicy::ReturnExistingActive, $options->duplicateStartPolicy);
    }

    public function testWithVisibilityFactory(): void
    {
        $options = StartOptions::withVisibility(
            businessKey: 'order-123',
            labels: ['tenant' => 'acme'],
            duplicateStartPolicy: DuplicateStartPolicy::ReturnExistingActive,
        );

        $this->assertSame('order-123', $options->businessKey);
        $this->assertSame(['tenant' => 'acme'], $options->labels);
        $this->assertSame(DuplicateStartPolicy::ReturnExistingActive, $options->duplicateStartPolicy);
    }

    // ---------------------------------------------------------------
    //  Business key validation
    // ---------------------------------------------------------------

    public function testValidBusinessKey(): void
    {
        $options = new StartOptions(businessKey: 'order-123');

        $this->assertSame('order-123', $options->businessKey);
    }

    public function testBusinessKeyTrimsWhitespace(): void
    {
        $options = new StartOptions(businessKey: '  order-123  ');

        $this->assertSame('order-123', $options->businessKey);
    }

    public function testNullBusinessKeyIsAllowed(): void
    {
        $options = new StartOptions(businessKey: null);

        $this->assertNull($options->businessKey);
    }

    public function testEmptyBusinessKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-empty');

        new StartOptions(businessKey: '');
    }

    public function testWhitespaceOnlyBusinessKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-empty');

        new StartOptions(businessKey: '   ');
    }

    public function testOverlongBusinessKeyThrows(): void
    {
        $this->expectException(LogicException::class);

        new StartOptions(businessKey: str_repeat('a', WorkflowInstanceId::MAX_LENGTH + 1));
    }

    // ---------------------------------------------------------------
    //  Labels validation
    // ---------------------------------------------------------------

    public function testValidLabels(): void
    {
        $options = new StartOptions(labels: ['tenant' => 'acme', 'env' => 'production']);

        $this->assertSame(['env' => 'production', 'tenant' => 'acme'], $options->labels);
    }

    public function testLabelsSortedByKey(): void
    {
        $options = new StartOptions(labels: ['z-key' => 'last', 'a-key' => 'first']);

        $this->assertSame(['a-key' => 'first', 'z-key' => 'last'], $options->labels);
    }

    public function testNullLabelValueIsSkipped(): void
    {
        $options = new StartOptions(labels: ['present' => 'yes', 'absent' => null]);

        $this->assertSame(['present' => 'yes'], $options->labels);
    }

    public function testNumericLabelValueCastToString(): void
    {
        $options = new StartOptions(labels: ['count' => 42]);

        $this->assertSame(['count' => '42'], $options->labels);
    }

    public function testInvalidLabelKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('URL-safe');

        new StartOptions(labels: ['invalid key with spaces' => 'value']);
    }

    // ---------------------------------------------------------------
    //  Memo validation
    // ---------------------------------------------------------------

    public function testValidMemo(): void
    {
        $options = new StartOptions(memo: ['order_id' => 123, 'customer' => 'Taylor']);

        $this->assertSame(['customer' => 'Taylor', 'order_id' => 123], $options->memo);
    }

    public function testNestedMemo(): void
    {
        $options = new StartOptions(memo: ['details' => ['name' => 'Taylor', 'amount' => 99.50]]);

        $this->assertSame(['details' => ['amount' => 99.50, 'name' => 'Taylor']], $options->memo);
    }

    public function testMemoListArray(): void
    {
        $options = new StartOptions(memo: ['tags' => ['a', 'b', 'c']]);

        $this->assertSame(['tags' => ['a', 'b', 'c']], $options->memo);
    }

    public function testEmptyMemoKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-empty');

        new StartOptions(memo: ['' => 'value']);
    }

    // ---------------------------------------------------------------
    //  Search attributes validation
    // ---------------------------------------------------------------

    public function testValidSearchAttributes(): void
    {
        $options = new StartOptions(searchAttributes: ['status' => 'active', 'priority' => 'high']);

        $this->assertSame(['priority' => 'high', 'status' => 'active'], $options->searchAttributes);
    }

    public function testSearchAttributesSortedByKey(): void
    {
        $options = new StartOptions(searchAttributes: ['z-attr' => 'last', 'a-attr' => 'first']);

        $this->assertSame(['a-attr' => 'first', 'z-attr' => 'last'], $options->searchAttributes);
    }

    public function testNullSearchAttributeIsSkipped(): void
    {
        $options = new StartOptions(searchAttributes: ['present' => 'yes', 'absent' => null]);

        $this->assertSame(['present' => 'yes'], $options->searchAttributes);
    }

    public function testBooleanSearchAttributeCastToString(): void
    {
        $options = new StartOptions(searchAttributes: ['active' => true, 'deleted' => false]);

        $this->assertSame(['active' => '1', 'deleted' => '0'], $options->searchAttributes);
    }

    public function testInvalidSearchAttributeKeyThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('URL-safe');

        new StartOptions(searchAttributes: ['invalid key' => 'value']);
    }

    // ---------------------------------------------------------------
    //  Timeout validation
    // ---------------------------------------------------------------

    public function testValidExecutionTimeout(): void
    {
        $options = new StartOptions(executionTimeoutSeconds: 3600);

        $this->assertSame(3600, $options->executionTimeoutSeconds);
    }

    public function testValidRunTimeout(): void
    {
        $options = new StartOptions(runTimeoutSeconds: 1800);

        $this->assertSame(1800, $options->runTimeoutSeconds);
    }

    public function testNullTimeoutsAreAllowed(): void
    {
        $options = new StartOptions(executionTimeoutSeconds: null, runTimeoutSeconds: null);

        $this->assertNull($options->executionTimeoutSeconds);
        $this->assertNull($options->runTimeoutSeconds);
    }

    public function testZeroExecutionTimeoutThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        new StartOptions(executionTimeoutSeconds: 0);
    }

    public function testNegativeRunTimeoutThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        new StartOptions(runTimeoutSeconds: -5);
    }

    // ---------------------------------------------------------------
    //  Immutable builders
    // ---------------------------------------------------------------

    public function testWithBusinessKeyReturnsNewInstance(): void
    {
        $original = new StartOptions(businessKey: 'first');
        $modified = $original->withBusinessKey('second');

        $this->assertSame('first', $original->businessKey);
        $this->assertSame('second', $modified->businessKey);
        $this->assertNotSame($original, $modified);
    }

    public function testWithLabelsReturnsNewInstance(): void
    {
        $original = new StartOptions(labels: ['a' => '1']);
        $modified = $original->withLabels(['b' => '2']);

        $this->assertSame(['a' => '1'], $original->labels);
        $this->assertSame(['b' => '2'], $modified->labels);
    }

    public function testWithMemoReturnsNewInstance(): void
    {
        $original = new StartOptions(memo: ['key' => 'value']);
        $modified = $original->withMemo(['other' => 'data']);

        $this->assertSame(['key' => 'value'], $original->memo);
        $this->assertSame(['other' => 'data'], $modified->memo);
    }

    public function testWithSearchAttributesReturnsNewInstance(): void
    {
        $original = new StartOptions(searchAttributes: ['status' => 'active']);
        $modified = $original->withSearchAttributes(['priority' => 'high']);

        $this->assertSame(['status' => 'active'], $original->searchAttributes);
        $this->assertSame(['priority' => 'high'], $modified->searchAttributes);
    }

    public function testWithExecutionTimeoutReturnsNewInstance(): void
    {
        $original = new StartOptions(executionTimeoutSeconds: 100);
        $modified = $original->withExecutionTimeout(200);

        $this->assertSame(100, $original->executionTimeoutSeconds);
        $this->assertSame(200, $modified->executionTimeoutSeconds);
    }

    public function testWithRunTimeoutReturnsNewInstance(): void
    {
        $original = new StartOptions(runTimeoutSeconds: 100);
        $modified = $original->withRunTimeout(200);

        $this->assertSame(100, $original->runTimeoutSeconds);
        $this->assertSame(200, $modified->runTimeoutSeconds);
    }

    public function testBuilderChainPreservesAllFields(): void
    {
        $options = StartOptions::returnExistingActive()
            ->withBusinessKey('order-456')
            ->withLabels(['tenant' => 'acme'])
            ->withMemo(['note' => 'test'])
            ->withSearchAttributes(['priority' => 'high'])
            ->withExecutionTimeout(3600)
            ->withRunTimeout(1800);

        $this->assertSame(DuplicateStartPolicy::ReturnExistingActive, $options->duplicateStartPolicy);
        $this->assertSame('order-456', $options->businessKey);
        $this->assertSame(['tenant' => 'acme'], $options->labels);
        $this->assertSame(['note' => 'test'], $options->memo);
        $this->assertSame(['priority' => 'high'], $options->searchAttributes);
        $this->assertSame(3600, $options->executionTimeoutSeconds);
        $this->assertSame(1800, $options->runTimeoutSeconds);
    }
}
