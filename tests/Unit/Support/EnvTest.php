<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Tests\TestCase;
use Workflow\Support\Env;

final class EnvTest extends TestCase
{
    private const PRIMARY = 'DW_TEST_V2_EXAMPLE';

    private const LEGACY = 'WORKFLOW_TEST_V2_EXAMPLE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        parent::tearDown();
    }

    public function testReturnsDefaultWhenNeitherNameIsSet(): void
    {
        $this->assertSame('fallback', Env::dw(self::PRIMARY, self::LEGACY, 'fallback'));
        $this->assertNull(Env::dw(self::PRIMARY, self::LEGACY));
    }

    public function testReadsLegacyNameWhenPrimaryIsUnset(): void
    {
        $this->set(self::LEGACY, 'legacy-value');

        $this->assertSame('legacy-value', Env::dw(self::PRIMARY, self::LEGACY, 'fallback'));
    }

    public function testPrefersPrimaryOverLegacy(): void
    {
        $this->set(self::PRIMARY, 'primary-value');
        $this->set(self::LEGACY, 'legacy-value');

        $this->assertSame('primary-value', Env::dw(self::PRIMARY, self::LEGACY, 'fallback'));
    }

    public function testPrimaryOverridesLegacyEvenWhenEmpty(): void
    {
        // Laravel's env() maps the literal "(empty)" to the empty string,
        // which counts as "set" — so the primary wins and the legacy is
        // ignored even though the operator might expect the legacy value
        // to leak through. Pinning the semantics keeps behavior aligned
        // with App\Support\EnvAuditor::env in the server repo.
        $this->set(self::PRIMARY, '(empty)');
        $this->set(self::LEGACY, 'legacy-value');

        $this->assertSame('', Env::dw(self::PRIMARY, self::LEGACY, 'fallback'));
    }

    private function set(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clear(): void
    {
        foreach ([self::PRIMARY, self::LEGACY] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }
}
