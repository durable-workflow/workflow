<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Activities\PrepareRecoveryActivity;
use Workflow\V2\Compensation\Activities\RegisterChildScopeActivity;
use Workflow\V2\Compensation\Activities\RegisterObligationActivity;
use Workflow\V2\Compensation\Activities\RequestScopeActivity;
use Workflow\V2\Compensation\Activities\VerifyScopeActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\Resolution;

final class ScopeDescriptorTest extends TestCase
{
    public function testRecordedActivityTypesAreIndependentOfPhpClassNames(): void
    {
        foreach ([
            CompensateObligationActivity::class => 'dw.compensation.compensate-obligation.v1',
            ForwardOperationActivity::class => 'dw.compensation.forward-operation.v1',
            PrepareRecoveryActivity::class => 'dw.compensation.prepare-recovery.v1',
            RegisterChildScopeActivity::class => 'dw.compensation.register-child-scope.v1',
            RegisterObligationActivity::class => 'dw.compensation.register-obligation.v1',
            RequestScopeActivity::class => 'dw.compensation.request-scope.v1',
            VerifyScopeActivity::class => 'dw.compensation.verify-scope.v1',
        ] as $class => $expected) {
            $attributes = (new ReflectionClass($class))->getAttributes(Type::class);
            $this->assertCount(1, $attributes);
            $this->assertSame($expected, $attributes[0]->newInstance()->key);
        }
    }

    public function testVersionOnePersistedFormatsHaveStableCanonicalIdentities(): void
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $obligation = new Obligation('book', 'carrier.cancel', '1', [
            'reference' => 'booking-1',
        ]);
        $this->assertSame('ceb73af611903fb425f8bcdb3b415d52be6f619cc5f1f8f6c3c3996aea4c5a8d', $scope->id());
        $this->assertSame('43a5906816309c817fafc95d183ade5b5786123115a765b85b13b12440bcfa13', $obligation->fingerprint());
        $this->assertSame($scope->id(), ScopeIdentity::fromArray($scope->toArray())->id());
        $this->assertSame($obligation->fingerprint(), Obligation::fromArray($obligation->toArray())->fingerprint());
        $this->assertSame('df14bcf01709ef85b55c7fc5770dcd7f4f0690c6a1ed4dcae092ee91df669396', hash(
            'sha256',
            PortableDescriptor::encode(ForwardResult::observed([
                'reference' => 'booking-1',
            ], ['provider:proof'])->toArray()),
        ));
        $this->assertSame('8e9b39641e72e2dd9b9f16c457c2d988f619e4fe17fae0586ed72f2d1ec92790', hash(
            'sha256',
            PortableDescriptor::encode(Resolution::verified(['provider:proof'], true)->toArray()),
        ));
    }

    public static function unsupportedVersions(): iterable
    {
        foreach ([null, '1', true, 1.0, 2] as $index => $version) {
            foreach (['scope', 'obligation', 'forward', 'resolution'] as $kind) {
                yield $kind . '-' . $index => [$kind, $version];
            }
        }
    }

    #[DataProvider('unsupportedVersions')]
    public function testUnsupportedPersistedVersionsRefuseInterpretation(string $kind, mixed $version): void
    {
        $payload = match ($kind) {
            'scope' => (new ScopeIdentity('shop', 'client-a', 'order-42', '1'))->toArray(),
            'obligation' => (new Obligation('book', 'carrier.cancel', '1', []))->toArray(),
            'forward' => ForwardResult::unknown('unknown')->toArray(),
            'resolution' => Resolution::unknown('unknown')->toArray(),
        };
        $payload['version'] = $version;
        $this->expectException(InvalidArgumentException::class);
        match ($kind) {
            'scope' => ScopeIdentity::fromArray($payload),
            'obligation' => Obligation::fromArray($payload),
            'forward' => ForwardResult::fromArray($payload),
            'resolution' => Resolution::fromArray($payload),
        };
    }

    public function testScopeIdentityIncludesApplicationNamespaceAndGeneration(): void
    {
        $original = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $this->assertSame($original->id(), (new ScopeIdentity('shop', 'client-a', 'order-42', '1'))->id());
        foreach ([['other', 'client-a', 'order-42', '1'],
            ['shop', 'client-b', 'order-42', '1'],
            ['shop', 'client-a', 'order-42', '2'],
        ] as $parts) {
            $this->assertNotSame($original->id(), (new ScopeIdentity(...$parts))->id());
        }
    }

    public function testDescriptorCanonicalizesMapsWithoutReorderingLists(): void
    {
        $a = new Obligation('book', 'carrier.cancel', '1', [
            'b' => 2,
            'a' => [
                '01' => 1,
                '001' => 2,
            ],
        ]);
        $b = new Obligation('book', 'carrier.cancel', '1', [
            'a' => [
                '001' => 2,
                '01' => 1,
            ],
            'b' => 2,
        ]);
        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame(
            (new Obligation('book', 'carrier.cancel', '1', [1, 2]))->fingerprint(),
            (new Obligation('book', 'carrier.cancel', '1', [2, 1]))->fingerprint()
        );
        $this->assertSame($a->fingerprint(), Obligation::fromArray($a->toArray())->fingerprint());
    }

    public function testExecutableClassNameCannotBeAHandlerIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Obligation('book', 'App\\DeleteEverything', '1', []);
    }

    public function testMutableObjectsCannotEnterTheDescriptor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Obligation('book', 'carrier.cancel', '1', [
            'input' => new \stdClass(),
        ]);
    }

    public function testEvidenceReferenceLimitIsValidatedBeforeAcceptingProviderResults(): void
    {
        $references = array_fill(0, 33, 'proof');
        foreach ([
            static fn () => ForwardResult::observed([], $references),
            static fn () => Resolution::verified($references, true),
        ] as $result) {
            try {
                $result();
                $this->fail('The result admitted more evidence than its bounded journal envelope supports.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('evidence reference limit', $error->getMessage());
            }
        }
    }

    public function testEvidenceReferencesCannotHideUnboundedMetadataInMapKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ForwardResult::observed([], [
            'provider-metadata' => 'proof',
        ]);
    }

    public function testOversizedDescriptorIsRejectedBeforeStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Obligation('book', 'carrier.cancel', '1', [
            'input' => str_repeat('x', 16385),
        ]);
    }
}
