<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/configured-state-machines/providers/bootstrap.php';

final class ProviderEffectTimeCapabilityTest extends TestCase
{
    public function testCapabilityAdmissionDoesNotDispatchOrInventAnEffectTimestamp(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a', captureEffectTimes: true);
        $provider->install();
        $provider->requireEffectTimeSupport();
        foreach (['provider_requests', 'provider_operations', 'provider_effects', 'provider_effect_times'] as $table) {
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        }
        $this->assertFalse($db->inTransaction());
    }

    public function testTheUnselectedCapabilityDoesNotInstallOrChangeTheLegacyFourTables(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        try {
            $provider->requireEffectTimeSupport();
            $this->fail('Unselected timing cannot become an admitted capability.');
        } catch (LogicException $error) {
            $this->assertSame('The provider did not admit original effect timestamps.', $error->getMessage());
        }
        $this->assertSame(
            4,
            (int) $db->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'provider_%'"
            )->fetchColumn()
        );
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
        $this->assertFalse($db->inTransaction());
    }

    public function testMissingSchemaRefusalRollsBackOnlyItsOwnedMetadataTransaction(): void
    {
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a', captureEffectTimes: true);
        $provider->install();
        $db->exec('DROP TABLE provider_effect_times');
        try {
            $provider->requireEffectTimeSupport();
            $this->fail('A declared capability requires its actual schema.');
        } catch (PDOException $error) {
            $this->assertStringContainsString('provider_effect_times', $error->getMessage());
        }
        $this->assertFalse($db->inTransaction());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
    }
}
