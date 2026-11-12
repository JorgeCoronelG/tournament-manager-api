<?php

namespace Tests\Unit\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Core\Enum\MatchStatus;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\FieldAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/**
 * Regla central de disponibilidad de canchas, con partidos en memoria (sin base de datos).
 */
class FieldAvailabilityTest extends TestCase
{
    private const FIELD = 1;

    private int $nextId = 1;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function match(string $at, int $duration, array $attributes = [], int $tournamentId = 10): GameMatch
    {
        $tournament = (new Tournament)->forceFill(['id' => $tournamentId, 'name' => "Torneo {$tournamentId}", 'match_duration_minutes' => $duration]);
        $match = (new GameMatch)->forceFill($attributes + [
            'id' => $this->nextId++,
            'tournament_id' => $tournamentId,
            'field_id' => self::FIELD,
            'match_datetime' => $at,
            'status' => MatchStatus::SCHEDULED,
        ]);

        return $match->setRelation('tournament', $tournament)->setRelation('field', (new Field)->forceFill(['id' => $match->field_id, 'name' => 'Cancha 1']));
    }

    private function availability(GameMatch ...$matches): FieldAvailability
    {
        $repository = $this->createMock(MatchRepositoryInterface::class);
        $repository->method('scheduledInLeague')->willReturn(new Collection($matches));

        return (new FieldAvailability($repository))
            ->forRange(1, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-09'));
    }

    public function test_partial_overlap_takes_the_slot_and_returns_the_occupant(): void
    {
        $occupant = $this->match('2026-11-08 08:00:00', 90);
        $availability = $this->availability($occupant);

        // 8:00-9:30 contra 9:00-10:00.
        $this->assertSame($occupant, $availability->occupant(self::FIELD, '2026-11-08', 540, 60));
        $this->assertTrue($availability->isTaken(self::FIELD, '2026-11-08', 540, 60));
        // Y al revés: el espacio empieza antes y termina dentro del partido.
        $this->assertTrue($availability->isTaken(self::FIELD, '2026-11-08', 450, 60));
    }

    public function test_adjacent_intervals_do_not_overlap(): void
    {
        $availability = $this->availability($this->match('2026-11-08 08:00:00', 90));

        // El partido termina a las 9:30: el espacio que empieza a las 9:30 está libre.
        $this->assertNull($availability->occupant(self::FIELD, '2026-11-08', 570, 60));
        // Y el que termina a las 8:00, cuando empieza el partido, también.
        $this->assertFalse($availability->isTaken(self::FIELD, '2026-11-08', 420, 60));
        // Un minuto antes sí chocan.
        $this->assertTrue($availability->isTaken(self::FIELD, '2026-11-08', 569, 60));
    }

    public function test_each_match_uses_the_duration_of_its_own_tournament(): void
    {
        $long = $this->match('2026-11-08 08:00:00', 120, [], 10);
        $short = $this->match('2026-11-08 11:00:00', 30, [], 20);
        $availability = $this->availability($long, $short);

        // El de 120 min ocupa los dos espacios de 60; el de 30 min solo la primera mitad de su hora.
        $this->assertSame($long, $availability->occupant(self::FIELD, '2026-11-08', 480, 60));
        $this->assertSame($long, $availability->occupant(self::FIELD, '2026-11-08', 540, 60));
        $this->assertNull($availability->occupant(self::FIELD, '2026-11-08', 600, 60));
        $this->assertSame($short, $availability->occupant(self::FIELD, '2026-11-08', 660, 45));
        $this->assertNull($availability->occupant(self::FIELD, '2026-11-08', 690, 45));
    }

    public function test_matches_of_another_date_or_field_do_not_take_the_slot(): void
    {
        $availability = $this->availability(
            $this->match('2026-11-07 08:00:00', 60),
            $this->match('2026-11-08 08:00:00', 60, ['field_id' => 2]),
        );

        $this->assertFalse($availability->isTaken(self::FIELD, '2026-11-08', 480, 60));
        $this->assertTrue($availability->isTaken(self::FIELD, '2026-11-07', 480, 60));
        $this->assertTrue($availability->isTaken(2, '2026-11-08', 480, 60));
    }

    public function test_cancelled_and_untimed_matches_are_ignored(): void
    {
        $availability = $this->availability(
            $this->match('2026-11-08 08:00:00', 60, ['status' => MatchStatus::CANCELLED]),
            $this->match('2026-11-08 08:00:00', 60, ['match_datetime' => null]),
        );

        $this->assertFalse($availability->isTaken(self::FIELD, '2026-11-08', 480, 60));
    }

    public function test_played_and_locked_matches_take_the_slot(): void
    {
        $played = $this->match('2026-11-08 08:00:00', 60, ['status' => MatchStatus::PLAYED]);
        $locked = $this->match('2026-11-08 09:00:00', 60, ['schedule_locked' => true]);
        $availability = $this->availability($played, $locked);

        $this->assertSame($played, $availability->occupant(self::FIELD, '2026-11-08', 480, 60));
        $this->assertSame($locked, $availability->occupant(self::FIELD, '2026-11-08', 540, 60));
    }

    public function test_filters_return_copies_without_the_given_matches_or_tournament(): void
    {
        $own = $this->match('2026-11-08 08:00:00', 60, [], 10);
        $other = $this->match('2026-11-08 09:00:00', 60, [], 20);
        $availability = $this->availability($own, $other);

        $this->assertFalse($availability->withoutMatches([$own->id])->isTaken(self::FIELD, '2026-11-08', 480, 60));
        $this->assertFalse($availability->withoutTournament(10)->isTaken(self::FIELD, '2026-11-08', 480, 60));
        $this->assertTrue($availability->withoutTournament(10)->isTaken(self::FIELD, '2026-11-08', 540, 60));
        // El original no cambia.
        $this->assertCount(2, $availability->matches());
    }

    public function test_describe_returns_the_data_of_the_occupant(): void
    {
        $this->assertSame([
            'date' => '2026-11-08',
            'field_id' => self::FIELD,
            'field_name' => 'Cancha 1',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'tournament_id' => 10,
            'tournament_name' => 'Torneo 10',
        ], FieldAvailability::describe($this->match('2026-11-08 08:00:00', 90)));
    }
}
