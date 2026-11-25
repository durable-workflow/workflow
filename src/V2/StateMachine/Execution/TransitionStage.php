<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

/**
 * The protocol gate that produced a transition result.
 *
 * Several statuses can come from different gates: Blocked from the shared invariant, the execution
 * guard, live admission or a forward budget; Pending from a target with no declared work or a
 * still-unsatisfied target after work. The stage tells them apart without parsing reasons.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
enum TransitionStage: string
{
    /** No unique event/source edge: Unavailable or Ambiguous. */
    case Selection = 'selection';

    /** The recorded snapshot's state or revision differs from the expected source: Conflict. */
    case Source = 'source';

    /** The pinned shared machine invariant was unsatisfied or unknown. */
    case Invariant = 'invariant';

    /** The target condition was unknown, or remained unsatisfied without further work. */
    case Target = 'target';

    /** The pure execution guard refused or could not decide. */
    case Permission = 'permission';

    /** Recorded live admission refused or could not decide. */
    case Admission = 'admission';

    /** A configured forward execution budget refused the next command. */
    case Budget = 'budget';

    /** The protected business commit returned its acknowledgement. */
    case Commit = 'commit';
}
