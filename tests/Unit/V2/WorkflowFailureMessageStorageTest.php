<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        $this->assertSame('base64:' . base64_encode($message), $failure->getAttributes()['message_full']);
        $this->assertLessThanOrEqual(65_535, strlen($failure->getAttributes()['message']));
        $this->assertTrue(mb_check_encoding($failure->getAttributes()['message'], 'UTF-8'));
        $this->assertStringContainsString('[full message stored separately]', $failure->getAttributes()['message']);
        $this->assertArrayNotHasKey('message_full', $failure->toArray());
        $this->assertSame('original-attempt', $failure->source_id);
        $this->assertTrue($failure->non_retryable);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function messages(): iterable
    {
        yield 'ASCII beyond TEXT' => [str_repeat('a', 70_000)];
        yield 'Unicode and NUL beyond TEXT' => [str_repeat("\0界🦀", 10_000)];
        yield 'large embedded exception' => [str_repeat('x', 3_000_000)];
        yield 'short NUL must not be silently truncated by PostgreSQL' => ["prefix\0suffix"];
    }

    public function testColdReadRestoresFullMessageWithoutExposingPhysicalStorageField(): void
    {
        $message = str_repeat('界🦀', 10_000);
        $failure = new WorkflowFailure();
        $failure->setRawAttributes([
            'message' => 'legacy preview [full message stored separately]',
            'message_full' => 'base64:' . base64_encode($message),
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
            'message_full' => 'base64:' . base64_encode(str_repeat('old', 30_000)),
        ], true);
        $failure->message = 'replacement';

        $this->assertSame('replacement', $failure->message);
        $this->assertSame('replacement', $failure->getAttributes()['message']);
        $this->assertNull($failure->getAttributes()['message_full']);
        $this->assertSame(['message' => 'replacement'], $failure->toArray());
    }

    #[DataProvider('invalidStorage')]
    public function testMalformedOverflowStorageNeverFabricatesCanonicalMessage(mixed $stored): void
    {
        $failure = new WorkflowFailure();
        $failure->setRawAttributes(['message' => 'diagnostic preview', 'message_full' => $stored], true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid stored full failure-message encoding.');
        $failure->message;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStorage(): iterable
    {
        yield 'missing encoding marker' => ['raw text'];
        yield 'empty accidental default' => [''];
        yield 'invalid base64' => ['base64:!'];
        yield 'noncanonical missing padding' => ['base64:Zg'];
        yield 'noncanonical whitespace' => ["base64:Zg==\n"];
        yield 'wrong stored type' => [false];
    }
}
