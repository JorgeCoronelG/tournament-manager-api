<?php

namespace Tests\Unit\Core;

use App\Core\Classes\Filter;
use App\Core\Classes\ListQuery;
use App\Core\Enum\OperatorSql;
use Tests\Support\CreatesItemsTable;
use Tests\Support\ItemData;
use Tests\Support\ItemPatchData;
use Tests\Support\ItemRepository;
use Tests\Support\ItemService;
use Tests\TestCase;

class BaseServiceTest extends TestCase
{
    use CreatesItemsTable;

    private ItemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createItemsTable();
        $this->service = new ItemService(new ItemRepository);
    }

    public function test_create_from_data_object(): void
    {
        $item = $this->service->create(new ItemData('Ana', 1));

        $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Ana', 'status' => 1]);
    }

    public function test_update_from_data_object(): void
    {
        $item = $this->service->create(new ItemData('Ana', 1));

        $this->service->update($item->id, new ItemData('Ana María', 2));

        $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'Ana María', 'status' => 2]);
    }

    public function test_update_with_regular_dto_writes_null_for_nullable_fields(): void
    {
        // Un DTO con "?int $status" siempre incluye status en toArray(): null = "poner null".
        $item = $this->service->create(new ItemData('Ana', 5));

        $this->service->update($item->id, new ItemData(name: 'Nuevo', status: null));

        $this->assertNull($item->fresh()->status);
    }

    public function test_partial_update_does_not_overwrite_unsent_fields_when_dto_uses_optional(): void
    {
        $item = $this->service->create(new ItemData('Ana', 5));

        $this->service->update($item->id, ItemPatchData::from(['name' => 'Nuevo']));

        $item = $item->fresh();
        $this->assertSame('Nuevo', $item->name);
        $this->assertSame(5, $item->status);
    }

    public function test_partial_update_can_explicitly_set_null_with_optional_dto(): void
    {
        $item = $this->service->create(new ItemData('Ana', 5));

        $this->service->update($item->id, ItemPatchData::from(['status' => null]));

        $item = $item->fresh();
        $this->assertSame('Ana', $item->name);
        $this->assertNull($item->status);
    }

    public function test_delete_and_find_by_id(): void
    {
        $item = $this->service->create(new ItemData('Ana'));

        $this->assertSame('Ana', $this->service->findById($item->id)->name);

        $this->service->delete($item->id);
        $this->assertDatabaseCount('items', 0);
    }

    public function test_find_all_paginated_with_list_query(): void
    {
        $this->seedItems();

        $page = $this->service->findAllPaginated(new ListQuery(
            [new Filter('status', 1, OperatorSql::EQUAL)],
            '-name',
            1
        ));

        $this->assertSame(2, $page->total());
        $this->assertSame(1, $page->perPage());
        $this->assertSame('Carlos', $page->items()[0]->name);
    }

    public function test_find_all_paginated_uses_default_page_size(): void
    {
        $this->seedItems();

        $page = $this->service->findAllPaginated(new ListQuery);

        $this->assertSame(5, $page->perPage());
    }
}
