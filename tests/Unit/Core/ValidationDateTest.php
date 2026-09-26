<?php

namespace Tests\Unit\Core;

use App\Exceptions\CustomErrorException;
use App\Helpers\Validation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationDateTest extends TestCase
{
    #[DataProvider('validDates')]
    public function test_valid_dates_are_returned_as_they_came(string $date): void
    {
        $this->assertSame($date, Validation::validateDate($date));
    }

    public static function validDates(): array
    {
        return [
            'con barras' => ['2024/02/29'],
            'con guiones' => ['2024-12-31'],
            'mes y día de un dígito' => ['2024-1-5'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_bad_request(?string $date): void
    {
        $this->expectException(CustomErrorException::class);
        $this->expectExceptionCode(400);

        Validation::validateDate($date);
    }

    public static function invalidDates(): array
    {
        return [
            'nulo' => [null],
            'sin separador' => ['20240101'],
            'mes inexistente' => ['2024/13/01'],
            'día inexistente' => ['2024-02-30'],
            'no bisiesto' => ['2023-02-29'],
            'partes de menos' => ['2024-01'],
            'partes de más' => ['2024-01-01-01'],
            'letras' => ['abcd-ef-gh'],
            'basura tras el número' => ['2024-01-5x'],
            'partes vacías' => ['2024--'],
        ];
    }
}
