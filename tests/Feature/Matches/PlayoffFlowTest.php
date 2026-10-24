<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\Role as RoleEnum;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\PlayoffTie;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Groups\InteractsWithGroups;
use Tests\TestCase;

class PlayoffFlowTest extends TestCase
{
    use InteractsWithGroups, RefreshDatabase;

    private function base(League $league, Tournament $tournament): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    /**
     * Torneo en curso con un grupo de $teams equipos y su calendario generado. Con $played, todos los partidos jugados
     * (el de inscripción más antigua le gana a los demás). Con liguilla de las posiciones 1 a $teams.
     *
     * @param  list<int>|null  $legs
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: int|null, 4: list<Registration>}
     */
    private function tournament(int $teams = 4, bool $played = true, ?array $legs = null, bool $playoff = true): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $group = $tournament->groups()->firstOrFail();
        $registrations = $this->teamsIn($tournament, $group, $teams);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => 1])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();

        if ($played) {
            $rank = array_flip(array_map(fn (Registration $r) => $r->id, $registrations));

            foreach (GameMatch::query()->get() as $match) {
                $homeWins = $rank[$match->home_registration_id] < $rank[$match->away_registration_id];
                $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeWins ? 1 : 0, 'away_score' => $homeWins ? 0 : 1])->save();
            }
        }

        $playoffId = null;

        if ($playoff) {
            $playoffId = $this->postJson($this->groupsUrl($league, $tournament), [
                'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => $legs,
                'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => $teams]],
            ], $header)->assertCreated()->json('id');
        }

        return [$league, $tournament, $header, $playoffId, $registrations];
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function entry(int $groupId, array $dates, ?array $ties = null): array
    {
        return ['group_id' => $groupId, 'round_dates' => $dates] + ($ties === null ? [] : ['ties' => $ties]);
    }

    /**
     * Torneo cerrado de 4 equipos con liguilla [2, 1] (semifinales ida y vuelta, final a un partido).
     *
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: int, 4: list<Registration>}
     */
    private function closed(?array $legs = [2, 1]): array
    {
        [$league, $tournament, $header, $playoffId, $registrations] = $this->tournament(4, true, $legs);
        $dates = $legs === [2, 1] ? ['2026-12-07', '2026-12-10', '2026-12-16'] : ['2026-12-07', '2026-12-14'];
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [$this->entry($playoffId, $dates)]], $header)->assertOk();

        return [$league, $tournament->refresh(), $header, $playoffId, $registrations];
    }

    private function tie(int $playoffId, int $round, int $position): PlayoffTie
    {
        return PlayoffTie::query()->where('group_id', $playoffId)->where('round_index', $round)->where('bracket_position', $position)->firstOrFail();
    }

    private function resultUrl(League $league, Tournament $tournament, GameMatch $match): string
    {
        return $this->base($league, $tournament)."/matches/{$match->id}/result";
    }

    public function test_preview_blocks_on_pending_matches_and_missing_calendar(): void
    {
        [$league, $tournament, $header] = $this->tournament(4, false);
        $url = $this->base($league, $tournament).'/close-regular-phase/preview';

        $response = $this->getJson($url, $header)->assertOk()->assertJsonPath('blockers.0.type', 'pending_matches')
            ->assertJsonPath('blockers.0.count', 6)->assertJsonPath('playoffs', []);
        $this->assertCount(6, $response->json('blockers.0.examples'));
        $this->assertSame(['match_id', 'group', 'matchday', 'home', 'away'], array_keys($response->json('blockers.0.examples.0')));

        GameMatch::query()->delete();
        $this->getJson($url, $header)->assertOk()->assertJsonPath('blockers.0.type', 'no_calendar');
    }

    public function test_preview_limits_pending_examples_to_ten(): void
    {
        [$league, $tournament, $header] = $this->tournament(8, false);

        $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)
            ->assertJsonPath('blockers.0.count', 28)->assertJsonCount(10, 'blockers.0.examples');
    }

    public function test_preview_blocks_on_unresolved_ties_that_cross_the_cut_but_only_warns_inside_it(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->tournament(4, false);
        // 1.º gana a todos; 2.º y 3.º empatan entre sí y ganan al 4.º con el mismo marcador: empate total en 2.º y 3.º.
        $rank = array_flip(array_map(fn ($r) => $r->id, $teams));

        foreach (GameMatch::query()->get() as $match) {
            [$h, $a] = [$rank[$match->home_registration_id], $rank[$match->away_registration_id]];
            $stronger = min($h, $a);
            $score = ($stronger === 0 || max($h, $a) === 3) ? ($h < $a ? [1, 0] : [0, 1]) : [0, 0];
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $score[0], 'away_score' => $score[1]])->save();
        }

        // La liguilla de 4 incluye a todos: el empate 2.º-3.º queda dentro; aviso, sin bloqueo.
        $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()
            ->assertJsonPath('blockers', [])->assertJsonPath('warnings.0.type', 'tied_qualified');

        // Una liguilla de 2 (posiciones 1-2) deja el empate cruzando el corte: bloquea.
        $this->patchJson($this->groupsUrl($league, $tournament, "/{$playoffId}"), ['sources' => [['source_group_id' => $tournament->groups()->where('type', 'regular_stage')->value('id'), 'position_start' => 1, 'position_end' => 2]]], $header)->assertOk();
        $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()
            ->assertJsonPath('blockers.0.type', 'unresolved_ties')->assertJsonPath('blockers.0.positions', [2, 3])->assertJsonCount(2, 'blockers.0.teams');
    }

    public function test_preview_proposes_seeds_pairings_rounds_and_dates(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->tournament(8, true, [1, 2, 1]);

        $response = $this->getJson($this->base($league, $tournament).'/close-regular-phase/preview', $header)->assertOk()->assertJsonPath('blockers', []);
        $proposal = $response->json('playoffs.0');

        $this->assertSame($playoffId, $proposal['group_id']);
        $this->assertSame([1, 2, 1], $proposal['round_legs']);
        $this->assertSame(range(1, 8), array_column($proposal['qualified'], 'seed'));
        $this->assertSame($teams[0]->team_id, $proposal['qualified'][0]['team_id']);
        $this->assertSame(['Cuartos de final', 'Semifinal', 'Final'], array_column($proposal['rounds'], 'name'));
        $this->assertSame([1, 2, 1], array_column($proposal['rounds'], 'legs'));
        $this->assertSame([[1, 8], [4, 5], [2, 7], [3, 6]], array_map(fn ($t) => [$t['team_a']['seed'], $t['team_b']['seed']], $proposal['rounds'][0]['ties']));
        $this->assertNull($proposal['rounds'][1]['ties'][0]['team_a']);
        $this->assertCount(4, $proposal['dates']);
        $this->assertSame(['source_group', 'position'], array_values(array_intersect(['source_group', 'position'], array_keys($proposal['qualified'][0]))));
        $this->assertSame(7, (int) (new \DateTime($proposal['dates'][1]))->diff(new \DateTime($proposal['dates'][0]))->days);
    }

    public function test_preview_availability(): void
    {
        [$league, $tournament, $header] = $this->tournament(4, true, null, false);
        $url = $this->base($league, $tournament).'/close-regular-phase/preview';

        $this->getJson($url, $header)->assertStatus(422)->assertJsonPath('error', Message::CLOSE_NO_PLAYOFFS);

        $tournament->forceFill(['status' => TournamentStatus::PLAYOFFS])->save();
        $this->getJson($url, $header)->assertStatus(422)->assertJsonPath('error', Message::CLOSE_ONLY_IN_PROGRESS);
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => 1, 'round_dates' => ['2026-12-01']]]], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::CLOSE_ONLY_IN_PROGRESS);
    }

    public function test_closing_with_the_proposed_pairings_creates_ties_matches_and_moves_to_playoffs(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->tournament(4, true, [2, 1]);

        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [$this->entry($playoffId, ['2026-12-07', '2026-12-10', '2026-12-16'])]], $header)
            ->assertOk()->assertJsonPath('status', 'playoffs');

        $this->assertSame(3, PlayoffTie::query()->count());
        $this->assertSame(5, GameMatch::query()->where('group_id', $playoffId)->count());
        $this->assertSame('2026-12-07', TournamentGroup::query()->findOrFail($playoffId)->start_date?->toDateString());

        $semi = $this->tie($playoffId, 1, 1);
        $this->assertSame([$teams[0]->id, $teams[3]->id], [$semi->registration_a_id, $semi->registration_b_id]);
        $this->assertSame([1, 4], [$semi->seed_a, $semi->seed_b]);
        [$leg1, $leg2] = $semi->matches->all();
        $this->assertSame([$teams[3]->id, $teams[0]->id], [$leg1->home_registration_id, $leg1->away_registration_id], 'La ida es del peor sembrado.');
        $this->assertSame([$teams[0]->id, $teams[3]->id], [$leg2->home_registration_id, $leg2->away_registration_id]);
        $this->assertSame([1, 2], [$leg1->leg, $leg2->leg]);
        $this->assertSame(['2026-12-07', '2026-12-10'], [$leg1->scheduled_date->toDateString(), $leg2->scheduled_date->toDateString()]);
        $this->assertSame(MatchPhase::SEMIFINAL, $leg1->phase);

        $final = $this->tie($playoffId, 2, 1)->matches->firstOrFail();
        $this->assertNull($final->home_registration_id);
        $this->assertNull($final->leg);
        $this->assertSame('2026-12-16', $final->scheduled_date->toDateString());
        $this->assertSame(MatchPhase::FINAL, $final->phase);
    }

    public function test_closing_with_adjusted_pairings_and_validations(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->tournament(4, true, null);
        $url = $this->base($league, $tournament).'/close-regular-phase';
        $dates = ['2026-12-07', '2026-12-14'];
        $ids = array_map(fn ($r) => $r->team_id, $teams);

        $this->postJson($url, ['liguillas' => []], $header)->assertStatus(422);
        $this->postJson($url, ['liguillas' => [['group_id' => 999999, 'round_dates' => $dates]]], $header)->assertStatus(422)->assertJsonPath('error', Message::CLOSE_CONFIRM_ALL_PLAYOFFS);

        // Equipos que faltan o repetidos.
        foreach ([
            [['bracket_position' => 1, 'team_a_id' => $ids[0], 'team_b_id' => $ids[1]], ['bracket_position' => 2, 'team_a_id' => $ids[2], 'team_b_id' => $ids[2]]],
            [['bracket_position' => 1, 'team_a_id' => $ids[0], 'team_b_id' => $ids[1]], ['bracket_position' => 2, 'team_a_id' => $ids[2], 'team_b_id' => 999999]],
            [['bracket_position' => 1, 'team_a_id' => $ids[0], 'team_b_id' => $ids[1]]],
            [['bracket_position' => 1, 'team_a_id' => $ids[0], 'team_b_id' => $ids[1]], ['bracket_position' => 1, 'team_a_id' => $ids[2], 'team_b_id' => $ids[3]]],
        ] as $ties) {
            $this->postJson($url, ['liguillas' => [$this->entry($playoffId, $dates, $ties)]], $header)->assertStatus(422)->assertJsonPath('error', Message::CLOSE_TIES_INVALID);
        }

        // Fechas: cantidad, orden y formato.
        $this->assertSame(Message::PLAYOFF_ROUND_DATES_COUNT, $this->postJson($url, ['liguillas' => [$this->entry($playoffId, ['2026-12-07'])]], $header)->assertStatus(422)->json('error')['liguillas.0.round_dates'][0]);
        $this->assertSame(Message::PLAYOFF_ROUND_DATES_ORDER, $this->postJson($url, ['liguillas' => [$this->entry($playoffId, ['2026-12-14', '2026-12-07'])]], $header)->assertStatus(422)->json('error')['liguillas.0.round_dates'][0]);
        $this->postJson($url, ['liguillas' => [['group_id' => $playoffId]]], $header)->assertStatus(422);
        $this->postJson($url, ['liguillas' => [$this->entry($playoffId, ['x', 'y'])]], $header)->assertStatus(422);
        $this->assertSame(TournamentStatus::IN_PROGRESS, $tournament->fresh()->status);

        // Cruces ajustados: 1 vs 2 y 3 vs 4 (en otro orden de posiciones y con equipos A/B invertidos).
        $adjusted = [['bracket_position' => 2, 'team_a_id' => $ids[3], 'team_b_id' => $ids[2]], ['bracket_position' => 1, 'team_a_id' => $ids[1], 'team_b_id' => $ids[0]]];
        $this->postJson($url, ['liguillas' => [$this->entry($playoffId, $dates, $adjusted)]], $header)->assertOk();

        $one = $this->tie($playoffId, 1, 1);
        $two = $this->tie($playoffId, 1, 2);
        $this->assertSame([$teams[0]->id, $teams[1]->id, 1, 2], [$one->registration_a_id, $one->registration_b_id, $one->seed_a, $one->seed_b]);
        $this->assertSame([$teams[2]->id, $teams[3]->id, 3, 4], [$two->registration_a_id, $two->registration_b_id, $two->seed_a, $two->seed_b]);
    }

    public function test_dates_must_be_after_the_last_regular_match(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->tournament(4, true, null);
        $url = $this->base($league, $tournament).'/close-regular-phase';
        GameMatch::query()->where('phase', MatchPhase::REGULAR_STAGE)->first()?->forceFill(['match_datetime' => '2026-12-10 18:00:00'])->save();

        $this->assertSame(Message::CLOSE_DATES_AFTER_REGULAR, $this->postJson($url, ['liguillas' => [$this->entry($playoffId, ['2026-12-10', '2026-12-17'])]], $header)
            ->assertStatus(422)->json('error')['liguillas.0.round_dates'][0]);
        $this->postJson($url, ['liguillas' => [$this->entry($playoffId, ['2026-12-11', '2026-12-17'])]], $header)->assertOk();

    }

    public function test_pending_matches_prevent_closing_with_the_blockers_listed(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->tournament(4, false, null);
        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [$this->entry($playoffId, ['2026-12-11', '2026-12-17'])]], $header)
            ->assertStatus(422)->assertJsonPath('error.blockers.0.type', 'pending_matches');
    }

    public function test_preview_respects_saved_dates_only_when_after_the_last_regular_match(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->tournament(4, true, null);
        $this->patchJson($this->groupsUrl($league, $tournament, "/{$playoffId}"), ['start_date' => '2026-12-01', 'round_dates' => ['2026-12-01', '2026-12-09']], $header)->assertOk();
        $url = $this->base($league, $tournament).'/close-regular-phase/preview';

        $this->getJson($url, $header)->assertJsonPath('playoffs.0.dates', ['2026-12-01', '2026-12-09']);

        GameMatch::query()->where('phase', MatchPhase::REGULAR_STAGE)->first()?->forceFill(['match_datetime' => '2026-12-05 18:00:00'])->save();
        $this->getJson($url, $header)->assertJsonPath('playoffs.0.dates', ['2026-12-12', '2026-12-19']);
    }

    public function test_results_and_penalties_rules_for_single_matches(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->closed([1, 1]);
        $semi = $this->tie($playoffId, 1, 1)->matches->firstOrFail();
        $url = $this->resultUrl($league, $tournament, $semi);

        // Sin empate no se aceptan penales; con empate son obligatorios y distintos.
        $this->patchJson($url, ['home_goals' => 2, 'away_goals' => 1, 'home_penalties' => 3, 'away_penalties' => 1], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_PENALTIES_ONLY_ON_DRAW);
        $this->patchJson($url, ['home_goals' => 1, 'away_goals' => 1], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_PENALTIES_REQUIRED);
        $this->patchJson($url, ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 3, 'away_penalties' => 3], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_PENALTIES_REQUIRED);
        $this->patchJson($url, ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 3], $header)->assertStatus(422);
        $this->patchJson($url, ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 100, 'away_penalties' => 3], $header)->assertStatus(422);

        $this->patchJson($url, ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 3, 'away_penalties' => 5], $header)->assertOk()
            ->assertJsonPath('status', 'played')->assertJsonPath('home_penalties', 3)
            ->assertJsonPath('tie.decided_by', 'penalties')->assertJsonPath('tie.aggregate', ['a' => 1, 'b' => 1]);
        $tie = $this->tie($playoffId, 1, 1)->refresh();
        $this->assertSame($tie->registration_b_id, $tie->winner_registration_id, 'Con el local (mejor sembrado A) perdiendo 3-5.');
    }

    public function test_two_legged_results_penalties_and_order(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->closed([2, 1]);
        [$leg1, $leg2] = $this->tie($playoffId, 1, 1)->matches->all();

        $this->patchJson($this->resultUrl($league, $tournament, $leg2), ['home_goals' => 1, 'away_goals' => 0], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_FIRST_LEG_FIRST);
        $this->patchJson($this->resultUrl($league, $tournament, $leg1), ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 4, 'away_penalties' => 3], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_FIRST_LEG_NO_PENALTIES);

        // Ida: local (peor sembrado) 0, visitante (mejor) 2.
        $this->patchJson($this->resultUrl($league, $tournament, $leg1), ['home_goals' => 0, 'away_goals' => 2], $header)->assertOk()
            ->assertJsonPath('tie.winner_registration_id', null)->assertJsonPath('advanced_to', null);

        // Vuelta con global no empatado: sin penales.
        $this->patchJson($this->resultUrl($league, $tournament, $leg2), ['home_goals' => 0, 'away_goals' => 1, 'home_penalties' => 1, 'away_penalties' => 0], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_PENALTIES_ONLY_ON_DRAW);

        // Global empatado 2-2: penales obligatorios.
        $this->patchJson($this->resultUrl($league, $tournament, $leg2), ['home_goals' => 0, 'away_goals' => 2], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_PENALTIES_REQUIRED);
        $this->patchJson($this->resultUrl($league, $tournament, $leg2), ['home_goals' => 0, 'away_goals' => 2, 'home_penalties' => 5, 'away_penalties' => 4], $header)->assertOk()
            ->assertJsonPath('tie.decided_by', 'penalties')->assertJsonPath('tie.aggregate', ['a' => 2, 'b' => 2]);
    }

    public function test_teams_must_be_defined_before_a_match_can_be_captured(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->closed([1, 1]);
        $final = $this->tie($playoffId, 2, 1)->matches->firstOrFail();

        $this->patchJson($this->resultUrl($league, $tournament, $final), ['home_goals' => 1, 'away_goals' => 0], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_NO_TEAMS);
    }

    public function test_winners_advance_the_champion_is_set_and_the_tournament_can_finish(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->closed([1, 1]);
        $base = $this->base($league, $tournament);
        $semi1 = $this->tie($playoffId, 1, 1)->matches->firstOrFail();
        $semi2 = $this->tie($playoffId, 1, 2)->matches->firstOrFail();
        $final = $this->tie($playoffId, 2, 1)->matches->firstOrFail();

        $this->patchJson("{$base}/status", ['status' => 'finished'], $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFFS_PENDING_MATCHES);

        // Semifinal 1 (seed 1 vs 4): gana el 1; entra como equipo A de la final.
        $response = $this->patchJson($this->resultUrl($league, $tournament, $semi1), ['home_goals' => 2, 'away_goals' => 0], $header)->assertOk()
            ->assertJsonPath('tie.decided_by', 'goals')->assertJsonPath('advanced_to.slot', 'a')->assertJsonPath('advanced_to.match_ids', [$final->id]);
        $this->assertSame($teams[0]->team_id, $response->json('tie.winner_team_id'));
        $finalTie = $this->tie($playoffId, 2, 1);
        $this->assertSame($teams[0]->id, $finalTie->registration_a_id);
        $this->assertNull($final->refresh()->home_registration_id, 'Con un solo equipo el partido sigue sin local y visitante.');

        // Semifinal 2 (seed 2 vs 3): gana el visitante (3); entra como B y la final queda con equipos.
        $this->patchJson($this->resultUrl($league, $tournament, $semi2), ['home_goals' => 0, 'away_goals' => 1], $header)->assertOk()->assertJsonPath('advanced_to.slot', 'b');
        $final->refresh();
        $this->assertSame([$teams[0]->id, $teams[2]->id], [$final->home_registration_id, $final->away_registration_id], 'Partido único: el mejor sembrado es local.');

        $this->patchJson($this->resultUrl($league, $tournament, $final), ['home_goals' => 3, 'away_goals' => 1], $header)->assertOk()->assertJsonPath('advanced_to', null);

        $this->getJson("{$base}/playoffs", $header)->assertOk()
            ->assertJsonPath('playoffs.0.champion.team_id', $teams[0]->team_id)
            ->assertJsonPath('playoffs.0.rounds.1.name', 'Final')
            ->assertJsonPath('playoffs.0.rounds.1.ties.0.winner_team_id', $teams[0]->team_id);

        $this->patchJson("{$base}/status", ['status' => 'finished'], $header)->assertOk()->assertJsonPath('status', 'finished');
        $this->patchJson($this->resultUrl($league, $tournament, $final), ['home_goals' => 0, 'away_goals' => 1], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_TOURNAMENT_FINISHED);
    }

    public function test_clearing_and_editing_results_with_and_without_results_in_the_next_round(): void
    {
        [$league, $tournament, $header, $playoffId, $teams] = $this->closed([1, 1]);
        $semi1 = $this->tie($playoffId, 1, 1)->matches->firstOrFail();
        $semi2 = $this->tie($playoffId, 1, 2)->matches->firstOrFail();
        $final = $this->tie($playoffId, 2, 1)->matches->firstOrFail();

        $this->patchJson($this->resultUrl($league, $tournament, $semi1), ['home_goals' => 1, 'away_goals' => 0], $header)->assertOk();
        $this->patchJson($this->resultUrl($league, $tournament, $semi2), ['home_goals' => 1, 'away_goals' => 0], $header)->assertOk();
        $this->patchJson($this->resultUrl($league, $tournament, $final), ['home_goals' => 1, 'away_goals' => 0], $header)->assertOk();

        // Con la final jugada no se toca ni se anula una semifinal.
        $this->patchJson($this->resultUrl($league, $tournament, $semi1), ['home_goals' => 0, 'away_goals' => 1], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_NEXT_ROUND_HAS_RESULTS);
        $this->deleteJson($this->resultUrl($league, $tournament, $semi1), [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_NEXT_ROUND_HAS_RESULTS);

        // Anulada la final, sí: se corrige la semifinal y el ganador nuevo ocupa su lugar.
        $this->deleteJson($this->resultUrl($league, $tournament, $final), [], $header)->assertOk()->assertJsonPath('status', 'scheduled')->assertJsonPath('tie.winner_registration_id', null);
        $this->patchJson($this->resultUrl($league, $tournament, $semi1), ['home_goals' => 0, 'away_goals' => 1], $header)->assertOk();
        $this->assertSame($teams[3]->id, $this->tie($playoffId, 2, 1)->registration_a_id);

        // Anular una semifinal vacía su lugar en la final y los partidos de la final quedan sin equipo.
        $this->deleteJson($this->resultUrl($league, $tournament, $semi1), [], $header)->assertOk();
        $finalTie = $this->tie($playoffId, 2, 1);
        $this->assertNull($finalTie->registration_a_id);
        $this->assertNull($finalTie->winner_registration_id);
        $this->assertSame([null, null], [$final->refresh()->home_registration_id, $final->away_registration_id]);
        $this->assertNotNull($finalTie->registration_b_id);
    }

    public function test_changing_the_first_leg_with_the_second_played_is_not_allowed(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->closed([2, 1]);
        [$leg1, $leg2] = $this->tie($playoffId, 1, 1)->matches->all();
        $this->patchJson($this->resultUrl($league, $tournament, $leg1), ['home_goals' => 0, 'away_goals' => 1], $header)->assertOk();
        $this->patchJson($this->resultUrl($league, $tournament, $leg2), ['home_goals' => 1, 'away_goals' => 0], $header)->assertOk()->assertJsonPath('tie.decided_by', 'goals');

        $this->patchJson($this->resultUrl($league, $tournament, $leg1), ['home_goals' => 0, 'away_goals' => 3], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_NEXT_ROUND_HAS_RESULTS);
        $this->deleteJson($this->resultUrl($league, $tournament, $leg1), [], $header)->assertStatus(422)->assertJsonPath('error', Message::MATCH_NEXT_ROUND_HAS_RESULTS);

        $this->deleteJson($this->resultUrl($league, $tournament, $leg2), [], $header)->assertOk()->assertJsonPath('tie.winner_registration_id', null);
        $this->deleteJson($this->resultUrl($league, $tournament, $leg1), [], $header)->assertOk();
    }

    public function test_regular_stage_is_locked_after_closing(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->closed([1, 1]);
        $regular = GameMatch::query()->where('phase', MatchPhase::REGULAR_STAGE)->firstOrFail();

        $this->patchJson($this->resultUrl($league, $tournament, $regular), ['home_goals' => 1, 'away_goals' => 0], $header)->assertStatus(422)->assertJsonPath('error', Message::REGULAR_STAGE_CLOSED);
        $this->deleteJson($this->resultUrl($league, $tournament, $regular), [], $header)->assertStatus(422)->assertJsonPath('error', Message::REGULAR_STAGE_CLOSED);

    }

    public function test_regular_matches_never_accept_penalties(): void
    {
        [$league, $tournament, $header] = $this->tournament(4, false, null, false);
        $regular = GameMatch::query()->where('tournament_id', $tournament->id)->firstOrFail();
        $this->patchJson($this->resultUrl($league, $tournament, $regular), ['home_goals' => 1, 'away_goals' => 1, 'home_penalties' => 4, 'away_penalties' => 3], $header)
            ->assertStatus(422)->assertJsonPath('error', Message::MATCH_REGULAR_NO_PENALTIES);
        $this->patchJson($this->resultUrl($league, $tournament, $regular), ['home_score' => 1, 'away_score' => 1], $header)->assertOk()
            ->assertJsonPath('tie', null)->assertJsonPath('advanced_to', null);
    }

    public function test_playoffs_view_availability_and_shape(): void
    {
        [$league, $tournament, $header, $playoffId] = $this->tournament(4, true, [2, 1]);
        $url = $this->base($league, $tournament).'/playoffs';

        $this->getJson($url, $header)->assertStatus(422)->assertJsonPath('error', Message::PLAYOFFS_NOT_STARTED);

        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [$this->entry($playoffId, ['2026-12-07', '2026-12-10', '2026-12-16'])]], $header)->assertOk();

        $this->getJson($url, $header)->assertOk()
            ->assertJsonPath('playoffs.0.champion', null)
            ->assertJsonPath('playoffs.0.rounds.0.index', 1)
            ->assertJsonPath('playoffs.0.rounds.0.legs', 2)
            ->assertJsonCount(2, 'playoffs.0.rounds.0.ties')
            ->assertJsonCount(2, 'playoffs.0.rounds.0.ties.0.matches')
            ->assertJsonPath('playoffs.0.rounds.0.ties.0.matches.1.scheduled_date', '2026-12-10')
            ->assertJsonPath('playoffs.0.rounds.0.ties.0.aggregate', null)
            ->assertJsonPath('playoffs.0.rounds.1.ties.0.team_a', null)
            ->assertJsonStructure(['playoffs' => [['rounds' => [['ties' => [['id', 'bracket_position', 'team_a' => ['team_id', 'team_name'], 'team_b', 'seed_a', 'seed_b',
                'matches' => [['id', 'leg', 'scheduled_date', 'home_team', 'away_team', 'home_goals', 'away_goals', 'home_penalties', 'away_penalties', 'status']],
                'aggregate', 'winner_team_id', 'decided_by']]]]]]]);
    }

    public function test_finishing_from_in_progress_with_playoffs_is_still_rejected(): void
    {
        [$league, $tournament, $header] = $this->tournament(4, true, null);

        $this->patchJson($this->base($league, $tournament).'/status', ['status' => 'finished'], $header)->assertStatus(422)->assertJsonPath('error', Message::TOURNAMENT_FINISH_HAS_PLAYOFFS);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'preview' => ['GET', '/close-regular-phase/preview'],
            'close' => ['POST', '/close-regular-phase'],
            'playoffs' => ['GET', '/playoffs'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_permissions(string $method, string $suffix): void
    {
        $tournament = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        $url = $this->base($tournament->league, $tournament).$suffix;

        $this->json($method, $url)->assertStatus(401);

        foreach ([RoleEnum::MANAGER, RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->headerForRole($role))->assertForbidden();
        }

        [, $header] = $this->leagueWithAdminHeader();
        $this->json($method, $url, [], $header)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_responds_404_for_a_tournament_of_another_league(string $method, string $suffix): void
    {
        $foreign = Tournament::factory()->status(TournamentStatus::IN_PROGRESS)->create();
        [$league, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->base($league, $foreign).$suffix, [], $header)->assertNotFound();
    }
}
