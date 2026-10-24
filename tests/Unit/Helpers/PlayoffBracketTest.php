<?php

namespace Tests\Unit\Helpers;

use App\Helpers\PlayoffBracket;
use PHPUnit\Framework\TestCase;

class PlayoffBracketTest extends TestCase
{
    public function test_standard_bracket_order(): void
    {
        $this->assertSame([[1, 2]], PlayoffBracket::firstRound(2));
        $this->assertSame([[1, 4], [2, 3]], PlayoffBracket::firstRound(4));
        $this->assertSame([[1, 8], [4, 5], [2, 7], [3, 6]], PlayoffBracket::firstRound(8));
        $this->assertSame([[1, 16], [8, 9], [4, 13], [5, 12], [2, 15], [7, 10], [3, 14], [6, 11]], PlayoffBracket::firstRound(16));
        $this->assertCount(16, PlayoffBracket::firstRound(32));
    }

    public function test_separates_same_group_rivals_swapping_with_a_contiguous_seed(): void
    {
        // Seeds 1 y 8 son del grupo A; 2 y 7 de grupos distintos: 1v7 y 2v8 evita el conflicto.
        $groups = [1 => 'A', 8 => 'A', 2 => 'B', 7 => 'C', 3 => 'D', 6 => 'E', 4 => 'F', 5 => 'G'];

        $this->assertSame([[1, 7], [4, 5], [2, 8], [3, 6]], PlayoffBracket::firstRound(8, $groups));
    }

    public function test_leaves_the_conflict_when_it_cannot_be_avoided(): void
    {
        $this->assertSame([[1, 4], [2, 3]], PlayoffBracket::firstRound(4, [1 => 'A', 2 => 'A', 3 => 'A', 4 => 'A']));
    }

    public function test_winners_advance_to_the_next_round_slot_by_position_parity(): void
    {
        $this->assertSame(['position' => 1, 'side' => 'a'], PlayoffBracket::next(1));
        $this->assertSame(['position' => 1, 'side' => 'b'], PlayoffBracket::next(2));
        $this->assertSame(['position' => 2, 'side' => 'a'], PlayoffBracket::next(3));
        $this->assertSame(['position' => 2, 'side' => 'b'], PlayoffBracket::next(4));
    }

    public function test_home_and_away_rules(): void
    {
        // Partido único: el mejor sembrado (seed 1, inscripción 10) es local.
        $this->assertSame([10, 20], PlayoffBracket::sides(1, 10, 4, 20, 1, 1));
        $this->assertSame([10, 20], PlayoffBracket::sides(4, 20, 1, 10, 1, 1));
        // Ida y vuelta: la ida en casa del peor sembrado, la vuelta del mejor.
        $this->assertSame([20, 10], PlayoffBracket::sides(1, 10, 4, 20, 2, 1));
        $this->assertSame([10, 20], PlayoffBracket::sides(1, 10, 4, 20, 2, 2));
    }
}
