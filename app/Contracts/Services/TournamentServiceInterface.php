<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Data\Tournaments\TournamentData;
use App\Models\League;
use App\Models\Tournament;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<Tournament>
 */
interface TournamentServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Tournament>
     */
    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?TournamentGender $gender,
        ?TournamentStatus $status
    ): LengthAwarePaginator;

    public function createInLeague(League $league, TournamentData $data): Tournament;

    /**
     * Planeación: todos los campos. En curso: solo el nombre. Finalizado: 422.
     */
    public function updateTournament(Tournament $tournament, TournamentData $data): Tournament;

    /**
     * Solo avanza un paso: planeación → en curso → finalizado; cualquier otra transición: 422.
     */
    public function changeStatus(Tournament $tournament, TournamentStatus $status): Tournament;

    /**
     * Baja lógica, solo en planeación (422 en otro estado).
     */
    public function deleteTournament(Tournament $tournament): void;
}
