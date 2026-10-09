<?php

namespace Tests\Feature\Referees;

use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListRefereesTest extends TestCase
{
    use InteractsWithReferees, RefreshDatabase;

    public function test_lists_paginated_with_resource_shape(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        foreach (range(1, 3) as $i) {
            $this->linkReferee($league);
        }
        $this->linkReferee($league, $this->createRefereeUser(pending: true));

        $response = $this->getJson("/api/leagues/{$league->id}/referees?per_page=2", $header)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4);

        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'phone', 'user_code', 'photo_url', 'account_status', 'status', 'created_at'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_reports_account_status_and_link_status(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $pending = $this->createRefereeUser(['first_name' => 'Ana'], pending: true);
        $inactive = $this->createRefereeUser(['first_name' => 'Beto', 'is_active' => false]);
        $this->linkReferee($league, $pending);
        $this->linkReferee($league, $inactive, ['status' => 'inactive']);

        $data = collect($this->getJson("/api/leagues/{$league->id}/referees", $header)->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('pending', $data[$pending->id]['account_status']);
        $this->assertSame('active', $data[$pending->id]['status']);
        $this->assertSame('inactive', $data[$inactive->id]['account_status']);
        $this->assertSame('inactive', $data[$inactive->id]['status']);
    }

    public function test_default_sort_is_first_name_and_valid_sorts_work(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $b = $this->createRefereeUser(['first_name' => 'Beto', 'last_name' => 'Alfa']);
        $a = $this->createRefereeUser(['first_name' => 'Ana', 'last_name' => 'Zeta']);
        $this->linkReferee($league, $b);
        $this->linkReferee($league, $a);
        $url = "/api/leagues/{$league->id}/referees";

        $this->assertSame([$a->id, $b->id], $this->getJson($url, $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("$url?sort=-first_name", $header)->json('data.*.id'));
        $this->assertSame([$b->id, $a->id], $this->getJson("$url?sort=last_name", $header)->json('data.*.id'));
        $this->getJson("$url?sort=-created_at", $header)->assertOk();
        $this->getJson("$url?sort=email", $header)->assertStatus(400);
    }

    public function test_search_matches_name_email_phone_and_user_code(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $target = $this->createRefereeUser([
            'first_name' => 'Gustavo', 'last_name' => 'Peña', 'email' => 'gus@mail.test',
            'phone' => '5599887766', 'user_code' => 'ZXCV1234',
        ]);
        $this->linkReferee($league, $target);
        $this->linkReferee($league);
        $url = "/api/leagues/{$league->id}/referees?search=";

        foreach (['Gusta', 'Peñ', 'gus@mail', '998877', 'zxcv12', 'Gustavo Peña'] as $term) {
            $this->assertSame([$target->id], $this->getJson($url.urlencode($term), $header)->assertOk()->json('data.*.id'), $term);
        }
    }

    public function test_filters_by_link_status_and_rejects_invalid_values(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $active = $this->linkReferee($league);
        $this->linkReferee($league, null, ['status' => 'inactive']);
        $url = "/api/leagues/{$league->id}/referees";

        $this->assertSame([$active->user_id], $this->getJson("$url?status=active", $header)->json('data.*.id'));
        $this->assertCount(1, $this->getJson("$url?status=inactive", $header)->json('data'));
        $this->getJson("$url?status=banned", $header)->assertStatus(400);
        $this->getJson("$url?status=&search=&sort=", $header)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_excludes_other_leagues_deleted_links_and_deleted_users(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $visible = $this->linkReferee($league);
        $this->linkReferee(League::factory()->create());
        $this->linkReferee($league)->delete();
        $deletedUser = $this->createRefereeUser();
        $this->linkReferee($league, $deletedUser);
        $deletedUser->delete();

        $this->getJson("/api/leagues/{$league->id}/referees", $header)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->user_id);
    }

    public function test_same_referee_can_appear_in_several_leagues(): void
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $user = $this->createRefereeUser();
        $this->linkReferee($league, $user);
        $this->linkReferee(League::factory()->create(), $user);

        $this->getJson("/api/leagues/{$league->id}/referees", $header)->assertOk()->assertJsonCount(1, 'data');
    }
}
