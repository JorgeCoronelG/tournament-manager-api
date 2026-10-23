<?php

namespace App\Contracts\Services;

use App\Core\Contracts\BaseServiceInterface;
use App\Exceptions\CustomErrorException;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Grupos de fase regular y liguillas de un torneo. Todo lo que modifica solo se permite con el torneo en planeación.
 *
 * @extends BaseServiceInterface<TournamentGroup>
 */
interface GroupServiceInterface extends BaseServiceInterface
{
    /**
     * Grupos del torneo (regulares primero) y las inscripciones activas sin grupo.
     *
     * @return array{groups: Collection<int, TournamentGroup>, unassigned: Collection<int, Registration>, finals_aligned: bool|null}
     */
    public function overview(Tournament $tournament): array;

    /**
     * El grupo con sus equipos asignados (relación `registrations` cargada).
     */
    public function detail(TournamentGroup $group): TournamentGroup;

    /**
     * Crea un grupo regular o una liguilla (con sus fuentes).
     *
     * @param  array<string, mixed>  $attributes  name, type y, para liguillas, start_date, round_interval_days y sources
     *
     * @throws CustomErrorException
     * @throws ValidationException
     */
    public function createGroup(Tournament $tournament, array $attributes): TournamentGroup;

    /**
     * @param  array<string, mixed>  $attributes  name y, para liguillas, start_date, round_interval_days y sources
     *
     * @throws CustomErrorException
     * @throws ValidationException
     */
    public function updateGroup(Tournament $tournament, TournamentGroup $group, array $attributes): TournamentGroup;

    /**
     * @throws CustomErrorException
     */
    public function deleteGroup(Tournament $tournament, TournamentGroup $group): void;

    /**
     * Mueve equipos entre grupos de forma atómica; `group_id` null deja la inscripción sin grupo.
     *
     * @param  list<array{registration_id: int, group_id: int|null}>  $assignments
     *
     * @throws CustomErrorException
     * @throws ValidationException
     */
    public function assign(Tournament $tournament, array $assignments): void;

    /**
     * Reparte al azar y de forma equilibrada todas las inscripciones activas entre los grupos regulares.
     *
     * @throws CustomErrorException
     */
    public function randomAssignment(Tournament $tournament): void;

    /**
     * Validaciones de grupos para iniciar el torneo.
     *
     * @throws CustomErrorException
     */
    public function assertReadyToStart(Tournament $tournament): void;

    /**
     * Si el torneo define partidos mínimos para liguilla, ningún grupo regular puede jugar menos: (equipos - 1) x vueltas.
     * Cuenta equipos activos y no vetados.
     *
     * @throws CustomErrorException
     */
    public function assertMinMatchesFit(Tournament $tournament, bool $atStart): void;
}
