<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ScheduleVisibilityQuery;
use Workflow\V2\Support\ScheduleVisibilityQueryException;

final class ScheduleVisibilityQueryTest extends TestCase
{
    public function testPortableSystemFieldVectors(): void
    {
        $vectors = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/ScheduleVisibilityQuery/system-fields.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $query = new ScheduleVisibilityQuery();
        foreach ($vectors['cases'] as $case) {
            try {
                $actual = $query->parse($case['query']);
                $this->assertArrayHasKey('predicates', $case, $case['name']);
                $this->assertSame($case['predicates'], $actual, $case['name']);
            } catch (ScheduleVisibilityQueryException $error) {
                $this->assertArrayHasKey('reason', $case, $case['name']);
                $this->assertSame($case['reason'], $error->reason, $case['name']);
            }
        }
    }

    public function testQueryLengthCountsUnicodeCharactersBeforeTrimming(): void
    {
        $query = new ScheduleVisibilityQuery();
        $this->assertSame(
            str_repeat('🙂', 2039),
            $query->parse('Note = "' . str_repeat('🙂', 2039) . '"')[0]['literal']
        );
        $this->expectException(ScheduleVisibilityQueryException::class);
        $query->parse(' ' . 'Note = "' . str_repeat('🙂', 2039) . '"');
    }

    public function testMalformedUtf8IsRefused(): void
    {
        $this->expectException(ScheduleVisibilityQueryException::class);
        (new ScheduleVisibilityQuery())->parse("Note = \"\xff\"");
    }
}
