<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Models\GameMatch;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<GameMatch>
 */
interface MatchRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Inscripciones del grupo que juegan (activas y con equipo no vetado), por id, con su equipo.
     *
     * @return Collection<int, Registration>
     */
    public function playingRegistrations(int $groupId): Collection;

    public function hasPlayedRegular(int $tournamentId): bool;

    /**
     * Borra (soft delete) todos los partidos de fase regular del torneo.
     */
    public function deleteRegular(int $tournamentId): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertMany(array $rows): void;

    /**
     * @param  array{group_id?: int, matchday?: int, status?: string, round_number?: int, phase?: string}  $filters
     * @return LengthAwarePaginator<int, GameMatch>
     */
    public function paginateByTournament(int $tournamentId, ListQuery $query, array $filters): LengthAwarePaginator;

    /**
     * Partidos de fase regular del torneo ordenados por jornada y por id, con grupo y equipos.
     *
     * @return Collection<int, GameMatch>
     */
    public function regularMatches(int $tournamentId): Collection;
}
