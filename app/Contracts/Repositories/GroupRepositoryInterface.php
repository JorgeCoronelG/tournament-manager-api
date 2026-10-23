<?php

namespace App\Contracts\Repositories;

use App\Core\Contracts\BaseRepositoryInterface;
use App\Models\Registration;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepositoryInterface<TournamentGroup>
 */
interface GroupRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Grupos del torneo, primero los regulares y por id, con `teams_count` (inscripciones activas) y sus fuentes.
     *
     * @return Collection<int, TournamentGroup>
     */
    public function listByTournament(int $tournamentId): Collection;

    /**
     * Inscripciones activas del torneo sin grupo, con equipo y delegado.
     *
     * @return Collection<int, Registration>
     */
    public function unassignedRegistrations(int $tournamentId): Collection;

    /**
     * Inscripciones activas asignadas al grupo, con equipo y delegado.
     *
     * @return Collection<int, Registration>
     */
    public function registrationsOfGroup(int $groupId): Collection;

    /**
     * Todas las inscripciones activas del torneo, por id.
     *
     * @return Collection<int, Registration>
     */
    public function activeRegistrations(int $tournamentId): Collection;

    /**
     * Grupos de fase regular del torneo, por id.
     *
     * @return Collection<int, TournamentGroup>
     */
    public function regularGroups(int $tournamentId): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createGroup(int $tournamentId, string $type, array $attributes): TournamentGroup;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateGroup(TournamentGroup $group, array $attributes): TournamentGroup;

    /**
     * Reemplaza por completo las fuentes de una liguilla.
     *
     * @param  list<array{source_group_id: int, position_start: int, position_end: int}>  $sources
     */
    public function replaceSources(TournamentGroup $group, array $sources): void;

    /**
     * Baja lógica del grupo y borrado de sus fuentes.
     */
    public function deleteGroup(TournamentGroup $group): void;

    /**
     * Fuentes de las demás liguillas del torneo (todas si `$exceptGroupId` es null).
     *
     * @return list<array{source_group_id: int, position_start: int, position_end: int}>
     */
    public function sourcesOfOtherPlayoffs(int $tournamentId, ?int $exceptGroupId): array;

    public function hasMatches(int $groupId): bool;

    /**
     * Equipos del grupo que juegan: inscripciones activas con equipo no vetado.
     */
    public function playingCount(int $groupId): int;

    public function isUsedAsSource(int $groupId): bool;

    public function countRegistrations(int $groupId): int;

    /**
     * Equipos activos por grupo: id del grupo => cantidad.
     *
     * @return array<int, int>
     */
    public function teamCounts(int $tournamentId): array;

    public function setRegistrationGroup(int $registrationId, ?int $groupId): void;

    /**
     * Asigna al grupo las inscripciones activas del torneo que no tienen grupo.
     */
    public function assignUnassignedTo(int $tournamentId, int $groupId): void;
}
