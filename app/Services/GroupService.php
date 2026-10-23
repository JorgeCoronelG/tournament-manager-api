<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Services\GroupServiceInterface;
use App\Core\BaseService;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentGroupType;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\PlayoffRounds;
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

        /** @var list<array{source_group_id: int, position_start: int, position_end: int}> $sources */
        $sources = $attributes['sources'];
        $this->assertSources($tournament, $sources, null, is_string($attributes['name']) ? $attributes['name'] : '');

        $startDate = is_string($attributes['start_date'] ?? null) ? $attributes['start_date'] : null;
        $roundDates = $this->normalizeRoundDates($attributes['round_dates'] ?? null, $this->roundsOf($sources), $startDate);

        return DB::transaction(function () use ($tournament, $type, $attributes, $sources, $roundDates, $startDate) {
            $group = $this->groupRepository->createGroup($tournament->id, $type->value, [
                'name' => $attributes['name'],
                'start_date' => $startDate,
                'round_interval_days' => $attributes['round_interval_days'] ?? 7,
                'round_dates' => $roundDates,
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

        /** @var list<array{source_group_id: int, position_start: int, position_end: int}>|null $sources */
        $sources = $attributes['sources'] ?? null;

        if ($sources !== null) {
            $this->assertSources($tournament, $sources, $group->id, is_string($attributes['name'] ?? null) ? $attributes['name'] : $group->name);
        }

        $fields = array_intersect_key($attributes, ['name' => true, 'start_date' => true, 'round_interval_days' => true]);
        $currentRounds = PlayoffRounds::count($group->totalTeams());
        $rounds = $sources !== null ? $this->roundsOf($sources) : $currentRounds;

        if (array_key_exists('round_dates', $attributes)) {
            $startDate = array_key_exists('start_date', $attributes)
                ? (is_string($attributes['start_date']) ? $attributes['start_date'] : null)
                : $group->start_date?->toDateString();
            $fields['round_dates'] = $this->normalizeRoundDates($attributes['round_dates'], $rounds, $startDate);
        } elseif (array_key_exists('start_date', $attributes) || $rounds !== $currentRounds) {
            // Solo start_date, o cambió el número de rondas: vuelve al cálculo automático.
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

        foreach ($groups->where('type', TournamentGroupType::PLAYOFF) as $playoff) {
            foreach ($playoff->sources as $source) {
                $teams = $counts[$source->source_group_id] ?? 0;

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
     * @param  list<array{source_group_id: int, position_start: int, position_end: int}>  $sources
     */
    private function roundsOf(array $sources): int
    {
        return PlayoffRounds::count((int) array_sum(array_map(fn (array $s) => $s['position_end'] - $s['position_start'] + 1, $sources)));
    }

    /**
     * Fechas de rondas: null si vienen vacías; si no, requieren start_date, una por ronda, en orden estrictamente creciente y la primera igual a start_date.
     *
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    private function normalizeRoundDates(mixed $dates, int $rounds, ?string $startDate): ?array
    {
        if (! is_array($dates) || $dates === []) {
            return null;
        }

        $dates = array_values(array_map(fn (mixed $date) => is_string($date) ? $date : '', $dates));

        if ($startDate === null) {
            throw ValidationException::withMessages(['round_dates' => Message::PLAYOFF_ROUND_DATES_NEED_START]);
        }

        if (count($dates) !== $rounds) {
            throw ValidationException::withMessages(['round_dates' => Message::getMessagePlayoffRoundDatesCount($rounds)]);
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
     * Fuentes de una liguilla: grupos regulares del torneo, rangos sin traslape con las demás fuentes y total de
     * equipos potencia de 2 (al menos 2).
     *
     * @param  list<array{source_group_id: int, position_start: int, position_end: int}>  $sources
     *
     * @throws ValidationException
     */
    private function assertSources(Tournament $tournament, array $sources, ?int $exceptGroupId, string $playoffName): void
    {
        $regular = $this->groupRepository->regularGroups($tournament->id)->keyBy('id');
        $errors = [];
        $total = 0;

        foreach ($sources as $position => $source) {
            if (! $regular->has($source['source_group_id'])) {
                $errors["sources.{$position}.source_group_id"] = Message::PLAYOFF_SOURCE_INVALID_GROUP;
            }

            $total += $source['position_end'] - $source['position_start'] + 1;
        }

        if ($errors === []) {
            $errors = $this->overlaps($sources, $this->groupRepository->sourcesOfOtherPlayoffs($tournament->id, $exceptGroupId), $regular);
        }

        // Con el torneo en curso ya hay equipos en los grupos: la posición final no puede exceder los actuales.
        if ($errors === [] && $tournament->status === TournamentStatus::IN_PROGRESS) {
            $counts = $this->groupRepository->teamCounts($tournament->id);

            foreach ($sources as $position => $source) {
                $teams = $counts[$source['source_group_id']] ?? 0;

                if ($source['position_end'] > $teams) {
                    $name = $regular->get($source['source_group_id'])->name ?? '';
                    $errors["sources.{$position}.position_end"] = Message::getMessagePlayoffAsksTooMany($playoffName, $source['position_end'], $name, $teams);
                }
            }
        }

        if ($errors === [] && ! PlayoffRounds::isValidTotal($total)) {
            $errors['sources'] = Message::getMessagePlayoffTotalTeams($total);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array{source_group_id: int, position_start: int, position_end: int}>  $sources
     * @param  list<array{source_group_id: int, position_start: int, position_end: int}>  $others
     * @param  Collection<int|string, TournamentGroup>|\Illuminate\Support\Collection<int|string, TournamentGroup>  $regular
     * @return array<string, string>
     */
    private function overlaps(array $sources, array $others, mixed $regular): array
    {
        $errors = [];

        foreach ($sources as $position => $source) {
            $conflicts = array_merge(
                array_slice($sources, 0, $position),
                array_slice($sources, $position + 1),
                $others
            );

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
