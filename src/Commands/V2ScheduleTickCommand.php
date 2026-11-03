<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Workflow\V2\Contracts\SchedulerRole;

class V2ScheduleTickCommand extends Command
{
    public const DEFAULT_LIMIT = 100;

    protected $signature = 'workflow:v2:schedule-tick
        {--limit= : Pass size; defaults to workflows.v2.schedules.tick_limit (100)}
        {--json : Output the tick report as JSON}';

    protected $description = 'Evaluate all due workflow v2 schedules and trigger matching workflows';

    public function __construct(
        private readonly SchedulerRole $schedulerRole,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = $this->resolveLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $results = $this->schedulerRole->tick($limit);

        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            if ($results === []) {
                $this->line('No schedules due.');
            } else {
                foreach ($results as $result) {
                    $instanceId = $result['instance_id'] ?? 'skipped';
                    $this->line(sprintf('[%s] → %s', $result['schedule_id'], $instanceId));
                }

                $this->line(sprintf('Processed %d schedule(s).', count($results)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The explicit option wins over config. An invalid value from either
     * source refuses the pass instead of silently using another size.
     */
    private function resolveLimit(): ?int
    {
        $option = $this->option('limit');

        if ($option !== null) {
            $limit = self::positiveInt($option);

            if ($limit === null) {
                $this->error(sprintf(
                    'Invalid --limit value [%s]: expected a positive integer.',
                    self::describe($option),
                ));
            }

            return $limit;
        }

        $configured = config('workflows.v2.schedules.tick_limit');

        if ($configured === null) {
            return self::DEFAULT_LIMIT;
        }

        $limit = self::positiveInt($configured);

        if ($limit === null) {
            $this->error(sprintf(
                'Invalid workflows.v2.schedules.tick_limit value [%s]: expected a positive integer.',
                self::describe($configured),
            ));
        }

        return $limit;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        // \z, not $: $ would also match before a trailing newline.
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        // Digits only, so this fails only when the value overflows an int.
        $limit = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($limit) ? $limit : null;
    }

    /**
     * Show a rejected value on one line, with control characters escaped
     * and no console formatting tags interpreted.
     */
    private static function describe(mixed $value): string
    {
        $shown = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };

        return OutputFormatter::escape(addcslashes($shown, "\0..\37\177"));
    }
}
