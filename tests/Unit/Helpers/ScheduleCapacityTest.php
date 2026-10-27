<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ScheduleCapacity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleCapacityTest extends TestCase
{
    private function slots(int $duration, array $windows, array $blocks = [], string $start = '2026-11-02'): array
    {
        $start = CarbonImmutable::parse($start);

        return ScheduleCapacity::weekSlots($start->startOfWeek(CarbonImmutable::MONDAY), $start, $windows, $blocks, $duration);
    }

    public function test_slots_are_consecutive_and_must_end_within_the_window(): void
    {
        $window = [['weekday' => 6, 'field_id' => 1, 'start' => 480, 'end' => 840]];

        $this->assertCount(6, $this->slots(60, $window));

        $fifty = array_map(fn (array $slot) => ScheduleCapacity::time($slot['start']), $this->slots(50, $window));
        $this->assertSame(['08:00', '08:50', '09:40', '10:30', '11:20', '12:10', '13:00'], $fifty);
    }

    public function test_split_windows_of_a_field_add_up(): void
    {
        $windows = [
            ['weekday' => 6, 'field_id' => 2, 'start' => 480, 'end' => 540],
            ['weekday' => 6, 'field_id' => 2, 'start' => 600, 'end' => 840],
        ];

        $this->assertCount(5, $this->slots(60, $windows));
    }

    public function test_blocks_remove_a_day_a_field_or_overlapping_slots(): void
    {
        $windows = [
            ['weekday' => 6, 'field_id' => 1, 'start' => 480, 'end' => 600],
            ['weekday' => 6, 'field_id' => 2, 'start' => 480, 'end' => 600],
        ];
        $day = ['date' => '2026-11-07', 'field_id' => null, 'start' => null, 'end' => null];

        $this->assertCount(4, $this->slots(60, $windows));
        $this->assertCount(0, $this->slots(60, $windows, [$day]));
        $this->assertCount(2, $this->slots(60, $windows, [['field_id' => 2] + $day]));
        $this->assertCount(3, $this->slots(60, $windows, [['field_id' => 1, 'start' => 540, 'end' => 570] + $day]));
    }

    public function test_days_before_the_start_date_are_not_used(): void
    {
        $windows = [['weekday' => 6, 'field_id' => 1, 'start' => 480, 'end' => 540]];

        $this->assertCount(0, $this->slots(60, $windows, [], '2026-11-08'));
    }
}
