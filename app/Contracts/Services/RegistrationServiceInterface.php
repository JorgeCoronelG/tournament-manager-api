<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\RegistrationStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<Registration>
 */
interface RegistrationServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Registration>
     */
    public function paginateByTournament(Tournament $tournament, ListQuery $query, ?string $search, ?RegistrationStatus $status): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginateAvailableTeams(Tournament $tournament, ListQuery $query, ?string $search): LengthAwarePaginator;

    /**
     * Total de equipos inscritos (inscripciones activas) en el torneo y cuántos de ellos están vetados,
     * sin importar búsqueda ni filtros del listado.
     *
     * @return array{registered: int, banned: int}
     */
    public function countsByTournament(Tournament $tournament): array;

    /**
     * Regla para el módulo de grupos y calendario: no se puede generar el calendario con equipos vetados
     * inscritos (422). Aún no la llama nadie.
     */
    public function assertNoBannedTeams(Tournament $tournament): void;

    /**
     * Inscribe todos los equipos o ninguno. Solo con el torneo en planeación (422 si no). Cada equipo debe ser
     * de la liga, no estar eliminado ni vetado, ni inscrito ya, y su nombre no repetirse entre las inscripciones
     * activas del torneo (incluidos los demás equipos de la misma petición). Los errores salen como 422 por
     * `team_ids.{posición}`, todos juntos.
     *
     * @param  list<int>  $teamIds
     * @return Collection<int, Registration>
     */
    public function registerTeams(Tournament $tournament, array $teamIds): Collection;

    /**
     * Baja lógica de la inscripción, solo con el torneo en planeación (422 si no).
     */
    /**
     * Asigna el grupo de fase regular a la inscripción; debe ser del mismo torneo.
     *
     * @throws CustomErrorException
     */
    public function assignGroup(Registration $registration, int $groupId): Registration;

    public function removeRegistration(Tournament $tournament, Registration $registration): void;
}
