<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowFailure;

final class WorkflowFailureMessageStorageTest extends TestCase
{
    #[DataProvider('messages')]
    public function testOversizedMessagesKeepExactCanonicalBytesAndBoundLegacyStorage(string $message): void
    {
        $failure = new WorkflowFailure([
            'message' => $message,
            'source_id' => 'original-attempt',
            'non_retryable' => true,
        ]);

        $this->assertSame($message, $failure->message);
        $this->assertSame($message, $failure->toArray()['message']);
        $this->assertSame($message, $failure->getAttributes()['message_full']);
        $this->assertLessThanOrEqual(65_535, strlen($failure->getAttributes()['message']));
        $this->assertTrue(mb_check_encoding($failure->getAttributes()['message'], 'UTF-8'));
        $this->assertStringContainsString('[full message stored separately]', $failure->getAttributes()['message']);
        $this->assertArrayNotHasKey('message_full', $failure->toArray());
        $this->assertSame('original-attempt', $failure->source_id);
        $this->assertTrue($failure->non_retryable);
    }

    public static function messages(): iterable
    {
        yield 'ASCII beyond TEXT' => [str_repeat('a', 70_000)];
        yield 'Unicode and NUL beyond TEXT' => [str_repeat("\0界🦀", 10_000)];
        yield 'large embedded exception' => [str_repeat('x', 3_000_000)];
    }

    public function testColdReadRestoresFullMessageWithoutExposingPhysicalStorageField(): void
    {
        $message = str_repeat('界🦀', 10_000);
        $failure = new WorkflowFailure();
        $failure->setRawAttributes([
            'message' => 'legacy preview [full message stored separately]',
            'message_full' => $message,
        ], true);

        $this->assertSame($message, $failure->message);
        $this->assertSame(['message' => $message], $failure->toArray());
        $this->assertFalse($failure->isDirty());
    }

    public function testPreexistingRowNeedsNoBackfillToReadItsOriginalMessage(): void
    {
        $message = "unchanged\0雪";
        $failure = new WorkflowFailure();
        $failure->setRawAttributes(['message' => $message], true);

        $this->assertSame($message, $failure->message);
        $this->assertSame(['message' => $message], $failure->toArray());
        $this->assertFalse($failure->isDirty());
    }

    public function testReplacingLargeMessageClearsStaleOverflowBytes(): void
    {
        $failure = new WorkflowFailure();
        $failure->setRawAttributes([
            'message' => 'old preview',
            'message_full' => str_repeat('old', 30_000),
        ], true);
        $failure->message = 'replacement';

        $this->assertSame('replacement', $failure->message);
        $this->assertSame('replacement', $failure->getAttributes()['message']);
        $this->assertNull($failure->getAttributes()['message_full']);
        $this->assertSame(['message' => 'replacement'], $failure->toArray());
    }
}
