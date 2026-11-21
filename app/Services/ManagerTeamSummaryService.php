<?php

namespace App\Services;

use App\Contracts\Services\ManagerTeamSummaryServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RosterStatus;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\Registration;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Mi equipo" del delegado: estadísticas de cada jugador de la plantilla (activos y dados de baja), su
 * elegibilidad como la calcula el árbitro y el calendario del equipo con resultados.
 */
class ManagerTeamSummaryService implements ManagerTeamSummaryServiceInterface
{
    public function summaryFor(Registration $registration, User $manager): array
    {
        $team = Team::query()->withTrashed()->findOrFail($registration->team_id);
        if ($team->manager_user_id !== $manager->id) {
            throw new CustomErrorException(Message::AUTHORIZATION_EXCEPTION, Response::HTTP_FORBIDDEN);
        }

        $tournament = Tournament::query()->findOrFail($registration->tournament_id);

        $playerIds = array_values(array_map(self::int(...), DB::table('rosters')
            ->where('registration_id', $registration->id)
            ->whereIn('status', [RosterStatus::ACTIVE->value, RosterStatus::REMOVED->value])
            ->pluck('player_id')
            ->unique()
            ->values()
            ->all()));

        return [
            'players' => $this->players($registration, $tournament, $playerIds),
            'matches' => $this->matches($registration),
        ];
    }

    /**
     * @param  list<int>  $playerIds
     * @return list<array<string, mixed>>
     */
    private function players(Registration $registration, Tournament $tournament, array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $totals = DB::table('match_events as e')
            ->join('matches as m', 'm.id', '=', 'e.match_id')
            ->where('e.registration_id', $registration->id)
            ->where('m.status', MatchStatus::PLAYED->value)
            ->whereNull('m.deleted_at')
            ->where('e.is_voided', false)
            ->whereIn('e.type', ['goal', 'yellow_card', 'red_card'])
            ->whereIn('e.player_id', $playerIds)
            ->groupBy('e.player_id')
            ->get([
                'e.player_id',
                DB::raw("SUM(CASE WHEN e.type = 'goal' THEN 1 ELSE 0 END) as goals"),
                DB::raw("SUM(CASE WHEN e.type = 'yellow_card' THEN 1 ELSE 0 END) as yellow_cards"),
                DB::raw("SUM(CASE WHEN e.type = 'red_card' THEN 1 ELSE 0 END) as red_cards"),
            ])
            ->keyBy(fn (stdClass $row) => self::int($row->player_id));

        $suspensions = DB::table('sanctions')
            ->where('tournament_id', $tournament->id)
            ->where('is_completed', false)
            ->whereNull('deleted_at')
            ->whereIn('player_id', $playerIds)
            ->groupBy('player_id')
            ->get(['player_id', DB::raw('SUM(suspension_matches) as matches_left'), DB::raw('MAX(is_permanent) as permanent')])
            ->keyBy(fn (stdClass $row) => self::int($row->player_id));

        $banned = array_flip(array_map(self::int(...), DB::table('player_league_bans')
            ->where('league_id', $tournament->league_id)
            ->whereNull('lifted_at')
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id')
            ->all()));

        $rows = [];
        foreach ($playerIds as $playerId) {
            $total = $totals->get($playerId);
            $suspension = $suspensions->get($playerId);

            $rows[] = [
                'player_id' => $playerId,
                'goals' => self::int($total?->goals),
                'yellow_cards' => self::int($total?->yellow_cards),
                'red_cards' => self::int($total?->red_cards),
                'eligibility' => match (true) {
                    isset($banned[$playerId]) => 'banned',
                    $suspension !== null => 'suspended',
                    default => 'eligible',
                },
                // null: sin suspensión o suspensión por tiempo indefinido.
                'suspension_matches_left' => $suspension === null || self::int($suspension->permanent) === 1
                    ? null
                    : self::int($suspension->matches_left),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matches(Registration $registration): array
    {
        return array_values(GameMatch::query()
            ->where(fn (Builder $side) => $side
                ->where('home_registration_id', $registration->id)
                ->orWhere('away_registration_id', $registration->id))
            ->whereNotNull('home_registration_id')
            ->whereNotNull('away_registration_id')
            ->whereNotNull('match_datetime')
            ->orderBy('match_datetime')
            ->orderBy('id')
            ->with(['field', 'home.team', 'away.team'])
            ->get()
            ->map(function (GameMatch $match) use ($registration): array {
                $isHome = $match->home_registration_id === $registration->id;
                $played = $match->status === MatchStatus::PLAYED;
                $opponent = $isHome ? $match->away : $match->home;

                return [
                    'id' => $match->id,
                    'match_datetime' => $match->match_datetime?->toIso8601String(),
                    'phase' => $match->phase->value,
                    'matchday' => $match->matchday,
                    'field' => $match->field === null ? null : ['id' => $match->field->id, 'name' => $match->field->name],
                    'status' => $match->status->value,
                    'is_home' => $isHome,
                    'opponent' => $opponent?->team->name ?? '',
                    'goals_for' => $played ? self::nullableInt($isHome ? $match->home_score : $match->away_score) : null,
                    'goals_against' => $played ? self::nullableInt($isHome ? $match->away_score : $match->home_score) : null,
                ];
            })
            ->all());
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }
}
