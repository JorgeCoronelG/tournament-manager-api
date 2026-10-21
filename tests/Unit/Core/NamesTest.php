<?php

namespace Tests\Unit\Core;

use App\Helpers\Names;
use PHPUnit\Framework\TestCase;

class NamesTest extends TestCase
{
    public function test_ignores_case_accents_and_extra_spaces(): void
    {
        $this->assertSame(Names::normalize('Águilas  FC'), Names::normalize("  aguilas \t fc "));
        $this->assertSame(Names::normalize('CÓNDORES'), Names::normalize('condores'));
        $this->assertNotSame(Names::normalize('Tigres'), Names::normalize('Tigre'));
    }

    public function test_any_matches(): void
    {
        $this->assertTrue(Names::anyMatches(['Lobos', 'Águilas'], ' aguilas'));
        $this->assertFalse(Names::anyMatches(['Lobos'], 'Pumas'));
        $this->assertFalse(Names::anyMatches([], 'Pumas'));
    }
}
