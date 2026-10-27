<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Tournament;

/**
 * Hora y cancha de los partidos de liguilla dentro de la fecha (scheduled_date) que ya tiene cada uno: nunca cambia
 * la fecha. Los espacios salen de las ventanas del torneo menos bloqueos y menos los partidos de la liga.
 */
interface PlayoffScheduleServiceInterface
{
    /**
     * Calcula el horario de liguilla sin guardarlo.
     *
     * @return array<string, mixed> {scheduled, unscheduled, locked_kept, played_kept, assigned_kept, matchdays, rounds, warnings, matches}
     *
     * @throws CustomErrorException
     */
    public function preview(Tournament $tournament, string $scope, bool $alignFinals): array;

    /**
     * Calcula y guarda en una sola transacción.
     *
     * @return array<string, mixed> Igual que preview().
     *
     * @throws CustomErrorException
     */
    public function generate(Tournament $tournament, string $scope, bool $alignFinals): array;

    /**
     * Quita fecha-hora, cancha y árbitro de los partidos de liguilla no jugados ni fijados (conserva scheduled_date).
     *
     * @return array{cleared: int}
     *
     * @throws CustomErrorException
     */
    public function clear(Tournament $tournament): array;

    /**
     * Partidos requeridos contra espacios por ronda y fecha de las liguillas del torneo.
     *
     * @return array{playoff_rounds: list<array<string, mixed>>, playoff_warnings: list<array<string, mixed>>}
     */
    public function capacity(Tournament $tournament): array;

    /**
     * Avisos de capacidad para las fechas de una propuesta de cierre de fase regular (aún sin partidos).
     *
     * @param  list<array<string, mixed>>  $playoffs  La lista `playoffs` de PlayoffServiceInterface::preview().
     * @return list<array<string, mixed>>
     */
    public function proposalWarnings(Tournament $tournament, array $playoffs): array;
}
