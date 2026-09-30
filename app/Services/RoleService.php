<?php

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Services\RoleServiceInterface;
use App\Core\BaseService;
use App\Models\Role;

/**
 * @extends BaseService<Role>
 */
class RoleService extends BaseService implements RoleServiceInterface
{
    public function __construct(RoleRepositoryInterface $repository)
    {
        parent::__construct($repository);
    }
}
