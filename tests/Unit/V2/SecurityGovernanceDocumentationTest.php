<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the security document discoverable and verifies the package-owned
 * dependency and configuration boundaries. The Markdown body is intentionally
 * not parsed so editorial changes do not require synchronized test changes.
 */
final class SecurityGovernanceDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/security-governance.md';

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testPackageKeepsWaterlineAuthConfigurationOutsideWorkflowPackage(): void
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 3) . '/composer.json') ?: '',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($composer['require'] ?? null);
        $this->assertArrayNotHasKey('durable-workflow/waterline', $composer['require']);

        $configuration = file_get_contents(dirname(__DIR__, 3) . '/src/config/workflows.php');
        $this->assertIsString($configuration);
        $this->assertStringNotContainsString('WATERLINE_NAMESPACE', $configuration);
    }
}
