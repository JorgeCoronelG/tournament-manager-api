<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\CustomErrorException;
use App\Helpers\Validation;
use PHPUnit\Framework\TestCase;

class ValidationYearTest extends TestCase
{
    public function test_accepts_four_digit_years(): void
    {
        $this->assertSame(2026, Validation::validateYear('2026'));
    }

    public function test_rejects_invalid_years(): void
    {
        foreach ([null, '', 'abc', '26', '20260', '2026-01', ' 2026', '-2026'] as $value) {
            try {
                Validation::validateYear($value);
                $this->fail('Debió rechazar '.var_export($value, true));
            } catch (CustomErrorException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
