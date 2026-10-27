<?php

namespace App\Contracts\Repositories;

use App\Models\Tournament;
use App\Models\TournamentScheduleBlock;
use App\Models\TournamentScheduleWindow;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ventanas de juego y bloqueos de horario de un torneo.
 */
interface ScheduleRepositoryInterface
{
    /**
     * Ventanas con su cancha, por día de la semana, cancha y hora.
     *
     * @return Collection<int, TournamentScheduleWindow>
     */
    public function windows(int $tournamentId): Collection;

    /**
     * Bloqueos con su cancha, por fecha y hora.
     *
     * @return Collection<int, TournamentScheduleBlock>
     */
    public function blocks(int $tournamentId): Collection;

    /**
     * Reemplaza fecha de inicio, ventanas y bloqueos en una sola operación.
     *
     * @param  list<array{weekday: int, field_id: int, start_time: string, end_time: string}>  $windows
     * @param  list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null, reason: string|null}>  $blocks
     */
    public function replace(Tournament $tournament, ?string $startDate, array $windows, array $blocks): void;

    /**
     * Cuántas de las canchas son de la liga y (si $onlyActive) están activas.
     *
     * @param  list<int>  $fieldIds  Distintos entre sí.
     */
    public function countLeagueFields(int $leagueId, array $fieldIds, bool $onlyActive): int;

    /**
     * Si algún partido de fase regular ya tiene hora asignada.
     */
    public function hasTimedMatches(int $tournamentId): bool;

    /**
     * Partidos de fase regular por jornada (todos los grupos juntos): jornada => partidos.
     *
     * @return array<int, int>
     */
    public function matchesPerMatchday(int $tournamentId): array;
}
