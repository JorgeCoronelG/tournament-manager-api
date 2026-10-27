<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\PlayoffRepositoryInterface;
use App\Contracts\Repositories\TournamentRepositoryInterface;
use App\Contracts\Services\MatchScheduleServiceInterface;
use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\PlayoffServiceInterface;
use App\Contracts\Services\StandingServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\BestOfPosition;
use App\Helpers\PlayoffBracket;
use App\Helpers\PlayoffRounds;
use App\Models\GameMatch;
use App\Models\PlayoffSource;
use App\Models\PlayoffTie;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PlayoffService implements PlayoffServiceInterface
{
    private const MAX_EXAMPLES = 10;

    public function __construct(
        protected PlayoffRepositoryInterface $playoffRepository,
        protected MatchRepositoryInterface $matchRepository,
        protected GroupRepositoryInterface $groupRepository,
        protected StandingServiceInterface $standingService,
        protected TournamentRepositoryInterface $tournamentRepository,
        protected PlayoffScheduleServiceInterface $playoffSchedule,
        protected MatchScheduleServiceInterface $matchSchedule,
    ) {}

    public function preview(Tournament $tournament): array
    {
        $this->assertCanClose($tournament);
        $analysis = $this->analyse($tournament);
        $bestOf = $analysis['best_of'];

        if ($analysis['blockers'] === []) {
            $playoffs = [];

            foreach ($analysis['plans'] as $groupId => $plan) {
                $playoffs[] = $this->proposal($tournament, $plan) + ['best_of' => $bestOf[$groupId] ?? []];
            }
        } else {
            // Sin propuesta, pero la comparativa de los mejores se muestra para poder resolver sus empates.
            $playoffs = array_values(array_map(
                fn (TournamentGroup $group) => ['group_id' => $group->id, 'name' => $group->name, 'best_of' => $bestOf[$group->id]],
                array_filter($analysis['playoffs']->all(), fn (TournamentGroup $group) => isset($bestOf[$group->id]))
            ));
        }

        return [
            'blockers' => $analysis['blockers'],
            'warnings' => $analysis['warnings'],
            // Capacidad de horarios para las fechas propuestas o capturadas; no bloquea el cierre.
            'schedule_warnings' => $analysis['blockers'] === [] ? $this->playoffSchedule->proposalWarnings($tournament, $playoffs) : [],
            'playoffs' => $playoffs,
        ];
    }

    public function saveBestManualOrder(Tournament $tournament, PlayoffSource $source, array $teamIds): void
    {
        $table = $this->assertBestManualOrderAllowed($tournament, $source);

        // Los empates se calculan sin el orden manual guardado: se puede volver a ordenar el mismo conjunto.
        $byTeam = [];

        foreach ($table['candidates'] as $candidate) {
            $byTeam[$this->int($candidate['team_id'])] = $this->int($candidate['registration_id']);
        }

        $wanted = [];

        foreach ($teamIds as $teamId) {
            if (! isset($byTeam[$teamId])) {
                throw ValidationException::withMessages(['team_ids' => Message::STANDINGS_NOT_TIED]);
            }

            $wanted[] = $byTeam[$teamId];
        }

        $sorted = $wanted;
        sort($sorted);

        foreach (BestOfPosition::rank($table['candidates'], (int) $source->best_count)['ties'] as $tie) {
            $tieSorted = $tie;
            sort($tieSorted);

            if ($tieSorted === $sorted) {
                $this->playoffRepository->saveBestManualOrder($source->playoff_group_id, $source->id, $wanted);

                return;
            }
        }

        throw ValidationException::withMessages(['team_ids' => Message::STANDINGS_NOT_TIED]);
    }

    public function clearBestManualOrder(Tournament $tournament, PlayoffSource $source): void
    {
        $this->assertBestManualOrderAllowed($tournament, $source);

        $this->playoffRepository->clearBestManualOrder($source->id);
    }

    public function close(Tournament $tournament, array $entries): Tournament
    {
        $this->assertCanClose($tournament);
        $analysis = $this->analyse($tournament);

        if ($analysis['blockers'] !== []) {
            throw new CustomErrorException(Message::CLOSE_BLOCKED, Response::HTTP_UNPROCESSABLE_ENTITY, ['blockers' => $analysis['blockers']]);
        }

        $plans = $analysis['plans'];
        $byGroup = [];

        foreach ($entries as $index => $entry) {
            $byGroup[$entry['group_id']] = [$index, $entry];
        }

        if (count($byGroup) !== count($entries) || array_diff(array_keys($plans), array_keys($byGroup)) !== [] || array_diff(array_keys($byGroup), array_keys($plans)) !== []) {
            throw new CustomErrorException(Message::CLOSE_CONFIRM_ALL_PLAYOFFS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lastRegular = $this->matchRepository->lastRegularDate($tournament->id);
        $prepared = [];

        foreach ($plans as $groupId => $plan) {
            [$index, $entry] = $byGroup[$groupId];
            /** @var TournamentGroup $group */
            $group = $plan['group'];
            $prepared[$groupId] = [
                'pairs' => $this->pairsFromEntry($plan, $entry['ties'] ?? null),
                'dates' => $this->validatedDates($group, $entry['round_dates'], $lastRegular, $index),
            ];
        }

        return DB::transaction(function () use ($tournament, $plans, $prepared) {
            foreach ($plans as $groupId => $plan) {
                $this->createBracket($plan, $prepared[$groupId]['pairs'], $prepared[$groupId]['dates']);
            }

            return $this->tournamentRepository->updateStatus($tournament, TournamentStatus::PLAYOFFS);
        });
    }

    public function bracket(Tournament $tournament): array
    {
        if (! in_array($tournament->status, [TournamentStatus::PLAYOFFS, TournamentStatus::FINISHED], true)) {
            throw new CustomErrorException(Message::PLAYOFFS_NOT_STARTED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playoffs = $this->groupRepository->listByTournament($tournament->id)->where('type', TournamentGroupType::PLAYOFF)->values();
        $ties = $this->playoffRepository->tiesOfGroups(array_values(array_map(fn (TournamentGroup $group) => $group->id, $playoffs->all())))->groupBy('group_id');
        $result = [];

        foreach ($playoffs as $playoff) {
            $detail = $playoff->roundsDetail();
            $groupTies = $ties->get($playoff->id) ?? collect();
            $rounds = [];

            foreach ($detail as $index => $round) {
                $rounds[] = [
                    'index' => $index + 1,
                    'name' => $round['name'],
                    'legs' => $round['legs'],
                    'ties' => $groupTies->where('round_index', $index + 1)->map(fn (PlayoffTie $tie) => $this->describeTie($tie))->values()->all(),
                ];
            }

            $final = $groupTies->firstWhere('round_index', count($detail));

            $result[] = [
                'group_id' => $playoff->id,
                'name' => $playoff->name,
                'rounds' => $rounds,
                'champion' => $final instanceof PlayoffTie ? $this->team($final->winner) : null,
            ];
        }

        return ['playoffs' => $result];
    }

    public function recordResult(GameMatch $match, int $homeGoals, int $awayGoals, ?int $homePenalties, ?int $awayPenalties): array
    {
        $tie = $this->playoffRepository->findTie((int) $match->tie_id);
        $match = $tie->matches->firstWhere('id', $match->id) ?? $match;
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if ($match->home_registration_id === null || $match->away_registration_id === null) {
            throw new CustomErrorException(Message::MATCH_NO_TEAMS, $unprocessable);
        }

        $legs = $tie->matches->count();
        $first = $tie->matches->first();

        if ($match->leg === 2 && (! $first instanceof GameMatch || $first->status !== MatchStatus::PLAYED)) {
            throw new CustomErrorException(Message::MATCH_FIRST_LEG_FIRST, $unprocessable);
        }

        $this->assertCanModify($tie, $match);
        $this->assertPenalties($match, $first, $legs, $homeGoals, $awayGoals, $homePenalties, $awayPenalties);

        return DB::transaction(function () use ($match, $tie, $homeGoals, $awayGoals, $homePenalties, $awayPenalties) {
            $saved = $this->matchRepository->setResult($match, $homeGoals, $awayGoals, $homePenalties, $awayPenalties);

            return $this->settle($tie->id, $saved);
        });
    }

    public function clearResult(GameMatch $match): array
    {
        $tie = $this->playoffRepository->findTie((int) $match->tie_id);
        $match = $tie->matches->firstWhere('id', $match->id) ?? $match;

        $this->assertCanModify($tie, $match);

        return DB::transaction(function () use ($match, $tie) {
            $saved = $this->matchRepository->setResult($match, null, null);

            return $this->settle($tie->id, $saved);
        });
    }

    /**
     * Solo con el torneo en curso y para fuentes de mejores de una posición.
     *
     * @return array{candidates: list<array<string, mixed>>}
     */
    private function assertBestManualOrderAllowed(Tournament $tournament, PlayoffSource $source): array
    {
        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::STANDINGS_MANUAL_ORDER_ONLY_IN_PROGRESS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $source->isBestOfPosition()) {
            throw new CustomErrorException(Message::PLAYOFF_BEST_NOT_SOURCE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playoff = $this->groupRepository->listByTournament($tournament->id)->firstWhere('id', $source->playoff_group_id);
        $standings = collect($this->standingService->standings($tournament, null))->keyBy('group_id');

        return ['candidates' => $playoff instanceof TournamentGroup ? $this->candidatesOf($playoff, $source, $standings->all()) : []];
    }

    /**
     * Candidatos de una fuente de mejores: el equipo de cada grupo regular candidato que quedó en esa posición, con su
     * rendimiento en la tabla de su grupo.
     *
     * @param  array<int|string, array{group_name: string, rows: list<array<string, mixed>>}>  $standings
     * @return list<array<string, mixed>>
     */
    private function candidatesOf(TournamentGroup $playoff, PlayoffSource $best, array $standings): array
    {
        $ranges = $playoff->sources->filter(fn (PlayoffSource $s) => ! $s->isBestOfPosition())->map(fn (PlayoffSource $s) => [
            'source_group_id' => $s->source_group_id === null ? null : (int) $s->source_group_id,
            'position_start' => (int) $s->position_start,
            'position_end' => (int) $s->position_end,
        ])->values()->all();
        $regularIds = array_map(fn (mixed $id) => $this->int($id), array_keys($standings));
        $candidates = [];

        foreach (BestOfPosition::candidateGroupIds((int) $best->position_start, $ranges, $regularIds) as $groupId) {
            $table = $standings[$groupId] ?? null;
            $row = $table === null ? null : ($table['rows'][$best->position_start - 1] ?? null);

            if ($table !== null && $row !== null) {
                $candidates[] = $row + ['group' => $table['group_name'], 'group_id' => $groupId];
            }
        }

        return $candidates;
    }

    /**
     * Comparativa de una fuente de mejores: tabla de candidatos, clasificados y bloqueo si un empate cruza el corte.
     *
     * @param  array<int|string, array{group_name: string, rows: list<array<string, mixed>>}>  $standings
     * @return array{table: array<string, mixed>, qualified: list<array<string, mixed>>, blockers: list<array<string, mixed>>}
     */
    private function bestOf(TournamentGroup $playoff, PlayoffSource $source, array $standings, int &$order): array
    {
        $position = (int) $source->position_start;
        $count = (int) $source->best_count;
        $candidates = $this->candidatesOf($playoff, $source, $standings);
        $ranked = BestOfPosition::rank($candidates, $count, $this->playoffRepository->bestManualOrder($source->id));
        $tied = array_merge(...($ranked['ties'] === [] ? [[]] : $ranked['ties']));
        // Mientras un empate sin resolver cruce el corte, ninguno de sus equipos se marca como clasificado.
        $undecided = array_merge(...($ranked['blocking'] === [] ? [[]] : $ranked['blocking']));
        $qualified = [];
        $table = [];

        foreach ($ranked['ordered'] as $index => $row) {
            $isQualified = $index < $count;
            $table[] = [
                'rank' => $index + 1,
                'registration_id' => $row['registration_id'],
                'team_id' => $row['team_id'],
                'team_name' => $row['team_name'],
                'group' => $row['group'],
                'played' => $row['played'],
                'points' => $row['points'],
                'goal_difference' => $row['goal_difference'],
                'goals_for' => $row['goals_for'],
                'points_per_match' => $this->int($row['played']) === 0 ? 0.0 : round($this->int($row['points']) / $this->int($row['played']), 3),
                'qualified' => $isQualified && ! in_array($row['registration_id'], $undecided, true),
                'tied' => in_array($row['registration_id'], $tied, true),
            ];

            if ($isQualified) {
                $qualified[] = $row + ['rank' => $position, 'source_group' => "Mejor {$position}.º", 'source_group_id' => $row['group_id'], 'order' => $order++];
            }
        }

        $blockers = array_map(fn (array $ids) => [
            'type' => 'unresolved_ties',
            'scope' => 'best_of_position',
            'message' => Message::CLOSE_UNRESOLVED_TIES,
            'source_id' => $source->id,
            'position' => $position,
            'group' => $playoff->name,
            'teams' => array_map(fn (int $id) => $this->text(collect($candidates)->firstWhere('registration_id', $id)['team_name'] ?? ''), $ids),
        ], $ranked['blocking']);

        return [
            'table' => ['source_id' => $source->id, 'position' => $position, 'best_count' => $count, 'table' => $table],
            'qualified' => $qualified,
            'blockers' => $blockers,
        ];
    }

    /**
     * @throws CustomErrorException
     */
    private function assertCanClose(Tournament $tournament): void
    {
        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::CLOSE_ONLY_IN_PROGRESS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $tournament->groups()->where('type', TournamentGroupType::PLAYOFF)->exists()) {
            throw new CustomErrorException(Message::CLOSE_NO_PLAYOFFS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Bloqueos y avisos del cierre, y por liguilla los clasificados sembrados con sus cruces de primera ronda.
     *
     * @return array{blockers: list<array<string, mixed>>, warnings: list<array<string, mixed>>, plans: array<int, array<string, mixed>>, best_of: array<int, list<array<string, mixed>>>, playoffs: Collection<int, TournamentGroup>}
     */
    private function analyse(Tournament $tournament): array
    {
        $playoffs = $this->groupRepository->listByTournament($tournament->id)->where('type', TournamentGroupType::PLAYOFF)->values();
        $standings = collect($this->standingService->standings($tournament, null))->keyBy('group_id');
        $regular = $this->matchRepository->regularMatches($tournament->id);
        $blockers = [];
        $warnings = [];
        $plans = [];
        $bestOf = [];

        if ($regular->isEmpty()) {
            $blockers[] = ['type' => 'no_calendar', 'message' => Message::CLOSE_NO_CALENDAR];
        }

        $pending = $regular->filter(fn (GameMatch $match) => $match->status === MatchStatus::SCHEDULED);

        if ($pending->isNotEmpty()) {
            $blockers[] = [
                'type' => 'pending_matches',
                'message' => Message::CLOSE_PENDING_MATCHES,
                'count' => $pending->count(),
                'examples' => $pending->take(self::MAX_EXAMPLES)->map(fn (GameMatch $match) => [
                    'match_id' => $match->id,
                    'group' => $match->group?->name,
                    'matchday' => $match->matchday,
                    'home' => $match->home?->team?->name,
                    'away' => $match->away?->team?->name,
                ])->values()->all(),
            ];
        }

        $seenTies = [];

        foreach ($playoffs as $playoff) {
            $qualified = [];
            $order = 0;

            foreach ($playoff->sources->sortBy('id') as $source) {
                if ($source->isBestOfPosition()) {
                    continue;
                }

                $table = $standings->get($source->source_group_id);

                if ($table === null) {
                    continue;
                }

                $rows = $table['rows'];
                $registrationIds = array_column($rows, 'registration_id');

                for ($rank = $source->position_start; $rank <= $source->position_end; $rank++) {
                    $row = $rows[$rank - 1] ?? null;

                    if ($row === null) {
                        break;
                    }

                    $qualified[] = $row + ['rank' => $rank, 'source_group' => $table['group_name'], 'source_group_id' => $source->source_group_id, 'order' => $order++];
                }

                foreach ($table['ties'] as $tie) {
                    $positions = array_map(fn (int $id) => (int) array_search($id, $registrationIds, true) + 1, $tie);
                    sort($positions);
                    $inside = count(array_filter($positions, fn (int $position) => $position >= $source->position_start && $position <= $source->position_end));
                    $key = $table['group_id'].'|'.implode(',', $positions);

                    if (isset($seenTies[$key]) || $inside === 0) {
                        continue;
                    }

                    $seenTies[$key] = true;
                    $teams = array_map(fn (int $id) => $this->text($rows[(int) array_search($id, $registrationIds, true)]['team_name'] ?? ''), $tie);

                    if ($inside < count($positions)) {
                        $blockers[] = ['type' => 'unresolved_ties', 'message' => Message::CLOSE_UNRESOLVED_TIES, 'group' => $table['group_name'], 'positions' => $positions, 'teams' => $teams];
                    } else {
                        $warnings[] = ['type' => 'tied_qualified', 'group' => $table['group_name'], 'positions' => $positions, 'teams' => $teams];
                    }
                }
            }

            foreach ($playoff->sources->sortBy('id')->filter(fn (PlayoffSource $source) => $source->isBestOfPosition()) as $source) {
                $best = $this->bestOf($playoff, $source, $standings->all(), $order);
                $bestOf[$playoff->id][] = $best['table'];
                array_push($qualified, ...$best['qualified']);
                array_push($blockers, ...$best['blockers']);
            }

            if (! PlayoffRounds::isValidTotal(count($qualified))) {
                $blockers[] = ['type' => 'invalid_qualified', 'message' => Message::CLOSE_INVALID_QUALIFIED, 'group' => $playoff->name, 'count' => count($qualified)];

                continue;
            }

            usort($qualified, fn (array $a, array $b) => [$a['position'], $b['points'], $b['goal_difference'], $b['goals_for'], $a['order']]
                <=> [$b['position'], $a['points'], $a['goal_difference'], $a['goals_for'], $b['order']]);

            /** @var array<int, array<string, mixed>> $seeded */
            $seeded = [];
            /** @var array<int, int> $groupBySeed */
            $groupBySeed = [];

            foreach ($qualified as $index => $row) {
                $seeded[$index + 1] = $row + ['seed' => $index + 1];
                $groupBySeed[$index + 1] = $this->int($row['source_group_id']);
            }

            $plans[$playoff->id] = [
                'group' => $playoff,
                'qualified' => $seeded,
                'pairs' => PlayoffBracket::firstRound(count($seeded), $groupBySeed),
            ];
        }

        return ['blockers' => $blockers, 'warnings' => $warnings, 'plans' => $plans, 'best_of' => $bestOf, 'playoffs' => $playoffs];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function proposal(Tournament $tournament, array $plan): array
    {
        /** @var TournamentGroup $group */
        $group = $plan['group'];
        /** @var array<int, array<string, mixed>> $qualified */
        $qualified = $plan['qualified'];
        /** @var list<array{0: int, 1: int}> $pairs */
        $pairs = $plan['pairs'];

        $rounds = [];

        foreach ($group->roundsDetail() as $index => $round) {
            $ties = [];

            foreach (range(1, intdiv(count($qualified), 2 ** ($index + 1))) as $position) {
                $ties[] = [
                    'bracket_position' => $position,
                    'team_a' => $index === 0 ? $this->seedTeam($qualified[$pairs[$position - 1][0]]) : null,
                    'team_b' => $index === 0 ? $this->seedTeam($qualified[$pairs[$position - 1][1]]) : null,
                ];
            }

            $rounds[] = ['name' => $round['name'], 'legs' => $round['legs'], 'ties' => $ties];
        }

        return [
            'group_id' => $group->id,
            'name' => $group->name,
            'qualified' => array_values(array_map(fn (array $row) => $this->seedTeam($row) + ['source_group' => $row['source_group'], 'position' => $row['rank']], $qualified)),
            'rounds' => $rounds,
            'dates' => $this->proposedDates($group, $this->matchRepository->lastRegularDate($tournament->id)),
            'round_legs' => $group->legsPerRound(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function seedTeam(array $row): array
    {
        return ['seed' => $row['seed'], 'registration_id' => $row['registration_id'], 'team_id' => $row['team_id'], 'team_name' => $row['team_name']];
    }

    /**
     * Fechas guardadas si siguen siendo válidas y posteriores al último partido regular; si no, el último partido regular
     * (o hoy, sin fechas de fase regular) más round_interval_days y de ahí un intervalo por partido.
     *
     * @return list<string>
     */
    private function proposedDates(TournamentGroup $group, ?string $lastRegular): array
    {
        $count = $group->matchCount();
        $stored = $group->round_dates;

        if (is_array($stored) && count($stored) === $count && $group->start_date !== null && ($lastRegular === null || (string) $stored[0] > $lastRegular)) {
            return array_values(array_map('strval', $stored));
        }

        $interval = (int) $group->round_interval_days;
        $base = Carbon::parse($lastRegular ?? now()->toDateString())->addDays($interval);

        return array_map(fn (int $k) => $base->copy()->addDays($k * $interval)->toDateString(), $count > 0 ? range(0, $count - 1) : []);
    }

    /**
     * Cruces de primera ronda `[mejor seed, peor seed]`: los propuestos o los ajustados por el admin, que deben usar
     * exactamente a los clasificados.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array{bracket_position: int, team_a_id: int, team_b_id: int}>|null  $ties
     * @return list<array{0: int, 1: int}>
     */
    private function pairsFromEntry(array $plan, ?array $ties): array
    {
        /** @var list<array{0: int, 1: int}> $default */
        $default = $plan['pairs'];

        if ($ties === null || $ties === []) {
            return $default;
        }

        /** @var array<int, array<string, mixed>> $qualified */
        $qualified = $plan['qualified'];
        $seedOf = [];

        foreach ($qualified as $seed => $row) {
            $seedOf[$this->int($row['team_id'])] = $seed;
        }

        $half = intdiv(count($qualified), 2);
        $invalid = new CustomErrorException(Message::CLOSE_TIES_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
        $used = [];
        $pairs = [];

        if (count($ties) !== $half) {
            throw $invalid;
        }

        foreach ($ties as $tie) {
            $position = (int) $tie['bracket_position'];
            $a = (int) $tie['team_a_id'];
            $b = (int) $tie['team_b_id'];

            if ($position < 1 || $position > $half || isset($pairs[$position]) || $a === $b
                || ! isset($seedOf[$a], $seedOf[$b]) || isset($used[$a]) || isset($used[$b])) {
                throw $invalid;
            }

            $used[$a] = $used[$b] = true;
            $pairs[$position] = $seedOf[$a] <= $seedOf[$b] ? [$seedOf[$a], $seedOf[$b]] : [$seedOf[$b], $seedOf[$a]];
        }

        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * Una fecha por partido de la liguilla, estrictamente crecientes y posteriores al último partido de la fase regular.
     *
     * @param  list<string>  $dates
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function validatedDates(TournamentGroup $group, array $dates, ?string $lastRegular, int $entryIndex): array
    {
        $key = "liguillas.{$entryIndex}.round_dates";
        if (count($dates) !== $group->matchCount()) {
            throw ValidationException::withMessages([$key => Message::PLAYOFF_ROUND_DATES_COUNT]);
        }

        for ($i = 1; $i < count($dates); $i++) {
            if ($dates[$i] <= $dates[$i - 1]) {
                throw ValidationException::withMessages([$key => Message::PLAYOFF_ROUND_DATES_ORDER]);
            }
        }

        if ($lastRegular !== null && $dates[0] <= $lastRegular) {
            throw ValidationException::withMessages([$key => Message::CLOSE_DATES_AFTER_REGULAR]);
        }

        return $dates;
    }

    /**
     * Guarda las fechas y crea cruces y partidos de toda la liguilla: la primera ronda con equipos y las siguientes vacías.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array{0: int, 1: int}>  $pairs
     * @param  list<string>  $dates
     */
    private function createBracket(array $plan, array $pairs, array $dates): void
    {
        /** @var TournamentGroup $group */
        $group = $plan['group'];
        /** @var array<int, array<string, mixed>> $qualified */
        $qualified = $plan['qualified'];
        $total = count($qualified);
        $legsPerRound = $group->legsPerRound();
        $offset = 0;
        $rows = [];
        $now = now();

        $this->groupRepository->updateGroup($group, ['start_date' => $dates[0], 'round_dates' => $dates]);

        foreach ($legsPerRound as $index => $legs) {
            $round = $index + 1;
            $teamsInRound = intdiv($total, 2 ** $index);
            $roundDates = array_slice($dates, $offset, $legs);
            $offset += $legs;

            foreach (range(1, intdiv($teamsInRound, 2)) as $position) {
                $attributes = [];
                $sides = null;

                if ($round === 1) {
                    $a = $qualified[$pairs[$position - 1][0]];
                    $b = $qualified[$pairs[$position - 1][1]];
                    $sides = [$this->int($a['seed']), $this->int($a['registration_id']), $this->int($b['seed']), $this->int($b['registration_id'])];
                    $attributes = [
                        'registration_a_id' => $sides[1], 'registration_b_id' => $sides[3],
                        'seed_a' => $sides[0], 'seed_b' => $sides[2],
                    ];
                }

                $tie = $this->playoffRepository->createTie($group->id, $round, $position, $attributes);

                foreach (range(1, $legs) as $leg) {
                    [$home, $away] = $sides === null
                        ? [null, null]
                        : PlayoffBracket::sides($sides[0], $sides[1], $sides[2], $sides[3], $legs, $leg);

                    $rows[] = [
                        'tournament_id' => $group->tournament_id,
                        'group_id' => $group->id,
                        'tie_id' => $tie->id,
                        'home_registration_id' => $home,
                        'away_registration_id' => $away,
                        'phase' => PlayoffRounds::phaseFor($teamsInRound)->value,
                        'round_index' => $round,
                        'leg' => $legs === 2 ? $leg : null,
                        'bracket_position' => $position,
                        'scheduled_date' => $roundDates[$leg - 1],
                        'status' => MatchStatus::SCHEDULED->value,
                        'schedule_locked' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        $this->matchRepository->insertMany($rows);
    }

    /**
     * No se modifica un resultado si la vuelta ya está jugada (al cambiar la ida) ni si el siguiente cruce ya tiene resultados.
     */
    private function assertCanModify(PlayoffTie $tie, GameMatch $match): void
    {
        $second = $tie->matches->count() === 2 ? $tie->matches->last() : null;

        if ($match->leg === 1 && $second instanceof GameMatch && $second->status === MatchStatus::PLAYED) {
            throw new CustomErrorException(Message::MATCH_NEXT_ROUND_HAS_RESULTS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $next = $this->nextTie($tie);

        if ($tie->winner_registration_id !== null && $next instanceof PlayoffTie
            && $next->matches->contains(fn (GameMatch $m) => $m->status === MatchStatus::PLAYED)) {
            throw new CustomErrorException(Message::MATCH_NEXT_ROUND_HAS_RESULTS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Reglas de penales: solo cuando el partido único o el global de la vuelta quedan empatados; nunca en la ida.
     */
    private function assertPenalties(GameMatch $match, ?GameMatch $first, int $legs, int $home, int $away, ?int $homePenalties, ?int $awayPenalties): void
    {
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;
        $hasPenalties = $homePenalties !== null || $awayPenalties !== null;

        if ($legs === 2 && $match->leg === 1) {
            if ($hasPenalties) {
                throw new CustomErrorException(Message::MATCH_FIRST_LEG_NO_PENALTIES, $unprocessable);
            }

            return;
        }

        if ($legs === 2 && $first instanceof GameMatch) {
            $firstHome = $first->home_registration_id === $match->home_registration_id;
            $home += (int) ($firstHome ? $first->home_score : $first->away_score);
            $away += (int) ($firstHome ? $first->away_score : $first->home_score);
        }

        if ($home !== $away) {
            if ($hasPenalties) {
                throw new CustomErrorException(Message::MATCH_PENALTIES_ONLY_ON_DRAW, $unprocessable);
            }

            return;
        }

        if ($homePenalties === null || $awayPenalties === null || $homePenalties === $awayPenalties) {
            throw new CustomErrorException(Message::MATCH_PENALTIES_REQUIRED, $unprocessable);
        }
    }

    /**
     * Recalcula el cruce tras guardar o anular un partido: ganador, cómo se definió y avance a la siguiente ronda.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>, advanced_to: array<string, mixed>|null}
     */
    private function settle(int $tieId, GameMatch $saved): array
    {
        $tie = $this->playoffRepository->findTie($tieId);
        $matches = $tie->matches;
        $winner = null;
        $decidedBy = null;

        if ($matches->every(fn (GameMatch $m) => $m->status === MatchStatus::PLAYED)) {
            $a = (int) $tie->registration_a_id;
            $b = (int) $tie->registration_b_id;
            $goalsA = $matches->sum(fn (GameMatch $m) => $this->goalsOf($m, $a));
            $goalsB = $matches->sum(fn (GameMatch $m) => $this->goalsOf($m, $b));

            if ($goalsA !== $goalsB) {
                $winner = $goalsA > $goalsB ? $a : $b;
                $decidedBy = 'goals';
            } else {
                $last = $matches->last();
                $penaltiesA = $last instanceof GameMatch ? $this->penaltiesOf($last, $a) : 0;
                $penaltiesB = $last instanceof GameMatch ? $this->penaltiesOf($last, $b) : 0;
                $winner = $penaltiesA > $penaltiesB ? $a : $b;
                $decidedBy = 'penalties';
            }
        }

        $advanced = null;

        if ($tie->winner_registration_id !== $winner) {
            $tie = $this->playoffRepository->updateTie($tie, ['winner_registration_id' => $winner, 'decided_by' => $decidedBy]);
            $advanced = $this->advance($tie, $winner);
        } elseif ($tie->decided_by !== $decidedBy) {
            $tie = $this->playoffRepository->updateTie($tie, ['decided_by' => $decidedBy]);
        }

        $tie = $this->playoffRepository->findTie($tieId);

        return ['match' => $tie->matches->firstWhere('id', $saved->id) ?? $saved, 'tie' => $this->describeTie($tie), 'advanced_to' => $advanced];
    }

    /**
     * Coloca (o retira) al ganador en su lugar del siguiente cruce y asigna local y visitante cuando ya están los dos.
     *
     * @return array<string, mixed>|null
     */
    private function advance(PlayoffTie $tie, ?int $winner): ?array
    {
        $next = $this->nextTie($tie);

        if (! $next instanceof PlayoffTie) {
            return null;
        }

        $slot = PlayoffBracket::next($tie->bracket_position)['side'];
        $seed = $winner === null ? null : ($winner === $tie->registration_a_id ? $tie->seed_a : $tie->seed_b);

        $next = $this->playoffRepository->updateTie($next, [
            "registration_{$slot}_id" => $winner,
            "seed_{$slot}" => $seed,
        ]);

        $legs = $next->matches->count();
        $ready = $next->registration_a_id !== null && $next->registration_b_id !== null;

        foreach ($next->matches as $match) {
            [$home, $away] = $ready
                ? PlayoffBracket::sides((int) $next->seed_a, (int) $next->registration_a_id, (int) $next->seed_b, (int) $next->registration_b_id, $legs, $match->leg ?? 1)
                : [null, null];

            $this->playoffRepository->setMatchSides($match->id, $home, $away);
        }

        if ($winner === null) {
            return null;
        }

        // El equipo que llega a un partido que ya tenía hora puede chocar con otro: solo se avisa, el resultado se guarda.
        $warnings = [];

        foreach ($this->playoffRepository->findTie($next->id)->matches as $match) {
            $warnings = [...$warnings, ...$this->matchSchedule->teamConflictWarnings($match, $winner)];
        }

        return ['tie_id' => $next->id, 'round_index' => $next->round_index, 'bracket_position' => $next->bracket_position, 'slot' => $slot, 'match_ids' => $next->matches->pluck('id')->all(), 'warnings' => $warnings];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nextTie(PlayoffTie $tie): ?PlayoffTie
    {
        return $this->playoffRepository->tieAt($tie->group_id, $tie->round_index + 1, PlayoffBracket::next($tie->bracket_position)['position']);
    }

    private function goalsOf(GameMatch $match, int $registration): int
    {
        return (int) ($match->home_registration_id === $registration ? $match->home_score : $match->away_score);
    }

    private function penaltiesOf(GameMatch $match, int $registration): int
    {
        return (int) ($match->home_registration_id === $registration ? $match->home_penalties : $match->away_penalties);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(?Registration $registration): ?array
    {
        $team = $registration?->team;

        if ($registration === null || $team === null) {
            return null;
        }

        return ['registration_id' => $registration->id, 'team_id' => $registration->team_id, 'team_name' => $team->name, 'logo_url' => $team->logoUrl()];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTie(PlayoffTie $tie): array
    {
        $a = (int) $tie->registration_a_id;
        $b = (int) $tie->registration_b_id;
        $played = $tie->matches->filter(fn (GameMatch $m) => $m->status === MatchStatus::PLAYED && $m->home_registration_id !== null);

        return [
            'id' => $tie->id,
            'round_index' => $tie->round_index,
            'bracket_position' => $tie->bracket_position,
            'team_a' => $this->team($tie->registrationA),
            'team_b' => $this->team($tie->registrationB),
            'seed_a' => $tie->seed_a,
            'seed_b' => $tie->seed_b,
            'matches' => $tie->matches->map(fn (GameMatch $m) => [
                'id' => $m->id,
                'leg' => $m->leg,
                'scheduled_date' => $m->scheduled_date?->toDateString(),
                'match_datetime' => $m->match_datetime?->toIso8601String(),
                'original_match_datetime' => $m->original_match_datetime?->toIso8601String(),
                'schedule_locked' => $m->schedule_locked,
                'field' => $m->field_id === null ? null : ['id' => $m->field_id, 'name' => $m->field?->name],
                'referee' => $m->referee_user_id === null ? null : ['id' => $m->referee_user_id, 'name' => $m->referee === null ? null : trim($m->referee->first_name.' '.$m->referee->last_name)],
                'home_team' => $this->team($m->home),
                'away_team' => $this->team($m->away),
                'home_goals' => $m->home_score,
                'away_goals' => $m->away_score,
                'home_penalties' => $m->home_penalties,
                'away_penalties' => $m->away_penalties,
                'status' => $m->status->value,
            ])->values()->all(),
            'aggregate' => $played->isEmpty() ? null : [
                'a' => $played->sum(fn (GameMatch $m) => $this->goalsOf($m, $a)),
                'b' => $played->sum(fn (GameMatch $m) => $this->goalsOf($m, $b)),
            ],
            'winner_team_id' => $tie->winner?->team_id,
            'winner_registration_id' => $tie->winner_registration_id,
            'decided_by' => $tie->decided_by,
        ];
    }
}
