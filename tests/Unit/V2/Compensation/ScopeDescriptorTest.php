<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\Resolution;

final class ScopeDescriptorTest extends TestCase
{
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

    public function testPersistedVersionOneSchemasHaveStableIdentityAndFingerprintVectors(): void
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $obligation = new Obligation('book', 'carrier.cancel', '1', []);
        $this->assertSame('d0f6748c0bcd9166d88ca96e6e60c5d118108ee7ec1e9a13339ceb67977a92bb', $scope->id());
        $this->assertSame(
            '78c1a87680c6374f612fad9e4ae34f9db0e139868bc9f7df3e4605b78129d260',
            $obligation->fingerprint()
        );
        $this->assertSame(
            ['schemaVersion', 'application', 'namespace', 'key', 'generation'],
            array_keys($scope->toArray())
        );
        $this->assertSame(
            ['schemaVersion', 'key', 'handlerType', 'handlerVersion', 'input'],
            array_keys($obligation->toArray())
        );
        $this->assertSame([
            'schemaVersion' => 1,
            'observed' => false,
            'value' => [],
            'evidence' => [],
            'reason' => 'pending',
        ], ForwardResult::unknown('pending')->toArray());
        $this->assertSame([
            'schemaVersion' => 1,
            'verified' => false,
            'reason' => 'pending',
            'evidence' => [],
            'forwardSettled' => false,
        ], Resolution::unknown('pending')->toArray());
        foreach ([0, 2, '1', null] as $version) {
            foreach ([$scope, $obligation] as $value) {
                $payload = $value->toArray();
                $payload['schemaVersion'] = $version;
                try {
                    $value::fromArray($payload);
                    $this->fail('Unsupported schema was accepted.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
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
