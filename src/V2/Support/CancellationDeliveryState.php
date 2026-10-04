<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/** Internal result, including a durable wait before authored cleanup begins. */
enum CancellationDeliveryState
{
    case None;
    case Waiting;
    case Delivered;
}
