<?php

namespace App\Contracts\Repositories;

use Carbon\CarbonInterface;

/**
 * Cifras del panel. Todas ignoran registros eliminados. Sin `$leagueId` (global) solo cuentan ligas activas; con
 * `$leagueId` cuentan esa liga aunque esté inactiva (el superadmin la consulta de forma explícita).
 */
interface DashboardRepositoryInterface
{
    /** Ligas activas del sistema (siempre global). */
    public function activeLeagues(): int;

    /** Torneos en curso o en liguilla. */
    public function activeTournaments(?int $leagueId = null): int;

    /** Equipos distintos con inscripción activa en torneos activos, sin los vetados. */
    public function teamsInActiveTournaments(?int $leagueId = null): int;

    /** Jugadores distintos con plantilla activa en torneos activos. */
    public function playersInActiveTournaments(?int $leagueId = null): int;

    /** Partidos programados o jugados con fecha entre `$from` y `$to` (ambos incluidos). */
    public function matchesBetween(CarbonInterface $from, CarbonInterface $to, ?int $leagueId = null): int;

    /** Árbitros distintos activos en alguna liga activa. */
    public function activeReferees(?int $leagueId = null): int;
}
