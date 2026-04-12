<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowInstanceId;

final class WorkflowInstanceIdTest extends TestCase
{
    // ---------------------------------------------------------------
    //  Frozen contract constants
    // ---------------------------------------------------------------

    #[Test]
    public function max_length_is_191(): void
    {
        $this->assertSame(191, WorkflowInstanceId::MAX_LENGTH);
    }

    // ---------------------------------------------------------------
    //  isValid — accepted patterns
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('validInstanceIds')]
    public function accepts_valid_instance_ids(string $id): void
    {
        $this->assertTrue(WorkflowInstanceId::isValid($id));
    }

    public static function validInstanceIds(): iterable
    {
        yield 'simple alpha' => ['order-123'];
        yield 'dots and colons' => ['tenant.alpha:order-456'];
        yield 'underscores' => ['my_workflow_1'];
        yield 'single character' => ['a'];
        yield 'max length' => [str_repeat('a', WorkflowInstanceId::MAX_LENGTH)];
        yield 'mixed safe chars' => ['Org.unit:sub-group_item.123'];
    }

    // ---------------------------------------------------------------
    //  isValid — rejected patterns
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('invalidInstanceIds')]
    public function rejects_invalid_instance_ids(string $id): void
    {
        $this->assertFalse(WorkflowInstanceId::isValid($id));
    }

    public static function invalidInstanceIds(): iterable
    {
        yield 'empty string' => [''];
        yield 'over max length' => [str_repeat('a', WorkflowInstanceId::MAX_LENGTH + 1)];
        yield 'contains space' => ['order 123'];
        yield 'contains slash' => ['order/123'];
        yield 'contains hash' => ['order#123'];
        yield 'contains at sign' => ['order@123'];
        yield 'contains percent' => ['order%20'];
        yield 'contains question mark' => ['order?id=1'];
    }

    // ---------------------------------------------------------------
    //  assertValid — throws on invalid
    // ---------------------------------------------------------------

    #[Test]
    public function assert_valid_throws_for_empty(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage((string) WorkflowInstanceId::MAX_LENGTH);

        WorkflowInstanceId::assertValid('');
    }

    #[Test]
    public function assert_valid_throws_for_overlong(): void
    {
        $this->expectException(LogicException::class);

        WorkflowInstanceId::assertValid(str_repeat('x', WorkflowInstanceId::MAX_LENGTH + 1));
    }

    #[Test]
    public function assert_valid_passes_for_valid(): void
    {
        WorkflowInstanceId::assertValid('tenant.alpha:order-789');

        $this->assertTrue(true); // No exception thrown.
    }

    // ---------------------------------------------------------------
    //  Message helpers
    // ---------------------------------------------------------------

    #[Test]
    public function requirement_message_includes_max_length(): void
    {
        $this->assertStringContainsString(
            (string) WorkflowInstanceId::MAX_LENGTH,
            WorkflowInstanceId::requirementMessage(),
        );
    }

    #[Test]
    public function validation_message_includes_field_name(): void
    {
        $message = WorkflowInstanceId::validationMessage('workflow_id');

        $this->assertStringContainsString('workflow_id', $message);
        $this->assertStringContainsString((string) WorkflowInstanceId::MAX_LENGTH, $message);
    }
}
