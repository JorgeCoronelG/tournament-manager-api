<?php

namespace App\Services;

use App\Contracts\Repositories\GroupRepositoryInterface;
use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Services\PlayoffScheduleServiceInterface;
use App\Contracts\Services\ScheduleServiceInterface;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Helpers\ScheduleCapacity;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentScheduleWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-type Slot array{date: string, field_id: int, start: int, end: int}
 */
class PlayoffScheduleService implements PlayoffScheduleServiceInterface
{
    private const WEEKDAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /** Duración máxima de un partido: ventana para buscar traslapes. */
    private const LOOKBACK_MINUTES = Tournament::MAX_MATCH_DURATION_MINUTES;

    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected GroupRepositoryInterface $groupRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
        protected ScheduleServiceInterface $scheduleService,
    ) {}

    public function preview(Tournament $tournament, string $scope, bool $alignFinals): array
    {
        return $this->compute($tournament, $scope, $alignFinals)['result'];
    }

    public function generate(Tournament $tournament, string $scope, bool $alignFinals): array
    {
        $computed = $this->compute($tournament, $scope, $alignFinals);

        DB::transaction(fn () => $this->matchRepository->assignSchedule($computed['assignments']));

        return $computed['result'];
    }

    public function clear(Tournament $tournament): array
    {
        $this->assertPlayoffs($tournament);

        $assignments = [];
        $cleared = 0;

        foreach ($this->matchRepository->playoffMatches($tournament->id) as $match) {
            if ($match->status === MatchStatus::PLAYED || $match->schedule_locked) {
                continue;
            }

            if ($match->match_datetime !== null || $match->field_id !== null || $match->referee_user_id !== null) {
                $assignments[$match->id] = [null, null, true];
                $cleared++;
            }
        }

        DB::transaction(fn () => $this->matchRepository->assignSchedule($assignments));

        return ['cleared' => $cleared];
    }

    public function capacity(Tournament $tournament): array
    {
        $matches = $this->matchRepository->playoffMatches($tournament->id);

        if ($matches->isEmpty()) {
            return ['playoff_rounds' => [], 'playoff_warnings' => []];
        }

        $groups = $this->groupRepository->playoffGroups($tournament->id)->keyBy('id');
        $rows = [];

        foreach ($matches as $match) {
            $group = $groups->get($match->group_id);
            $date = $match->scheduled_date?->toDateString();

            if ($group === null || $date === null) {
                continue;
            }

            $key = $group->id.'|'.$match->round_index.'|'.$match->leg.'|'.$date;
            $rows[$key] ??= $this->row($group, (int) $match->round_index, $match->leg, $date, 0);
            $rows[$key]['matches']++;
        }

        return $this->capacityOfRows($tournament, array_values($rows), array_values($matches->map(fn (GameMatch $match) => $match->id)->all()));
    }

    public function proposalWarnings(Tournament $tournament, array $playoffs): array
    {
        $groups = $this->groupRepository->playoffGroups($tournament->id)->keyBy('id');
        $rows = [];

        foreach ($playoffs as $playoff) {
            $groupId = $playoff['group_id'] ?? 0;
            $group = is_int($groupId) ? $groups->get($groupId) : null;
            $dates = $playoff['dates'] ?? null;

            if ($group === null || ! is_array($dates) || ! is_array($playoff['rounds'] ?? null)) {
                continue;
            }

            $offset = 0;

            /** @var array<int, array<string, mixed>> $playoffRounds */
            $playoffRounds = $playoff['rounds'];

            foreach ($playoffRounds as $index => $round) {
                $legs = ($round['legs'] ?? 1) === 2 ? 2 : 1;
                $ties = is_array($round['ties'] ?? null) ? count($round['ties']) : 0;

                foreach (range(1, $legs) as $leg) {
                    $date = $dates[$offset + $leg - 1] ?? null;

                    if (is_string($date)) {
                        $rows[] = $this->row($group, $index + 1, $legs === 2 ? $leg : null, $date, $ties);
                    }
                }

                $offset += $legs;
            }
        }

        return $this->capacityOfRows($tournament, $rows, [])['playoff_warnings'];
    }

    /**
     * @return array{result: array<string, mixed>, assignments: array<int, array{0: string|null, 1: int|null, 2: bool}>}
     */
    private function compute(Tournament $tournament, string $scope, bool $alignFinals): array
    {
        $this->assertPlayoffs($tournament);

        $matches = $this->matchRepository->playoffMatches($tournament->id);

        if ($matches->isEmpty()) {
            throw new CustomErrorException(Message::SCHEDULE_NO_PLAYOFF_MATCHES, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($tournament->schedule_start_date === null || $tournament->match_duration_minutes <= 0 || ! $tournament->scheduleWindows()->exists()) {
            throw new CustomErrorException(Message::SCHEDULE_NOT_CONFIGURED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $groups = $this->groupRepository->playoffGroups($tournament->id)->keyBy('id');
        $duration = (int) $tournament->match_duration_minutes;
        $playedKept = 0;
        $lockedKept = 0;
        $assignedKept = 0;
        $pool = [];

        foreach ($matches as $match) {
            if ($match->status === MatchStatus::PLAYED) {
                $playedKept++;
            } elseif ($match->schedule_locked) {
                $lockedKept++;
            } elseif ($scope === 'unscheduled' && $match->match_datetime !== null) {
                $assignedKept++;
            } else {
                $pool[] = $match;
            }
        }

        $poolIds = array_map(fn (GameMatch $match) => $match->id, $pool);
        $warnings = [];
        $alignedDate = $alignFinals ? $this->alignedFinalsDate($pool, $groups, $warnings) : null;

        $byDate = [];

        foreach ($pool as $match) {
            $byDate[$match->scheduled_date?->toDateString() ?? ''][] = $match;
        }

        ksort($byDate);

        $assignments = [];
        $placedSlots = [];

        foreach ($byDate as $date => $dateMatches) {
            $placedSlots += $this->placeDate((string) $date, $dateMatches, $groups, $tournament, $duration, $poolIds, $alignedDate);
        }

        $rounds = [];
        $listed = [];
        $scheduled = 0;
        $unscheduled = 0;
        $missing = [];

        foreach ($pool as $match) {
            $group = $groups->get($match->group_id);
            $slot = $placedSlots[$match->id] ?? null;
            $roundName = $group === null ? '' : $this->roundName($group, (int) $match->round_index);
            $date = $match->scheduled_date?->toDateString();
            $datetime = $slot === null ? null : $slot['date'].' '.ScheduleCapacity::time($slot['start']).':00';
            $key = $match->group_id.'|'.$match->round_index.'|'.$match->leg.'|'.$date;

            $rounds[$key] ??= ['liguilla' => $group->name ?? null, 'round_name' => $roundName, 'leg' => $match->leg, 'date' => $date, 'matches' => 0, 'scheduled' => 0, 'unscheduled' => 0];
            $rounds[$key]['matches']++;
            $rounds[$key][$slot === null ? 'unscheduled' : 'scheduled']++;

            if ($slot === null) {
                $unscheduled++;
                $missing[$date.'|'.$match->group_id.'|'.$roundName] = ($missing[$date.'|'.$match->group_id.'|'.$roundName] ?? 0) + 1;
            } else {
                $scheduled++;
            }

            $assignments[$match->id] = [$datetime, $slot['field_id'] ?? null, $scope === 'all'];
            $listed[] = [
                'id' => $match->id,
                'matchday' => null,
                'group' => $group->name ?? null,
                'round_name' => $roundName,
                'leg' => $match->leg,
                'scheduled_date' => $date,
                'home_team' => $match->home?->team?->name,
                'away_team' => $match->away?->team?->name,
                'match_datetime' => $datetime === null ? null : str_replace(' ', 'T', $datetime),
                'field' => $slot === null ? null : ['id' => $slot['field_id'], 'name' => $this->fieldName($tournament, $slot['field_id'])],
            ];
        }

        foreach ($missing as $key => $count) {
            [$date, $groupId, $roundName] = explode('|', $key, 3);
            $warnings[] = [
                'date' => $date === '' ? null : $date,
                'round_name' => $roundName,
                'liguilla' => $groups->get((int) $groupId)->name ?? null,
                'unscheduled' => $count,
                'message' => ($count === 1 ? 'Falta 1 espacio el ' : "Faltan {$count} espacios el ").($date === '' ? 'día sin fecha' : CarbonImmutable::parse($date)->format('d/m'))." para {$roundName}.",
            ];
        }

        return [
            'result' => [
                'scheduled' => $scheduled,
                'unscheduled' => $unscheduled,
                'locked_kept' => $lockedKept,
                'played_kept' => $playedKept,
                'assigned_kept' => $assignedKept,
                'seed' => null,
                'matchdays' => [],
                'rounds' => array_values($rounds),
                'warnings' => $warnings,
                'matches' => $listed,
            ],
            'assignments' => $assignments,
        ];
    }

    /**
     * Asigna los partidos de una fecha a sus espacios libres. Primero reserva los últimos espacios para las finales
     * alineadas y después recorre las rondas de la más temprana a la final, cada una desde el espacio libre más temprano
     * (así los partidos de una ronda coinciden en hora en canchas distintas).
     *
     * @param  list<GameMatch>  $matches
     * @param  Collection<int, TournamentGroup>  $groups
     * @param  list<int>  $poolIds
     * @return array<int, Slot> Espacio por id de partido.
     */
    private function placeDate(string $date, array $matches, $groups, Tournament $tournament, int $duration, array $poolIds, ?string $alignedDate): array
    {
        if ($date === '') {
            return [];
        }

        $free = $this->freeSlots($tournament, $date, $poolIds);
        $day = CarbonImmutable::parse($date)->startOfDay();
        $others = $this->matchRepository->scheduledInLeague($tournament->league_id, $day->subMinutes(self::LOOKBACK_MINUTES), $day->endOfDay(), $poolIds);
        $busy = [];
        $playingToday = [];

        foreach ($others as $other) {
            $start = intdiv($other->match_datetime?->getTimestamp() ?? 0, 60);
            $end = $start + max(1, (int) $other->tournament?->match_duration_minutes);

            foreach ($this->teamIds($other) as $team) {
                $busy[$team][] = [$start, $end];

                if ($other->tournament_id === $tournament->id && $other->match_datetime?->format('Y-m-d') === $date) {
                    $playingToday[$team] = true;
                }
            }
        }

        $fits = function (GameMatch $match, array $slot) use (&$busy, &$playingToday, $duration): bool {
            $from = intdiv(CarbonImmutable::parse($slot['date'])->getTimestamp(), 60) + $slot['start'];

            foreach ($this->teamIds($match) as $team) {
                if (isset($playingToday[$team])) {
                    return false;
                }

                foreach ($busy[$team] ?? [] as [$busyFrom, $busyTo]) {
                    if ($busyFrom < $from + $duration && $from < $busyTo) {
                        return false;
                    }
                }
            }

            return true;
        };

        $placed = [];
        $take = function (GameMatch $match, int $index) use (&$free, &$placed, &$playingToday): void {
            $placed[$match->id] = $free[$index];

            foreach ($this->teamIds($match) as $team) {
                $playingToday[$team] = true;
            }

            array_splice($free, $index, 1);
        };

        $rest = $matches;

        if ($alignedDate === $date) {
            $finals = array_values(array_filter($matches, fn (GameMatch $match) => $this->isFinal($match, $groups)));
            usort($finals, fn (GameMatch $a, GameMatch $b) => [-$this->priority($groups->get($a->group_id)), $a->id] <=> [-$this->priority($groups->get($b->group_id)), $b->id]);

            // Los últimos espacios del día son de las finales, la de menor prioridad primero y la principal al final. Una
            // final que no cabe queda sin horario: no se coloca con las demás rondas.
            $indexes = $this->lastSlotIndexes($free, count($finals));
            $reserved = [];

            foreach ($finals as $position => $final) {
                $index = $indexes[$position] ?? null;

                if ($index !== null && $fits($final, $free[$index])) {
                    $placed[$final->id] = $free[$index];
                    $reserved[] = $index;

                    foreach ($this->teamIds($final) as $team) {
                        $playingToday[$team] = true;
                    }
                }
            }

            $free = array_values(array_filter($free, fn (array $slot, int $index) => ! in_array($index, $reserved, true), ARRAY_FILTER_USE_BOTH));
            $finalIds = array_map(fn (GameMatch $final) => $final->id, $finals);
            $rest = array_values(array_filter($matches, fn (GameMatch $match) => ! in_array($match->id, $finalIds, true)));
        }

        usort($rest, fn (GameMatch $a, GameMatch $b) => $this->order($a, $groups) <=> $this->order($b, $groups));

        foreach ($rest as $match) {
            foreach ($free as $index => $slot) {
                if ($fits($match, $slot)) {
                    $take($match, $index);

                    break;
                }
            }
        }

        return $placed;
    }

    /**
     * @param  list<Slot>  $free  Ordenados por hora y cancha.
     * @return array<int, int> Índice de los últimos espacios, uno por final y en horas consecutivas distintas si alcanzan.
     */
    private function lastSlotIndexes(array $free, int $count): array
    {
        if ($count === 0 || $free === []) {
            return [];
        }

        $byStart = [];

        foreach ($free as $index => $slot) {
            $byStart[$slot['start']] ??= $index;
        }

        $starts = array_keys($byStart);

        if (count($starts) >= $count) {
            return array_map(fn (int $start) => $byStart[$start], array_slice($starts, -$count));
        }

        return array_slice(array_keys($free), -$count);
    }

    /**
     * Espacios libres de la fecha: los configurados menos los ocupados (misma cancha y hora que se traslape) por
     * partidos de la liga que no se reasignan.
     *
     * @param  list<int>  $excludeIds
     * @return list<Slot>
     */
    private function freeSlots(Tournament $tournament, string $date, array $excludeIds): array
    {
        $slots = $this->scheduleService->slotsOfDate($tournament, $date);

        if ($slots === []) {
            return [];
        }

        $day = CarbonImmutable::parse($date)->startOfDay();
        $others = $this->matchRepository->scheduledInLeague($tournament->league_id, $day->subMinutes(self::LOOKBACK_MINUTES), $day->endOfDay(), $excludeIds);
        $free = [];

        foreach ($slots as $slot) {
            $from = $day->addMinutes($slot['start']);
            $to = $day->addMinutes($slot['end']);
            $taken = $others->contains(function (GameMatch $other) use ($slot, $from, $to) {
                if ($other->field_id !== $slot['field_id'] || $other->match_datetime === null) {
                    return false;
                }

                $start = CarbonImmutable::instance($other->match_datetime);

                return $start->lt($to) && $from->lt($start->addMinutes(max(1, (int) $other->tournament?->match_duration_minutes)));
            });

            if (! $taken) {
                $free[] = $slot;
            }
        }

        return $free;
    }

    /**
     * Fecha común de las finales de las liguillas (las de la última ronda), o null si caen en fechas distintas (con
     * aviso) o solo hay una liguilla con finales.
     *
     * @param  list<GameMatch>  $pool
     * @param  Collection<int, TournamentGroup>  $groups
     * @param  list<array<string, mixed>>  $warnings
     */
    private function alignedFinalsDate(array $pool, $groups, array &$warnings): ?string
    {
        $dates = [];

        foreach ($pool as $match) {
            if ($this->isFinal($match, $groups) && $match->scheduled_date !== null && $match->group_id !== null) {
                $dates[$match->group_id] = max($dates[$match->group_id] ?? '', $match->scheduled_date->toDateString());
            }
        }

        if ($dates === []) {
            return null;
        }

        if (count(array_unique($dates)) > 1) {
            $warnings[] = ['date' => null, 'round_name' => 'Final', 'liguilla' => null, 'unscheduled' => 0, 'message' => Message::SCHEDULE_FINALS_NOT_ALIGNED];

            return null;
        }

        return (string) array_values($dates)[0];
    }

    /**
     * @param  Collection<int, TournamentGroup>  $groups
     */
    private function isFinal(GameMatch $match, $groups): bool
    {
        $group = $groups->get($match->group_id);

        return $group !== null && (int) $match->round_index === count($group->legsPerRound()) && $match->phase === MatchPhase::FINAL;
    }

    /**
     * Rondas más tempranas primero, luego la liguilla de mayor prioridad, posición del cruce e ida/vuelta.
     *
     * @param  Collection<int, TournamentGroup>  $groups
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
     */
    private function order(GameMatch $match, $groups): array
    {
        $group = $groups->get($match->group_id);
        $fromEnd = $group === null ? 0 : count($group->legsPerRound()) - (int) $match->round_index;

        return [-$fromEnd, $this->priority($group), (int) $match->bracket_position, (int) $match->leg, $match->id];
    }

    /**
     * Prioridad de una liguilla: menor es más importante. Por la mejor posición de origen de sus fuentes (la liguilla que
     * toma a los primeros lugares es la principal) y, a igualdad, por antigüedad.
     */
    private function priority(?TournamentGroup $group): int
    {
        if ($group === null) {
            return PHP_INT_MAX;
        }

        $best = $group->sources->map(fn ($source) => (int) $source->position_start)->filter(fn (int $position) => $position > 0)->min() ?? 999;

        return $best * 1_000_000 + $group->id;
    }

    private function roundName(TournamentGroup $group, int $roundIndex): string
    {
        return $group->roundsDetail()[$roundIndex - 1]['name'] ?? '';
    }

    private function assertPlayoffs(Tournament $tournament): void
    {
        if ($tournament->status !== TournamentStatus::PLAYOFFS) {
            throw new CustomErrorException(Message::SCHEDULE_PLAYOFFS_ONLY, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
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

    private function fieldName(Tournament $tournament, int $fieldId): ?string
    {
        static $names = [];

        if (! isset($names[$tournament->id])) {
            $names[$tournament->id] = [];

            foreach ($this->scheduleRepository->windows($tournament->id) as $window) {
                /** @var TournamentScheduleWindow $window */
                $names[$tournament->id][(int) $window->field_id] = $window->field->name ?? null;
            }
        }

        return $names[$tournament->id][$fieldId] ?? null;
    }

    /**
     * @return array{group: TournamentGroup, round_index: int, liguilla: string|null, round_name: string, leg: int|null, date: string, matches: int, sort: array<int, int|string>}
     */
    private function row(TournamentGroup $group, int $roundIndex, ?int $leg, string $date, int $matches): array
    {
        return [
            'group' => $group,
            'round_index' => $roundIndex,
            'liguilla' => $group->name,
            'round_name' => $this->roundName($group, $roundIndex),
            'leg' => $leg,
            'date' => $date,
            'matches' => $matches,
            'sort' => [$date, -(count($group->legsPerRound()) - $roundIndex), $this->priority($group), (int) $leg],
        ];
    }

    /**
     * Partidos requeridos contra espacios libres por fecha. En una fecha con varias rondas, los espacios se reparten en
     * orden (rondas tempranas primero): `slots_available` son los que quedan al llegar a esa ronda.
     *
     * @param  list<array{group: TournamentGroup, round_index: int, liguilla: string|null, round_name: string, leg: int|null, date: string, matches: int, sort: array<int, int|string>}>  $rows
     * @param  list<int>  $excludeIds  Partidos de liguilla del torneo: son la demanda, no ocupan espacios.
     * @return array{playoff_rounds: list<array<string, mixed>>, playoff_warnings: list<array<string, mixed>>}
     */
    private function capacityOfRows(Tournament $tournament, array $rows, array $excludeIds): array
    {
        usort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        $windowDays = [];

        foreach ($this->scheduleRepository->windows($tournament->id) as $window) {
            $windowDays[(int) $window->weekday] = true;
        }

        $remaining = [];
        $result = [];
        $warnings = [];

        foreach ($rows as $row) {
            $date = $row['date'];
            $remaining[$date] ??= count($this->freeSlots($tournament, $date, $excludeIds));
            $available = $remaining[$date];
            $required = $row['matches'];
            $deficit = max(0, $required - $available);
            $remaining[$date] = max(0, $available - $required);
            $day = CarbonImmutable::parse($date);

            $result[] = [
                'liguilla' => $row['liguilla'],
                'round_name' => $row['round_name'],
                'leg' => $row['leg'],
                'date' => $date,
                'matches_required' => $required,
                'slots_available' => $available,
                'deficit' => $deficit,
            ];

            if (! isset($windowDays[$day->dayOfWeekIso])) {
                $warnings[] = ['date' => $date, 'liguilla' => $row['liguilla'], 'round_name' => $row['round_name'], 'message' => 'No hay ventanas configuradas para el '.self::WEEKDAYS[$day->dayOfWeek].' '.$day->format('d/m').'.'];
            } elseif ($deficit > 0) {
                $warnings[] = ['date' => $date, 'liguilla' => $row['liguilla'], 'round_name' => $row['round_name'], 'message' => ($deficit === 1 ? 'Falta 1 espacio el ' : "Faltan {$deficit} espacios el ").$day->format('d/m')." para {$row['round_name']}."];
            }
        }

        return ['playoff_rounds' => $result, 'playoff_warnings' => $warnings];
    }
}
