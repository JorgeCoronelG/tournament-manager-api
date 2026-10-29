<?php

namespace Tests\Feature\Leagues;

use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentStatus;
use App\Models\Field;
use App\Models\League;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteLeagueTest extends TestCase
{
    use InteractsWithLeagues, RefreshDatabase;

    public function test_soft_deletes_a_league_and_then_404s(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['admin_user_id' => $this->createLeagueAdmin()->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertSoftDeleted($league);

        $this->getJson("/api/leagues/{$league->id}", $this->authHeader($token))
            ->assertStatus(404)
            ->assertExactJson(['code' => 404, 'error' => Message::MODEL_NOT_FOUND_EXCEPTION]);
        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertStatus(404);
    }

    public function test_deletes_the_fields_of_a_league_without_tournaments(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $fields = Field::factory(2)->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertSoftDeleted($league);
        $fields->each(fn (Field $field) => $this->assertSoftDeleted($field));
    }

    public function test_finished_tournaments_do_not_block(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $field = Field::factory()->create(['league_id' => $league->id]);
        Tournament::factory(2)->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertSoftDeleted($league);
        $this->assertSoftDeleted($field);
    }

    /**
     * @return array<string, array{0: TournamentStatus}>
     */
    public static function activeStatuses(): array
    {
        return ['planning' => [TournamentStatus::PLANNING], 'in_progress' => [TournamentStatus::IN_PROGRESS], 'playoffs' => [TournamentStatus::PLAYOFFS]];
    }

    /**
     * @dataProvider activeStatuses
     */
    public function test_unfinished_tournaments_block_the_deletion(TournamentStatus $status): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['name' => 'Liga Norte']);
        $field = Field::factory()->create(['league_id' => $league->id]);
        Tournament::factory()->status($status)->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))
            ->assertStatus(422)
            ->assertExactJson([
                'code' => 422,
                'error' => 'La liga <b>Liga Norte</b> tiene torneos activos asociados. Para eliminarla, primero debes finalizar o eliminar esos torneos.',
            ]);

        $this->assertNotSoftDeleted($league);
        $this->assertNotSoftDeleted($field);
    }

    public function test_one_unfinished_tournament_is_enough_to_block(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        Tournament::factory()->status(TournamentStatus::FINISHED)->create(['league_id' => $league->id]);
        Tournament::factory()->status(TournamentStatus::PLANNING)->create(['league_id' => $league->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertStatus(422);

        $this->assertNotSoftDeleted($league);
    }

    public function test_a_soft_deleted_unfinished_tournament_does_not_block(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        Tournament::factory()->status(TournamentStatus::PLANNING)->create(['league_id' => $league->id])->delete();

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertSoftDeleted($league);
    }

    public function test_fields_of_other_leagues_are_kept(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $other = Field::factory()->create(['league_id' => League::factory()->create()->id]);

        $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))->assertNoContent();

        $this->assertNotSoftDeleted($other);
    }

    public function test_the_league_name_is_escaped_in_the_message(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create(['name' => '<script>x</script>']);
        Tournament::factory()->status(TournamentStatus::PLAYOFFS)->create(['league_id' => $league->id]);

        $error = $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($token))
            ->assertStatus(422)
            ->json('error');

        $this->assertStringContainsString('<b>&lt;script&gt;x&lt;/script&gt;</b>', $error);
        $this->assertStringNotContainsString('<script>', $error);
    }

    public function test_only_superadmin_can_delete(): void
    {
        $league = League::factory()->create();

        foreach ([RoleEnum::LEAGUE_ADMIN, RoleEnum::REFEREE, RoleEnum::MANAGER, RoleEnum::PLAYER] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($this->createRole($role));

            $this->deleteJson("/api/leagues/{$league->id}", [], $this->authHeader($user->createToken('test')->plainTextToken))->assertStatus(403);
        }

        $this->assertNotSoftDeleted($league);
    }
}
