<?php

namespace Tests\Feature\Auth;

use App\Contracts\Services\PhotoProcessorInterface;
use App\Core\Enum\Role as RoleEnum;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Role::query()->forceCreate(['id' => RoleEnum::PLAYER->value, 'name' => RoleEnum::PLAYER->label()]);
        $this->mock(PhotoProcessorInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('process')->andReturn('webp-bytes');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function signup(array $overrides = []): TestResponse
    {
        return $this->post('/api/register', array_merge([
            'first_name' => 'Uriel',
            'last_name' => 'González',
            'email' => 'uriel@test.com',
            'phone' => '5512345678',
            'password' => 'secreta123',
            'password_confirmation' => 'secreta123',
            'birth_date' => '2000-05-10',
            'gender' => 'male',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 600, 800),
            'client' => 'mobile',
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_creates_the_player_and_returns_a_mobile_session(): void
    {
        $response = $this->signup()
            ->assertCreated()
            ->assertJsonPath('user.email', 'uriel@test.com')
            ->assertJsonPath('user.roles', [['id' => 5, 'code' => 'player', 'name' => RoleEnum::PLAYER->label()]])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'photo_url', 'roles'], 'token']);

        $user = User::query()->where('email', 'uriel@test.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', (string) $user->user_code);
        $this->assertSame('5512345678', $user->phone);

        $player = Player::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('2000-05-10', $player->birth_date->toDateString());
        $this->assertSame('male', $player->gender->value);
        $this->assertSame(32, strlen($player->qr_code));

        $files = Storage::disk('public')->files('users/photos');
        $this->assertCount(1, $files);
        $this->assertSame('webp-bytes', Storage::disk('public')->get($files[0]));
        $this->assertStringEndsWith($files[0], (string) $user->photo_url);

        // El token sirve para la app.
        $this->withToken($response->json('token'))->getJson('/api/user')->assertOk();
    }

    public function test_requires_the_photo_phone_birth_date_and_gender(): void
    {
        $this->signup(['photo' => null, 'phone' => null, 'birth_date' => null, 'gender' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo', 'phone', 'birth_date', 'gender'], 'error');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_minors(): void
    {
        $birth = CarbonImmutable::today()->subYears(18)->addDay()->toDateString();

        $this->signup(['birth_date' => $birth])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birth_date'], 'error');

        $exactly18 = CarbonImmutable::today()->subYears(18)->toDateString();
        $this->signup(['birth_date' => $exactly18])->assertCreated();
    }

    public function test_rejects_files_that_are_not_photos_or_are_too_small(): void
    {
        $this->signup(['photo' => UploadedFile::fake()->create('selfie.pdf', 100, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo'], 'error');

        $this->signup(['photo' => UploadedFile::fake()->image('selfie.jpg', 120, 120)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo'], 'error');
    }

    public function test_rejects_an_email_or_phone_already_in_use(): void
    {
        User::factory()->create(['email' => 'uriel@test.com']);
        User::factory()->create(['phone' => '5599999999']);

        $this->signup()->assertUnprocessable()->assertJsonValidationErrors(['email'], 'error');
        $this->signup(['email' => 'otro@test.com', 'phone' => '5599999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone'], 'error');
    }

    public function test_only_the_mobile_client_can_register(): void
    {
        $this->signup(['client' => 'web'])->assertUnprocessable()->assertJsonValidationErrors(['client'], 'error');
    }

    public function test_ignores_roles_sent_by_the_client(): void
    {
        Role::query()->forceCreate(['id' => RoleEnum::MANAGER->value, 'name' => RoleEnum::MANAGER->label()]);

        $this->signup(['roles' => [RoleEnum::MANAGER->value]])->assertCreated();

        $user = User::query()->where('email', 'uriel@test.com')->firstOrFail();
        $this->assertSame([RoleEnum::PLAYER->value], $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_deletes_the_photo_if_the_account_cannot_be_saved(): void
    {
        User::saving(function () {
            throw new RuntimeException('fallo simulado');
        });

        $this->withoutExceptionHandling();

        try {
            $this->signup();
            $this->fail('Se esperaba la excepción simulada.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame([], Storage::disk('public')->files('users/photos'));
    }
}
