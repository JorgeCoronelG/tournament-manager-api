<?php

namespace App\Services;

use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Services\ScheduleServiceInterface;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\ScheduleCapacity;
use App\Models\Tournament;
use App\Models\TournamentScheduleBlock;
use App\Models\TournamentScheduleWindow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ScheduleService implements ScheduleServiceInterface
{
    /** Tope de semanas que se recorren para ubicar las jornadas. */
    private const MAX_WEEKS = 52;

    public function __construct(protected ScheduleRepositoryInterface $scheduleRepository) {}

    public function show(Tournament $tournament): array
    {
        return [
            'schedule_start_date' => $this->startDate($tournament)?->toDateString(),
            'match_duration_minutes' => $tournament->match_duration_minutes,
            'windows' => $this->scheduleRepository->windows($tournament->id)->map(fn (TournamentScheduleWindow $window) => [
                'weekday' => (int) $window->weekday,
                'field' => ['id' => $window->field_id, 'name' => $window->field->name ?? null],
                'start_time' => ScheduleCapacity::time(ScheduleCapacity::minutes($window->start_time)),
                'end_time' => ScheduleCapacity::time(ScheduleCapacity::minutes($window->end_time)),
            ])->values()->all(),
            'blocks' => $this->scheduleRepository->blocks($tournament->id)->map(fn (TournamentScheduleBlock $block) => [
                'date' => $block->date->toDateString(),
                'field' => $block->field_id === null ? null : ['id' => $block->field_id, 'name' => $block->field->name ?? null],
                'start_time' => $block->start_time === null ? null : ScheduleCapacity::time(ScheduleCapacity::minutes($block->start_time)),
                'end_time' => $block->end_time === null ? null : ScheduleCapacity::time(ScheduleCapacity::minutes($block->end_time)),
                'reason' => $block->reason,
            ])->values()->all(),
        ];
    }

    public function save(Tournament $tournament, array $data): array
    {
        if (! in_array($tournament->status, [TournamentStatus::PLANNING, TournamentStatus::IN_PROGRESS], true)) {
            throw new CustomErrorException(Message::SCHEDULE_NOT_EDITABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $duration = $this->duration($tournament);
        $windows = $data['windows'];
        $blocks = $data['blocks'];

        $this->validateWindows($tournament, $windows, $duration);
        $this->validateBlocks($tournament, $blocks);

        if ($windows !== [] && $data['schedule_start_date'] === null) {
            throw ValidationException::withMessages(['schedule_start_date' => Message::SCHEDULE_START_DATE_REQUIRED]);
        }

        $this->scheduleRepository->replace($tournament, $data['schedule_start_date'], $windows, $blocks);

        return $this->show($tournament->refresh());
    }

    public function capacity(Tournament $tournament): array
    {
        $windows = array_values($this->scheduleRepository->windows($tournament->id)->map(fn (TournamentScheduleWindow $window) => [
            'weekday' => (int) $window->weekday,
            'field_id' => (int) $window->field_id,
            'start' => ScheduleCapacity::minutes($window->start_time),
            'end' => ScheduleCapacity::minutes($window->end_time),
        ])->all());
        $blocks = array_values($this->scheduleRepository->blocks($tournament->id)->map(fn (TournamentScheduleBlock $block) => [
            'date' => $block->date->toDateString(),
            'field_id' => $block->field_id === null ? null : (int) $block->field_id,
            'start' => $block->start_time === null ? null : ScheduleCapacity::minutes($block->start_time),
            'end' => $block->end_time === null ? null : ScheduleCapacity::minutes($block->end_time),
        ])->all());

        return $this->computeCapacity($tournament, $this->startDate($tournament), $windows, $blocks);
    }

    public function previewCapacity(Tournament $tournament, array $data): array
    {
        $windows = array_map(fn (array $window) => [
            'weekday' => $window['weekday'],
            'field_id' => $window['field_id'],
            'start' => ScheduleCapacity::minutes($window['start_time']),
            'end' => ScheduleCapacity::minutes($window['end_time']),
        ], $data['windows']);
        $blocks = array_map(fn (array $block) => [
            'date' => $block['date'],
            'field_id' => $block['field_id'],
            'start' => $block['start_time'] === null ? null : ScheduleCapacity::minutes($block['start_time']),
            'end' => $block['end_time'] === null ? null : ScheduleCapacity::minutes($block['end_time']),
        ], $data['blocks']);
        $startDate = $data['schedule_start_date'] === null ? null : CarbonImmutable::parse($data['schedule_start_date']);

        return $this->computeCapacity($tournament, $startDate, $windows, $blocks);
    }

    /**
     * @param  list<array{weekday: int, field_id: int, start: int, end: int}>  $windows
     * @param  list<array{date: string, field_id: int|null, start: int|null, end: int|null}>  $blocks
     * @return array<string, mixed>
     */
    private function computeCapacity(Tournament $tournament, ?CarbonImmutable $startDate, array $windows, array $blocks): array
    {
        $duration = $this->duration($tournament);
        $perMatchday = $this->scheduleRepository->matchesPerMatchday($tournament->id);
        $hasCalendar = $perMatchday !== [];

        // Las jornadas, en orden, van solo a las semanas con espacios; las de 0 espacios se saltan sin consumir jornada.
        $pending = array_keys($perMatchday);
        $assigned = [];

        for ($week = 1; $startDate !== null && $hasCalendar && $pending !== [] && $week <= self::MAX_WEEKS; $week++) {
            $monday = $startDate->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($week - 1);
            $slots = ScheduleCapacity::weekSlots($monday, $startDate, $windows, $blocks, $duration);

            if ($slots !== []) {
                $matchday = array_shift($pending);
                $assigned[$matchday] = ['week' => $week, 'start_date' => $monday->toDateString(), 'end_date' => $monday->addDays(6)->toDateString(), 'slots' => count($slots)];
            }
        }

        $matchdays = [];

        foreach ($perMatchday as $matchday => $required) {
            $slotsAvailable = $assigned[$matchday]['slots'] ?? 0;
            $matchdays[] = [
                'matchday' => $matchday,
                'week' => $assigned[$matchday]['week'] ?? null,
                'start_date' => $assigned[$matchday]['start_date'] ?? null,
                'end_date' => $assigned[$matchday]['end_date'] ?? null,
                'matches_required' => $required,
                'slots_available' => $slotsAvailable,
                'deficit' => max(0, $required - $slotsAvailable),
            ];
        }

        return [
            'schedule_start_date' => $startDate?->toDateString(),
            'match_duration_minutes' => $duration,
            'has_calendar' => $hasCalendar,
            'schedule_outdated' => $this->scheduleRepository->hasTimedMatches($tournament->id),
            'matchdays' => $matchdays,
            'warnings' => array_values(array_filter($matchdays, fn (array $matchday) => $matchday['deficit'] > 0)),
        ];
    }

    private function startDate(Tournament $tournament): ?CarbonImmutable
    {
        return $tournament->schedule_start_date === null
            ? null
            : CarbonImmutable::parse($tournament->schedule_start_date->toDateString());
    }

    private function duration(Tournament $tournament): int
    {
        $raw = $tournament->getAttribute('match_duration_minutes');
        $minutes = is_numeric($raw) ? (int) $raw : 0;

        if ($minutes <= 0) {
            throw new CustomErrorException(Message::SCHEDULE_DURATION_REQUIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $minutes;
    }

    /**
     * @param  list<array{weekday: int, field_id: int, start_time: string, end_time: string}>  $windows
     */
    private function validateWindows(Tournament $tournament, array $windows, int $duration): void
    {
        $ranges = [];

        foreach ($windows as $window) {
            $range = ['start' => ScheduleCapacity::minutes($window['start_time']), 'end' => ScheduleCapacity::minutes($window['end_time'])];

            if ($range['start'] >= $range['end']) {
                throw ValidationException::withMessages(['windows' => Message::SCHEDULE_TIME_ORDER]);
            }

            $ranges[] = $range + ['weekday' => $window['weekday'], 'field_id' => $window['field_id']];
        }

        foreach ($ranges as $i => $a) {
            foreach (array_slice($ranges, $i + 1) as $b) {
                if ($a['field_id'] === $b['field_id'] && $a['weekday'] === $b['weekday'] && ScheduleCapacity::overlap($a, $b)) {
                    throw ValidationException::withMessages(['windows' => Message::SCHEDULE_WINDOWS_OVERLAP]);
                }
            }
        }

        $this->assertFields($tournament, array_column($windows, 'field_id'), true, 'windows');

        foreach ($ranges as $range) {
            if ($duration > $range['end'] - $range['start']) {
                throw ValidationException::withMessages(['windows' => Message::SCHEDULE_WINDOW_TOO_SHORT]);
            }
        }
    }

    /**
     * @param  list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null, reason: string|null}>  $blocks
     */
    private function validateBlocks(Tournament $tournament, array $blocks): void
    {
        foreach ($blocks as $block) {
            if (($block['start_time'] === null) !== ($block['end_time'] === null)) {
                throw ValidationException::withMessages(['blocks' => Message::SCHEDULE_BLOCK_TIME_PAIR]);
            }

            if ($block['start_time'] !== null && $block['end_time'] !== null
                && ScheduleCapacity::minutes($block['start_time']) >= ScheduleCapacity::minutes($block['end_time'])) {
                throw ValidationException::withMessages(['blocks' => Message::SCHEDULE_TIME_ORDER]);
            }
        }

        $fieldIds = array_filter(array_column($blocks, 'field_id'), fn (?int $id) => $id !== null);

        // Un bloqueo puede apuntar a una cancha que luego se desactivó: basta con que sea de la liga.
        $this->assertFields($tournament, array_values($fieldIds), false, 'blocks');
    }

    /**
     * @param  list<int>  $fieldIds
     */
    private function assertFields(Tournament $tournament, array $fieldIds, bool $onlyActive, string $key): void
    {
        $distinct = array_values(array_unique($fieldIds));

        if ($distinct !== [] && $this->scheduleRepository->countLeagueFields($tournament->league_id, $distinct, $onlyActive) !== count($distinct)) {
            throw ValidationException::withMessages([$key => Message::SCHEDULE_FIELD_UNAVAILABLE]);
        }
    }
}
