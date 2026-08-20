<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Foundation\Application;

final class TestInjectedDependency
{
    public function __construct(
        private Application $application
    ) {
    }

    public function marker(): string
    {
        return $this->application->runningInConsole() ? 'injected' : 'unavailable';
    }
}
