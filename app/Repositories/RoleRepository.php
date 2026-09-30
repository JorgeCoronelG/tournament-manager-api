<?php

namespace App\Repositories;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Core\BaseRepository;
use App\Models\Role;

/**
 * @extends BaseRepository<Role>
 */
class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    public function __construct(Role $entity)
    {
        parent::__construct($entity);
    }
}
