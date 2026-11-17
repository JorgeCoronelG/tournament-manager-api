<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Tournament;

/**
 * Tabla de posiciones de la fase regular y orden manual de los empates que persisten.
 */
interface StandingServiceInterface
{
    /**
     * Tabla de cada grupo regular (o solo la del grupo indicado). Con el torneo en curso, en liguilla o terminado.
     *
     * `complete`: todos los partidos de la fase regular del grupo tienen resultado (mismo criterio que el cierre de fase).
     * `ties_pending`: `complete` y quedan empates (`ties`) sin orden manual; antes de terminar el grupo el empate aún puede resolverse solo.
     *
     * `has_manual_order`: el grupo tiene un orden manual guardado.
     *
     * @return list<array{group_id: int, group_name: string, criteria: list<string>, rows: list<array<string, mixed>>, ties: list<list<int>>, complete: bool, ties_pending: bool, has_manual_order: bool}>
     *
     * @throws CustomErrorException
     */
    public function standings(Tournament $tournament, ?int $groupId): array;

    /**
     * Guarda el orden manual de un conjunto empatado sin resolver. Solo con el torneo en curso.
     *
     * @param  list<int>  $registrationIds  del primero al último
     *
     * @throws CustomErrorException
     */
    public function saveManualOrder(Tournament $tournament, int $groupId, array $registrationIds): void;

    /**
     * Borra el orden manual del grupo. Solo con el torneo en curso.
     *
     * @throws CustomErrorException
     */
    public function clearManualOrder(Tournament $tournament, int $groupId): void;
}
