<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservation;
use Workflow\V2\Compensation\Admission\RootStartProfile;
use Workflow\V2\Compensation\Definition\ScopeIdentity;

final class RootDescriptorTest extends TestCase
{
    public static function malformedIdentities(): array
    {
        return [
            'unsupported version' => [['version'], 2],
            'class type' => [['workflowClass'], 17],
            'empty class' => [['workflowClass'], ''],
            'definition hash' => [['definitionFingerprint'], 'mutable-alias'],
            'profile version' => [['profile', 'version'], 2],
            'empty queue' => [['profile', 'queue'], ''],
            'compatibility normalization' => [['profile', 'compatibility'], ' '],
            'labels type' => [['profile', 'options', 'labels'], false],
            'priority coercion' => [['profile', 'options', 'priority'], '1'],
            'timeout coercion' => [['profile', 'options', 'runTimeoutSeconds'], '2'],
            'duplicate policy' => [['profile', 'options', 'duplicateStartPolicy'], 'reset-original'],
            'business key coercion' => [['profile', 'options', 'businessKey'], 12],
            'noncanonical labels' => [['profile', 'options', 'labels'], [
                'label' => ' padded ',
            ]],
        ];
    }

    #[DataProvider('malformedIdentities')]
    public function testStoredIdentityRefusesCoercionUnknownVersionsAndNoncanonicalIntent(
        array $path,
        mixed $replacement
    ): void {
        $value = $this->identity()
            ->toArray();
        $target = &$value;
        foreach ($path as $key) {
            $target = &$target[$key];
        }
        $target = $replacement;
        $this->expectException(InvalidArgumentException::class);
        RootIdentity::fromArray($value);
    }

    public function testUnsupportedReservationAndAdmissionVersionsCannotBecomeReceipts(): void
    {
        $reservation = new RootReservation($this->identity(), 'original-run', 'joint-v1', 'scope-v1');
        foreach (['reservation', 'binding'] as $format) {
            $value = $format === 'reservation' ? $reservation->toArray()
                : (new RootBinding($reservation, 'start-command'))->toArray();
            $value['version'] = 2;
            try {
                $format === 'reservation' ? RootReservation::fromArray($value) : RootBinding::fromArray($value);
                $this->fail('An unknown version became an authoritative receipt.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('Unsupported', $error->getMessage());
            }
        }
    }

    public function testEmptyPinnedPolicyCannotDescribeAnOriginalReservation(): void
    {
        $identity = $this->identity();
        $this->expectException(InvalidArgumentException::class);
        new RootReservation($identity, 'original-run', '', 'scope-v1');
    }

    public function testHistoricalIdentityDecodingNeverAutoloadsItsClass(): void
    {
        $calls = [];
        $loader = static function (string $class) use (&$calls): void {
            $calls[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $value = $this->identity()
                ->toArray();
            $this->assertSame($value, RootIdentity::fromArray($value)->toArray());
            $this->assertNotContains($value['workflowClass'], $calls);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    private function identity(): RootIdentity
    {
        return new RootIdentity(
            new ScopeIdentity('app', 'tenant', 'order', 'generation'),
            'Historical\\MissingRoot',
            'original.root.v1',
            'sha256:' . str_repeat('a', 64),
            str_repeat('b', 64),
            new RootStartProfile('database', 'original-root', 'avro')
        );
    }
}
