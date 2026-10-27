<?php

namespace App\Contracts\Repositories;

use App\Models\LeagueReferee;
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
     * Partidos de fase regular no jugados con hora asignada: fecha (Y-m-d), hora de inicio (minutos), cancha y si está fijado.
     *
     * @return list<array{date: string, start: int, field_id: int|null, locked: bool}>
     */
    public function timedMatches(int $tournamentId): array;

    /**
     * Partidos de fase regular por jornada (todos los grupos juntos): jornada => partidos.
     *
     * @return array<int, int>
     */
    public function matchesPerMatchday(int $tournamentId): array;

    /**
     * Árbitros activos de la liga (con su usuario), por nombre.
     *
     * @return Collection<int, LeagueReferee>
     */
    public function activeReferees(int $leagueId): Collection;

    /**
     * Si el usuario es árbitro activo de la liga.
     */
    public function isActiveReferee(int $leagueId, int $userId): bool;

    /**
     * Si el usuario juega (plantilla activa) en alguna de las inscripciones.
     *
     * @param  list<int>  $registrationIds
     */
    public function playsInRegistrations(int $userId, array $registrationIds): bool;
}
