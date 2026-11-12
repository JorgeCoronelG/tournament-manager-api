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
     * Intervalos (minutos desde la época) en que un equipo ya juega en otros torneos de la liga.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    private array $foreignBusy = [];

    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected ScheduleServiceInterface $scheduleService,
        protected FieldAvailability $fieldAvailability,
    ) {}

    public function preview(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        return $this->compute($tournament, $mode, $scope, $seed)['result'];
    }

    public function generate(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        $computed = $this->compute($tournament, $mode, $scope, $seed);
        $assignments = $computed['assignments'];

        // Entre el cálculo y el guardado pudo cambiar la liga: dentro de la transacción se vuelven a leer (con bloqueo)
        // los partidos del rango y cada asignación se revalida. La que ya choca no se guarda; el resto sí.
        $taken = DB::transaction(function () use ($tournament, $computed, &$assignments) {
            $taken = $this->revalidate($tournament, $assignments, $computed['range']);

            foreach (array_keys($taken) as $matchId) {
                $assignments[$matchId] = [null, null, $assignments[$matchId][2]];
            }

            $this->matchRepository->assignSchedule($assignments);

            return $taken;
        });

        return $this->withSlotTaken($computed['result'], $taken);
    }

    /**
     * Asignaciones cuyo espacio ya ocupa otro partido de la liga: id del partido => partido que lo ocupa.
     *
     * @param  array<int, array{0: string|null, 1: int|null, 2: bool}>  $assignments
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $range
     * @return array<int, GameMatch>
     */
    private function revalidate(Tournament $tournament, array $assignments, ?array $range): array
    {
        if ($range === null) {
            return [];
        }

        $current = $this->fieldAvailability->forRange($tournament->league_id, $range[0], $range[1], array_keys($assignments), true);
        $taken = [];

        foreach ($assignments as $matchId => [$datetime, $fieldId]) {
            $occupant = $datetime === null || $fieldId === null
                ? null
                : $current->occupantAt($fieldId, CarbonImmutable::parse($datetime), $this->duration);

            if ($occupant !== null) {
                $taken[$matchId] = $occupant;
            }
        }

        return $taken;
    }

    /**
     * Deja sin horario en la respuesta los partidos cuyo espacio se ocupó y agrega un aviso `slot_taken` por cada uno.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, GameMatch>  $taken
     * @return array<string, mixed>
     */
    private function withSlotTaken(array $result, array $taken): array
    {
        if ($taken === []) {
            return $result;
        }

        /** @var list<array{id: int, matchday: int, group: string|null, home_team: string|null, away_team: string|null, match_datetime: string|null, field: array{id: int, name: string|null}|null}> $matches */
        $matches = $result['matches'];
        /** @var list<array{matchday: int, week_start: string|null, week_end: string|null, matches: int, scheduled: int, unscheduled: int, occupied_by_others: list<array<string, mixed>>}> $matchdays */
        $matchdays = $result['matchdays'];
        /** @var list<array<string, mixed>> $warnings */
        $warnings = $result['warnings'];
        /** @var int $scheduled */
        $scheduled = $result['scheduled'];
        /** @var int $unscheduled */
        $unscheduled = $result['unscheduled'];

        foreach ($matches as $index => $listed) {
            $occupant = $taken[$listed['id']] ?? null;

            if ($occupant === null) {
                continue;
            }

            $listed = ['match_datetime' => null, 'field' => null] + $listed;
            $matches[$index] = $listed;
            $by = FieldAvailability::describe($occupant);

            foreach ($matchdays as $key => $summary) {
                if ($summary['matchday'] === $listed['matchday']) {
                    $matchdays[$key]['scheduled']--;
                    $matchdays[$key]['unscheduled']++;
                }
            }

            $warnings[] = [
                'code' => 'slot_taken',
                'matchday' => $listed['matchday'],
                'unscheduled' => 1,
                'message' => "El partido {$listed['home_team']} vs {$listed['away_team']} de la jornada {$listed['matchday']} quedó sin horario: su espacio lo ocupó {$by['tournament_name']} mientras se asignaba.",
                'match' => $listed,
                'occupied_by' => $by,
            ];
        }

        return [
            'scheduled' => $scheduled - count($taken),
            'unscheduled' => $unscheduled + count($taken),
            'matchdays' => $matchdays,
            'warnings' => $warnings,
            'matches' => $matches,
        ] + $result;
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
     * @return array{result: array<string, mixed>, assignments: array<int, array{0: string|null, 1: int|null, 2: bool}>, range: array{0: CarbonImmutable, 1: CarbonImmutable}|null}
     */
    private function compute(Tournament $tournament, string $mode, string $scope, ?int $seed): array
    {
        $this->assertReady($tournament, true);

        $matches = $this->matchRepository->regularMatches($tournament->id);
        $matchdays = array_values(array_unique($matches->map(fn (GameMatch $match) => (int) $match->matchday)->all()));
        sort($matchdays);
        $plan = $this->scheduleService->plan($tournament, $matchdays, ScheduleService::MAX_WEEKS);

        $this->duration = max(1, (int) $tournament->match_duration_minutes);
        $range = $plan['weeks'] === [] ? null : [$plan['weeks'][0]['monday'], $plan['weeks'][array_key_last($plan['weeks'])]['monday']->addDays(7)];
        $league = $range === null ? $this->fieldAvailability : $this->fieldAvailability->forRange($tournament->league_id, $range[0], $range[1]);
        $others = $league->withoutTournament($tournament->id);
        $this->loadForeignBusy($others);

        $playedKept = 0;
        $lockedKept = 0;
        $assignedKept = 0;
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

                foreach ([$match->home_registration_id, $match->away_registration_id] as $team) {
                    if ($team !== null) {
                        $teamDays[$team][$date] = true;
                    }
                }
            }
        }

        // Ocupan cancha todos los partidos de la liga que no se van a mover: los de otros torneos y los que este torneo
        // conserva (jugados, fijados y, con "unscheduled", los que ya tenían horario).
        $taken = $league->withoutMatches(array_map(fn (GameMatch $match) => $match->id, array_merge(...array_values($pool))));

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
                fn (array $slot) => ! $taken->isTaken($slot['field_id'], $slot['date'], $slot['start'], $this->duration)
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
                'occupied_by_others' => $rest > 0 && $week !== null ? $this->occupiedByOthers($others, $week['slots']) : [],
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

        return ['result' => $result, 'assignments' => $assignments, 'range' => $range];
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
                if (FieldAvailability::overlaps($from, $to, $busyFrom, $busyTo)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Horarios en que cada equipo ya juega en otros torneos de la liga.
     */
    private function loadForeignBusy(FieldAvailability $others): void
    {
        $this->foreignBusy = [];

        foreach ($others->matches() as $other) {
            $interval = FieldAvailability::interval($other);

            if ($interval === null) {
                continue;
            }

            foreach ([$other->home, $other->away] as $registration) {
                if ($registration !== null) {
                    $this->foreignBusy[$registration->team_id][] = $interval;
                }
            }
        }
    }

    /**
     * Partidos de otros torneos que ocupan espacios de la semana (sin repetir), para explicar los espacios faltantes.
     *
     * @param  list<Slot>  $slots
     * @return list<array<string, mixed>>
     */
    private function occupiedByOthers(FieldAvailability $others, array $slots): array
    {
        $occupiedBy = [];

        foreach ($slots as $slot) {
            $occupant = $others->occupant($slot['field_id'], $slot['date'], $slot['start'], $this->duration);

            if ($occupant !== null) {
                $occupiedBy[$occupant->id] = FieldAvailability::describe($occupant);
            }
        }

        return array_values($occupiedBy);
    }

    /**
     * Intervalo del espacio en minutos desde la época.
     *
     * @param  Slot  $slot
     * @return array{0: int, 1: int}
     */
    private function interval(array $slot): array
    {
        $from = intdiv(FieldAvailability::slotStart($slot['date'], $slot['start'])->getTimestamp(), 60);

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
