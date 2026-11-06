<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** @internal Original execution deadline and the backend timestamp domain. */
final readonly class PreparedLocalRetryWindow
{
    public ?DateTimeImmutable $deadline;

    public DateTimeImmutable $latest;

    public ?DateTimeImmutable $earliest;

    public function __construct(
        ?DateTimeInterface $deadline,
        DateTimeInterface $latest,
        ?DateTimeInterface $earliest = null,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->deadline = $deadline === null ? null : DateTimeImmutable::createFromInterface($deadline)->setTimezone(
            $utc
        );
        $this->latest = DateTimeImmutable::createFromInterface($latest)->setTimezone($utc);
        $this->earliest = $earliest === null ? null : DateTimeImmutable::createFromInterface($earliest)->setTimezone(
            $utc
        );
    }
}
