<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Workflow\Outbox;

final class OutboxTest extends TestCase
{
    public function testNewOutboxIsEmpty(): void
    {
        $outbox = new Outbox();

        $this->assertSame(0, $outbox->transmitted);
        $this->assertSame(0, $outbox->sent);
        $this->assertSame([], $outbox->values);
    }

    public function testSendAddsValueAndIncrementsTransmitted(): void
    {
        $outbox = new Outbox();

        $outbox->send('message1');

        $this->assertSame(['message1'], $outbox->values);
        $this->assertSame(1, $outbox->transmitted);
        $this->assertSame(0, $outbox->sent);
    }

    public function testSendMultipleValues(): void
    {
        $outbox = new Outbox();

        $outbox->send('message1');
        $outbox->send('message2');
        $outbox->send('message3');

        $this->assertSame(['message1', 'message2', 'message3'], $outbox->values);
        $this->assertSame(3, $outbox->transmitted);
        $this->assertSame(0, $outbox->sent);
    }

    public function testNextUnsentReturnsNullWhenEmpty(): void
    {
        $outbox = new Outbox();

        $this->assertNull($outbox->nextUnsent());
        $this->assertSame(0, $outbox->sent);
    }

    public function testNextUnsentReturnsValueAndIncrementsSent(): void
    {
        $outbox = new Outbox();
        $outbox->send('message1');

        $value = $outbox->nextUnsent();

        $this->assertSame('message1', $value);
        $this->assertSame(1, $outbox->sent);
    }

    public function testNextUnsentReturnsValuesInOrder(): void
    {
        $outbox = new Outbox();
        $outbox->send('first');
        $outbox->send('second');
        $outbox->send('third');

        $this->assertSame('first', $outbox->nextUnsent());
        $this->assertSame('second', $outbox->nextUnsent());
        $this->assertSame('third', $outbox->nextUnsent());
        $this->assertNull($outbox->nextUnsent());
    }

    public function testSendAfterReadingMaintainsCorrectState(): void
    {
        $outbox = new Outbox();

        $outbox->send('message1');
        $this->assertSame('message1', $outbox->nextUnsent());

        $outbox->send('message2');
        $this->assertSame(2, $outbox->transmitted);
        $this->assertSame(1, $outbox->sent);
        $this->assertSame('message2', $outbox->nextUnsent());
    }

    public function testSendWithDifferentTypes(): void
    {
        $outbox = new Outbox();

        $outbox->send('string');
        $outbox->send(123);
        $outbox->send(['array', 'value']);
        $outbox->send(null);
        $object = new \stdClass();
        $object->foo = 'bar';
        $outbox->send($object);

        $this->assertSame('string', $outbox->nextUnsent());
        $this->assertSame(123, $outbox->nextUnsent());
        $this->assertSame(['array', 'value'], $outbox->nextUnsent());
        $this->assertNull($outbox->nextUnsent());
        $this->assertEquals($object, $outbox->nextUnsent());
    }
}
