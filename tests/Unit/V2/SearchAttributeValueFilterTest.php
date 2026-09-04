<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\SearchAttributeValueFilter;

final class SearchAttributeValueFilterTest extends TestCase
{
    #[DataProvider('typedValues')]
    public function testTypedValuesTargetTheirStorageColumns(mixed $value, string $column): void
    {
        $query = $this->query('sqlite');

        SearchAttributeValueFilter::apply($query, $value);

        $this->assertStringContainsString('"' . $column . '" = ?', $query->toSql());
        $this->assertSame([$value], $query->getBindings());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function typedValues(): iterable
    {
        yield 'boolean' => [true, 'value_bool'];
        yield 'integer' => [42, 'value_int'];
        yield 'float' => [2.5, 'value_float'];
        yield 'datetime' => [new DateTimeImmutable('2026-09-04T12:00:00Z'), 'value_datetime'];
        yield 'long string' => [str_repeat('x', 256), 'value_string'];
    }

    public function testShortStringsMatchKeywordsOrKeywordListMembersOnSqlite(): void
    {
        $query = $this->query('sqlite');

        SearchAttributeValueFilter::apply($query, 'php');

        $this->assertStringContainsString('"value_keyword" = ?', $query->toSql());
        $this->assertStringContainsString('json_each("value_keyword_list")', $query->toSql());
        $this->assertSame(['php', 'php'], $query->getBindings());
    }

    public function testKeywordListsRequireEveryStringMemberOnSqlite(): void
    {
        $query = $this->query('sqlite');

        SearchAttributeValueFilter::apply($query, ['php', 42, 'rust']);

        $this->assertSame(2, substr_count($query->toSql(), 'json_each("value_keyword_list")'));
        $this->assertSame(['php', 'rust'], $query->getBindings());
    }

    public function testKeywordListsWithoutStringMembersNeverMatch(): void
    {
        $query = $this->query('sqlite');

        SearchAttributeValueFilter::apply($query, [42, false]);

        $this->assertStringContainsString('0 = 1', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function testMysqlUsesNativeJsonMembershipForKeywordValuesAndLists(): void
    {
        $keyword = $this->query('mysql');
        SearchAttributeValueFilter::apply($keyword, 'php');

        $list = $this->query('mysql');
        SearchAttributeValueFilter::apply($list, ['php', 'rust']);

        $this->assertStringContainsString('json_contains(`value_keyword_list`, ?)', $keyword->toSql());
        $this->assertSame(['php', '"php"'], $keyword->getBindings());
        $this->assertSame(2, substr_count($list->toSql(), 'json_contains(`value_keyword_list`, ?)'));
        $this->assertSame(['"php"', '"rust"'], $list->getBindings());
    }

    private function query(string $driver): Builder
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => $driver,
            'database' => ':memory:',
            'prefix' => '',
        ], $driver);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $model = new SearchAttributeFilterModel();
        $model->setConnection($driver);

        return $model->newQuery();
    }
}

final class SearchAttributeFilterModel extends Model
{
    public $timestamps = false;

    protected $table = 'workflow_search_attributes';
}
