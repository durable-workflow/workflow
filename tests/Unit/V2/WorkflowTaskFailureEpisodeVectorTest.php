<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Support\WorkflowTaskFailureEpisodeVectors;

final class WorkflowTaskFailureEpisodeVectorTest extends TestCase
{
    public function testTheCommittedVectorsConformToTheClosedSourceContract(): void
    {
        $this->assertSame(34, WorkflowTaskFailureEpisodeVectors::validate($this->source()));
        $document = json_decode($this->source(), false, 64, JSON_THROW_ON_ERROR);
        $vectors = array_column($document->vectors, null, 'id');
        foreach ([
            'active_first_failure_at_sample' => 0,
            'active_first_failure_plus_999us' => 0,
            'active_first_failure_plus_1000us' => 1,
        ] as $id => $age) {
            $metric = $vectors[$id]->expected_metric;
            $this->assertSame('complete', $metric->evaluation);
            $this->assertSame(1, $metric->count);
            $this->assertSame('2026-11-05T12:00:01.000000Z', $metric->oldest_started_at);
            $this->assertSame($age, $metric->max_age_ms);
            $this->assertSame('warning', $vectors[$id]->expected_health);
        }
    }

    public function testTheValidatorRefusesSchemaAndSemanticMutations(): void
    {
        $mutations = [
            'unknown root member' => static function (stdClass $document): void {
                $document->enabled = true;
            },
            'enabled source' => static function (stdClass $document): void {
                $document->status = 'enabled';
            },
            'empty vector list' => static function (stdClass $document): void {
                $document->vectors = [];
            },
            'duplicate ID' => static function (stdClass $document): void {
                $document->vectors[] = $document->vectors[0];
            },
            'unknown vector member' => static function (stdClass $document): void {
                $document->vectors[0]->extra = 1;
            },
            'missing metric field' => static function (stdClass $document): void {
                unset($document->vectors[0]->expected_metric->count);
            },
            'Boolean metric count' => static function (stdClass $document): void {
                $document->vectors[0]->expected_metric->count = false;
            },
            'invalid timestamp' => static function (stdClass $document): void {
                $document->vectors[0]->sample_at = '2026-13-05T12:00:10.000000Z';
            },
            'unknown state member' => static function (stdClass $document): void {
                $document->vectors[0]->after[0]->new_clock = 0;
            },
            'duplicate run membership' => static function (stdClass $document): void {
                $document->vectors[0]->after[] = $document->vectors[0]->after[0];
            },
            'unknown transition member' => static function (stdClass $document): void {
                $document->vectors[1]->transitions[0]->extra = true;
            },
            'unknown transition kind' => static function (stdClass $document): void {
                $document->vectors[1]->transitions[0]->kind = 'guess';
            },
            'refused failure recording' => static function (stdClass $document): void {
                $document->vectors[1]->transitions[0]->authority = 'wrong_owner';
            },
            'wrong failure projection' => static function (stdClass $document): void {
                $document->vectors[1]->after[0]->first_failure_at = '2026-11-05T12:00:02.000000Z';
            },
        ];
        foreach ($mutations as $label => $mutation) {
            $document = json_decode($this->source(), false, 64, JSON_THROW_ON_ERROR);
            $mutation($document);
            $this->assertRefused(json_encode($document, JSON_THROW_ON_ERROR), $label);
        }
        $document = json_decode($this->source(), false, 64, JSON_THROW_ON_ERROR);
        foreach ($document->vectors as $vector) {
            if ($vector->id === 'active_first_failure_plus_999us') {
                $vector->expected_metric->max_age_ms = 1;
            }
        }
        $this->assertRefused(json_encode($document, JSON_THROW_ON_ERROR), 'ceiling instead of flooring');
        foreach (['future_clock_does_not_report_zero_age', 'unknown_membership_is_unavailable',
            'automatic_lease_recovery_keeps_episode', 'waiting_history_completed_ack_clears',
            'duplicate_completion_cannot_clear_later_episode'] as $id) {
            $document = json_decode($this->source(), false, 64, JSON_THROW_ON_ERROR);
            foreach ($document->vectors as $vector) {
                if ($vector->id !== $id) {
                    continue;
                }
                if ($id === 'future_clock_does_not_report_zero_age') {
                    $vector->expected_metric->max_age_ms = 0;
                } elseif ($id === 'unknown_membership_is_unavailable') {
                    $vector->after = [];
                } elseif ($id === 'automatic_lease_recovery_keeps_episode') {
                    $vector->after[0]->episode_active = false;
                    $vector->after[0]->first_failure_at = null;
                } elseif ($id === 'waiting_history_completed_ack_clears') {
                    $vector->transitions[0]->committed = false;
                } else {
                    $vector->transitions[0]->committed = true;
                }
            }
            $this->assertRefused(json_encode($document, JSON_THROW_ON_ERROR), $id);
        }
        $this->assertRefused(
            str_replace('"format_version": 1,', '"format_version": 9, "format_version": 1,', $this->source()),
            'duplicate JSON key cannot disappear during decode'
        );
        $this->assertRefused(
            str_replace('"format_version": 1,', '"format_versi\\u006fn": 9, "format_version": 1,', $this->source()),
            'escaped duplicate JSON key'
        );
    }

    private function assertRefused(string $json, string $label): void
    {
        try {
            WorkflowTaskFailureEpisodeVectors::validate($json);
            $this->fail($label . ' was accepted');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage(), $label);
        }
    }

    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/protocol/workflow-task-failure-episodes-v1-vectors.json'
        );
    }
}
