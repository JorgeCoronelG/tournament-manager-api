<?php

namespace Tests\Feature\Auth;

use App\Contracts\Services\PhotoProcessorInterface;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Feature\Teams\InteractsWithTeams;
use Tests\TestCase;

/**
 * Delegado o árbitro que también quiere jugar: completa teléfono, fecha, rama y selfie desde la app.
 */
class BecomePlayerTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->createRole(RoleEnum::PLAYER);
        $this->mock(PhotoProcessorInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('process')->andReturn('webp-bytes');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function becomePlayer(User $user, array $overrides = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/user/player-profile', array_merge([
            'phone' => '5512345678',
            'birth_date' => '1990-03-15',
            'gender' => 'female',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 600, 800),
        ], $overrides), $this->authHeader($user->createToken('t')->plainTextToken) + ['Accept' => 'application/json']);
    }

    private function referee(array $attributes = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), ...$attributes]);
        $user->roles()->attach($this->createRole(RoleEnum::REFEREE));

        return $user;
    }

    public function test_a_manager_completes_his_data_and_gets_a_player_profile_with_qr(): void
    {
        $manager = $this->createManagerUser(['phone' => null]);

        $this->becomePlayer($manager)
            ->assertCreated()
            ->assertJsonPath('phone', '5512345678')
            ->assertJsonPath('birth_date', '1990-03-15');

        $manager->refresh();
        $this->assertEqualsCanonicalizing(
            [RoleEnum::MANAGER->value, RoleEnum::PLAYER->value],
            $manager->roles()->pluck('roles.id')->all()
        );
        $player = Player::query()->where('user_id', $manager->id)->firstOrFail();
        $this->assertSame('female', $player->gender->value);
        $this->assertSame(32, strlen($player->qr_code));

        $files = Storage::disk('public')->files('users/photos');
        $this->assertCount(1, $files);
        $this->assertSame($files[0], $manager->getRawOriginal('photo_url'));

        // Con el rol nuevo ya entra a Mi QR.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/player/me', $this->authHeader($manager->createToken('t2')->plainTextToken) + ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('qr_code', $player->qr_code);
    }

    public function test_the_phone_is_optional_when_the_account_already_has_one(): void
    {
        $referee = $this->referee(['phone' => '5599998888']);

        $this->becomePlayer($referee, ['phone' => ''])->assertCreated()->assertJsonPath('phone', '5599998888');
    }

    public function test_validation_age_phone_and_photo(): void
    {
        $this->createManagerUser(['phone' => '5511112222']);
        $manager = $this->createManagerUser(['phone' => null]);

        $this->becomePlayer($manager, ['phone' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['phone']]);
        $this->becomePlayer($manager, ['phone' => '5511112222'])->assertStatus(422)->assertJsonStructure(['error' => ['phone']]);
        $this->becomePlayer($manager, ['birth_date' => now()->subYears(17)->toDateString()])
            ->assertStatus(422)->assertJsonStructure(['error' => ['birth_date']]);
        $this->becomePlayer($manager, ['photo' => null])->assertStatus(422)->assertJsonStructure(['error' => ['photo']]);

        $this->assertSame(0, Player::query()->count());
        $this->assertSame([], Storage::disk('public')->files('users/photos'));
    }

    public function test_it_cannot_be_done_twice_and_players_or_strangers_cannot_use_it(): void
    {
        $manager = $this->createManagerUser(['phone' => null]);
        $this->becomePlayer($manager)->assertCreated();
        $this->becomePlayer($manager->fresh() ?? $manager, ['phone' => ''])
            ->assertStatus(422)->assertJsonPath('error', Message::PLAYER_PROFILE_EXISTS);

        $nobody = User::factory()->create();
        $this->becomePlayer($nobody)->assertForbidden();
    }
}
