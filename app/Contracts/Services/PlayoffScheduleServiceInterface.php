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
     * @param  array{windows: list<array{weekday: int, field_id: int, start_time: string, end_time: string}>, blocks: list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null}>}|null  $config  Ventanas y bloqueos sin guardar; null usa los guardados.
     * @return array{playoff_rounds: list<array<string, mixed>>, playoff_warnings: list<array<string, mixed>>}
     */
    public function capacity(Tournament $tournament, ?array $config = null): array;

    /**
     * Día siguiente al último partido de fase regular con fecha (Y-m-d); null si no hay.
     */
    public function minStartDate(Tournament $tournament): ?string;

    /**
     * Capacidad de la liguilla con ventanas y bloqueos sin guardar y las fechas elegidas por ronda.
     *
     * @param  array{windows: list<array{weekday: int, field_id: int, start_time: string, end_time: string}>, blocks: list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null}>}  $config
     * @param  list<array{group_id: int, round_dates: list<string>}>  $dates
     * @param  list<array<string, mixed>>  $playoffs  Propuesta de cierre (se usa solo si aún no hay partidos de liguilla).
     * @return array{playoff_rounds: list<array<string, mixed>>, playoff_warnings: list<array<string, mixed>>}
     */
    public function capacityWithDates(Tournament $tournament, array $config, array $dates, array $playoffs): array;

    /**
     * Avisos de capacidad para las fechas de una propuesta de cierre de fase regular (aún sin partidos).
     *
     * @param  list<array<string, mixed>>  $playoffs  La lista `playoffs` de PlayoffServiceInterface::preview().
     * @return list<array<string, mixed>>
     */
    public function proposalWarnings(Tournament $tournament, array $playoffs): array;
}
