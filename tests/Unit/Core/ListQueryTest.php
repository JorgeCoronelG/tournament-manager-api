<?php

namespace Tests\Unit\Core;

use App\Core\Classes\ListQuery;
use App\Core\Enum\OperatorSql;
use App\Exceptions\CustomErrorException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListQueryTest extends TestCase
{
    public function test_defaults(): void
    {
        $query = new ListQuery;

        $this->assertSame([], $query->filters);
        $this->assertNull($query->sort);
        $this->assertSame(5, $query->perPage);
    }

    public function test_from_request_reads_filters_sort_and_page_size(): void
    {
        $request = Request::create('/items', 'GET', [
            'q' => json_encode(['filters' => [['field' => 'status', 'value' => 1, 'operator' => '=']]]),
            'per_page' => 20,
            'sort' => '-name',
        ]);

        $query = ListQuery::fromRequest($request);

        $this->assertCount(1, $query->filters);
        $this->assertSame(OperatorSql::EQUAL, $query->filters[0]->operator);
        $this->assertSame('-name', $query->sort);
        $this->assertSame(20, $query->perPage);
    }

    public function test_from_request_without_params_uses_defaults(): void
    {
        $query = ListQuery::fromRequest(Request::create('/items'));

        $this->assertSame([], $query->filters);
        $this->assertNull($query->sort);
        $this->assertSame(5, $query->perPage);
    }

    public function test_from_request_with_invalid_filters_is_bad_request(): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        ListQuery::fromRequest(Request::create('/items', 'GET', ['q' => 'no-es-json']));
    }

    #[DataProvider('arrayParams')]
    public function test_from_request_with_array_params_is_bad_request(string $param): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        ListQuery::fromRequest(Request::create('/items', 'GET', [$param => ['x']]));
    }

    public static function arrayParams(): array
    {
        return [
            'filtros' => ['q'],
            'orden' => ['sort'],
            'tamaño de página' => ['per_page'],
        ];
    }
}
