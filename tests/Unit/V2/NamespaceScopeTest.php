<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Support\NamespaceScope;

final class NamespaceScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: mixed, 2: list<string>}>
     */
    public static function configuredScopes(): iterable
    {
        yield 'absent list, unset namespace' => [null, null, ['default']];
        yield 'absent list, blank namespace' => ['  ', null, ['default']];
        yield 'absent list, configured namespace' => ['billing', null, ['billing']];
        yield 'comma-separated list' => ['billing', ' shipping, billing ,, ', ['billing', 'shipping']];
        yield 'config list' => [null, ['shipping', 'default'], ['default', 'shipping']];
        yield 'empty string' => ['billing', '', []];
        yield 'blank string' => ['billing', ' , ', []];
        yield 'empty list' => ['billing', [], []];
    }

    #[DataProvider('configuredScopes')]
    public function testConfiguredScope(?string $namespace, mixed $namespaces, array $expected): void
    {
        config()->set([
            'workflows.v2.namespace' => $namespace,
            'workflows.v2.namespaces' => $namespaces,
            'workflows.v2.all_namespaces' => false,
        ]);

        $scope = NamespaceScope::configured();

        $this->assertFalse($scope->isAll());
        $this->assertSame($expected, $scope->namespaces());
        $this->assertSame($expected === [], $scope->isEmpty());
    }

    public function testDefaultNamespaceCoversNullEmptyAndDefaultValues(): void
    {
        $scope = NamespaceScope::of([null]);

        $this->assertTrue($scope->allows(null));
        $this->assertTrue($scope->allows(''));
        $this->assertTrue($scope->allows('default'));
        $this->assertFalse($scope->allows('billing'));
        $this->assertFalse(NamespaceScope::of(['billing'])->allows(null));
    }

    public function testEmptyScopeAllowsNothing(): void
    {
        $scope = NamespaceScope::of([]);

        $this->assertTrue($scope->isEmpty());
        $this->assertFalse($scope->allows(null));
        $this->assertFalse($scope->allows('billing'));
    }

    public function testEveryNamespaceIsOnlyTheAdministrativeOptIn(): void
    {
        config()->set([
            'workflows.v2.namespace' => 'billing',
            'workflows.v2.namespaces' => '',
            'workflows.v2.all_namespaces' => true,
        ]);

        $scope = NamespaceScope::configured();

        $this->assertTrue($scope->isAll());
        $this->assertNull($scope->namespaces());
        $this->assertTrue($scope->allows('anything'));
        $this->assertSame('*', $scope->key());
    }

    #[DataProvider('invalidLists')]
    public function testInvalidListsAreRefused(mixed $namespaces): void
    {
        config()->set([
            'workflows.v2.namespaces' => $namespaces,
            'workflows.v2.all_namespaces' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);

        NamespaceScope::configured();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLists(): iterable
    {
        yield 'wildcard string' => ['*'];
        yield 'wildcard entry' => [['billing', '*']];
        yield 'non-string entry' => [['billing', 7]];
        yield 'non-list value' => [7];
    }

    public function testScopeKeysAreStableAcrossOrderAndDuplicates(): void
    {
        $this->assertSame(NamespaceScope::of(['b', 'a', 'a'])->key(), NamespaceScope::of(['a', 'b'])->key());
        $this->assertNotSame(NamespaceScope::of(['a'])->key(), NamespaceScope::of([])->key());
    }
}
