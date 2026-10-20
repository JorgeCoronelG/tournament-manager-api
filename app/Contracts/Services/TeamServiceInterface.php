<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\TeamStatus;
use App\Data\Teams\CreateTeamData;
use App\Data\Teams\ManagerInputData;
use App\Data\Teams\ManagerProfileData;
use App\Data\Teams\TeamData;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<Team>
 */
interface TeamServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?TeamStatus $status,
        ?bool $hasManager,
        ?int $managerUserId = null
    ): LengthAwarePaginator;

    /**
     * Delegados de los equipos de la liga (sin repetir), para el filtro del listado.
     *
     * @return Collection<int, User>
     */
    public function managersByLeague(League $league): Collection;

    /**
     * En una transacción: crea el equipo y, si se indicó, resuelve el delegado (existente por
     * clave o nuevo con invitación).
     */
    public function createTeam(League $league, CreateTeamData $data): Team;

    /**
     * Solo cambia el nombre; el estado, el delegado y el logo tienen sus propios métodos.
     */
    public function updateTeam(Team $team, TeamData $data): Team;

    public function changeStatus(Team $team, TeamStatus $status): Team;

    /**
     * Baja lógica; el archivo del logo se conserva.
     */
    public function deleteTeam(Team $team): void;

    /**
     * Datos mínimos de un delegado activo por su user_code; 404 genérico si no es elegible.
     */
    public function lookupManager(string $userCode): User;

    /**
     * Asigna o cambia el delegado sin tocar la cuenta ni los roles del anterior.
     */
    public function assignManager(Team $team, ManagerInputData $input): Team;

    /**
     * Solo con cuenta pendiente y delegado únicamente de equipos de esta liga; si no, 422.
     */
    public function updateManager(Team $team, ManagerProfileData $data): Team;

    public function removeManager(Team $team): Team;

    /**
     * Solo si la cuenta del delegado está pendiente; si no, 422.
     */
    public function resendManagerInvitation(Team $team): Team;

    /**
     * Procesa y guarda el logo; si ya había uno, borra el archivo anterior.
     */
    public function setLogo(Team $team, UploadedFile $file): Team;

    public function removeLogo(Team $team): Team;
}
