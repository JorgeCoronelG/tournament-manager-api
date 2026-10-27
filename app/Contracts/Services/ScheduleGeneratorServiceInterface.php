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
     * Calcula el horario sin guardarlo.
     *
     * @return array<string, mixed> {scheduled, unscheduled, locked_kept, played_kept, assigned_kept, matchdays, warnings, matches}
     *
     * @throws CustomErrorException
     */
    public function preview(Tournament $tournament, string $mode, string $scope, ?int $seed): array;

    /**
     * Calcula y guarda el horario en una sola transacción.
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
