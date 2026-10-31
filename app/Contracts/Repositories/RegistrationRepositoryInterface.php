<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\RegistrationStatus;
use App\Models\Registration;
use App\Models\RegistrationTeamChange;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Registration>
 */
interface RegistrationRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Inscripciones no eliminadas del torneo, con su equipo y el delegado. `search` busca en el nombre
     * del equipo y en el nombre del delegado.
     *
     * @return LengthAwarePaginator<int, Registration>
     */
    public function paginateByTournament(int $tournamentId, ListQuery $query, ?string $search, ?RegistrationStatus $status): LengthAwarePaginator;

    /**
     * Equipos de la liga, vetados incluidos (el front los deshabilita), no eliminados, que aún no tienen inscripción en el torneo. `search`
     * busca en el nombre del equipo y en el nombre del delegado.
     *
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginateAvailableTeams(int $leagueId, int $tournamentId, ListQuery $query, ?string $search): LengthAwarePaginator;

    /**
     * Bloquea la fila del torneo hasta que termine la transacción, para que dos altas simultáneas no se crucen.
     */
    public function lockTournament(int $tournamentId): Tournament;

    /**
     * Equipos no eliminados de la liga entre los ids indicados, indexados por id.
     *
     * @param  list<int>  $teamIds
     * @return Collection<int, Team>
     */
    public function findTeamsInLeague(int $leagueId, array $teamIds): Collection;

    /**
     * Ids, entre los indicados, de equipos que ya tienen una inscripción no eliminada en el torneo.
     *
     * @param  list<int>  $teamIds
     * @return list<int>
     */
    public function registeredTeamIds(int $tournamentId, array $teamIds): array;

    /**
     * Nombres de los equipos con inscripción activa en el torneo.
     *
     * @return list<string>
     */
    public function activeTeamNames(int $tournamentId): array;

    /**
     * Inscribe el equipo, activo. Si hubo una inscripción eliminada de ese equipo en el torneo la restaura,
     * porque (team_id, tournament_id) es único aunque la fila esté eliminada.
     */
    public function register(int $tournamentId, int $teamId): Registration;

    /**
     * Inscripciones activas del torneo que aún no tienen grupo.
     */
    public function countActiveWithoutGroup(int $tournamentId): int;

    /**
     * Si el grupo es de fase regular y pertenece al torneo.
     */
    public function isRegularGroupOfTournament(int $groupId, int $tournamentId): bool;

    /**
     * Asigna el grupo a la inscripción; null la deja sin grupo.
     */
    public function assignGroup(Registration $registration, ?int $groupId): Registration;

    /**
     * Inscripciones activas del torneo, sin importar el estado del equipo.
     */
    public function countActiveByTournament(int $tournamentId): int;

    /**
     * Inscripciones activas del torneo cuyo equipo está vetado: conservan su lugar pero no participan.
     */
    public function countBannedTeamsByTournament(int $tournamentId): int;

    /**
     * Si el equipo tiene alguna inscripción activa, en cualquier torneo.
     */
    public function teamHasActiveRegistrations(int $teamId): bool;

    /**
     * Si el equipo tiene alguna inscripción en el torneo, incluso dada de baja o eliminada: (team_id, tournament_id)
     * es único aunque la fila no esté activa.
     */
    public function teamHasAnyRegistration(int $tournamentId, int $teamId): bool;

    /**
     * Da de baja la inscripción: status withdrawn con cuándo, motivo y quién.
     */
    public function markWithdrawn(Registration $registration, string $reason, int $userId): Registration;

    /**
     * Cambia el equipo de la inscripción (misma fila), la deja activa (quita la baja) y guarda el cambio en el historial.
     */
    public function replaceTeam(Registration $registration, int $newTeamId, string $reason, int $userId): Registration;

    /**
     * Reemplazos de equipo de la inscripción, del más reciente al más antiguo, con los equipos y quien lo hizo.
     *
     * @return Collection<int, RegistrationTeamChange>
     */
    public function teamChanges(int $registrationId): Collection;

    /**
     * Baja lógica de todas las inscripciones del torneo.
     */
    public function deleteByTournament(int $tournamentId): void;
}
