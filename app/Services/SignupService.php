<?php

namespace App\Services;

use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Services\PhotoProcessorInterface;
use App\Contracts\Services\SignupServiceInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Core\Enum\LoginClient;
use App\Core\Enum\Role as RoleEnum;
use App\Data\Auth\SignupData;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registro propio de jugadores desde la app móvil. Siempre crea el rol jugador: delegados y árbitros
 * los da de alta el encargado de liga desde el panel.
 */
class SignupService implements SignupServiceInterface
{
    public const PHOTO_DIRECTORY = 'users/photos';

    private const QR_CODE_LENGTH = 32;

    public function __construct(
        protected UserServiceInterface $userService,
        protected AuthRepositoryInterface $authRepository,
        protected PhotoProcessorInterface $photoProcessor,
    ) {}

    /**
     * @return array{user: User, token: string}
     *
     * @throws Throwable
     */
    public function register(SignupData $data): array
    {
        // La foto se procesa y guarda antes de la transacción; si algo falla después, se borra.
        $disk = Storage::disk('public');
        $path = self::PHOTO_DIRECTORY.'/'.Str::uuid().'.webp';
        $disk->put($path, $this->photoProcessor->process($data->photo));

        try {
            $user = DB::transaction(function () use ($data, $disk, $path): User {
                $user = new User;
                $user->fill([
                    'first_name' => $data->first_name,
                    'last_name' => $data->last_name,
                    'email' => $data->email,
                    'phone' => $data->phone,
                    'user_code' => $this->userService->generateUserCode(),
                    'password' => Hash::make($data->password),
                    'photo_url' => $disk->url($path),
                    'is_active' => true,
                ]);
                // Él mismo eligió su contraseña: la cuenta no queda pendiente de invitación.
                $user->email_verified_at = now();
                $user->save();

                $user->roles()->attach(RoleEnum::PLAYER->value);

                $player = new Player([
                    'birth_date' => $data->birth_date,
                    'gender' => $data->gender,
                ]);
                $player->user_id = $user->id;
                $player->qr_code = $this->generateQrCode();
                $player->save();

                return $user;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $user->load('roles');
        $token = $this->authRepository->createToken($user, LoginClient::MOBILE->value);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * El QR es fijo y nunca se muestra fuera de la app del jugador y la del árbitro.
     */
    private function generateQrCode(): string
    {
        do {
            $code = Str::random(self::QR_CODE_LENGTH);
        } while (Player::query()->withTrashed()->where('qr_code', $code)->exists());

        return $code;
    }
}
