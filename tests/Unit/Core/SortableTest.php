<?php

namespace Tests\Unit\Core;

use App\Exceptions\CustomErrorException;
use Tests\Support\CreatesItemsTable;
use Tests\Support\Item;
use Tests\Support\ItemWithoutSorts;
use Tests\TestCase;

class SortableTest extends TestCase
{
    use CreatesItemsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createItemsTable();
        $this->seedItems();
    }

    public function test_sorts_ascending_and_descending(): void
    {
        $this->assertSame(['Ana', 'Beto', 'Carlos'], Item::applySort('name')->pluck('name')->all());
        $this->assertSame(['Carlos', 'Beto', 'Ana'], Item::applySort('-name')->pluck('name')->all());
    }

    public function test_multiple_sort_fields(): void
    {
        $this->assertSame(
            ['Beto', 'Ana', 'Carlos'],
            Item::applySort('status,name')->pluck('name')->all()
        );
    }

    public function test_null_sort_leaves_query_untouched(): void
    {
        $this->assertCount(3, Item::applySort(null)->get());
    }

    public function test_field_outside_whitelist_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Item::applySort('created_at')->get();
    }

    public function test_model_without_allowed_sorts_works_when_no_sort_requested(): void
    {
        $this->assertCount(3, ItemWithoutSorts::applySort(null)->get());
    }

    public function test_model_without_allowed_sorts_fails_when_a_sort_is_requested(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(500);

        ItemWithoutSorts::applySort('name')->get();
    }
}
