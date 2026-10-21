<?php

namespace App\Services;

use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Repositories\TeamRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Services\LogoProcessorInterface;
use App\Contracts\Services\TeamServiceInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TeamStatus;
use App\Data\Teams\CreateTeamData;
use App\Data\Teams\ManagerInputData;
use App\Data\Teams\ManagerProfileData;
use App\Data\Teams\TeamData;
use App\Data\Users\CreateUserData;
use App\Exceptions\CustomErrorException;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ManagerAssignedToTeamNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<Team>
 */
class TeamService extends BaseService implements TeamServiceInterface
{
    private const LOGO_DIRECTORY = 'teams/logos';

    public function __construct(
        protected TeamRepositoryInterface $teamRepository,
        protected RegistrationRepositoryInterface $registrationRepository,
        protected UserRepositoryInterface $userRepository,
        protected UserServiceInterface $userService,
        protected LogoProcessorInterface $logoProcessor,
    ) {
        parent::__construct($teamRepository);
    }

    public function paginateByLeague(
        League $league,
        ListQuery $query,
        ?string $search,
        ?TeamStatus $status,
        ?bool $hasManager,
        ?int $managerUserId = null
    ): LengthAwarePaginator {
        return $this->teamRepository->paginateByLeague($league->id, $query, $search, $status, $hasManager, $managerUserId);
    }

    public function managersByLeague(League $league): Collection
    {
        return $this->teamRepository->managersByLeague($league->id);
    }

    public function createTeam(League $league, CreateTeamData $data): Team
    {
        $notify = null;

        $team = DB::transaction(function () use ($league, $data, &$notify) {
            $manager = $data->manager !== null ? $this->resolveManager($data->manager) : null;
            $notify = $manager !== null && $manager['notify'] ? $manager['user'] : null;

            // Un delegado recién creado no tiene equipos: no puede haber conflicto.
            if ($manager === null || ! $manager['created']) {
                $this->assertNameAvailable($league->id, $data->name, $manager === null ? null : $manager['user']->id, null, true);
            }

            return $this->teamRepository->createInLeague(
                $league->id,
                ['name' => $data->name],
                $manager === null ? null : $manager['user']->id
            );
        });

        // Fuera de la transacción: si falla el alta no se avisa a nadie.
        $this->notifyAssigned($notify, $team);

        return $team->load('manager');
    }

    public function updateTeam(Team $team, TeamData $data): Team
    {
        $team = DB::transaction(function () use ($team, $data) {
            $this->assertNameAvailable($team->league_id, $data->name, $team->manager_user_id, $team->id, true);

            return $this->teamRepository->updateModel($team, ['name' => $data->name]);
        });

        return $team->load('manager');
    }

    public function changeStatus(Team $team, TeamStatus $status): Team
    {
        return $this->teamRepository->updateStatus($team, $status)->load('manager');
    }

    public function deleteTeam(Team $team): void
    {
        if ($this->registrationRepository->teamHasActiveRegistrations($team->id)) {
            throw new CustomErrorException(Message::TEAM_HAS_ACTIVE_REGISTRATIONS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->delete($team->id);
    }

    public function lookupManager(string $userCode): User
    {
        return $this->findEligibleManagerOrFail($userCode);
    }

    public function assignManager(Team $team, ManagerInputData $input): Team
    {
        $previousId = $team->manager_user_id;

        $notify = null;
        $team = DB::transaction(function () use ($team, $input, &$notify) {
            $manager = $this->resolveManager($input);
            $notify = $manager['notify'] ? $manager['user'] : null;

            if (! $manager['created']) {
                $this->assertNameAvailable($team->league_id, $team->name, $manager['user']->id, $team->id, false);
            }

            return $this->teamRepository->setManager($team, $manager['user']->id);
        });

        // Si ya era el delegado no hay nada nuevo que avisar.
        if ($notify !== null && $notify->id !== $previousId) {
            $this->notifyAssigned($notify, $team);
        }

        return $team->load('manager');
    }

    public function updateManager(Team $team, ManagerProfileData $data): Team
    {
        $user = $this->managerOrFail($team);

        $isPending = $user->email_verified_at === null;
        $onlyHere = $this->teamRepository->countManagedInOtherLeagues($user->id, $team->league_id) === 0;

        if (! $isPending || ! $onlyHere) {
            throw new CustomErrorException(Message::TEAM_MANAGER_EDIT_NOT_ALLOWED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->userService->updateProfile($user, $data->first_name, $data->last_name, $data->email, $data->phone);

        return $team->unsetRelation('manager')->load('manager');
    }

    public function removeManager(Team $team): Team
    {
        $team = DB::transaction(function () use ($team) {
            $this->assertNameAvailable($team->league_id, $team->name, null, $team->id, false);

            return $this->teamRepository->setManager($team, null);
        });

        return $team->load('manager');
    }

    public function resendManagerInvitation(Team $team): Team
    {
        $user = $this->managerOrFail($team);

        $this->userService->resendInvitation($user->id);

        return $team->load('manager');
    }

    public function setLogo(Team $team, UploadedFile $file): Team
    {
        $contents = $this->logoProcessor->process($file);
        $disk = Storage::disk('public');
        $path = self::LOGO_DIRECTORY.'/'.Str::uuid().'.webp';
        $previous = $team->logo_path;

        $disk->put($path, $contents);

        try {
            $this->teamRepository->setLogoPath($team, $path);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $team->load('manager');
    }

    public function removeLogo(Team $team): Team
    {
        $previous = $team->logo_path;

        if ($previous !== null) {
            $this->teamRepository->setLogoPath($team, null);
            Storage::disk('public')->delete($previous);
        }

        return $team->load('manager');
    }

    /**
     * @return array{user: User, notify: bool, created: bool} `created`: el usuario se creó ahora. `notify`: solo una cuenta ya activada recibe el
     *                                                        aviso de asignación; la pendiente recibe su invitación.
     */
    private function resolveManager(ManagerInputData $input): array
    {
        if ($input->user_code !== null) {
            $user = $this->findEligibleManagerOrFail($input->user_code);

            return ['user' => $user, 'notify' => $user->email_verified_at !== null, 'created' => false];
        }

        $profile = $input->profile;

        if ($profile === null) {
            throw new CustomErrorException(Message::TEAM_MANAGER_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // El rol es siempre manager: nunca sale del body.
        $user = $this->userService->create(new CreateUserData(
            $profile->first_name,
            $profile->last_name,
            $profile->email,
            $profile->phone,
            [RoleEnum::MANAGER->value],
        ));

        return ['user' => $user, 'notify' => false, 'created' => true];
    }

    /**
     * El nombre es único por liga y delegado (`null`: sin delegado). Con `$asField` el error es de
     * validación del campo `name`; si no (acciones sobre el delegado), un error general 422.
     *
     * @throws CustomErrorException
     * @throws ValidationException
     */
    private function assertNameAvailable(int $leagueId, string $name, ?int $managerUserId, ?int $exceptTeamId, bool $asField): void
    {
        if (! $this->teamRepository->existsWithNameAndManager($leagueId, $name, $managerUserId, $exceptTeamId)) {
            return;
        }

        $message = $managerUserId === null ? Message::TEAM_NAME_TAKEN_WITHOUT_MANAGER : Message::TEAM_NAME_TAKEN_BY_MANAGER;

        throw $asField
            ? ValidationException::withMessages(['name' => $message])
            : new CustomErrorException($message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function notifyAssigned(?User $user, Team $team): void
    {
        $user?->notify(new ManagerAssignedToTeamNotification($team->loadMissing('league')));
    }

    /**
     * @throws CustomErrorException
     */
    private function managerOrFail(Team $team): User
    {
        return $team->manager
            ?? throw new CustomErrorException(Message::TEAM_HAS_NO_MANAGER, Response::HTTP_NOT_FOUND);
    }

    /**
     * Mismo 404 genérico para clave inexistente, sin rol manager o desactivado: no revela qué claves existen.
     *
     * @throws CustomErrorException
     */
    private function findEligibleManagerOrFail(string $userCode): User
    {
        return $this->userRepository->findEligibleByRole($userCode, RoleEnum::MANAGER)
            ?? throw new CustomErrorException(Message::MODEL_NOT_FOUND_EXCEPTION, Response::HTTP_NOT_FOUND);
    }
}
