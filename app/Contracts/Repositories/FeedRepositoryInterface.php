<?php

namespace App\Contracts\Repositories;

use App\Models\GameMatch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Consultas de Inicio y Tabla de la app móvil (todos los roles): partidos, eventos y estadísticas de los torneos
 * que el usuario puede ver.
 */
interface FeedRepositoryInterface
{
    /**
     * Ligas activas con las que el usuario tiene relación: jugador con plantilla, delegado, árbitro activo o encargado.
     *
     * @return list<int>
     */
    public function relatedLeagueIds(User $user): array;

    /**
     * Ids de todas las ligas activas.
     *
     * @return list<int>
     */
    public function activeLeagueIds(): array;

    /**
     * Torneos visibles de esas ligas: en curso, en liguilla o terminados con algún partido desde `$finishedSince`.
     *
     * @param  list<int>  $leagueIds
     * @return Collection<int, \App\Models\Tournament>
     */
    public function visibleTournaments(array $leagueIds, CarbonInterface $finishedSince): Collection;

    /**
     * Inscripciones de esos torneos en las que el usuario juega (plantilla activa) o es el delegado.
     *
     * @param  list<int>  $tournamentIds
     * @return list<int>
     */
    public function myRegistrationIds(User $user, array $tournamentIds): array;

    /**
     * Partidos con ambos equipos, filtrados por torneos, por inscripciones (null = todas), por fecha y estado.
     *
     * @param  list<int>  $tournamentIds
     * @param  list<int>|null  $registrationIds
     * @param  list<string>  $statuses
     * @return Collection<int, GameMatch>
     */
    public function matches(
        array $tournamentIds,
        ?array $registrationIds,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        array $statuses,
        bool $newestFirst,
        int $offset = 0,
        ?int $limit = null,
    ): Collection;

    /**
     * Eventos no anulados del partido con el nombre y el dorsal del jugador, en orden de minuto.
     *
     * @return BaseCollection<int, object>
     */
    public function eventsOf(GameMatch $match): BaseCollection;

    /**
     * Jugadores que el árbitro registró (escaneo válido) en el partido, con nombre y dorsal.
     *
     * @return BaseCollection<int, object>
     */
    public function lineupsOf(GameMatch $match): BaseCollection;

    /**
     * Partidos jugados de la fase regular del torneo, del más viejo al más reciente.
     *
     * @return Collection<int, GameMatch>
     */
    public function playedRegularMatches(int $tournamentId): Collection;

    /**
     * Totales por jugador de los partidos jugados del torneo: goles, amarillas y rojas (sin anulados ni autogoles).
     *
     * @return BaseCollection<int, object>
     */
    public function playerTotals(int $tournamentId): BaseCollection;
}
