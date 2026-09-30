<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Services\UserServiceInterface;
use App\Core\BaseApiController;
use App\Core\Classes\ListQuery;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\UserAccountStatus;
use App\Exceptions\CustomErrorException;
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserController extends BaseApiController
{
    public function __construct(protected UserServiceInterface $userService) {}

    public function index(Request $request): JsonResponse
    {
        $listQuery = ListQuery::fromRequest($request);
        $listQuery->sort ??= '-created_at';

        $roleId = $this->parseRoleId($request);
        $status = $this->parseStatus($request);
        $search = $this->parseSearch($request);

        $users = $this->userService->paginate($listQuery, $roleId, $status, $search);

        return $this->showAll(UserResource::collection($users));
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->toData());

        return $this->showOne(new UserResource($user), Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->userService->findById($id);
        $user->loadMissing('roles');

        return $this->showOne(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->userService->update($id, $request->toData());

        return $this->showOne(new UserResource($user));
    }

    public function updateStatus(UpdateUserStatusRequest $request, int $id): JsonResponse
    {
        /** @var User $actingUser */
        $actingUser = $request->user();
        $user = $this->userService->updateStatus($id, $request->boolean('is_active'), $actingUser);

        return $this->showOne(new UserResource($user));
    }

    public function destroy(Request $request, int $id): Response
    {
        /** @var User $actingUser */
        $actingUser = $request->user();
        $this->userService->deleteUser($id, $actingUser);

        return $this->noContentResponse();
    }

    public function resendInvitation(int $id): JsonResponse
    {
        $user = $this->userService->resendInvitation($id);

        return $this->showOne(new UserResource($user));
    }

    /**
     * @throws CustomErrorException
     */
    private function parseStatus(Request $request): ?UserAccountStatus
    {
        if (! $request->filled('status')) {
            return null;
        }

        $status = UserAccountStatus::tryFrom((string) $request->string('status'));

        if ($status === null) {
            throw new CustomErrorException(Message::INVALID_QUERY_PARAMETER, Response::HTTP_BAD_REQUEST);
        }

        return $status;
    }

    /**
     * Debe ser un id del catálogo de roles asignables (nunca superadmin).
     *
     * @throws CustomErrorException
     */
    private function parseRoleId(Request $request): ?int
    {
        if (! $request->filled('role_id')) {
            return null;
        }

        $roleId = $request->integer('role_id');
        $assignableRoleIds = array_map(
            fn (RoleEnum $role) => $role->value,
            array_filter(RoleEnum::cases(), fn (RoleEnum $role) => $role !== RoleEnum::SUPERADMIN)
        );

        if (! in_array($roleId, $assignableRoleIds, true)) {
            throw new CustomErrorException(Message::INVALID_ROLE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $roleId;
    }

    private function parseSearch(Request $request): ?string
    {
        $search = trim((string) $request->string('search'));

        return $search === '' ? null : $search;
    }
}
