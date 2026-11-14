<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;

/** Trusted application configuration. Recorded input selects a name/version, never executable code. */
final class ProfileCatalogue
{
    public const STATES = ['received', 'working', 'completed', 'cancelled'];

    public static function select(string $integration, string $version): PolicyProfile
    {
        if ($version !== '1' || ! in_array($integration, ['prepare', 'imported'], true)) {
            throw new LogicException('The original integration profile is unavailable.');
        }
        $transitions = [
            new TransitionDefinition('begin', 'begin', ['received'], 'working', $integration, '1'),
            new TransitionDefinition('finish', 'approve', ['working'], 'completed', 'finish', '1', false),
        ];
        if ($integration === 'imported') {
            $transitions[] = new TransitionDefinition(
                'accept-existing',
                'already-completed',
                ['received'],
                'completed',
                'finish',
                '1',
                false,
            );
        }
        return new PolicyProfile('fulfilment', '1', $integration, $version, self::STATES, $transitions);
    }

    /**
     * @return list<string>
     */
    public static function actions(string $integration, string $version): array
    {
        self::select($integration, $version);
        return $integration === 'prepare' ? ['reserve', 'prepare'] : ['import'];
    }
}
