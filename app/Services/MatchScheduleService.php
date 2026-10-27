<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Services\MatchScheduleServiceInterface;
use App\Contracts\Services\ScheduleServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\ScheduleCapacity;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MatchScheduleService implements MatchScheduleServiceInterface
{
    /** Duración máxima de un partido (Tournament::MAX_MATCH_DURATION_MINUTES): ventana para buscar traslapes. */
    private const LOOKBACK_MINUTES = Tournament::MAX_MATCH_DURATION_MINUTES;

    private const RELATIONS = ['tournament', 'home.team', 'away.team'];

    private const WEEKDAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected ScheduleServiceInterface $scheduleService,
    ) {}

    public function update(League $league, GameMatch $match, array $data): array
    {
        $this->prepare($match);
        $this->assertEditable($league, $match);

        $tournament = $this->tournament($match);
        $current = $match->match_datetime === null ? null : CarbonImmutable::instance($match->match_datetime);
        $datetime = array_key_exists('match_datetime', $data) ? $this->toDate($data['match_datetime']) : $current;
        $fieldSent = array_key_exists('field_id', $data);
        $fieldId = $fieldSent ? $this->toId($data['field_id']) : $match->field_id;
        $refereeSent = array_key_exists('referee_user_id', $data);
        $refereeId = $refereeSent ? $this->toId($data['referee_user_id']) : $match->referee_user_id;

        if (array_key_exists('match_datetime', $data) && $datetime === null) {
            if ($fieldSent && $fieldId !== null) {
                throw new CustomErrorException(Message::MATCH_SCHEDULE_FIELD_NEEDS_DATETIME, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $fieldId = null;
        }

        if ($fieldId !== null && $datetime === null) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_FIELD_NEEDS_DATETIME, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $touchesSchedule = array_key_exists('match_datetime', $data) || $fieldSent || $refereeSent;
        $warnings = [];

        if ($touchesSchedule) {
            if ($fieldSent && $fieldId !== null) {
                $this->assertField($league, $fieldId);
            }

            if ($refereeSent && $refereeId !== null) {
                $this->assertReferee($league, $match, $refereeId);
            }

            $this->assertNoConflict($match, $datetime, $fieldId, $refereeId, []);

            if ($datetime !== null && (array_key_exists('match_datetime', $data) || $fieldSent)) {
                $warnings = $this->warnings($match, $tournament, $datetime, $fieldId);
            }
        }

        $attributes = [
            'match_datetime' => $datetime?->format('Y-m-d H:i:s'),
            'field_id' => $fieldId,
            'referee_user_id' => $refereeId,
            'original_match_datetime' => $this->original($current, $match->original_match_datetime, $datetime)?->format('Y-m-d H:i:s'),
        ];

        if (array_key_exists('schedule_locked', $data)) {
            $attributes['schedule_locked'] = (bool) $data['schedule_locked'];
        } elseif ($touchesSchedule) {
            $attributes['schedule_locked'] = true;
        }

        $this->matchRepository->saveSchedule($match, $attributes);

        return ['match' => $this->reload($match), 'warnings' => $this->finalizeWarnings($warnings)];
    }

    public function swap(League $league, GameMatch $match, int $withMatchId): array
    {
        $this->prepare($match);
        $this->assertEditable($league, $match, false);

        if ($withMatchId === $match->id) {
            throw new CustomErrorException(Message::MATCH_SWAP_SAME_MATCH, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $other = GameMatch::query()->with(self::RELATIONS)->find($withMatchId);

        if ($other === null || $other->tournament?->league_id !== $league->id) {
            throw ValidationException::withMessages(['with_match_id' => Message::MATCH_SWAP_NOT_FOUND]);
        }

        if ($other->tournament_id !== $match->tournament_id) {
            throw new CustomErrorException(Message::MATCH_SWAP_DIFFERENT_TOURNAMENT, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ([$match, $other] as $played) {
            if ($played->status === MatchStatus::PLAYED) {
                throw new CustomErrorException(Message::MATCH_SCHEDULE_PLAYED, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $this->assertInProgress($this->tournament($match));

        $first = ['at' => $this->toDate($match->match_datetime), 'field' => $match->field_id, 'referee' => $match->referee_user_id];
        $second = ['at' => $this->toDate($other->match_datetime), 'field' => $other->field_id, 'referee' => $other->referee_user_id];
        $exclude = [$match->id, $other->id];

        foreach ([[$match, $second], [$other, $first]] as [$target, $slot]) {
            if ($slot['referee'] !== null) {
                $this->assertReferee($league, $target, $slot['referee']);
            }

            $this->assertNoConflict($target, $slot['at'], $slot['field'], $slot['referee'], $exclude);
        }

        $tournament = $this->tournament($match);
        $warnings = [...$this->warnings($match, $tournament, $second['at'], $second['field'], $exclude), ...$this->warnings($other, $tournament, $first['at'], $first['field'], $exclude)];

        DB::transaction(function () use ($match, $other, $first, $second) {
            foreach ([[$match, $first, $second], [$other, $second, $first]] as [$target, $old, $new]) {
                $this->matchRepository->saveSchedule($target, [
                    'match_datetime' => $new['at']?->format('Y-m-d H:i:s'),
                    'field_id' => $new['field'],
                    'referee_user_id' => $new['referee'],
                    'original_match_datetime' => $this->original($old['at'], $target->original_match_datetime, $new['at'])?->format('Y-m-d H:i:s'),
                    'schedule_locked' => true,
                ]);
            }
        });

        return [
            'match' => $this->reload($match),
            'with_match' => $this->reload($other),
            'warnings' => $this->finalizeWarnings($warnings),
        ];
    }

    public function availableSlots(League $league, GameMatch $match, ?string $date): array
    {
        $this->prepare($match);
        $this->assertInLeague($league, $match);

        $tournament = $this->tournament($match);
        $day = $date !== null ? $date : ($match->match_datetime?->format('Y-m-d') ?? $this->scheduleService->firstSlotDate($tournament));

        if ($day === null) {
            return ['date' => null, 'slots' => []];
        }

        $slots = $this->scheduleService->slotsOfDate($tournament, $day);
        $dayStart = CarbonImmutable::parse($day)->startOfDay();
        $others = $this->matchRepository->scheduledInLeague($league->id, $dayStart->subMinutes(self::LOOKBACK_MINUTES), $dayStart->endOfDay(), [$match->id]);
        $duration = $this->duration($tournament);
        $teams = $this->teamIds($match);
        $names = [];

        foreach ($this->scheduleRepository->windows($tournament->id) as $window) {
            $names[(int) $window->field_id] = $window->field->name ?? '';
        }

        $free = [];

        foreach ($slots as $slot) {
            $at = CarbonImmutable::parse($slot['date'])->addMinutes($slot['start']);
            $busy = $others->contains(fn (GameMatch $other) => $this->overlaps($at, $duration, $other)
                && ($other->field_id === $slot['field_id'] || array_intersect($teams, $this->teamIds($other)) !== []));

            if (! $busy) {
                $free[] = [
                    'start_time' => ScheduleCapacity::time($slot['start']),
                    'end_time' => ScheduleCapacity::time($slot['end']),
                    'field_id' => $slot['field_id'],
                    'field_name' => $names[$slot['field_id']] ?? '',
                ];
            }
        }

        return ['date' => $day, 'slots' => $free];
    }

    public function availableReferees(League $league, GameMatch $match, ?string $datetime): array
    {
        $this->prepare($match);
        $this->assertInLeague($league, $match);

        $at = $datetime !== null ? $this->toDate($datetime) : $this->toDate($match->match_datetime);
        $duration = $this->duration($this->tournament($match));
        $registrations = [$match->home_registration_id, $match->away_registration_id];
        $registrations = array_values(array_filter($registrations, fn (?int $id) => $id !== null));
        $busy = $at === null ? collect() : $this->matchRepository
            ->scheduledInLeague($league->id, $at->subMinutes(self::LOOKBACK_MINUTES), $at->addMinutes($duration), [$match->id])
            ->filter(fn (GameMatch $other) => $other->referee_user_id !== null && $this->overlaps($at, $duration, $other));

        $result = [];

        foreach ($this->scheduleRepository->activeReferees($league->id) as $referee) {
            $reason = null;
            $conflict = $busy->first(fn (GameMatch $other) => $other->referee_user_id === $referee->user_id);

            if ($registrations !== [] && $this->scheduleRepository->playsInRegistrations($referee->user_id, $registrations)) {
                $reason = Message::MATCH_SCHEDULE_REFEREE_PLAYS;
            } elseif ($conflict !== null) {
                $reason = 'Ocupado en '.$this->describe($conflict).'.';
            }

            $result[] = [
                'id' => $referee->user_id,
                'name' => trim(($referee->user->first_name ?? '').' '.($referee->user->last_name ?? '')),
                'available' => $reason === null,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    /**
     * El partido debe ser de la liga (si no, 404) y, al editar, no estar jugado y con el torneo en curso.
     */
    private function assertEditable(League $league, GameMatch $match, bool $checkStatus = true): void
    {
        $this->assertInLeague($league, $match);

        if ($match->status === MatchStatus::PLAYED) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_PLAYED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($checkStatus) {
            $this->assertInProgress($this->tournament($match));
        }
    }

    private function assertInLeague(League $league, GameMatch $match): void
    {
        if ($this->tournament($match)->league_id !== $league->id) {
            throw new ModelNotFoundException;
        }
    }

    private function assertInProgress(Tournament $tournament): void
    {
        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_ONLY_IN_PROGRESS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function assertField(League $league, int $fieldId): void
    {
        if ($this->scheduleRepository->countLeagueFields($league->id, [$fieldId], true) !== 1) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_FIELD_UNAVAILABLE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function assertReferee(League $league, GameMatch $match, int $refereeId): void
    {
        if (! $this->scheduleRepository->isActiveReferee($league->id, $refereeId)) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_REFEREE_INACTIVE, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $registrations = array_values(array_filter([$match->home_registration_id, $match->away_registration_id], fn (?int $id) => $id !== null));

        if ($registrations !== [] && $this->scheduleRepository->playsInRegistrations($refereeId, $registrations)) {
            throw new CustomErrorException(Message::MATCH_SCHEDULE_REFEREE_PLAYS, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Cancha, árbitro y equipo contra el resto de la liga: traslape de [inicio, inicio + duración del torneo de cada
     * partido). Pegados (uno empieza cuando el otro termina) no se traslapan.
     *
     * @param  list<int>  $exclude  Partidos que no cuentan (además del propio).
     *
     * @throws CustomErrorException
     */
    private function assertNoConflict(GameMatch $match, ?CarbonImmutable $at, ?int $fieldId, ?int $refereeId, array $exclude): void
    {
        if ($at === null) {
            return;
        }

        $duration = $this->duration($this->tournament($match));
        $others = $this->matchRepository
            ->scheduledInLeague($this->tournament($match)->league_id, $at->subMinutes(self::LOOKBACK_MINUTES), $at->addMinutes($duration), [$match->id, ...$exclude])
            ->filter(fn (GameMatch $other) => $this->overlaps($at, $duration, $other));
        $teams = $this->teamIds($match);

        foreach ($others as $other) {
            if ($fieldId !== null && $other->field_id === $fieldId) {
                $this->conflict(self::CODE_FIELD_CONFLICT, 'La cancha ya está ocupada por '.$this->describe($other).'.');
            }
        }

        foreach ($others as $other) {
            if ($refereeId !== null && $other->referee_user_id === $refereeId) {
                $this->conflict(self::CODE_REFEREE_CONFLICT, 'El árbitro ya está asignado a '.$this->describe($other).'.');
            }
        }

        foreach ($others as $other) {
            $shared = array_values(array_intersect($teams, $this->teamIds($other)));

            if ($shared !== []) {
                $this->conflict(self::CODE_TEAM_CONFLICT, 'El equipo '.$this->teamName($match, $other, $shared[0]).' ya juega en '.$this->describe($other).'.');
            }
        }
    }

    /**
     * @throws CustomErrorException
     */
    private function conflict(string $code, string $message): never
    {
        throw new CustomErrorException($message, Response::HTTP_UNPROCESSABLE_ENTITY, ['code' => $code, 'message' => $message]);
    }

    /**
     * Avisos que no bloquean: fuera de ventanas, equipo que ya juega ese día (sin traslape) y fecha pasada.
     *
     * @param  list<int>  $exclude
     * @return list<array{code: string, message: string, team?: string}>
     */
    private function warnings(GameMatch $match, Tournament $tournament, ?CarbonImmutable $at, ?int $fieldId, array $exclude = []): array
    {
        if ($at === null) {
            return [];
        }

        $warnings = [];

        if ($this->scheduleService->fitsWindows($tournament, $at, $fieldId) === false) {
            $warnings[] = ['code' => 'outside_schedule', 'message' => Message::MATCH_WARNING_OUTSIDE_SCHEDULE];
        }

        $teams = $this->teamIds($match);
        $sameDay = $this->matchRepository->scheduledInLeague($tournament->league_id, $at->startOfDay(), $at->endOfDay(), [$match->id, ...$exclude]);

        foreach ($teams as $team) {
            $other = $sameDay->first(fn (GameMatch $candidate) => in_array($team, $this->teamIds($candidate), true));

            if ($other !== null) {
                $warnings[] = ['code' => 'same_team_same_day', 'message' => '', 'team' => $this->teamName($match, $other, $team)];
            }
        }

        if ($at->isPast()) {
            $warnings[] = ['code' => 'past_date', 'message' => Message::MATCH_WARNING_PAST_DATE];
        }

        return $warnings;
    }

    /**
     * Un solo aviso `same_team_same_day` con todos los equipos (sin repetir) y el resto sin duplicados.
     *
     * @param  list<array{code: string, message: string, team?: string}>  $warnings
     * @return list<array{code: string, message: string}>
     */
    private function finalizeWarnings(array $warnings): array
    {
        $result = [];
        $teams = [];

        foreach ($warnings as $warning) {
            if ($warning['code'] === 'same_team_same_day') {
                $teams[$warning['team'] ?? ''] = $warning['team'] ?? '';
                // El aviso único ocupa el lugar del primero.
                $result['same_team_same_day'] = ['code' => 'same_team_same_day', 'message' => ''];

                continue;
            }

            $result[$warning['code'].'|'.$warning['message']] = ['code' => $warning['code'], 'message' => $warning['message']];
        }

        if ($teams !== []) {
            $names = array_values($teams);
            $last = array_pop($names);
            $result['same_team_same_day'] = [
                'code' => 'same_team_same_day',
                'message' => $names === []
                    ? "El equipo {$last} ya juega ese día."
                    : 'Los equipos '.implode(', ', $names)." y {$last} ya juegan ese día.",
            ];
        }

        return array_values($result);
    }

    /**
     * Horario original: se llena la primera vez que un partido con hora pasa a otra fecha u hora y se limpia si
     * vuelve a ella. No cambia si solo cambian cancha o árbitro, ni al quitar la hora.
     */
    private function original(?CarbonInterface $old, ?CarbonInterface $original, ?CarbonInterface $new): ?CarbonInterface
    {
        if ($new === null) {
            return $original;
        }

        if ($original !== null && $original->format('Y-m-d H:i') === $new->format('Y-m-d H:i')) {
            return null;
        }

        if ($original === null && $old !== null && $old->format('Y-m-d H:i') !== $new->format('Y-m-d H:i')) {
            return $old;
        }

        return $original;
    }

    private function overlaps(CarbonImmutable $at, int $duration, GameMatch $other): bool
    {
        if ($other->match_datetime === null) {
            return false;
        }

        $start = CarbonImmutable::instance($other->match_datetime);
        $otherDuration = $this->duration($this->tournament($other));

        return $start->lt($at->addMinutes($duration)) && $at->lt($start->addMinutes($otherDuration));
    }

    /**
     * Equipos (no inscripciones: cambian por torneo) de un partido.
     *
     * @return list<int>
     */
    private function teamIds(GameMatch $match): array
    {
        $ids = [];

        foreach ([$match->home, $match->away] as $registration) {
            if ($registration !== null) {
                $ids[] = (int) $registration->team_id;
            }
        }

        return $ids;
    }

    private function teamName(GameMatch $match, GameMatch $other, int $teamId): string
    {
        foreach ([$match->home, $match->away, $other->home, $other->away] as $registration) {
            if ($registration !== null && (int) $registration->team_id === $teamId) {
                return (string) ($registration->team->name ?? '');
            }
        }

        return '';
    }

    /**
     * «Toros vs Tigres (Sáb 07/11, 8:00 a.m.)»
     */
    private function describe(GameMatch $match): string
    {
        $at = $match->match_datetime;
        $when = $at === null ? '' : ' ('.self::WEEKDAYS[$at->dayOfWeek].' '.$at->format('d/m').', '.$at->format('g:i').' '.($at->format('A') === 'AM' ? 'a.m.' : 'p.m.').')';

        return ($match->home->team->name ?? '?').' vs '.($match->away->team->name ?? '?').$when;
    }

    private function prepare(GameMatch $match): void
    {
        $match->loadMissing(self::RELATIONS);
    }

    private function tournament(GameMatch $match): Tournament
    {
        return $match->tournament ?? throw new ModelNotFoundException;
    }

    private function duration(Tournament $tournament): int
    {
        $raw = $tournament->getAttribute('match_duration_minutes');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : Tournament::DEFAULT_MATCH_DURATION_MINUTES;
    }

    private function toDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse(is_string($value) ? $value : '')->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private function toId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function reload(GameMatch $match): GameMatch
    {
        return $match->refresh()->load(['group', 'field', 'referee', 'home.team', 'away.team']);
    }
}
