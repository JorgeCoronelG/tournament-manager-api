<?php

namespace App\Services;

use App\Contracts\Repositories\FeedRepositoryInterface;
use App\Contracts\Services\FeedServiceInterface;
use App\Contracts\Services\StandingServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\Response;

class FeedService implements FeedServiceInterface
{
    /** Un torneo terminado sigue en Inicio y Tabla mientras haya tenido partidos en estos días. */
    private const FINISHED_VISIBLE_DAYS = 30;

    private const FORM_LENGTH = 5;

    private const TOP_SCORERS = 10;

    private const TOP_CARDS = 20;

    /** @var array<int, Collection<int, Tournament>> */
    private array $visible = [];

    public function __construct(
        protected FeedRepositoryInterface $feedRepository,
        protected StandingServiceInterface $standingService,
    ) {}

    public function home(User $user, ?int $tournamentId, bool $mine): array
    {
        $tournaments = $this->tournaments($user);
        $myRegistrations = $this->feedRepository->myRegistrationIds($user, $this->ids($tournaments));
        [$tournamentIds, $registrationIds] = $this->filter($tournaments, $myRegistrations, $tournamentId, $mine);

        $today = now()->startOfDay();
        $all = array_map(fn (MatchStatus $status) => $status->value, MatchStatus::cases());

        return [
            'today' => $this->feedRepository->matches($tournamentIds, $registrationIds, $today, $today->copy()->endOfDay(), $all, false),
            'upcoming' => $this->feedRepository->matches(
                $tournamentIds,
                $registrationIds,
                $today->copy()->addDay(),
                $today->copy()->addDays(self::UPCOMING_DAYS)->endOfDay(),
                [MatchStatus::SCHEDULED->value],
                false
            ),
            'results' => $this->page($tournamentIds, $registrationIds, 0),
            'tournaments' => $tournaments,
            'my_registration_ids' => $myRegistrations,
        ];
    }

    public function results(User $user, ?int $tournamentId, bool $mine, int $cursor): array
    {
        $tournaments = $this->tournaments($user);
        $myRegistrations = $this->feedRepository->myRegistrationIds($user, $this->ids($tournaments));
        [$tournamentIds, $registrationIds] = $this->filter($tournaments, $myRegistrations, $tournamentId, $mine);

        return $this->page($tournamentIds, $registrationIds, $cursor);
    }

    public function match(User $user, GameMatch $match): array
    {
        $this->assertVisible($user, $match->tournament_id);

        $match->load(['tournament.league', 'field', 'home.team', 'away.team']);

        $events = array_values($this->feedRepository->eventsOf($match)
            ->map(fn (array $event) => ['id' => (string) $event['id']] + $event)
            ->all());

        $registered = $this->feedRepository->lineupsOf($match);
        $lineups = [];

        foreach ([$match->home, $match->away] as $side) {
            if ($side === null) {
                continue;
            }

            $players = $registered->where('registration_id', $side->id);

            if ($players->isEmpty()) {
                continue;
            }

            $lineups[] = [
                'registration_id' => $side->id,
                'team_name' => $side->team->name ?? '',
                'players' => array_values($players
                    ->map(fn (array $row) => [
                        'player_id' => $row['player_id'],
                        'name' => $row['name'],
                        'jersey_number' => $row['jersey_number'],
                    ])
                    ->sortBy('jersey_number')
                    ->all()),
            ];
        }

        return ['match' => $match, 'events' => $events, 'lineups' => $lineups];
    }

    public function stats(User $user, Tournament $tournament): array
    {
        $this->assertVisible($user, $tournament->id);

        $form = $this->form($tournament->id);

        $groups = array_map(fn (array $group) => [
            'group_id' => $group['group_id'],
            'group_name' => $group['group_name'],
            'rows' => array_map(fn (array $row) => [
                'position' => $row['position'],
                'registration_id' => $row['registration_id'],
                'team' => ['id' => $row['team_id'], 'name' => $row['team_name'], 'logo_url' => $row['logo_url']],
                'withdrawn' => $row['withdrawn'],
                'played' => $row['played'],
                'won' => $row['won'],
                'drawn' => $row['drawn'],
                'lost' => $row['lost'],
                'goals_for' => $row['goals_for'],
                'goals_against' => $row['goals_against'],
                'goal_difference' => $row['goal_difference'],
                'points' => $row['points'],
                'form' => is_int($row['registration_id']) ? ($form[$row['registration_id']] ?? []) : [],
            ], $group['rows']),
        ], $this->standingService->standings($tournament, null));

        $totals = $this->feedRepository->playerTotals($tournament->id);

        $scorers = $totals->filter(fn (array $row) => $row['goals'] > 0)
            ->sort(fn (array $a, array $b) => [$b['goals'], $a['name']] <=> [$a['goals'], $b['name']])
            ->take(self::TOP_SCORERS)
            ->map(fn (array $row) => array_diff_key($row, ['yellow_cards' => 1, 'red_cards' => 1]))
            ->values()->all();

        $cards = $totals->filter(fn (array $row) => $row['yellow_cards'] + $row['red_cards'] > 0)
            ->sort(fn (array $a, array $b) => [$b['red_cards'], $b['yellow_cards'], $a['name']] <=> [$a['red_cards'], $a['yellow_cards'], $b['name']])
            ->take(self::TOP_CARDS)
            ->map(fn (array $row) => array_diff_key($row, ['goals' => 1]))
            ->values()->all();

        return [
            'tournament' => ['id' => $tournament->id, 'name' => $tournament->name, 'status' => $tournament->status->value],
            'my_registration_ids' => $this->feedRepository->myRegistrationIds($user, [$tournament->id]),
            'groups' => $groups,
            'scorers' => $scorers,
            'cards' => $cards,
        ];
    }

    /**
     * Últimos resultados (G, E, P) de cada inscripción en la fase regular, del más viejo al más reciente.
     *
     * @return array<int, list<string>>
     */
    private function form(int $tournamentId): array
    {
        $form = [];

        foreach ($this->feedRepository->playedRegularMatches($tournamentId) as $match) {
            $home = (int) $match->home_score;
            $away = (int) $match->away_score;

            foreach ([[$match->home_registration_id, $home, $away], [$match->away_registration_id, $away, $home]] as [$id, $for, $against]) {
                if ($id === null) {
                    continue;
                }

                $form[$id][] = $for > $against ? 'G' : ($for < $against ? 'P' : 'E');
            }
        }

        return array_map(fn (array $list) => array_slice($list, -self::FORM_LENGTH), $form);
    }

    /**
     * @param  list<int>  $tournamentIds
     * @param  list<int>|null  $registrationIds
     * @return array{data: Collection<int, GameMatch>, next_cursor: string|null}
     */
    private function page(array $tournamentIds, ?array $registrationIds, int $offset): array
    {
        // Uno de más para saber si hay otra página.
        $rows = $this->feedRepository->matches(
            $tournamentIds,
            $registrationIds,
            null,
            now()->startOfDay()->subSecond(),
            [MatchStatus::PLAYED->value, MatchStatus::CANCELLED->value],
            true,
            $offset,
            self::RESULTS_PAGE_SIZE + 1
        );

        $more = $rows->count() > self::RESULTS_PAGE_SIZE;

        return [
            'data' => $rows->take(self::RESULTS_PAGE_SIZE)->values(),
            'next_cursor' => $more ? (string) ($offset + self::RESULTS_PAGE_SIZE) : null,
        ];
    }

    /**
     * Torneos e inscripciones a consultar según el filtro. Un torneo que no es visible deja la lista vacía.
     *
     * @param  Collection<int, Tournament>  $tournaments
     * @param  list<int>  $myRegistrations
     * @return array{0: list<int>, 1: list<int>|null}
     */
    private function filter(Collection $tournaments, array $myRegistrations, ?int $tournamentId, bool $mine): array
    {
        $ids = $this->ids($tournaments);

        if ($tournamentId !== null) {
            $ids = in_array($tournamentId, $ids, true) ? [$tournamentId] : [];
        }

        return [$ids, $mine ? $myRegistrations : null];
    }

    /**
     * @return Collection<int, Tournament>
     */
    private function tournaments(User $user): Collection
    {
        if (! isset($this->visible[$user->id])) {
            $leagues = $this->feedRepository->relatedLeagueIds($user);
            $since = now()->subDays(self::FINISHED_VISIBLE_DAYS);
            $tournaments = $leagues === [] ? new Collection : $this->feedRepository->visibleTournaments($leagues, $since);

            // Sin liga propia (p. ej. un jugador recién registrado) o sin torneos activos en ellas: todas las ligas.
            if ($tournaments->isEmpty()) {
                $tournaments = $this->feedRepository->visibleTournaments($this->feedRepository->activeLeagueIds(), $since);
            }

            $this->visible[$user->id] = $tournaments;
        }

        return $this->visible[$user->id];
    }

    /**
     * @param  Collection<int, Tournament>  $tournaments
     * @return list<int>
     */
    private function ids(Collection $tournaments): array
    {
        return array_values($tournaments->map(fn (Tournament $tournament) => $tournament->id)->all());
    }

    /**
     * @throws CustomErrorException
     */
    private function assertVisible(User $user, int $tournamentId): void
    {
        if (! in_array($tournamentId, $this->ids($this->tournaments($user)), true)) {
            throw new CustomErrorException(Message::MODEL_NOT_FOUND_EXCEPTION, Response::HTTP_NOT_FOUND);
        }
    }
}
