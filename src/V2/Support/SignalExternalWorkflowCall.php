<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\Exceptions\InvalidExternalSignalException;

/**
 * Durable workflow-to-workflow signal yielded by workflow code.
 *
 * The field rules are the Server's `signal_external_workflow` command rules
 * (server docs/contracts/signal-external-workflow.md), checked in workflow
 * code before anything is recorded or sent.
 *
 * @experimental Durable signal-external may change in a minor release.
 * See docs/api-stability.md#durable-signal-external.
 */
final class SignalExternalWorkflowCall implements YieldedCommand
{
    public const MAX_ID_CHARACTERS = 191;

    public const MAX_SIGNAL_NAME_CHARACTERS = 191;

    public const MAX_SIGNAL_NAME_BYTES = 255;

    /**
     * Unicode White_Space other than the ASCII controls the name rules already
     * refuse, so "blank" means what the Server's `str::trim` means.
     */
    private const BLANK_PATTERN = '/\A[\x{09}-\x{0D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]*\z/u';

    /**
     * @var array<int|string, mixed>
     */
    public readonly array $arguments;

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function __construct(
        public readonly string $workflowId,
        public readonly string $signalName,
        array $arguments = [],
        public readonly ?string $runId = null,
    ) {
        self::assertIdentifier($workflowId, 'workflow_id');

        if ($runId !== null) {
            self::assertIdentifier($runId, 'run_id');
        }

        $problem = self::signalNameProblem($signalName);
        if ($problem !== null) {
            throw new InvalidExternalSignalException('invalid_signal_name', 'signal_name', $problem);
        }

        $this->arguments = array_is_list($arguments) ? array_values($arguments) : $arguments;
    }

    /**
     * The Server's signal-name rule for this command, or null when the name is
     * accepted: valid UTF-8, not empty, at most 255 bytes, no control
     * character (a byte below 0x20, or 0x7f), not blank, and at most 191
     * characters.
     */
    public static function signalNameProblem(string $name): ?string
    {
        if ($name === '') {
            return 'the signal name must not be empty';
        }

        if (! mb_check_encoding($name, 'UTF-8')) {
            return 'the signal name must be valid UTF-8';
        }

        if (strlen($name) > self::MAX_SIGNAL_NAME_BYTES) {
            return sprintf('the signal name exceeds %d bytes', self::MAX_SIGNAL_NAME_BYTES);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return 'the signal name must not contain control characters';
        }

        if (preg_match(self::BLANK_PATTERN, $name) === 1) {
            return 'the signal name must not be blank';
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_SIGNAL_NAME_CHARACTERS) {
            return sprintf('the signal name exceeds %d characters', self::MAX_SIGNAL_NAME_CHARACTERS);
        }

        return null;
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        $problem = match (true) {
            ! mb_check_encoding($value, 'UTF-8') => 'must be valid UTF-8',
            preg_match(self::BLANK_PATTERN, $value) === 1 => 'must not be blank',
            mb_strlen($value, 'UTF-8') > self::MAX_ID_CHARACTERS => sprintf(
                'exceeds %d characters',
                self::MAX_ID_CHARACTERS,
            ),
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidExternalSignalException('invalid_signal_external_target', $field, sprintf(
                'the %s %s',
                $field === 'run_id' ? 'run id' : 'workflow id',
                $problem,
            ));
        }
    }
}
