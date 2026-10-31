<?php

namespace Tests\Feature\Matches;

use App\Core\Enum\MatchStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\PlayoffTie;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Groups\InteractsWithGroups;

trait InteractsWithWalkovers
{
    use InteractsWithGroups;

    private function base(League $league, Tournament $tournament): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}";
    }

    /**
     * Torneo en curso a una vuelta con $teams equipos en su grupo y el calendario generado.
     *
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: list<Registration>}
     */
    private function running(int $teams = 4): array
    {
        [$league, $tournament, $header] = $this->tournamentWithAdminHeader();
        $registrations = $this->teamsIn($tournament, $tournament->groups()->firstOrFail(), $teams);
        $tournament->forceFill(['status' => TournamentStatus::IN_PROGRESS, 'rounds' => 1])->save();
        $this->postJson($this->base($league, $tournament).'/matches/generate', ['shuffle' => false], $header)->assertOk();

        return [$league, $tournament, $header, $registrations];
    }

    /**
     * Juega toda la fase regular: gana siempre el equipo de inscripción más antigua.
     *
     * @param  list<Registration>  $registrations
     */
    private function playRegularStage(array $registrations): void
    {
        $rank = array_flip(array_map(fn (Registration $registration) => $registration->id, $registrations));

        foreach (GameMatch::query()->get() as $match) {
            $homeWins = $rank[$match->home_registration_id] < $rank[$match->away_registration_id];
            $match->forceFill(['status' => MatchStatus::PLAYED, 'home_score' => $homeWins ? 1 : 0, 'away_score' => $homeWins ? 0 : 1])->save();
        }
    }

    /**
     * Torneo de 4 equipos con la fase regular jugada, cerrada y una liguilla con las rondas indicadas.
     *
     * @param  list<int>  $legs
     * @return array{0: League, 1: Tournament, 2: array<string, string>, 3: int, 4: list<Registration>}
     */
    private function inPlayoffs(array $legs = [1, 1]): array
    {
        [$league, $tournament, $header, $registrations] = $this->running(4);
        $this->playRegularStage($registrations);
        $group = $tournament->groups()->firstOrFail();
        $playoffId = $this->postJson($this->groupsUrl($league, $tournament), [
            'type' => 'playoff', 'name' => 'Liguilla', 'round_legs' => $legs,
            'sources' => [['source_group_id' => $group->id, 'position_start' => 1, 'position_end' => 4]],
        ], $header)->assertCreated()->json('id');
        $dates = $legs === [2, 1] ? ['2026-12-07', '2026-12-10', '2026-12-16'] : ['2026-12-07', '2026-12-14'];

        $this->postJson($this->base($league, $tournament).'/close-regular-phase', ['liguillas' => [['group_id' => $playoffId, 'round_dates' => $dates]]], $header)->assertOk();

        return [$league, $tournament->refresh(), $header, $playoffId, $registrations];
    }

    private function tie(int $playoffId, int $round, int $position): PlayoffTie
    {
        return PlayoffTie::query()->where('group_id', $playoffId)->where('round_index', $round)->where('bracket_position', $position)->firstOrFail();
    }

    /**
     * @return list<GameMatch>
     */
    private function tieMatches(PlayoffTie $tie): array
    {
        return GameMatch::query()->where('tie_id', $tie->id)->orderBy('leg')->orderBy('id')->get()->all();
    }

    private function walkover(League $league, Tournament $tournament, array $header, GameMatch $match, int $loserId, array $extra = []): TestResponse
    {
        return $this->postJson($this->base($league, $tournament)."/matches/{$match->id}/walkover", ['loser_registration_id' => $loserId] + $extra, $header);
    }
}
