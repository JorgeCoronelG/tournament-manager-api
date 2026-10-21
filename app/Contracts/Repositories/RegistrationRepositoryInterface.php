<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\RegistrationStatus;
use App\Models\Registration;
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
     * Inscripciones activas del torneo.
     */
    public function countActiveByTournament(int $tournamentId): int;

    /**
     * Si el equipo tiene alguna inscripción activa, en cualquier torneo.
     */
    public function teamHasActiveRegistrations(int $teamId): bool;

    /**
     * Baja lógica de todas las inscripciones del torneo.
     */
    public function deleteByTournament(int $tournamentId): void;
}
