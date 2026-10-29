<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\TournamentGender;
use App\Core\Enum\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<Tournament>
 */
interface TournamentRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Torneos no eliminados de una liga. `search` filtra por nombre.
     *
     * @return LengthAwarePaginator<int, Tournament>
     */
    public function paginateByLeague(
        int $leagueId,
        ListQuery $query,
        ?string $search,
        ?TournamentGender $gender,
        ?TournamentStatus $status
    ): LengthAwarePaginator;

    /**
     * Torneos no eliminados de la liga cuyo estado es distinto del indicado.
     */
    public function countByLeagueExceptStatus(int $leagueId, TournamentStatus $status): int;

    /**
     * Crea el torneo en la liga indicada, siempre en planeación.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInLeague(int $leagueId, array $data): Tournament;

    /**
     * Crea el grupo único de fase regular ("Fase regular") con el que nace todo torneo.
     */
    public function createRegularStageGroup(Tournament $tournament): TournamentGroup;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateModel(Tournament $tournament, array $data): Tournament;

    public function updateStatus(Tournament $tournament, TournamentStatus $status): Tournament;
}
