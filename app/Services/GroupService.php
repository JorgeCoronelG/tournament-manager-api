<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Services\GroupServiceInterface;
use App\Core\BaseService;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\BestOfPosition;
use App\Helpers\PlayoffRounds;
use App\Models\PlayoffSource;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends BaseService<TournamentGroup>
 */
class GroupService extends BaseService implements GroupServiceInterface
{
    public function __construct(protected GroupRepositoryInterface $groupRepository)
    {
        parent::__construct($groupRepository);
    }

    public function overview(Tournament $tournament): array
    {
        $groups = $this->groupRepository->listByTournament($tournament->id);
        $finals = $groups->map(fn (TournamentGroup $group) => $group->finalDate())->filter()->unique();
        $hasPlayoffs = $groups->contains(fn (TournamentGroup $group) => $group->type === TournamentGroupType::PLAYOFF);

        return [
            'groups' => $groups,
            'unassigned' => $this->groupRepository->unassignedRegistrations($tournament->id),
            // Hay aviso cuando dos o más liguillas con fecha terminan en días distintos.
            'finals_aligned' => $hasPlayoffs && $finals->isEmpty() ? null : $finals->count() <= 1,
        ];
    }

    public function detail(TournamentGroup $group): TournamentGroup
    {
        $group->setRelation('registrations', $this->groupRepository->registrationsOfGroup($group->id));

        return $group;
    }

    public function createGroup(Tournament $tournament, array $attributes): TournamentGroup
    {
        $type = TournamentGroupType::tryFrom(is_string($attributes['type'] ?? null) ? $attributes['type'] : '') ?? TournamentGroupType::REGULAR_STAGE;

        if ($type === TournamentGroupType::REGULAR_STAGE) {
            $this->assertPlanning($tournament);

            return $this->groupRepository->createGroup($tournament->id, $type->value, ['name' => $attributes['name']]);
        }

        $this->assertPlayoffsEditable($tournament, null);

        $sources = $this->normalizeSources((array) $attributes['sources']);
        $this->assertSources($tournament, $sources, null, is_string($attributes['name']) ? $attributes['name'] : '');

        $startDate = is_string($attributes['start_date'] ?? null) ? $attributes['start_date'] : null;
        $rounds = $this->roundsOf($sources);
        $roundLegs = $this->normalizeRoundLegs($attributes['round_legs'] ?? null, $rounds);
        $roundDates = $this->normalizeRoundDates($attributes['round_dates'] ?? null, $roundLegs === null ? $rounds : array_sum($roundLegs), $startDate);

        return DB::transaction(function () use ($tournament, $type, $attributes, $sources, $roundDates, $roundLegs, $startDate) {
            $group = $this->groupRepository->createGroup($tournament->id, $type->value, [
                'name' => $attributes['name'],
                'start_date' => $startDate,
                'round_interval_days' => $attributes['round_interval_days'] ?? 7,
                'round_dates' => $roundDates,
                'round_legs' => $roundLegs,
            ]);
            $this->groupRepository->replaceSources($group, $sources);

            return $group;
        });
    }

    public function updateGroup(Tournament $tournament, TournamentGroup $group, array $attributes): TournamentGroup
    {
        if ($group->type === TournamentGroupType::REGULAR_STAGE) {
            $this->assertPlanning($tournament);

            return $this->groupRepository->updateGroup($group, array_intersect_key($attributes, ['name' => true]));
        }

        $this->assertPlayoffsEditable($tournament, $group);

        $sources = isset($attributes['sources']) ? $this->normalizeSources((array) $attributes['sources']) : null;

        if ($sources !== null) {
            $this->assertSources($tournament, $sources, $group->id, is_string($attributes['name'] ?? null) ? $attributes['name'] : $group->name);
        }

        $fields = array_intersect_key($attributes, ['name' => true, 'start_date' => true, 'round_interval_days' => true]);
        $currentRounds = PlayoffRounds::count($group->totalTeams());
        $rounds = $sources !== null ? $this->roundsOf($sources) : $currentRounds;

        if (array_key_exists('round_legs', $attributes)) {
            $legs = $this->normalizeRoundLegs($attributes['round_legs'], $rounds);
            $fields['round_legs'] = $legs;
        } elseif ($rounds !== $currentRounds) {
            $legs = null;
            $fields['round_legs'] = null;
        } else {
            $legs = $group->round_legs === null ? null : $group->legsPerRound();
        }

        $matches = $legs === null ? $rounds : array_sum($legs);

        if (array_key_exists('round_dates', $attributes)) {
            $startDate = array_key_exists('start_date', $attributes)
                ? (is_string($attributes['start_date']) ? $attributes['start_date'] : null)
                : $group->start_date?->toDateString();
            $fields['round_dates'] = $this->normalizeRoundDates($attributes['round_dates'], $matches, $startDate);
        } elseif (array_key_exists('start_date', $attributes) || $rounds !== $currentRounds || $matches !== $group->matchCount()) {
            // Solo start_date, o cambió el número de rondas o de partidos: vuelve al cálculo automático.
            $fields['round_dates'] = null;
        }

        return DB::transaction(function () use ($group, $fields, $sources) {
            $group = $this->groupRepository->updateGroup($group, $fields);

            if ($sources !== null) {
                $this->groupRepository->replaceSources($group, $sources);
            }

            return $group;
        });
    }

    public function deleteGroup(Tournament $tournament, TournamentGroup $group): void
    {
        if ($group->type === TournamentGroupType::PLAYOFF) {
            $this->assertPlayoffsEditable($tournament, $group);
            $this->groupRepository->deleteGroup($group);

            return;
        }

        $this->assertPlanning($tournament);

        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if ($this->groupRepository->regularGroups($tournament->id)->count() <= 1) {
            throw new CustomErrorException(Message::GROUP_DELETE_LAST_REGULAR, $unprocessable);
        }

        if ($this->groupRepository->countRegistrations($group->id) > 0) {
            throw new CustomErrorException(Message::GROUP_DELETE_HAS_TEAMS, $unprocessable);
        }

        if ($this->groupRepository->hasMatches($group->id)) {
            throw new CustomErrorException(Message::GROUP_DELETE_HAS_MATCHES, $unprocessable);
        }

        if ($this->groupRepository->isUsedAsSource($group->id)) {
            throw new CustomErrorException(Message::GROUP_DELETE_USED_BY_PLAYOFF, $unprocessable);
        }

        DB::transaction(function () use ($tournament, $group) {
            $this->groupRepository->deleteGroup($group);

            // Con un único grupo regular restante, los equipos sin grupo pasan a él.
            $remaining = $this->groupRepository->regularGroups($tournament->id);

            $only = $remaining->count() === 1 ? $remaining->first() : null;

            if ($only !== null) {
                $this->groupRepository->assignUnassignedTo($tournament->id, $only->id);
            }
        });
    }

    public function assign(Tournament $tournament, array $assignments): void
    {
        $this->assertPlanning($tournament);

        $registrationIds = array_flip($this->groupRepository->activeRegistrations($tournament->id)->map(fn ($registration) => $registration->id)->all());
        $groupIds = array_flip($this->groupRepository->regularGroups($tournament->id)->map(fn ($group) => $group->id)->all());
        $errors = [];

        foreach ($assignments as $position => $assignment) {
            if (! isset($registrationIds[$assignment['registration_id']])) {
                $errors["assignments.{$position}.registration_id"] = Message::GROUP_ASSIGN_INVALID_REGISTRATION;
            }

            if ($assignment['group_id'] !== null && ! isset($groupIds[$assignment['group_id']])) {
                $errors["assignments.{$position}.group_id"] = Message::GROUP_ASSIGN_INVALID_GROUP;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($assignments) {
            foreach ($assignments as $assignment) {
                $this->groupRepository->setRegistrationGroup($assignment['registration_id'], $assignment['group_id']);
            }
        });
    }

    public function randomAssignment(Tournament $tournament): void
    {
        $this->assertPlanning($tournament);

        $registrations = $this->groupRepository->activeRegistrations($tournament->id)->shuffle()->values();
        $groups = $this->groupRepository->regularGroups($tournament->id)->values();

        if ($registrations->count() < 2) {
            throw new CustomErrorException(Message::GROUP_RANDOM_NEEDS_TEAMS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Reparto en círculo sobre la lista mezclada: los grupos difieren como máximo en un equipo.
        DB::transaction(function () use ($registrations, $groups) {
            foreach ($registrations as $index => $registration) {
                $group = $groups->get($index % $groups->count());

                if ($group !== null) {
                    $this->groupRepository->setRegistrationGroup($registration->id, $group->id);
                }
            }
        });
    }

    public function assertReadyToStart(Tournament $tournament): void
    {
        $counts = $this->groupRepository->teamCounts($tournament->id);
        $groups = $this->groupRepository->listByTournament($tournament->id);
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        foreach ($groups->where('type', TournamentGroupType::REGULAR_STAGE) as $group) {
            if (($counts[$group->id] ?? 0) < 2) {
                throw new CustomErrorException(Message::getMessageGroupNeedsTeams($group->name), $unprocessable);
            }
        }

        $this->assertMinMatchesFit($tournament, true);

        $regularIds = $groups->where('type', TournamentGroupType::REGULAR_STAGE)->pluck('id')->map(fn (mixed $id) => is_numeric($id) ? (int) $id : 0)->values()->all();
        $names = $groups->pluck('name', 'id')->all();

        foreach ($groups->where('type', TournamentGroupType::PLAYOFF) as $playoff) {
            $ranges = $playoff->sources->where('kind', PlayoffSource::KIND_RANGE)->map(fn (PlayoffSource $s) => [
                'source_group_id' => $s->source_group_id,
                'position_start' => $s->position_start,
                'position_end' => $s->position_end,
            ])->values()->all();

            foreach ($playoff->sources as $source) {
                if ($source->isBestOfPosition()) {
                    // Cada grupo candidato debe tener esa posición.
                    foreach (BestOfPosition::candidateGroupIds($source->position_start, $ranges, $regularIds) as $candidateId) {
                        $teams = $counts[$candidateId] ?? 0;

                        if ($teams < $source->position_start) {
                            throw new CustomErrorException(
                                Message::getMessagePlayoffAsksTooMany($playoff->name, $source->position_start, is_string($names[$candidateId] ?? null) ? $names[$candidateId] : '', $teams),
                                $unprocessable
                            );
                        }
                    }

                    continue;
                }

                $teams = $counts[(int) $source->source_group_id] ?? 0;

                if ($teams < $source->position_end) {
                    throw new CustomErrorException(
                        Message::getMessagePlayoffAsksTooMany($playoff->name, $source->position_end, $source->sourceGroup->name ?? '', $teams),
                        $unprocessable
                    );
                }
            }
        }
    }

    /**
     * Las liguillas se crean, editan y borran en planeación y en curso; en curso, solo sin partidos generados.
     */
    private function assertPlayoffsEditable(Tournament $tournament, ?TournamentGroup $group): void
    {
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if (! in_array($tournament->status, [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS], true)) {
            throw new CustomErrorException(Message::PLAYOFFS_LOCKED, $unprocessable);
        }

        if ($group !== null && $tournament->status === TournamentStatus::IN_PROGRESS && $this->groupRepository->hasMatches($group->id)) {
            throw new CustomErrorException(Message::PLAYOFF_HAS_MATCHES, $unprocessable);
        }
    }

    /**
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $sources
     */
    private function roundsOf(array $sources): int
    {
        return PlayoffRounds::count($this->sourcesTotal($sources));
    }

    /**
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $sources
     */
    private function sourcesTotal(array $sources): int
    {
        return (int) array_sum(array_map(
            fn (array $s) => $s['kind'] === PlayoffSource::KIND_BEST_OF_POSITION ? (int) $s['best_count'] : $s['position_end'] - $s['position_start'] + 1,
            $sources
        ));
    }

    /**
     * Completa cada fuente: `range` por defecto; las de mejores no llevan grupo de origen y su posición final es la inicial.
     *
     * @param  array<mixed, mixed>  $sources
     * @return list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>
     */
    private function normalizeSources(array $sources): array
    {
        $normalized = [];
        // validated() deja primero las fuentes con `kind`: se vuelve al orden en que se enviaron.
        ksort($sources);

        foreach ($sources as $source) {
            /** @var array<string, mixed> $source */
            $best = ($source['kind'] ?? PlayoffSource::KIND_RANGE) === PlayoffSource::KIND_BEST_OF_POSITION;
            $start = is_numeric($source['position_start'] ?? null) ? (int) $source['position_start'] : 0;

            $normalized[] = [
                'kind' => $best ? PlayoffSource::KIND_BEST_OF_POSITION : PlayoffSource::KIND_RANGE,
                'source_group_id' => $best || ! is_numeric($source['source_group_id'] ?? null) ? null : (int) $source['source_group_id'],
                'position_start' => $start,
                'position_end' => $best || ! is_numeric($source['position_end'] ?? null) ? $start : (int) $source['position_end'],
                'best_count' => $best ? (is_numeric($source['best_count'] ?? null) ? (int) $source['best_count'] : 0) : null,
            ];
        }

        return $normalized;
    }

    /**
     * Partidos por ronda: null si vienen vacíos; si no, uno por ronda y cada valor 1 (un partido) o 2 (ida y vuelta).
     *
     * @return list<int>|null
     *
     * @throws ValidationException
     */
    private function normalizeRoundLegs(mixed $legs, int $rounds): ?array
    {
        if (! is_array($legs) || $legs === []) {
            return null;
        }

        $legs = array_values($legs);

        if (count($legs) !== $rounds) {
            throw ValidationException::withMessages(['round_legs' => Message::PLAYOFF_ROUND_LEGS_COUNT]);
        }

        foreach ($legs as $value) {
            if ($value !== 1 && $value !== 2) {
                throw ValidationException::withMessages(['round_legs' => Message::PLAYOFF_ROUND_LEGS_VALUE]);
            }
        }

        return array_map(fn (mixed $value) => (int) $value, $legs);
    }

    /**
     * Fechas de partidos de ronda: null si vienen vacías; si no, requieren start_date, una por partido de ronda, en orden estrictamente creciente y la primera igual a start_date.
     *
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    private function normalizeRoundDates(mixed $dates, int $matches, ?string $startDate): ?array
    {
        if (! is_array($dates) || $dates === []) {
            return null;
        }

        $dates = array_values(array_map(fn (mixed $date) => is_string($date) ? $date : '', $dates));

        if ($startDate === null) {
            throw ValidationException::withMessages(['round_dates' => Message::PLAYOFF_ROUND_DATES_NEED_START]);
        }

        if (count($dates) !== $matches) {
            throw ValidationException::withMessages(['round_dates' => Message::PLAYOFF_ROUND_DATES_COUNT]);
        }

        for ($i = 1; $i < count($dates); $i++) {
            if ($dates[$i] <= $dates[$i - 1]) {
                throw ValidationException::withMessages(['round_dates' => Message::PLAYOFF_ROUND_DATES_ORDER]);
            }
        }

        if ($dates[0] !== $startDate) {
            throw ValidationException::withMessages(['round_dates' => Message::PLAYOFF_ROUND_DATES_FIRST_START]);
        }

        return $dates;
    }

    public function assertMinMatchesFit(Tournament $tournament, bool $atStart): void
    {
        $minimum = $tournament->min_matches_for_playoffs;

        if ($minimum === null) {
            return;
        }

        foreach ($this->groupRepository->regularGroups($tournament->id) as $group) {
            $teams = $this->groupRepository->playingCount($group->id);

            // Un grupo con menos de 2 equipos no juega: ya lo rechaza la validación de inicio.
            if ($teams < 2) {
                continue;
            }

            $matches = ($teams - 1) * $tournament->rounds;

            if ($minimum > $matches) {
                $message = Message::getMessageMinMatchesExceed($minimum, $group->name, $matches);

                throw new CustomErrorException($atStart ? $message.' Ajusta el torneo antes de iniciar.' : $message, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
    }

    private function assertPlanning(Tournament $tournament): void
    {
        if ($tournament->status === TournamentStatus::PLANNING) {
            return;
        }

        throw new CustomErrorException(
            match ($tournament->status) {
                TournamentStatus::FINISHED => Message::GROUPS_TOURNAMENT_FINISHED,
                TournamentStatus::PLAYOFFS => Message::GROUPS_TOURNAMENT_PLAYOFFS,
                default => Message::GROUPS_TOURNAMENT_IN_PROGRESS,
            },
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * Fuentes de una liguilla: grupos regulares del torneo, rangos sin traslape con las demás fuentes, mejores de una
     * posición válidos y total de equipos potencia de 2 (al menos 2).
     *
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $sources
     *
     * @throws ValidationException
     */
    private function assertSources(Tournament $tournament, array $sources, ?int $exceptGroupId, string $playoffName): void
    {
        $regular = $this->groupRepository->regularGroups($tournament->id)->keyBy('id');
        $others = $this->groupRepository->sourcesOfOtherPlayoffs($tournament->id, $exceptGroupId);
        $ranges = array_filter($sources, fn (array $s) => $s['kind'] === PlayoffSource::KIND_RANGE);
        $errors = [];

        foreach ($ranges as $position => $source) {
            if ($source['source_group_id'] === null || ! $regular->has($source['source_group_id'])) {
                $errors["sources.{$position}.source_group_id"] = Message::PLAYOFF_SOURCE_INVALID_GROUP;
            }
        }

        if ($errors === []) {
            $errors = $this->overlaps($ranges, array_values(array_filter($others, fn (array $o) => $o['kind'] === PlayoffSource::KIND_RANGE)), $regular);
        }

        // Con el torneo en curso ya hay equipos en los grupos: la posición final no puede exceder los actuales.
        if ($errors === [] && $tournament->status === TournamentStatus::IN_PROGRESS) {
            $counts = $this->groupRepository->teamCounts($tournament->id);

            foreach ($ranges as $position => $source) {
                $teams = $counts[(int) $source['source_group_id']] ?? 0;

                if ($source['position_end'] > $teams) {
                    $name = $regular->get($source['source_group_id'])->name ?? '';
                    $errors["sources.{$position}.position_end"] = Message::getMessagePlayoffAsksTooMany($playoffName, $source['position_end'], $name, $teams);
                }
            }
        }

        if ($errors === []) {
            $errors = $this->bestOfErrors($sources, $ranges, $others, $regular, $tournament);
        }

        $total = $this->sourcesTotal($sources);

        if ($errors === [] && ! PlayoffRounds::isValidTotal($total)) {
            $errors['sources'] = Message::getMessagePlayoffTotalTeams($total);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fuentes de mejores de una posición: sin repetir posición, la posición existe en todos los candidatos, los mejores
     * son al menos 1 y menos que los candidatos, y ningún candidato pertenece a otra liguilla.
     *
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $sources
     * @param  array<int, array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $ranges
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $others
     * @param  Collection<int|string, TournamentGroup>|\Illuminate\Support\Collection<int|string, TournamentGroup>  $regular
     * @return array<string, string>
     */
    private function bestOfErrors(array $sources, array $ranges, array $others, mixed $regular, Tournament $tournament): array
    {
        $errors = [];
        $seen = [];
        $regularIds = array_values($regular->keys()->map(fn ($id) => (int) $id)->all());
        $counts = $this->groupRepository->teamCounts($tournament->id);

        foreach ($sources as $index => $source) {
            if ($source['kind'] !== PlayoffSource::KIND_BEST_OF_POSITION) {
                continue;
            }

            $position = $source['position_start'];

            if (isset($seen[$position])) {
                $errors["sources.{$index}.position_start"] = Message::PLAYOFF_BEST_DUPLICATE;

                continue;
            }

            $seen[$position] = true;
            $candidates = BestOfPosition::candidateGroupIds($position, array_values($ranges), $regularIds);

            if ($position < 1 || array_filter($candidates, fn (int $id) => ($counts[$id] ?? 0) < $position) !== []) {
                $errors["sources.{$index}.position_start"] = Message::PLAYOFF_BEST_POSITION;
            } elseif ($source['best_count'] < 1 || $source['best_count'] >= count($candidates)) {
                $errors["sources.{$index}.best_count"] = Message::PLAYOFF_BEST_COUNT;
            } elseif ($this->candidateInOtherPlayoff($position, $candidates, $others)) {
                $errors["sources.{$index}.position_start"] = Message::getMessagePlayoffOverlap('');
            }
        }

        return $errors;
    }

    /**
     * @param  list<int>  $candidates
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $others
     */
    private function candidateInOtherPlayoff(int $position, array $candidates, array $others): bool
    {
        foreach ($others as $other) {
            if ($other['kind'] === PlayoffSource::KIND_BEST_OF_POSITION) {
                if ($other['position_start'] === $position) {
                    return true;
                }

                continue;
            }

            if (in_array($other['source_group_id'], $candidates, true) && $other['position_start'] <= $position && $position <= $other['position_end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $sources
     * @param  list<array{kind: string, source_group_id: int|null, position_start: int, position_end: int, best_count: int|null}>  $others
     * @param  Collection<int|string, TournamentGroup>|\Illuminate\Support\Collection<int|string, TournamentGroup>  $regular
     * @return array<string, string>
     */
    private function overlaps(array $sources, array $others, mixed $regular): array
    {
        $errors = [];

        foreach ($sources as $position => $source) {
            $conflicts = array_merge(array_values(array_diff_key($sources, [$position => true])), $others);

            foreach ($conflicts as $other) {
                if ($other['source_group_id'] === $source['source_group_id']
                    && $source['position_start'] <= $other['position_end']
                    && $other['position_start'] <= $source['position_end']) {
                    $name = $regular->get($source['source_group_id'])->name ?? '';
                    $errors["sources.{$position}.position_start"] = Message::getMessagePlayoffOverlap($name);

                    break;
                }
            }
        }

        return $errors;
    }
}
