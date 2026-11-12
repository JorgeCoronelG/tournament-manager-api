<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Tournament;

/**
 * Asigna fecha, hora y cancha a los partidos de fase regular según la configuración de horarios del torneo.
 * Las jornadas van, en orden, a las semanas con espacios (la misma regla de capacity).
 */
interface ScheduleGeneratorServiceInterface
{
    public const MODE_BALANCED = 'balanced';

    public const MODE_RANDOM = 'random';

    public const SCOPE_ALL = 'all';

    public const SCOPE_UNSCHEDULED = 'unscheduled';

    /**
     * Calcula el horario sin guardarlo. Cada jornada con partidos sin ubicar trae en `occupied_by_others` los partidos
     * de otros torneos de la liga que ocupan sus espacios.
     *
     * @return array<string, mixed> {scheduled, unscheduled, locked_kept, played_kept, assigned_kept, matchdays, warnings, matches}
     *
     * @throws CustomErrorException
     */
    public function preview(Tournament $tournament, string $mode, string $scope, ?int $seed): array;

    /**
     * Calcula y guarda el horario en una sola transacción. Antes de guardar se vuelven a leer los partidos de la liga
     * y se revalida cada asignación: la que ya choca por un cambio concurrente no se guarda (el partido queda sin
     * horario) y se agrega un aviso con `code` = "slot_taken", `match` y `occupied_by`. El resto se guarda normal.
     *
     * @return array<string, mixed> Igual que preview().
     *
     * @throws CustomErrorException
     */
    public function generate(Tournament $tournament, string $mode, string $scope, ?int $seed): array;

    /**
     * Quita fecha, hora y cancha de los partidos no jugados ni bloqueados.
     *
     * @return array{cleared: int}
     *
     * @throws CustomErrorException
     */
    public function clear(Tournament $tournament): array;
}
