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
     * Guarda (o corrige) el resultado de un partido y lo deja jugado. Fase regular: torneo en curso y sin penales.
     * Liguilla: torneo en liguilla, con las reglas de penales y avance del cruce.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>|null, advanced_to: array<string, mixed>|null}
     *
     * @throws CustomErrorException
     */
    public function recordResult(Tournament $tournament, GameMatch $match, int $homeScore, int $awayScore, ?int $homePenalties = null, ?int $awayPenalties = null): array;

    /**
     * Victoria por default del rival de `$loserRegistrationId`: marcador del torneo (walkover_goals) a favor del rival y
     * 0 en contra, sin penales, conservando fecha, cancha y árbitro. En liguilla aplica a todos los partidos
     * pendientes del cruce, lo decide a favor del rival (decided_by walkover) y lo avanza. Idempotente con el mismo
     * perdedor; otro perdedor, un partido ya jugado o cancelado, o un partido sin equipos definidos se rechazan.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>|null, advanced_to: array<string, mixed>|null}
     *
     * @throws CustomErrorException
     */
    public function registerWalkover(Tournament $tournament, GameMatch $match, int $loserRegistrationId, ?string $reason, ?int $userId): array;

    /**
     * Anula el resultado: marcadores nulos y partido programado. Mismas restricciones que recordResult.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>|null, advanced_to: array<string, mixed>|null}
     *
     * @throws CustomErrorException
     */
    public function clearResult(Tournament $tournament, GameMatch $match): array;

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
