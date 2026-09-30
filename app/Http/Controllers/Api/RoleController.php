<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\RoleServiceInterface;
use App\Core\BaseApiController;
use App\Core\Enum\Role as RoleEnum;
use App\Http\Resources\Users\RoleResource;
use Illuminate\Http\JsonResponse;

class RoleController extends BaseApiController
{
    public function __construct(protected RoleServiceInterface $roleService) {}

    /**
     * Roles asignables desde el panel (todos menos superadmin).
     */
    public function index(): JsonResponse
    {
        $roles = $this->roleService
            ->findAll()
            ->reject(fn ($role) => (int) $role->id === RoleEnum::SUPERADMIN->value)
            ->values();

        return $this->successResponse(RoleResource::collection($roles), 200);
    }
}
