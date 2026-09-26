<?php

namespace Tests\Unit\Core;

use App\Core\Classes\Filter;
use App\Core\Enum\OperatorSql;
use App\Exceptions\CustomErrorException;
use App\Helpers\Validation;
use Tests\Support\CreatesItemsTable;
use Tests\Support\Item;
use Tests\Support\ItemWithoutSorts;
use Tests\TestCase;

/**
 * Caracterización de Validation::getFilters y del scope AdvancedFilter.
 */
class FiltersTest extends TestCase
{
    use CreatesItemsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createItemsTable();
        $this->seedItems(); // Ana(1), Beto(0), Carlos(1)
    }

    private function names(array $filters): array
    {
        return Item::filter($filters)->orderBy('id')->pluck('name')->all();
    }

    // ---- Validation::getFilters ----

    public function test_get_filters_returns_empty_array_when_null(): void
    {
        $this->assertSame([], Validation::getFilters(null));
    }

    public function test_get_filters_parses_json(): void
    {
        $json = json_encode(['filters' => [['field' => 'name', 'value' => 'Ana', 'operator' => '=']]]);

        $filters = Validation::getFilters(urlencode($json));

        $this->assertCount(1, $filters);
        $this->assertSame('name', $filters[0]->field);
        $this->assertSame(OperatorSql::EQUAL, $filters[0]->operator);
    }

    public function test_get_filters_without_filters_key_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['x' => 1]));
    }

    public function test_get_filters_without_field_or_operator_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['filters' => [['field' => 'name']]]));
    }

    public function test_get_filters_with_unknown_operator_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['filters' => [['field' => 'name', 'value' => 'a', 'operator' => 'nope']]]));
    }

    public function test_get_filters_with_non_string_field_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['filters' => [['field' => ['x'], 'value' => 'a', 'operator' => '=']]]));
    }

    public function test_get_filters_with_invalid_boolean_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['filters' => [['field' => 'name', 'value' => 'a', 'operator' => '=', 'boolean' => 'xor']]]));
    }

    public function test_get_filters_with_non_scalar_value_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::getFilters(json_encode(['filters' => [['field' => 'name', 'value' => ['x'], 'operator' => '=']]]));
    }

    public function test_get_filters_accepts_is_null_without_value(): void
    {
        $filters = Validation::getFilters(json_encode(['filters' => [['field' => 'status', 'operator' => 'IS NULL']]]));

        $this->assertNull($filters[0]->value);
        $this->assertSame(OperatorSql::IS_NULL, $filters[0]->operator);
    }

    public function test_get_filters_boolean_defaults_to_and_and_accepts_or(): void
    {
        $filters = Validation::getFilters(json_encode(['filters' => [
            ['field' => 'name', 'value' => 'a', 'operator' => '='],
            ['field' => 'name', 'value' => 'b', 'operator' => '=', 'boolean' => 'OR'],
        ]]));

        $this->assertSame('and', $filters[0]->boolean);
        $this->assertSame('or', $filters[1]->boolean);
    }

    // ---- AdvancedFilter ----

    public function test_operators(): void
    {
        $this->assertSame(['Ana'], $this->names([new Filter('name', 'Ana', OperatorSql::EQUAL)]));
        $this->assertSame(['Beto', 'Carlos'], $this->names([new Filter('name', 'Ana', OperatorSql::NOT_EQUAL)]));
        $this->assertSame(['Carlos'], $this->names([new Filter('name', 'arl', OperatorSql::CONTAIN)]));
        $this->assertSame(['Ana', 'Beto'], $this->names([new Filter('name', 'arl', OperatorSql::NOT_CONTAIN)]));
        $this->assertSame(['Beto'], $this->names([new Filter('name', 'Be', OperatorSql::STARTS_WITH)]));
        $this->assertSame(['Ana'], $this->names([new Filter('name', 'na', OperatorSql::ENDS_WITH)]));
        $this->assertSame(['Ana', 'Carlos'], $this->names([new Filter('status', 1, OperatorSql::GREATER_THAN_OR_EQUAL)]));
        $this->assertSame(['Beto'], $this->names([new Filter('status', 1, OperatorSql::LESS_THAN)]));
        $this->assertSame([], $this->names([new Filter('status', null, OperatorSql::IS_NULL)]));
        $this->assertSame(['Ana', 'Beto', 'Carlos'], $this->names([new Filter('status', null, OperatorSql::NOT_NULL)]));
    }

    public function test_multiple_filters_are_combined_with_and_by_default(): void
    {
        $this->assertSame([], $this->names([
            new Filter('name', 'Ana', OperatorSql::EQUAL),
            new Filter('status', 0, OperatorSql::EQUAL),
        ]));

        $this->assertSame(['Ana'], $this->names([
            new Filter('name', 'Ana', OperatorSql::EQUAL),
            new Filter('status', 1, OperatorSql::EQUAL),
        ]));
    }

    public function test_filters_can_be_combined_with_or(): void
    {
        $result = $this->names([
            new Filter('name', 'Ana', OperatorSql::EQUAL),
            new Filter('status', 0, OperatorSql::EQUAL, 'or'),
        ]);

        $this->assertSame(['Ana', 'Beto'], $result);
    }

    public function test_or_filters_are_grouped_and_do_not_escape_other_constraints(): void
    {
        // status = 1 AND (name = Beto OR name = Carlos) => solo Carlos.
        $result = Item::where('status', 1)
            ->filter([
                new Filter('name', 'Beto', OperatorSql::EQUAL),
                new Filter('name', 'Carlos', OperatorSql::EQUAL, 'or'),
            ])
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $this->assertSame(['Carlos'], $result);
    }

    public function test_field_outside_whitelist_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Item::filter([new Filter('created_at', null, OperatorSql::NOT_NULL)])->get();
    }

    public function test_model_without_allowed_filters_fails_when_filters_are_requested(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(500);

        ItemWithoutSorts::filter([new Filter('name', 'Ana', OperatorSql::EQUAL)])->get();
    }

    public function test_model_without_allowed_filters_works_when_no_filters_requested(): void
    {
        $this->assertCount(3, ItemWithoutSorts::filter([])->get());
    }
}
