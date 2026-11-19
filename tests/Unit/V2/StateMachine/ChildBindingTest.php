<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

final class ChildBindingTest extends TestCase
{
    public function testVersionOneIdentityHasFrozenLookupAndDescriptorVectors(): void
    {
        $identity = $this->identity();
        $this->assertSame('922278dd098dc77d71e6f3a84ef4d2d7995d46fb561c1d851083cce7855e9253', $identity->key());
        $this->assertSame('66ed5bc95b201363bd3e27f739837e5539a787f7aa635b8701eb3fd35ea51c96', $identity->fingerprint());
    }

    public function testStoredBindingRoundTripsWithoutAWorkflowOrMutableStateLookup(): void
    {
        $binding = new ChildBinding($this->identity(), $this->admission());
        $stored = json_encode($binding->toArray(), JSON_THROW_ON_ERROR);
        $restored = ChildBinding::fromArray(json_decode($stored, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($binding->toArray(), $restored->toArray());
        $this->assertTrue($restored->matches($this->identity()));
        $this->assertSame('parent-run-1', $restored->admission->parentRunId);
        $this->assertSame('child-run-1', $restored->admission->childRunId);
        $this->assertSame('entry-1', $restored->identity->generation);
    }

    public static function descriptorChanges(): iterable
    {
        foreach (['hostingState', 'requiredOutcome', 'childWorkflowType'] as $key) {
            yield $key => [$key, 'changed'];
        }
        foreach (['childPolicyFingerprint', 'inputFingerprint'] as $key) {
            yield $key => [$key, str_repeat('d', 64)];
        }
    }

    #[DataProvider('descriptorChanges')]
    public function testAReusedKeyCannotMakeAChangedDescriptorMatch(string $key, string $value): void
    {
        $original = $this->identity();
        $payload = $original->toArray();
        $payload[$key] = $value;
        $changed = BindingIdentity::fromArray($payload);
        $this->assertSame($original->key(), $changed->key());
        $this->assertNotSame($original->fingerprint(), $changed->fingerprint());
        $this->assertFalse((new ChildBinding($original, $this->admission()))->matches($changed));
    }

    public static function originalIdentityChanges(): iterable
    {
        foreach (['namespace', 'parentInstanceId', 'parentRunId', 'slot', 'generation'] as $key) {
            yield $key => [$key, false];
        }
        foreach (['scope', 'subject', 'executionId'] as $key) {
            yield $key => [$key, true];
        }
    }

    #[DataProvider('originalIdentityChanges')]
    public function testOriginalOwnerSlotAndGenerationEachHaveTheirOwnLookupKey(string $key, bool $parent): void
    {
        $original = $this->identity();
        $payload = $original->toArray();
        if ($parent) {
            $payload['parentExecution'][$key] = 'different';
        } else {
            $payload[$key] = 'different';
        }
        $this->assertNotSame($original->key(), BindingIdentity::fromArray($payload)->key());
    }

    public static function receiptSubstitutions(): iterable
    {
        foreach (['namespace', 'parentInstanceId', 'parentRunId', 'workflowType'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('receiptSubstitutions')]
    public function testAReceiptFromAnotherOwnerOrTypeCannotBeBound(string $key): void
    {
        $receipt = $this->admission()
            ->toArray();
        $receipt[$key] = 'substituted';
        $this->expectException(InvalidArgumentException::class);
        new ChildBinding($this->identity(), StartedChildWorkflow::fromArray($receipt));
    }

    public static function invalidPersistedIdentities(): iterable
    {
        yield 'unknown version' => [static function (array &$p): void {
            $p['version'] = 2;
        }];
        yield 'extra field' => [static function (array &$p): void {
            $p['latest'] = true;
        }];
        yield 'missing generation' => [static function (array &$p): void {
            unset($p['generation']);
        }];
        yield 'untyped generation' => [static function (array &$p): void {
            $p['generation'] = 1;
        }];
        yield 'empty generation' => [static function (array &$p): void {
            $p['generation'] = '';
        }];
        yield 'changed parent schema' => [static function (array &$p): void {
            $p['parentExecution']['extra'] = '';
        }];
        yield 'missing policy' => [static function (array &$p): void {
            $p['childPolicyFingerprint'] = '';
        }];
        yield 'missing outcome' => [static function (array &$p): void {
            $p['requiredOutcome'] = '';
        }];
        yield 'unbound input' => [static function (array &$p): void {
            $p['inputFingerprint'] = 'latest';
        }];
        yield 'extra options' => [static function (array &$p): void {
            $p['childOptions']['extra'] = null;
        }];
        yield 'wrong routing type' => [static function (array &$p): void {
            $p['childOptions']['queue'] = false;
        }];
    }

    #[DataProvider('invalidPersistedIdentities')]
    public function testClosedVersionedIdentityRefusesAmbiguousStoredData(callable $mutate): void
    {
        $payload = $this->identity()
            ->toArray();
        $mutate($payload);
        $this->expectException(InvalidArgumentException::class);
        BindingIdentity::fromArray($payload);
    }

    private function identity(): BindingIdentity
    {
        return new BindingIdentity(
            new ExecutionReference('tenant-a', 'order-1', 'transition-1', str_repeat(
                'a',
                64
            ), 'Received', 'revision-7'),
            'client-a',
            'parent-1',
            'parent-run-1',
            'payment',
            'entry-1',
            'Processing',
            'example.child.v1',
            str_repeat('b', 64),
            'Paid',
            str_repeat('c', 64),
        );
    }

    private function admission(): StartedChildWorkflow
    {
        return new StartedChildWorkflow(
            'client-a',
            'parent-1',
            'parent-run-1',
            7,
            'child-1',
            'child-run-1',
            'child-call-1',
            'example.child.v1'
        );
    }
}
