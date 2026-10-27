<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Services\ScheduleGeneratorServiceInterface;
use App\Contracts\Services\ScheduleServiceInterface;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\ScheduleCapacity;
use App\Models\GameMatch;
use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-type Slot array{date: string, field_id: int, start: int, end: int}
 */
class ScheduleGeneratorService implements ScheduleGeneratorServiceInterface
{
    /** Minutos de duración del partido del torneo que se está programando. */
    private int $duration = 0;

    /**
     * Partidos de otros torneos de la liga (misma hora, cancha o equipo), solo durante compute().
     *
     * @var list<GameMatch>
     */
    private array $foreign = [];

    /**
     * Intervalos (minutos desde la época) en que un equipo ya juega en otros torneos de la liga.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    private array $foreignBusy = [];

    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected ScheduleServiceInterface $scheduleService,
    ) {}

    public function preview(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        return $this->compute($tournament, $mode, $scope, $seed)['result'];
    }

    public function generate(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        $computed = $this->compute($tournament, $mode, $scope, $seed);

        DB::transaction(fn () => $this->matchRepository->assignSchedule($computed['assignments']));

        return $computed['result'];
    }

    public function clear(Tournament $tournament): array
    {
        $this->assertReady($tournament, false);

        $assignments = [];
        $cleared = 0;

        foreach ($this->matchRepository->regularMatches($tournament->id) as $match) {
            if ($this->isKept($match)) {
                continue;
            }

            if ($match->match_datetime !== null || $match->field_id !== null) {
                $assignments[$match->id] = [null, null, true];
                $cleared++;
            }
        }

        DB::transaction(fn () => $this->matchRepository->assignSchedule($assignments));

        return ['cleared' => $cleared];
    }

    /**
     * @return array{result: array<string, mixed>, assignments: array<int, array{0: string|null, 1: int|null, 2: bool}>}
     */
    private function compute(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        $this->assertReady($tournament, true);

        $matches = $this->matchRepository->regularMatches($tournament->id);
        $matchdays = array_values(array_unique($matches->map(fn (GameMatch $match) => (int) $match->matchday)->all()));
        sort($matchdays);
        $plan = $this->scheduleService->plan($tournament, $matchdays, ScheduleService::MAX_WEEKS);

        $this->loadForeign($tournament, $plan['weeks']);

        $playedKept = 0;
        $lockedKept = 0;
        $assignedKept = 0;
        $occupied = [];
        $teamDays = [];
        $pool = [];

        foreach ($matches as $match) {
            if ($match->status === MatchStatus::PLAYED) {
                $playedKept++;
            } elseif ($match->schedule_locked) {
                $lockedKept++;
            } elseif ($scope === self::SCOPE_UNSCHEDULED && $match->match_datetime !== null) {
                $assignedKept++;
            } else {
                $pool[(int) $match->matchday][] = $match;

                continue;
            }

            $at = $match->match_datetime;

            if ($at !== null) {
                $date = $at->format('Y-m-d');
                $occupied[$date.'|'.$match->field_id.'|'.((int) $at->format('G') * 60 + (int) $at->format('i'))] = true;

                foreach ([$match->home_registration_id, $match->away_registration_id] as $team) {
                    if ($team !== null) {
                        $teamDays[$team][$date] = true;
                    }
                }
            }
        }

        $seed = $mode === self::MODE_RANDOM ? ($seed ?? random_int(0, 2147483647)) : null;
        $randomizer = $seed === null ? null : new Randomizer(new Mt19937($seed));
        $load = [];
        $assignments = [];
        $summary = [];
        $warnings = [];
        $listed = [];
        $scheduled = 0;
        $unscheduled = 0;

        foreach ($pool as $matchday => $dayMatches) {
            $weekIndex = $plan['assigned'][$matchday] ?? null;
            $week = $weekIndex === null ? null : $plan['weeks'][$weekIndex];
            $free = $week === null ? [] : array_values(array_filter(
                $week['slots'],
                fn (array $slot) => ! isset($occupied[$slot['date'].'|'.$slot['field_id'].'|'.$slot['start']]) && ! $this->takenByForeign($slot)
            ));

            $placed = $randomizer !== null
                ? $this->placeRandom($dayMatches, $free, $teamDays, $randomizer)
                : $this->placeBalanced($dayMatches, $free, $teamDays, $load);

            $rest = count($dayMatches) - count($placed);
            $scheduled += count($placed);
            $unscheduled += $rest;

            $summary[] = [
                'matchday' => $matchday,
                'week_start' => $week === null ? null : $week['monday']->toDateString(),
                'week_end' => $week === null ? null : $week['monday']->addDays(6)->toDateString(),
                'matches' => count($dayMatches),
                'scheduled' => count($placed),
                'unscheduled' => $rest,
            ];

            if ($rest > 0) {
                $warnings[] = [
                    'matchday' => $matchday,
                    'unscheduled' => $rest,
                    'message' => count($free) > count($placed)
                        ? "{$rest} partidos de la jornada {$matchday} no se pudieron ubicar sin que un equipo juegue dos veces el mismo día."
                        : ($rest === 1 ? "Falta 1 espacio en la jornada {$matchday}." : "Faltan {$rest} espacios en la jornada {$matchday}."),
                ];
            }

            foreach ($dayMatches as $match) {
                $slot = $placed[$match->id] ?? null;
                $datetime = $slot === null ? null : $slot['date'].' '.ScheduleCapacity::time($slot['start']).':00';

                // Con "all" se limpia lo que tuviera; con "unscheduled" el partido ya estaba sin horario.
                $assignments[$match->id] = [$datetime, $slot['field_id'] ?? null, $scope === self::SCOPE_ALL];
                $listed[] = [
                    'id' => $match->id,
                    'matchday' => $matchday,
                    'group' => $match->group->name ?? null,
                    'home_team' => $match->home?->team?->name,
                    'away_team' => $match->away?->team?->name,
                    'match_datetime' => $slot === null ? null : str_replace(' ', 'T', (string) $datetime),
                    'field' => $slot === null ? null : ['id' => $slot['field_id'], 'name' => $plan['names'][$slot['field_id']] ?? null],
                ];
            }
        }

        $result = [
            'scheduled' => $scheduled,
            'unscheduled' => $unscheduled,
            'locked_kept' => $lockedKept,
            'played_kept' => $playedKept,
            'assigned_kept' => $assignedKept,
            'seed' => $seed,
            'matchdays' => $summary,
            'warnings' => $warnings,
            'matches' => $listed,
        ];

        return ['result' => $result, 'assignments' => $assignments];
    }

    /**
     * Reparto parejo: los partidos se reparten entre los días de la semana y, dentro del día, entre las canchas. Las
     * franjas se rotan: un espacio temprano (primera mitad de los del día) toma el partido de los equipos con más
     * partidos tardíos y uno tardío el de los equipos con más tempranos. Los empates los decide el orden del partido.
     *
     * @param  list<GameMatch>  $matches
     * @param  list<Slot>  $free
     * @param  array<int, array<string, true>>  $teamDays
     * @param  array<int, array{early: int, late: int}>  $load
     * @return array<int, Slot> Espacio asignado por id de partido.
     */
    private function placeBalanced(array $matches, array $free, array &$teamDays, array &$load): array
    {
        $byDay = [];

        foreach ($free as $slot) {
            $byDay[$slot['date']][] = $slot;
        }

        $quota = array_fill_keys(array_keys($byDay), 0);
        $left = min(count($matches), count($free));

        while ($left > 0) {
            foreach ($byDay as $date => $slots) {
                if ($left > 0 && $quota[$date] < count($slots)) {
                    $quota[$date]++;
                    $left--;
                }
            }
        }

        $pending = $matches;
        $placed = [];
        $used = [];

        foreach ($byDay as $date => $slots) {
            $chosen = $this->pickSlots($slots, $quota[$date]);
            $half = intdiv(count($chosen) + 1, 2);

            foreach ($chosen as $index => $slot) {
                $early = $index < $half;
                $best = null;
                $bestScore = -1;

                foreach ($pending as $key => $match) {
                    if (! $this->teamsFree($match, $slot, $teamDays)) {
                        continue;
                    }

                    $score = 0;

                    foreach ([$match->home_registration_id, $match->away_registration_id] as $team) {
                        $score += $team === null ? 0 : ($load[$team][$early ? 'late' : 'early'] ?? 0);
                    }

                    if ($score > $bestScore) {
                        $best = $key;
                        $bestScore = $score;
                    }
                }

                if ($best !== null) {
                    $this->take($pending[$best], $slot, $teamDays);
                    $placed[$pending[$best]->id] = $slot;
                    $used[$slot['date'].'|'.$slot['field_id'].'|'.$slot['start']] = true;

                    foreach ([$pending[$best]->home_registration_id, $pending[$best]->away_registration_id] as $team) {
                        if ($team !== null) {
                            $load[$team] ??= ['early' => 0, 'late' => 0];
                            $load[$team][$early ? 'early' : 'late']++;
                        }
                    }

                    unset($pending[$best]);
                }
            }
        }

        // Los que no cupieron en su día (por equipos repetidos) prueban con cualquier espacio libre de la semana.
        foreach ($pending as $key => $match) {
            foreach ($free as $slot) {
                if (isset($used[$slot['date'].'|'.$slot['field_id'].'|'.$slot['start']]) || ! $this->teamsFree($match, $slot, $teamDays)) {
                    continue;
                }

                $this->take($match, $slot, $teamDays);
                $placed[$match->id] = $slot;
                $used[$slot['date'].'|'.$slot['field_id'].'|'.$slot['start']] = true;
                unset($pending[$key]);

                break;
            }
        }

        return $placed;
    }

    /**
     * @param  list<GameMatch>  $matches
     * @param  list<Slot>  $free
     * @param  array<int, array<string, true>>  $teamDays
     * @return array<int, Slot>
     */
    private function placeRandom(array $matches, array $free, array &$teamDays, Randomizer $randomizer): array
    {
        $slots = $randomizer->shuffleArray($free);
        $placed = [];
        $used = [];

        foreach ($randomizer->shuffleArray($matches) as $match) {
            foreach ($slots as $index => $slot) {
                if (isset($used[$index]) || ! $this->teamsFree($match, $slot, $teamDays)) {
                    continue;
                }

                $this->take($match, $slot, $teamDays);
                $placed[$match->id] = $slot;
                $used[$index] = true;

                break;
            }
        }

        return $placed;
    }

    /**
     * Elige $count espacios del día repartidos entre las canchas (por turnos, cada una desde su hora más temprana).
     *
     * @param  list<Slot>  $slots  Ordenados por hora.
     * @return list<Slot> Ordenados por hora y cancha.
     */
    private function pickSlots(array $slots, int $count): array
    {
        $byField = [];

        foreach ($slots as $slot) {
            $byField[$slot['field_id']][] = $slot;
        }

        $picked = [];

        while (count($picked) < $count) {
            foreach (array_keys($byField) as $fieldId) {
                $slot = count($picked) < $count ? array_shift($byField[$fieldId]) : null;

                if ($slot !== null) {
                    $picked[] = $slot;
                }
            }
        }

        usort($picked, fn (array $a, array $b) => [$a['start'], $a['field_id']] <=> [$b['start'], $b['field_id']]);

        return $picked;
    }

    /**
     * Ningún equipo repite día en este torneo ni juega a esa hora en otro torneo de la liga.
     *
     * @param  Slot  $slot
     * @param  array<int, array<string, true>>  $teamDays
     */
    private function teamsFree(GameMatch $match, array $slot, array $teamDays): bool
    {
        [$from, $to] = $this->interval($slot);

        foreach ([$match->home, $match->away] as $registration) {
            if ($registration === null) {
                continue;
            }

            if (isset($teamDays[$registration->id][$slot['date']])) {
                return false;
            }

            foreach ($this->foreignBusy[$registration->team_id] ?? [] as [$busyFrom, $busyTo]) {
                if ($busyFrom < $to && $from < $busyTo) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Carga los partidos de otros torneos de la liga que caen en las semanas del patrón.
     *
     * @param  list<array{week: int, monday: CarbonImmutable, slots: list<Slot>, matchday: int|null}>  $weeks
     */
    private function loadForeign(Tournament $tournament, array $weeks): void
    {
        $this->duration = max(1, (int) $tournament->match_duration_minutes);
        $this->foreign = [];
        $this->foreignBusy = [];

        if ($weeks === []) {
            return;
        }

        $from = $weeks[0]['monday']->subMinutes(Tournament::MAX_MATCH_DURATION_MINUTES);
        $to = $weeks[array_key_last($weeks)]['monday']->addDays(7);

        foreach ($this->matchRepository->scheduledInLeague($tournament->league_id, $from, $to) as $other) {
            if ($other->tournament_id === $tournament->id || $other->match_datetime === null) {
                continue;
            }

            $this->foreign[] = $other;
            $start = intdiv($other->match_datetime->getTimestamp(), 60);
            $end = $start + max(1, (int) $other->tournament?->match_duration_minutes);

            foreach ([$other->home, $other->away] as $registration) {
                if ($registration !== null) {
                    $this->foreignBusy[$registration->team_id][] = [$start, $end];
                }
            }
        }
    }

    /**
     * Si un partido de otro torneo ocupa esa cancha en un horario que se traslapa con el espacio.
     *
     * @param  Slot  $slot
     */
    private function takenByForeign(array $slot): bool
    {
        [$from, $to] = $this->interval($slot);

        foreach ($this->foreign as $other) {
            if ($other->field_id !== $slot['field_id'] || $other->match_datetime === null) {
                continue;
            }

            $start = intdiv($other->match_datetime->getTimestamp(), 60);

            if ($start < $to && $from < $start + max(1, (int) $other->tournament?->match_duration_minutes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Intervalo del espacio en minutos desde la época.
     *
     * @param  Slot  $slot
     * @return array{0: int, 1: int}
     */
    private function interval(array $slot): array
    {
        $from = intdiv(CarbonImmutable::parse($slot['date'])->getTimestamp(), 60) + $slot['start'];

        return [$from, $from + $this->duration];
    }

    /**
     * @param  Slot  $slot
     * @param  array<int, array<string, true>>  $teamDays
     */
    private function take(GameMatch $match, array $slot, array &$teamDays): void
    {
        foreach ([$match->home_registration_id, $match->away_registration_id] as $team) {
            if ($team !== null) {
                $teamDays[$team][$slot['date']] = true;
            }
        }
    }

    /**
     * Jugados y bloqueados no se mueven nunca.
     */
    private function isKept(GameMatch $match): bool
    {
        return $match->status === MatchStatus::PLAYED || $match->schedule_locked;
    }

    private function assertReady(Tournament $tournament, bool $needsConfig): void
    {
        $unprocessable = Response::HTTP_UNPROCESSABLE_ENTITY;

        if ($tournament->status !== TournamentStatus::IN_PROGRESS) {
            throw new CustomErrorException(Message::SCHEDULE_ONLY_IN_PROGRESS, $unprocessable);
        }

        if ($this->matchRepository->regularMatches($tournament->id)->isEmpty()) {
            throw new CustomErrorException(Message::SCHEDULE_NO_CALENDAR, $unprocessable);
        }

        if ($needsConfig && ! $this->isConfigured($tournament)) {
            throw new CustomErrorException(Message::SCHEDULE_NOT_CONFIGURED, $unprocessable);
        }
    }

    private function isConfigured(Tournament $tournament): bool
    {
        return $tournament->schedule_start_date !== null
            && $tournament->match_duration_minutes > 0
            && $tournament->scheduleWindows()->exists();
    }
}
