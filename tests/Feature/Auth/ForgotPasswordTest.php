<?php

namespace Tests\Feature\Auth;

use App\Core\Enum\Role as RoleEnum;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Leagues\InteractsWithLeagues;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    public function test_sends_a_code_and_stores_it_hashed_when_the_email_exists(): void
    {
        Notification::fake();
        $user = $this->createLeagueAdmin(['email' => 'jorge@test.com']);

        $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])
            ->assertOk()
            ->assertExactJson(['message' => 'Si el correo existe, se envió un código de verificación.']);

        $record = PasswordResetToken::query()->find('jorge@test.com');
        $this->assertNotNull($record);
        $this->assertSame(0, $record->attempts);
        $this->assertTrue($record->expires_at->isFuture());

        Notification::assertSentTo(
            $user,
            function (PasswordResetCodeNotification $notification) use ($record) {
                return Hash::check($this->codeFromNotification($notification), $record->code);
            }
        );
    }

    public function test_returns_the_same_generic_response_when_the_email_does_not_exist(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'no-existe@test.com'])
            ->assertOk()
            ->assertExactJson(['message' => 'Si el correo existe, se envió un código de verificación.']);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{0: list<RoleEnum>, 1: bool, 2: bool}> roles, is_active, verificado
     */
    public static function ineligibleUsers(): array
    {
        return [
            'referee' => [[RoleEnum::REFEREE], true, true],
            'manager' => [[RoleEnum::MANAGER], true, true],
            'player' => [[RoleEnum::PLAYER], true, true],
            'sin roles' => [[], true, true],
            'administrador desactivado' => [[RoleEnum::LEAGUE_ADMIN], false, true],
            'administrador pendiente' => [[RoleEnum::LEAGUE_ADMIN], true, false],
            'superadmin desactivado' => [[RoleEnum::SUPERADMIN], false, true],
        ];
    }

    /**
     * @dataProvider ineligibleUsers
     *
     * @param  list<RoleEnum>  $roles
     */
    public function test_ineligible_users_get_the_same_response_without_code_or_email(array $roles, bool $isActive, bool $verified): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'jorge@test.com',
            'is_active' => $isActive,
            'email_verified_at' => $verified ? now() : null,
        ]);
        foreach ($roles as $role) {
            $user->roles()->attach($this->createRole($role));
        }

        $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])
            ->assertOk()
            ->assertExactJson(['message' => 'Si el correo existe, se envió un código de verificación.']);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_superadmin_and_league_admin_with_extra_roles_receive_the_code(): void
    {
        Notification::fake();
        $superadmin = User::factory()->create(['email' => 'super@test.com']);
        $superadmin->roles()->attach($this->createRole(RoleEnum::SUPERADMIN));
        $mixed = $this->createLeagueAdmin(['email' => 'mixto@test.com']);
        $mixed->roles()->attach($this->createRole(RoleEnum::PLAYER));

        foreach (['super@test.com', 'mixto@test.com'] as $email) {
            $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
            $this->assertNotNull(PasswordResetToken::query()->find($email));
        }

        Notification::assertSentTo([$superadmin, $mixed], PasswordResetCodeNotification::class);
    }

    public function test_requires_a_valid_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'no-es-un-correo'])
            ->assertStatus(422)
            ->assertJsonStructure(['code', 'error' => ['email']]);
    }

    public function test_a_new_code_replaces_the_previous_one(): void
    {
        Notification::fake();
        $this->createLeagueAdmin(['email' => 'jorge@test.com']);

        $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])->assertOk();
        $firstCodeHash = PasswordResetToken::query()->find('jorge@test.com')->code;

        $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])->assertOk();

        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertNotSame($firstCodeHash, PasswordResetToken::query()->find('jorge@test.com')->code);
    }

    public function test_is_rate_limited_per_email_and_ip(): void
    {
        Notification::fake();
        $this->createLeagueAdmin(['email' => 'jorge@test.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'jorge@test.com'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    private function codeFromNotification(PasswordResetCodeNotification $notification): string
    {
        $mail = $notification->toMail(new User);

        // El código está embebido en una de las líneas del correo generado.
        foreach ($mail->introLines as $line) {
            if (preg_match('/(\d{6})/', $line, $matches)) {
                return $matches[1];
            }
        }

        $this->fail('No se encontró el código en la notificación.');
    }
}
