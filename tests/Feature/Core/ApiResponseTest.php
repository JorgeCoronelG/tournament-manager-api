<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesItemsTable;
use Tests\Support\ItemController;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    use CreatesItemsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createItemsTable();
        $this->seedItems(); // Ana, Beto, Carlos

        Route::get('/_test/items/paginated', [ItemController::class, 'paginated']);
        Route::get('/_test/items/all', [ItemController::class, 'all']);
        Route::get('/_test/items/one', [ItemController::class, 'one']);
    }

    public function test_show_all_keeps_pagination_data_for_a_paginated_collection(): void
    {
        $this->getJson('/_test/items/paginated')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('meta.currentPage', 1)
            ->assertJsonPath('meta.lastPage', 2)
            ->assertJsonPath('meta.perPage', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('links.first', null)
            ->assertJsonPath('links.prev', null)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['currentPage', 'from', 'lastPage', 'perPage', 'to', 'total']]);
    }

    public function test_show_all_second_page_has_previous_link(): void
    {
        $this->getJson('/_test/items/paginated?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.currentPage', 2)
            ->assertJsonPath('links.next', null)
            ->assertJsonPath('links.first', fn ($first) => is_string($first) && str_contains($first, 'page=1'));
    }

    public function test_show_all_returns_a_plain_list_when_the_collection_is_not_paginated(): void
    {
        $this->getJson('/_test/items/all')
            ->assertOk()
            ->assertExactJson([
                ['id' => 1, 'name' => 'Ana'],
                ['id' => 2, 'name' => 'Beto'],
                ['id' => 3, 'name' => 'Carlos'],
            ]);
    }

    public function test_show_one_returns_the_plain_resource(): void
    {
        $this->getJson('/_test/items/one')
            ->assertOk()
            ->assertExactJson(['id' => 1, 'name' => 'Ana']);
    }
}
