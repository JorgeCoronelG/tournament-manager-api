<?php

namespace App\Contracts\Services;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseServiceInterface;
use App\Core\Enum\UserAccountStatus;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseServiceInterface<User>
 */
interface UserServiceInterface extends BaseServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(ListQuery $query, ?int $roleId, ?UserAccountStatus $status, ?string $search): LengthAwarePaginator;

    public function updateStatus(int $id, bool $isActive, User $actingUser): User;

    public function deleteUser(int $id, User $actingUser): void;

    public function resendInvitation(int $id): User;

    /**
     * Cambia solo datos personales (no roles ni estado). Si la cuenta está pendiente y el correo
     * cambia, invalida el código anterior y reenvía la invitación.
     */
    public function updateProfile(User $user, string $firstName, string $lastName, string $email, ?string $phone): User;
}
