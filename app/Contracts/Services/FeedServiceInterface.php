<?php

namespace App\Contracts\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Inicio y Tabla de la app móvil, para todos los roles. Se ven los torneos de las ligas con las que el usuario
 * tiene relación (jugador, delegado, árbitro o encargado); si no tiene ninguna, los de todas las ligas activas.
 */
interface FeedServiceInterface
{
    public const RESULTS_PAGE_SIZE = 10;

    public const UPCOMING_DAYS = 7;

    /**
     * Portada: partidos de hoy, próximos 7 días, primera página de resultados, torneos para filtrar y las
     * inscripciones del usuario.
     *
     * @return array{today: Collection<int, GameMatch>, upcoming: Collection<int, GameMatch>, results: array{data: Collection<int, GameMatch>, next_cursor: string|null}, tournaments: Collection<int, Tournament>, my_registration_ids: list<int>}
     */
    public function home(User $user, ?int $tournamentId, bool $mine): array;

    /**
     * Resultados anteriores a hoy, del más reciente al más viejo. El cursor es la posición en la lista.
     *
     * @return array{data: Collection<int, GameMatch>, next_cursor: string|null}
     */
    public function results(User $user, ?int $tournamentId, bool $mine, int $cursor): array;

    /**
     * Detalle público de un partido: eventos y jugadores registrados. 404 si el torneo no es visible.
     *
     * @return array{match: GameMatch, events: list<array<string, mixed>>, lineups: list<array<string, mixed>>}
     */
    public function match(User $user, GameMatch $match): array;

    /**
     * Tabla por grupo con la racha, goleadores y tarjetas del torneo. 404 si el torneo no es visible.
     *
     * @return array<string, mixed>
     */
    public function stats(User $user, Tournament $tournament): array;
}
