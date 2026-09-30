<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Core\BaseRepository;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\UserAccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $entity)
    {
        parent::__construct($entity);
    }

    public function existsByUserCode(string $code): bool
    {
        return $this->entity->newQuery()->withTrashed()->where('user_code', $code)->exists();
    }

    public function paginateManaged(ListQuery $query, ?int $roleId, ?UserAccountStatus $status, ?string $search): LengthAwarePaginator
    {
        return $this->entity->newQuery()
            ->filter($query->filters)
            ->applySort($query->sort)
            ->whereDoesntHave('roles', fn ($relation) => $relation->where('roles.id', RoleEnum::SUPERADMIN->value))
            ->when($roleId !== null, function (Builder $builder) use ($roleId) {
                $builder->whereHas('roles', fn ($relation) => $relation->where('roles.id', $roleId));
            })
            ->when($status !== null, function (Builder $builder) use ($status) {
                match ($status) {
                    UserAccountStatus::PENDING => $builder->whereNull('email_verified_at'),
                    UserAccountStatus::INACTIVE => $builder->whereNotNull('email_verified_at')->where('is_active', false),
                    UserAccountStatus::ACTIVE => $builder->whereNotNull('email_verified_at')->where('is_active', true),
                    null => $builder,
                };
            })
            ->when($search !== null, function (Builder $builder) use ($search) {
                $builder->where(function (Builder $group) use ($search) {
                    $group->where('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%")
                        ->orWhere('user_code', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
            })
            ->with('roles')
            ->paginate($query->perPage)
            ->withQueryString();
    }
}
