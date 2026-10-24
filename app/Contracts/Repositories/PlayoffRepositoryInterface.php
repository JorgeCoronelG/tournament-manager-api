<?php

namespace App\Contracts\Repositories;

use App\Models\PlayoffTie;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cruces de liguilla y sus partidos.
 */
interface PlayoffRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTie(int $groupId, int $roundIndex, int $position, array $attributes): PlayoffTie;

    /**
     * Cruce con equipos, ganador y partidos cargados.
     */
    public function findTie(int $tieId): PlayoffTie;

    /**
     * Cruces de las liguillas con equipos, ganador y partidos, por liguilla, ronda y posición.
     *
     * @param  list<int>  $groupIds
     * @return Collection<int, PlayoffTie>
     */
    public function tiesOfGroups(array $groupIds): Collection;

    public function tieAt(int $groupId, int $roundIndex, int $position): ?PlayoffTie;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTie(PlayoffTie $tie, array $attributes): PlayoffTie;

    /**
     * Local y visitante (inscripciones) de un partido del cruce.
     */
    public function setMatchSides(int $matchId, ?int $homeRegistrationId, ?int $awayRegistrationId): void;

    /**
     * Orden manual guardado de los candidatos de una fuente de mejores: registration_id => orden.
     *
     * @return array<int, int>
     */
    public function bestManualOrder(int $sourceId): array;

    /**
     * Reemplaza el orden manual de la fuente (1..n según el orden recibido).
     *
     * @param  list<int>  $registrationIds
     */
    public function saveBestManualOrder(int $groupId, int $sourceId, array $registrationIds): void;

    public function clearBestManualOrder(int $sourceId): void;

    /**
     * Si algún cruce de las liguillas aún no tiene ganador.
     *
     * @param  list<int>  $groupIds
     */
    public function hasUndecidedTies(array $groupIds): bool;
}
