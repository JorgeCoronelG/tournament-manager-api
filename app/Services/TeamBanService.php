<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Contracts\Repositories\RegistrationRepositoryInterface;
use App\Contracts\Repositories\RosterRepositoryInterface;
use App\Contracts\Repositories\TeamRepositoryInterface;
use App\Contracts\Services\RegistrationChangeServiceInterface;
use App\Contracts\Services\RegistrationServiceInterface;
use App\Contracts\Services\TeamBanServiceInterface;
use App\Core\Enum\TeamStatus;
use App\Core\Enum\TournamentStatus;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class TeamBanService implements TeamBanServiceInterface
{
    /** Longitud máxima del motivo de una baja (withdrawn_reason). */
    private const MAX_REASON = 255;

    private const PREFIX = 'Equipo vetado: ';

    public function __construct(
        protected TeamRepositoryInterface $teamRepository,
        protected RegistrationRepositoryInterface $registrationRepository,
        protected RegistrationServiceInterface $registrationService,
        protected RegistrationChangeServiceInterface $changeService,
        protected MatchRepositoryInterface $matchRepository,
        protected RosterRepositoryInterface $rosterRepository,
    ) {}

    public function preview(Team $team): array
    {
        $items = [];

        foreach ($this->registrationRepository->activeOfTeam($team->id) as $registration) {
            $tournament = $registration->tournament;
            $action = $tournament === null ? null : $this->actionFor($tournament->status);

            if ($tournament === null || $action === null) {
                continue;
            }

            $open = $this->rosterRepository->countOpenByRegistration($registration->id);
            $items[] = [
                'tournament_id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status->value,
                'action' => $action,
                'pending_matches' => $action === 'withdrawn' ? $this->matchRepository->pendingOfRegistration($tournament->id, $registration->id)->count() : 0,
                'players_released' => $open['active'] + $open['pending'],
            ];
        }

        return [
            'tournaments' => $items,
            'totals' => [
                'removed_tournaments' => count(array_filter($items, fn (array $item) => $item['action'] === 'removed')),
                'withdrawn_tournaments' => count(array_filter($items, fn (array $item) => $item['action'] === 'withdrawn')),
                'pending_matches' => array_sum(array_column($items, 'pending_matches')),
                'players_released' => array_sum(array_column($items, 'players_released')),
            ],
        ];
    }

    public function ban(Team $team, string $reason, int $userId): array
    {
        $effects = ['removed_tournaments' => 0, 'withdrawn_tournaments' => 0, 'pending_matches' => 0, 'players_released' => 0];

        if ($team->status === TeamStatus::BANNED) {
            return ['team' => $team->load('manager'), 'effects' => $effects];
        }

        DB::transaction(function () use ($team, $reason, $userId, &$effects) {
            $this->teamRepository->updateStatus($team, TeamStatus::BANNED, $reason);
            $withdrawReason = mb_substr(self::PREFIX.$reason, 0, self::MAX_REASON);

            foreach ($this->registrationRepository->activeOfTeam($team->id) as $registration) {
                $tournament = $registration->tournament;
                $action = $tournament === null ? null : $this->actionFor($tournament->status);

                if ($tournament === null || $action === null) {
                    continue;
                }

                if ($action === 'removed') {
                    $effects['players_released'] += $this->rosterRepository->releaseOpenByRegistration($registration->id);
                    $this->registrationService->removeRegistration($tournament, $registration);
                    $effects['removed_tournaments']++;

                    continue;
                }

                $result = $this->changeService->withdraw($tournament, $registration, $withdrawReason, $userId);
                $effects['withdrawn_tournaments']++;
                $effects['pending_matches'] += $result['pending_matches'];
                $effects['players_released'] += $result['players_released'];
            }
        });

        return ['team' => $team->load('manager'), 'effects' => $effects];
    }

    /**
     * Planeación: se quita la inscripción; en curso o liguilla: se da de baja; finalizado: no se toca.
     */
    private function actionFor(TournamentStatus $status): ?string
    {
        return match ($status) {
            TournamentStatus::PLANNING => 'removed',
            TournamentStatus::IN_PROGRESS, TournamentStatus::PLAYOFFS => 'withdrawn',
            TournamentStatus::FINISHED => null,
        };
    }
}
