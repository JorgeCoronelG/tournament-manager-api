<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Calendario de emparejamientos de la fase regular (sin fechas ni horarios).
 *
 * @extends BaseServiceInterface<GameMatch>
 */
interface MatchServiceInterface extends BaseServiceInterface
{
    /**
     * Genera (o regenera) los partidos de fase regular: todos contra todos por grupo. Solo con el torneo en curso y
     * sin partidos jugados.
     *
     * @return array{groups: list<array{group_id: int, group_name: string, teams: int, matchdays: int, matches: int}>, total_matches: int}
     *
     * @throws CustomErrorException
     */
    public function generate(Tournament $tournament, bool $shuffle): array;

    /**
     * Borra todos los partidos de fase regular. Solo con el torneo en curso y sin partidos jugados.
     *
     * @throws CustomErrorException
     */
    public function deleteAll(Tournament $tournament): void;

    /**
     * @param  array{group_id?: int, matchday?: int, status?: string, round_number?: int, phase?: string}  $filters
     * @return LengthAwarePaginator<int, GameMatch>
     */
    public function paginateByTournament(Tournament $tournament, ListQuery $query, array $filters): LengthAwarePaginator;

    /**
     * Calendario por grupo y jornada con los equipos que descansan.
     *
     * @return array{generated: bool, groups: list<array{group_id: int, group_name: string, matchdays: list<array{matchday: int, round_number: int, matches: list<GameMatch>, resting: list<array{registration_id: int, team_name: string}>}>}>}
     */
    public function schedule(Tournament $tournament): array;
}
