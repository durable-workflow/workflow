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
