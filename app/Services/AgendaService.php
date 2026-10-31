<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Contracts\Services\AgendaServiceInterface;
use App\Core\Enum\MatchPhase;
use App\Core\Enum\Message;
use App\Core\Enum\RegistrationStatus;
use App\Exceptions\CustomErrorException;
use App\Models\Field;
use App\Models\GameMatch;
use App\Models\League;
use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

class AgendaService implements AgendaServiceInterface
{
    public function __construct(
        protected MatchRepositoryInterface $matchRepository,
        protected ScheduleRepositoryInterface $scheduleRepository,
    ) {}

    public function agenda(League $league, array $filters): array
    {
        [$from, $to] = $this->range($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $this->assertTournament($league, $filters['tournament_id'] ?? null);

        $matches = $this->matchRepository->agenda($league->id, $from->toDateString(), $to->toDateString(), $filters);
        $days = [];

        foreach ($matches as $match) {
            $date = $match->match_datetime?->format('Y-m-d');

            if ($date !== null) {
                $days[$date][] = $this->item($match);
            }
        }

        ksort($days);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'days' => array_map(fn (string $date) => ['date' => $date, 'matches' => $days[$date]], array_keys($days)),
            'fields' => array_values($this->scheduleRepository->activeFields($league->id)->map(fn (Field $field) => ['id' => $field->id, 'name' => $field->name])->all()),
        ];
    }

    public function unscheduled(League $league, ?int $tournamentId): array
    {
        $this->assertTournament($league, $tournamentId);

        return ['unscheduled' => $this->matchRepository->countUnscheduled($league->id, $tournamentId)];
    }

    /**
     * Por defecto la semana actual; con un solo extremo, siete días desde (o hasta) él.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws CustomErrorException
     */
    private function range(?string $dateFrom, ?string $dateTo): array
    {
        $from = $dateFrom === null ? null : CarbonImmutable::parse($dateFrom)->startOfDay();
        $to = $dateTo === null ? null : CarbonImmutable::parse($dateTo)->startOfDay();

        if ($from === null) {
            $from = $to === null ? CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay() : $to->subDays(6);
        }

        $to ??= $from->addDays(6);

        if ($from->gt($to)) {
            throw new CustomErrorException(Message::AGENDA_RANGE_INVALID, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new CustomErrorException(Message::AGENDA_RANGE_TOO_LONG, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return [$from, $to];
    }

    /**
     * Un torneo de otra liga no existe para esta (404).
     */
    private function assertTournament(League $league, ?int $tournamentId): void
    {
        if ($tournamentId !== null) {
            Tournament::query()->where('league_id', $league->id)->findOrFail($tournamentId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function item(GameMatch $match): array
    {
        $at = $match->match_datetime;
        $duration = max(1, (int) $match->tournament?->match_duration_minutes);

        return [
            'id' => $match->id,
            'tournament' => ['id' => $match->tournament_id, 'name' => $match->tournament?->name],
            'phase' => $match->phase === MatchPhase::REGULAR_STAGE ? 'regular' : 'playoffs',
            'round_label' => $this->roundLabel($match),
            'group' => $match->group?->name,
            'home_team' => $match->home?->team?->name,
            'away_team' => $match->away?->team?->name,
            'match_datetime' => $at?->toIso8601String(),
            'end_datetime' => $at?->copy()->addMinutes($duration)->toIso8601String(),
            'field' => $match->field_id === null ? null : ['id' => $match->field_id, 'name' => $match->field?->name],
            'referee' => $match->referee_user_id === null ? null : ['id' => $match->referee_user_id, 'name' => $match->referee === null ? null : trim($match->referee->first_name.' '.$match->referee->last_name)],
            'home_withdrawn' => $match->home?->status === RegistrationStatus::WITHDRAWN,
            'away_withdrawn' => $match->away?->status === RegistrationStatus::WITHDRAWN,
            'status' => $match->status->value,
            'schedule_locked' => $match->schedule_locked,
        ];
    }

    private function roundLabel(GameMatch $match): string
    {
        if ($match->phase === MatchPhase::REGULAR_STAGE) {
            return 'Jornada '.$match->matchday;
        }

        return $match->phase->label().match ($match->leg) {
            1 => ' (ida)',
            2 => ' (vuelta)',
            default => '',
        };
    }
}
