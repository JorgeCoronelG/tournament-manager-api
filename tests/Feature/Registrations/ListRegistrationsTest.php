<?php

namespace Tests\Feature\Registrations;

use App\Core\Enum\RegistrationStatus;
use App\Core\Enum\TeamStatus;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListRegistrationsTest extends TestCase
{
    use InteractsWithRegistrations, RefreshDatabase;

    public function test_lists_newest_first_with_team_and_manager(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $manager = $this->createManagerUser(['first_name' => 'Luis', 'last_name' => 'Pérez']);
        $withManager = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $manager->id]);
        $pending = Team::factory()->create(['league_id' => $league->id, 'manager_user_id' => $this->createManagerUser([], true)->id]);
        $noManager = Team::factory()->create(['league_id' => $league->id]);

        $old = Registration::factory()->create(['team_id' => $withManager->id, 'tournament_id' => $tournament->id, 'created_at' => now()->subDay()]);
        $mid = Registration::factory()->create(['team_id' => $pending->id, 'tournament_id' => $tournament->id, 'created_at' => now()]);
        $new = Registration::factory()->create(['team_id' => $noManager->id, 'tournament_id' => $tournament->id, 'created_at' => now()]);

        $response = $this->getJson($this->registrationsUrl($league, $tournament), $header)->assertOk();

        // Misma fecha: el id más alto primero.
        $this->assertSame([$new->id, $mid->id, $old->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.2.team.manager', ['name' => 'Luis Pérez', 'pending' => false])
            ->assertJsonPath('data.1.team.manager.pending', true)
            ->assertJsonPath('data.0.team.manager', null)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonStructure(['data' => [['id', 'status', 'created_at', 'team' => ['id', 'name', 'logo_url', 'manager']]], 'links', 'meta']);
    }

    public function test_paginates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        Registration::factory()->count(3)->create(['tournament_id' => $tournament->id, 'team_id' => fn () => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->getJson($this->registrationsUrl($league, $tournament).'?per_page=2', $header)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_searches_by_team_name_or_manager_name(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $byName = Team::factory()->create(['league_id' => $league->id, 'name' => 'Halcones']);
        $byManager = Team::factory()->create(['league_id' => $league->id, 'name' => 'Otros', 'manager_user_id' => $this->createManagerUser(['first_name' => 'Halcon', 'last_name' => 'Ruiz'])->id]);
        $other = Team::factory()->create(['league_id' => $league->id, 'name' => 'Lobos']);

        foreach ([$byName, $byManager, $other] as $team) {
            Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]);
        }

        $ids = array_column($this->getJson($this->registrationsUrl($league, $tournament).'?search=halcon', $header)->assertOk()->json('data'), 'team');

        $this->assertEqualsCanonicalizing([$byName->id, $byManager->id], array_column($ids, 'id'));
    }

    public function test_filters_by_status_and_rejects_an_invalid_one(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);
        Registration::factory()->status(RegistrationStatus::WITHDRAWN)->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->getJson($this->registrationsUrl($league, $tournament).'?status=withdrawn', $header)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'withdrawn');
        $this->getJson($this->registrationsUrl($league, $tournament).'?status=pending', $header)->assertStatus(400);
    }

    public function test_sorts_by_team_name_with_a_large_page(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        foreach (['Zorros', 'Aguilas', 'Mantas'] as $name) {
            Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id, 'name' => $name])->id]);
        }

        $names = fn (string $sort) => array_column($this->getJson($this->registrationsUrl($league, $tournament)."?per_page=100&sort={$sort}", $header)
            ->assertOk()->json('data'), 'team');

        $this->assertSame(['Aguilas', 'Mantas', 'Zorros'], array_column($names('name'), 'name'));
        $this->assertSame(['Zorros', 'Mantas', 'Aguilas'], array_column($names('-name'), 'name'));
    }

    public function test_skip_paging_returns_every_registration_in_one_page(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        Registration::factory()->count(7)->create(['tournament_id' => $tournament->id]);
        $url = $this->registrationsUrl($league, $tournament);

        $this->getJson($url.'?skip_paging=true&sort=name', $header)->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.currentPage', 1)
            ->assertJsonPath('meta.lastPage', 1)
            ->assertJsonStructure(['data', 'links', 'meta', 'summary']);
        $this->getJson($url.'?skip_paging=false', $header)->assertOk()->assertJsonCount(5, 'data');
        $this->getJson($url.'?skip_paging=maybe', $header)->assertStatus(400);
    }

    public function test_skip_paging_with_no_registrations_is_an_empty_page(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();

        $this->getJson($this->registrationsUrl($league, $tournament).'?skip_paging=1', $header)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_sorts_and_rejects_an_unknown_sort(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $first = Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id, 'created_at' => now()->subDay()]);
        Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->getJson($this->registrationsUrl($league, $tournament).'?sort=created_at', $header)->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->registrationsUrl($league, $tournament).'?sort=bogus', $header)->assertStatus(400);
    }

    public function test_only_lists_this_tournament_and_hides_removed_ones(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $removed = Registration::factory()->create(['tournament_id' => $tournament->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);
        $removed->delete();
        Registration::factory()->create(['tournament_id' => Tournament::factory()->create(['league_id' => $league->id])->id, 'team_id' => Team::factory()->create(['league_id' => $league->id])->id]);

        $this->getJson($this->registrationsUrl($league, $tournament), $header)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_available_teams_include_banned_but_exclude_registered_deleted_and_foreign_teams(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $free = Team::factory()->create(['league_id' => $league->id, 'name' => 'Libres', 'manager_user_id' => $this->createManagerUser(['first_name' => 'Ana'])->id]);
        $registered = Team::factory()->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $registered->id, 'tournament_id' => $tournament->id]);
        Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id, 'name' => 'Vetados']);
        $bannedRegistered = Team::factory()->status(TeamStatus::BANNED)->create(['league_id' => $league->id]);
        Registration::factory()->create(['team_id' => $bannedRegistered->id, 'tournament_id' => $tournament->id]);
        Team::factory()->create(['league_id' => $league->id])->delete();
        Team::factory()->create();
        // Su inscripción en otro torneo no lo saca de este.
        $elsewhere = Team::factory()->create(['league_id' => $league->id, 'name' => 'Viajeros']);
        Registration::factory()->create(['team_id' => $elsewhere->id, 'tournament_id' => Tournament::factory()->create(['league_id' => $league->id])->id]);

        $response = $this->getJson($this->registrationsUrl($league, $tournament, '/available-teams'), $header)->assertOk();

        $this->assertSame(['Libres', 'Vetados', 'Viajeros'], array_column($response->json('data'), 'name'));
        $this->assertSame(['active', 'banned', 'active'], array_column($response->json('data'), 'status'));
        $response->assertJsonPath('data.0.manager.name', trim('Ana '.$free->manager->last_name));
    }

    public function test_available_teams_searches_and_paginates(): void
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Aguilas']);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Lobos']);
        Team::factory()->create(['league_id' => $league->id, 'name' => 'Lobeznos']);
        $url = $this->registrationsUrl($league, $tournament, '/available-teams');

        $this->getJson($url.'?search=lob', $header)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'?per_page=2', $header)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }
}
