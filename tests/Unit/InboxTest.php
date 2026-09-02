<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Workflow\Inbox;

final class InboxTest extends TestCase
{
    public function testNewInboxIsEmpty(): void
    {
        $inbox = new Inbox();

        $this->assertFalse($inbox->hasUnread());
        $this->assertSame(0, $inbox->received);
        $this->assertSame(0, $inbox->read);
        $this->assertSame([], $inbox->values);
    }

    public function testReceiveAddsValueAndIncrementsReceived(): void
    {
        $inbox = new Inbox();

        $inbox->receive('message1');

        $this->assertSame(['message1'], $inbox->values);
        $this->assertSame(1, $inbox->received);
        $this->assertSame(0, $inbox->read);
        $this->assertTrue($inbox->hasUnread());
    }

    public function testReceiveMultipleValues(): void
    {
        $inbox = new Inbox();

        $inbox->receive('message1');
        $inbox->receive('message2');
        $inbox->receive('message3');

        $this->assertSame(['message1', 'message2', 'message3'], $inbox->values);
        $this->assertSame(3, $inbox->received);
        $this->assertSame(0, $inbox->read);
        $this->assertTrue($inbox->hasUnread());
    }

    public function testNextUnreadReturnsNullWhenEmpty(): void
    {
        $inbox = new Inbox();

        $this->assertNull($inbox->nextUnread());
        $this->assertSame(0, $inbox->read);
    }

    public function testNextUnreadReturnsValueAndIncrementsRead(): void
    {
        $inbox = new Inbox();
        $inbox->receive('message1');

        $value = $inbox->nextUnread();

        $this->assertSame('message1', $value);
        $this->assertSame(1, $inbox->read);
        $this->assertFalse($inbox->hasUnread());
    }

    public function testNextUnreadReturnsValuesInOrder(): void
    {
        $inbox = new Inbox();
        $inbox->receive('first');
        $inbox->receive('second');
        $inbox->receive('third');

        $this->assertSame('first', $inbox->nextUnread());
        $this->assertSame('second', $inbox->nextUnread());
        $this->assertSame('third', $inbox->nextUnread());
        $this->assertNull($inbox->nextUnread());
        $this->assertFalse($inbox->hasUnread());
    }

    public function testHasUnreadReturnsTrueWhenUnreadMessagesExist(): void
    {
        $inbox = new Inbox();
        $inbox->receive('message1');
        $inbox->receive('message2');

        $this->assertTrue($inbox->hasUnread());

        $inbox->nextUnread();
        $this->assertTrue($inbox->hasUnread());

        $inbox->nextUnread();
        $this->assertFalse($inbox->hasUnread());
    }

    public function testReceiveAfterReadingMaintainsCorrectState(): void
    {
        $inbox = new Inbox();

        $inbox->receive('message1');
        $this->assertSame('message1', $inbox->nextUnread());
        $this->assertFalse($inbox->hasUnread());

        $inbox->receive('message2');
        $this->assertTrue($inbox->hasUnread());
        $this->assertSame('message2', $inbox->nextUnread());
        $this->assertFalse($inbox->hasUnread());

        $this->assertSame(2, $inbox->received);
        $this->assertSame(2, $inbox->read);
    }

    public function testReceiveAcceptsMixedTypes(): void
    {
        $inbox = new Inbox();

        $inbox->receive('string');
        $inbox->receive(123);
        $inbox->receive([
            'array' => 'value',
        ]);
        $inbox->receive(null);

        $this->assertSame('string', $inbox->nextUnread());
        $this->assertSame(123, $inbox->nextUnread());
        $this->assertSame([
            'array' => 'value',
        ], $inbox->nextUnread());
        $this->assertNull($inbox->nextUnread());
        $this->assertFalse($inbox->hasUnread());
    }
}
