<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\League;

/**
 * Agenda de la liga: partidos con horario de todos sus torneos por día, con filtros.
 */
interface AgendaServiceInterface
{
    /** Días máximos que cubre una consulta de agenda. */
    public const MAX_DAYS = 31;

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, tournament_id?: int, field_id?: int, referee_user_id?: int, unassigned_referee?: bool}  $filters
     * @return array{date_from: string, date_to: string, days: list<array<string, mixed>>, fields: list<array{id: int, name: string}>}
     *
     * @throws CustomErrorException Si el rango es mayor a 31 días o inválido.
     */
    public function agenda(League $league, array $filters): array;

    /**
     * Partidos sin horario de la liga o de un torneo.
     *
     * @return array{unscheduled: int}
     */
    public function unscheduled(League $league, ?int $tournamentId): array;
}
