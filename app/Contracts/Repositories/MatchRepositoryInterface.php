<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\MatchStatus;
use App\Models\GameMatch;
use App\Models\Registration;
use Carbon\CarbonInterface;
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
     * Guarda el resultado y deja el partido jugado; con null en ambos marcadores lo anula y vuelve a programado.
     */
    public function setResult(GameMatch $match, ?int $homeScore, ?int $awayScore, ?int $homePenalties = null, ?int $awayPenalties = null): GameMatch;

    /**
     * Fecha del último partido de fase regular con fecha y hora; null si ninguno la tiene.
     */
    public function lastRegularDate(int $tournamentId): ?string;

    /**
     * Partidos jugados de fase regular del grupo.
     *
     * @return Collection<int, GameMatch>
     */
    public function playedOfGroup(int $groupId): Collection;

    /**
     * Inscripciones activas del grupo (con equipo), por id; incluye equipos vetados, que conservan su inscripción.
     *
     * @return Collection<int, Registration>
     */
    public function activeRegistrationsOfGroup(int $groupId): Collection;

    /**
     * Orden manual guardado del grupo: registration_id => posición.
     *
     * @return array<int, int>
     */
    public function manualOrder(int $groupId): array;

    /**
     * Reemplaza el orden manual de esas inscripciones en el grupo (posición 1..n según el orden recibido).
     *
     * @param  list<int>  $registrationIds
     */
    public function saveManualOrder(int $tournamentId, int $groupId, array $registrationIds): void;

    public function clearManualOrder(int $groupId): void;

    /**
     * Partidos de fase regular del torneo ordenados por jornada y por id, con grupo y equipos.
     *
     * @return Collection<int, GameMatch>
     */
    public function regularMatches(int $tournamentId): Collection;

    /**
     * Asigna fecha, hora y cancha: id del partido => [match_datetime (Y-m-d H:i:s) o null, field_id o null, si se
     * reinician también árbitro y horario original].
     *
     * @param  array<int, array{0: string|null, 1: int|null, 2: bool}>  $assignments
     */
    public function assignSchedule(array $assignments): void;

    /**
     * Partidos programados de la liga (todos los torneos, sin cancelados) con hora de inicio entre $from y $to, con
     * torneo, equipos y cancha.
     *
     * @param  list<int>  $excludeIds
     * @return Collection<int, GameMatch>
     */
    public function scheduledInLeague(int $leagueId, CarbonInterface $from, CarbonInterface $to, array $excludeIds = []): Collection;

    /**
     * Guarda columnas de horario de un partido (match_datetime, field_id, referee_user_id, schedule_locked,
     * original_match_datetime).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveSchedule(GameMatch $match, array $attributes): GameMatch;

    /**
     * Partidos de liguilla del torneo con liguilla, equipos y cancha, por fecha programada, ronda, posición e ida/vuelta.
     *
     * @return Collection<int, GameMatch>
     */
    public function playoffMatches(int $tournamentId): Collection;

    /**
     * Partidos con hora de la liga entre dos fechas (inclusive), con torneo, grupo, equipos, cancha y árbitro.
     *
     * @param  array{tournament_id?: int, field_id?: int, referee_user_id?: int, unassigned_referee?: bool}  $filters
     * @return Collection<int, GameMatch>
     */
    public function agenda(int $leagueId, string $dateFrom, string $dateTo, array $filters): Collection;

    /**
     * Si el árbitro tiene partidos no eliminados con el estado indicado en torneos no eliminados de la liga.
     */
    public function hasMatchesWithRefereeInLeague(int $leagueId, int $userId, MatchStatus $status): bool;

    /**
     * Partidos sin hora (no jugados ni cancelados) de la liga, o de un torneo.
     */
    public function countUnscheduled(int $leagueId, ?int $tournamentId): int;
}
