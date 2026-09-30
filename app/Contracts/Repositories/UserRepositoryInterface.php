<?php

namespace App\Contracts\Repositories;

use App\Core\Classes\ListQuery;
use App\Core\Contracts\BaseRepositoryInterface;
use App\Core\Enum\UserAccountStatus;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<User>
 */
interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Único en todo el sistema, incluidos usuarios eliminados.
     */
    public function existsByUserCode(string $code): bool;

    /**
     * Nunca incluye superadmin ni eliminados.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateManaged(ListQuery $query, ?int $roleId, ?UserAccountStatus $status, ?string $search): LengthAwarePaginator;
}
