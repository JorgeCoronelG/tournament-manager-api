<?php

namespace App\Services;

use App\Contracts\Services\PlayerProfileServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Mi QR" de la app: el qr_code que lee el árbitro, los equipos en torneos que no han terminado (activo o dado
 * de baja, con su elegibilidad como la calcula el árbitro) y los próximos partidos programados de esos equipos.
 */
class PlayerProfileService implements PlayerProfileServiceInterface
{
    public function profileOf(User $user): array
    {
        /** @var Player|null $player */
        $player = Player::query()->where('user_id', $user->id)->first();

        if ($player === null) {
            throw new CustomErrorException(Message::PLAYER_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        $rosters = Roster::query()
            ->where('player_id', $player->id)
            ->whereIn('status', [RosterStatus::ACTIVE, RosterStatus::REMOVED])
            ->whereHas('tournament', fn (Builder $tournament) => $tournament->where('status', '!=', TournamentStatus::FINISHED->value))
            ->with(['registration.team', 'tournament.league'])
            ->orderByRaw('status = ? desc', [RosterStatus::ACTIVE->value])
            ->orderByDesc('id')
            ->get();

        $bannedLeagues = array_flip(PlayerLeagueBan::query()
            ->where('player_id', $player->id)
            ->whereNull('lifted_at')
            ->pluck('league_id')
            ->map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0)
            ->all());
        $suspendedTournaments = array_flip(DB::table('sanctions')
            ->where('player_id', $player->id)
            ->where('is_completed', false)
            ->whereNull('deleted_at')
            ->pluck('tournament_id')
            ->map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0)
            ->all());

        $teams = [];
        $activeRegistrationIds = [];
        foreach ($rosters as $roster) {
            $registration = $roster->registration;
            $tournament = $roster->tournament;
            if ($registration === null || $tournament === null) {
                continue;
            }

            $league = $tournament->league;
            $team = $registration->team;
            if ($roster->status === RosterStatus::ACTIVE) {
                $activeRegistrationIds[] = $registration->id;
            }

            $teams[] = [
                'registration_id' => $registration->id,
                'team' => ['id' => $team?->id, 'name' => $team?->name, 'logo_url' => $team?->logoUrl()],
                'tournament' => ['id' => $tournament->id, 'name' => $tournament->name],
                'league' => ['id' => $league?->id, 'name' => $league?->name],
                'jersey_number' => $roster->jersey_number,
                'status' => $roster->status->value,
                'eligibility' => match (true) {
                    isset($bannedLeagues[$tournament->league_id]) => 'banned',
                    isset($suspendedTournaments[$tournament->id]) => 'suspended',
                    default => 'eligible',
                },
            ];
        }

        return [
            'player_id' => $player->id,
            'qr_code' => (string) $player->qr_code,
            'teams' => $teams,
            'upcoming_matches' => $this->upcoming($activeRegistrationIds),
        ];
    }

    /**
     * @param  list<int>  $registrationIds
     * @return list<array<string, mixed>>
     */
    private function upcoming(array $registrationIds): array
    {
        if ($registrationIds === []) {
            return [];
        }

        return array_values(GameMatch::query()
            ->where('status', MatchStatus::SCHEDULED)
            ->whereNotNull('match_datetime')
            ->where('match_datetime', '>=', now()->startOfDay())
            ->whereNotNull('home_registration_id')
            ->whereNotNull('away_registration_id')
            ->where(fn (Builder $side) => $side
                ->whereIn('home_registration_id', $registrationIds)
                ->orWhereIn('away_registration_id', $registrationIds))
            ->orderBy('match_datetime')
            ->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->with(['field', 'home.team', 'away.team'])
            ->get()
            ->map(fn (GameMatch $match): array => [
                'id' => $match->id,
                'match_datetime' => $match->match_datetime?->toIso8601String(),
                'phase' => $match->phase->value,
                'matchday' => $match->matchday,
                'field' => $match->field === null ? null : ['id' => $match->field->id, 'name' => $match->field->name],
                'home_team' => $this->teamName($match->home),
                'away_team' => $this->teamName($match->away),
            ])
            ->all());
    }

    private function teamName(?Registration $registration): string
    {
        return $registration?->team->name ?? '';
    }
}
