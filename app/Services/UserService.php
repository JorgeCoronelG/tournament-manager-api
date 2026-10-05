<?php

namespace App\Services;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Repositories\LeagueRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Core\BaseService;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\UserAccountStatus;
use App\Data\Users\CreateUserData;
use App\Data\Users\UpdateUserData;
use App\Exceptions\CustomErrorException;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * @extends BaseService<User>
 */
class UserService extends BaseService implements UserServiceInterface
{
    private const ACTIVATION_CODE_EXPIRES_IN_HOURS = 72;

    private const USER_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const USER_CODE_LENGTH = 8;

    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected PasswordResetRepositoryInterface $passwordResetRepository,
        protected AuthRepositoryInterface $authRepository,
        protected LeagueRepositoryInterface $leagueRepository,
    ) {
        parent::__construct($userRepository);
    }

    /**
     * @param  CreateUserData  $data
     */
    public function create(Data $data): User
    {
        /** @var User $user */
        $user = $this->userRepository->create([
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'email' => $data->email,
            'phone' => $data->phone,
            'user_code' => $this->generateUserCode(),
            'password' => Hash::make(Str::random(32)),
            'is_active' => true,
        ]);

        $this->userRepository->sync($user->id, 'roles', $data->roles);
        $user->load('roles');

        $this->issueActivationInvitation($user);

        return $user;
    }

    /**
     * @param  UpdateUserData  $data
     */
    public function update(int|string $id, Data $data): User
    {
        /** @var User $user */
        $user = $this->userRepository->findById($id);
        $user->load('roles');

        $this->guardNotSuperadmin($user);

        if ($user->roles->contains('id', RoleEnum::LEAGUE_ADMIN->value)
            && ! in_array(RoleEnum::LEAGUE_ADMIN->value, $data->roles, true)) {
            $this->guardNotLeagueAdmin($user, 'quitar el rol de administrador de liga');
        }

        $wasPending = $user->email_verified_at === null;
        $wasActive = $user->is_active;
        $emailChanged = $user->email !== $data->email;

        $this->userRepository->update($id, [
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'email' => $data->email,
            'phone' => $data->phone,
            'is_active' => $data->is_active,
        ]);

        $this->userRepository->sync($user->id, 'roles', $data->roles);

        $user->refresh();
        $user->load('roles');

        if ($wasActive && ! $data->is_active) {
            $this->authRepository->revokeAllTokens($user);
        }

        if ($wasPending && $emailChanged) {
            $this->issueActivationInvitation($user);
        }

        return $user;
    }

    public function paginate(ListQuery $query, ?int $roleId, ?UserAccountStatus $status, ?string $search): LengthAwarePaginator
    {
        return $this->userRepository->paginateManaged($query, $roleId, $status, $search);
    }

    public function updateStatus(int $id, bool $isActive, User $actingUser): User
    {
        /** @var User $user */
        $user = $this->userRepository->findById($id);
        $user->load('roles');

        if (! $isActive && $actingUser->id === $user->id) {
            throw new CustomErrorException(Message::CANNOT_MODIFY_SELF, Response::HTTP_FORBIDDEN);
        }

        $this->guardNotSuperadmin($user);

        $wasActive = $user->is_active;

        $this->userRepository->update($id, ['is_active' => $isActive]);

        if ($wasActive && ! $isActive) {
            $this->authRepository->revokeAllTokens($user);
        }

        $user->refresh();
        $user->load('roles');

        return $user;
    }

    public function deleteUser(int $id, User $actingUser): void
    {
        /** @var User $user */
        $user = $this->userRepository->findById($id);
        $user->load('roles');

        if ($actingUser->id === $user->id) {
            throw new CustomErrorException(Message::CANNOT_MODIFY_SELF, Response::HTTP_FORBIDDEN);
        }

        $this->guardNotSuperadmin($user);
        $this->guardNotLeagueAdmin($user, 'eliminar al usuario');

        $this->authRepository->revokeAllTokens($user);
        $this->userRepository->delete($id);
    }

    public function resendInvitation(int $id): User
    {
        /** @var User $user */
        $user = $this->userRepository->findById($id);
        $user->load('roles');

        if ($user->email_verified_at !== null) {
            throw new CustomErrorException(Message::INVITATION_ALREADY_ACTIVE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->issueActivationInvitation($user);

        return $user;
    }

    /**
     * @throws CustomErrorException
     */
    private function guardNotSuperadmin(User $user): void
    {
        if ($user->roles->contains('id', RoleEnum::SUPERADMIN->value)) {
            throw new CustomErrorException(Message::SUPERADMIN_PROTECTED, Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @throws CustomErrorException
     */
    private function guardNotLeagueAdmin(User $user, string $action): void
    {
        $leagues = $this->leagueRepository->countByAdmin($user->id);

        if ($leagues > 0) {
            throw new CustomErrorException(
                Message::getMessageUserAdministersLeagues($leagues, $action),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
    }

    private function issueActivationInvitation(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        $this->passwordResetRepository->createCode(
            $user->email,
            Hash::make($code),
            now()->addHours(self::ACTIVATION_CODE_EXPIRES_IN_HOURS)
        );

        $user->notify(new AccountInvitationNotification($code, self::ACTIVATION_CODE_EXPIRES_IN_HOURS));
    }

    private function generateUserCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::USER_CODE_LENGTH; $i++) {
                $code .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
            }
        } while ($this->userRepository->existsByUserCode($code));

        return $code;
    }
}
