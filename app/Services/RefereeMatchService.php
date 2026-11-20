<?php

namespace App\Services;

use App\Contracts\Services\MatchServiceInterface;
use App\Contracts\Services\RefereeMatchServiceInterface;
use App\Core\Enum\LeagueRefereeStatus;
use App\Core\Enum\MatchEventType;
use App\Core\Enum\MatchPeriod;
use App\Core\Enum\MatchStatus;
use App\Core\Enum\Message;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\ScanMethod;
use App\Core\Enum\ScanResult;
use App\Core\Enum\TournamentStatus;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\LeagueReferee;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerLeagueBan;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Scan;
use App\Models\Tournament;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RefereeMatchService implements RefereeMatchServiceInterface
{
    public function __construct(protected MatchServiceInterface $matchService) {}

    public function matchesOf(User $referee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $leagueIds = LeagueReferee::query()
            ->where('user_id', $referee->id)
            ->where('status', LeagueRefereeStatus::ACTIVE)
            ->pluck('league_id');

        return GameMatch::query()
            ->where('referee_user_id', $referee->id)
            ->whereNotNull('match_datetime')
            ->whereBetween('match_datetime', [$from->startOfDay(), $to->endOfDay()])
            ->whereHas('tournament', fn ($tournament) => $tournament->whereIn('league_id', $leagueIds))
            ->with([
                'tournament.league',
                'field',
                'home' => fn ($registration) => $registration->withTrashed()->with(['team' => fn ($team) => $team->withTrashed()]),
                'away' => fn ($registration) => $registration->withTrashed()->with(['team' => fn ($team) => $team->withTrashed()]),
                'tie.matches',
            ])
            ->orderBy('match_datetime')
            ->orderBy('id')
            ->get();
    }

    public function rostersOf(GameMatch $match, User $referee): array
    {
        $this->assertAssigned($match, $referee);
        $tournament = Tournament::query()->withTrashed()->findOrFail($match->tournament_id);
        $registrations = $this->registrationsOf($match);

        $rows = Roster::query()
            ->whereIn('registration_id', array_keys($registrations))
            ->whereIn('status', [RosterStatus::ACTIVE, RosterStatus::REMOVED])
            ->with(['player' => fn ($player) => $player->withTrashed()->with('user')])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('jersey_number')
            ->get();

        $playerIds = $rows->pluck('player_id')->map(fn (mixed $id) => (int) (is_numeric($id) ? $id : 0))->unique()->values()->all();
        $banned = array_flip($this->bannedPlayerIds((int) $tournament->league_id, $playerIds));
        $suspended = array_flip($this->suspendedPlayerIds($tournament->id, $playerIds));

        $teams = [];

        foreach ($registrations as $registrationId => $registration) {
            $players = [];

            // Si un jugador tiene una fila activa y otra dada de baja en el mismo equipo, manda la activa (va primero).
            foreach ($rows->where('registration_id', $registrationId)->unique('player_id') as $row) {
                $player = $row->player;
                $user = $player?->user;

                if ($player === null || $user === null) {
                    continue;
                }

                $players[] = [
                    'player_id' => $player->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'user_code' => $user->user_code,
                    'photo_url' => $user->photo_url,
                    'birth_date' => $player->birth_date->toDateString(),
                    'jersey_number' => $row->jersey_number,
                    'qr_code' => $player->qr_code,
                    'status' => $row->status->value,
                    'eligibility' => match (true) {
                        isset($banned[$player->id]) => ScanResult::BANNED->value,
                        isset($suspended[$player->id]) => ScanResult::SUSPENDED->value,
                        default => 'eligible',
                    },
                ];
            }

            $teams[] = [
                'registration_id' => $registrationId,
                'team_name' => (string) ($registration->team->name ?? ''),
                'players' => $players,
            ];
        }

        return ['match_id' => $match->id, 'downloaded_at' => now()->toIso8601String(), 'teams' => $teams];
    }

    public function sync(GameMatch $match, User $referee, array $payload): array
    {
        $this->assertAssigned($match, $referee);
        $tournament = Tournament::query()->findOrFail($match->tournament_id);

        if ($tournament->status === TournamentStatus::FINISHED) {
            throw new CustomErrorException(Message::MATCH_TOURNAMENT_FINISHED, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $registrationIds = array_keys($this->registrationsOf($match));

        [$scans, $events] = DB::transaction(function () use ($match, $referee, $tournament, $registrationIds, $payload) {
            $scans = [];

            foreach ($payload['scans'] as $scan) {
                $scans[] = ['client_uuid' => $scan['client_uuid'], 'result' => $this->storeScan($match, $referee, $tournament, $registrationIds, $scan)->value];
            }

            foreach ($payload['events'] as $event) {
                $this->storeEvent($match, $referee, $registrationIds, $event);
            }

            return [$scans, count($payload['events'])];
        });

        return ['scans' => $scans, 'events' => $events, 'result' => $this->storeResult($tournament, $match, $payload['result'])];
    }

    /**
     * Resultado del escaneo con las reglas del back (en este orden): QR desconocido, veto de liga, sin plantilla en
     * los dos equipos, dado de baja, suspendido y, si nada aplica, válido. Solo una fila activa puede dar válido.
     *
     * @param  list<int>  $registrationIds
     * @return array{0: ScanResult, 1: int|null} El resultado y la inscripción del jugador en el partido
     */
    private function scanResult(?Player $player, Tournament $tournament, array $registrationIds): array
    {
        if ($player === null) {
            return [ScanResult::UNKNOWN_QR, null];
        }

        if ($this->bannedPlayerIds((int) $tournament->league_id, [$player->id]) !== []) {
            return [ScanResult::BANNED, null];
        }

        $rows = Roster::query()
            ->where('player_id', $player->id)
            ->whereIn('registration_id', $registrationIds)
            ->get();

        $active = $rows->first(fn (Roster $row) => $row->status === RosterStatus::ACTIVE);

        if ($active instanceof Roster) {
            $suspended = $this->suspendedPlayerIds($tournament->id, [$player->id]) !== [];

            return [$suspended ? ScanResult::SUSPENDED : ScanResult::VALID, (int) $active->registration_id];
        }

        $removed = $rows->first(fn (Roster $row) => $row->status === RosterStatus::REMOVED);

        if ($removed instanceof Roster) {
            return [ScanResult::REMOVED, (int) $removed->registration_id];
        }

        return [ScanResult::NOT_REGISTERED, null];
    }

    /**
     * Un escaneo ya recibido (mismo client_uuid) no se vuelve a guardar: se responde con su resultado.
     *
     * @param  list<int>  $registrationIds
     * @param  array{client_uuid: string, method: ScanMethod, scanned_code: string|null, player_id: int|null, scanned_at: string}  $data
     */
    private function storeScan(GameMatch $match, User $referee, Tournament $tournament, array $registrationIds, array $data): ScanResult
    {
        $existing = Scan::query()->where('client_uuid', $data['client_uuid'])->first();

        if ($existing instanceof Scan) {
            $this->assertSameMatch($existing->match_id, $match);

            return $existing->result;
        }

        $player = $data['method'] === ScanMethod::MANUAL
            ? Player::query()->find($data['player_id'])
            : Player::query()->where('qr_code', (string) $data['scanned_code'])->first();

        [$result, $registrationId] = $this->scanResult($player, $tournament, $registrationIds);

        $scan = new Scan;
        $scan->forceFill([
            'client_uuid' => $data['client_uuid'],
            'match_id' => $match->id,
            'scanned_by_user_id' => $referee->id,
            'scanned_code' => $data['method'] === ScanMethod::MANUAL ? (string) $player?->qr_code : (string) $data['scanned_code'],
            'method' => $data['method'],
            'player_id' => $player?->id,
            'registration_id' => $registrationId,
            'result' => $result,
            'scanned_at' => $this->deviceTime($data['scanned_at']),
            'synced_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * Crea el evento o, si ya llegó antes, actualiza lo único que el árbitro puede cambiar después: anularlo y
     * corregir el minuto.
     *
     * @param  list<int>  $registrationIds
     * @param  array{client_uuid: string, registration_id: int, player_id: int|null, related_player_id: int|null, type: MatchEventType, minute: int|null, period: MatchPeriod|null, recorded_at: string, is_voided: bool}  $data
     */
    private function storeEvent(GameMatch $match, User $referee, array $registrationIds, array $data): void
    {
        if (! in_array($data['registration_id'], $registrationIds, true)) {
            throw new CustomErrorException(Message::REFEREE_EVENT_TEAM_NOT_IN_MATCH, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach (array_filter([$data['player_id'], $data['related_player_id']]) as $playerId) {
            $inTeam = Roster::query()
                ->where('registration_id', $data['registration_id'])
                ->where('player_id', $playerId)
                ->exists();

            if (! $inTeam) {
                throw new CustomErrorException(Message::REFEREE_EVENT_PLAYER_NOT_IN_TEAM, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $event = MatchEvent::query()->where('client_uuid', $data['client_uuid'])->first();

        if ($event instanceof MatchEvent) {
            $this->assertSameMatch($event->match_id, $match);
            $event->forceFill([
                'minute' => $data['minute'],
                'period' => $data['period'],
                'is_voided' => $data['is_voided'],
                'synced_at' => now(),
            ])->save();

            return;
        }

        $event = new MatchEvent;
        $event->forceFill([
            'client_uuid' => $data['client_uuid'],
            'match_id' => $match->id,
            'registration_id' => $data['registration_id'],
            'player_id' => $data['player_id'],
            'related_player_id' => $data['related_player_id'],
            'type' => $data['type'],
            'minute' => $data['minute'],
            'period' => $data['period'],
            'recorded_by_user_id' => $referee->id,
            'recorded_at' => $this->deviceTime($data['recorded_at']),
            'is_voided' => $data['is_voided'],
            'synced_at' => now(),
        ])->save();
    }

    /**
     * El marcador del árbitro se guarda con las mismas reglas que el del panel. Si el encargado ya lo capturó, manda
     * el del encargado; si rompe una regla (penales, torneo cerrado...), se informa sin perder escaneos ni eventos.
     *
     * @param  array{home_score: int, away_score: int, home_penalties: int|null, away_penalties: int|null}|null  $result
     * @return array{status: string, message: string|null}
     */
    private function storeResult(Tournament $tournament, GameMatch $match, ?array $result): array
    {
        if ($result === null) {
            return ['status' => 'none', 'message' => null];
        }

        if ($match->status === MatchStatus::PLAYED) {
            // Comparación como texto: el driver puede devolver los marcadores como cadena y null queda como ''.
            $same = (string) $match->home_score === (string) $result['home_score']
                && (string) $match->away_score === (string) $result['away_score']
                && (string) $match->home_penalties === (string) $result['home_penalties']
                && (string) $match->away_penalties === (string) $result['away_penalties'];

            return $same
                ? ['status' => 'saved', 'message' => null]
                : ['status' => 'already_recorded', 'message' => Message::REFEREE_RESULT_ALREADY_RECORDED];
        }

        try {
            $this->matchService->recordResult($tournament, $match, $result['home_score'], $result['away_score'], $result['home_penalties'], $result['away_penalties']);
        } catch (CustomErrorException $e) {
            return ['status' => 'rejected', 'message' => $e->getMessage()];
        }

        return ['status' => 'saved', 'message' => null];
    }

    /**
     * Inscripciones del partido por id (en liguilla pueden faltar mientras no se definan los equipos).
     *
     * @return array<int, Registration>
     */
    private function registrationsOf(GameMatch $match): array
    {
        $ids = array_values(array_filter([$match->home_registration_id, $match->away_registration_id]));

        return Registration::query()->withTrashed()->with(['team' => fn ($team) => $team->withTrashed()])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Registration $registration) => array_search($registration->id, $ids, true))
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, int>  $playerIds
     * @return array<int, int>
     */
    private function bannedPlayerIds(int $leagueId, array $playerIds): array
    {
        return PlayerLeagueBan::query()
            ->where('league_id', $leagueId)
            ->whereNull('lifted_at')
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id')
            ->map(fn (mixed $id) => (int) (is_numeric($id) ? $id : 0))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sanción vigente en el torneo (sin cumplir y sin borrar).
     *
     * @param  array<int, int>  $playerIds
     * @return array<int, int>
     */
    private function suspendedPlayerIds(int $tournamentId, array $playerIds): array
    {
        return DB::table('sanctions')
            ->where('tournament_id', $tournamentId)
            ->where('is_completed', false)
            ->whereNull('deleted_at')
            ->whereIn('player_id', $playerIds)
            ->pluck('player_id')
            ->map(fn (mixed $id) => (int) (is_numeric($id) ? $id : 0))
            ->unique()
            ->values()
            ->all();
    }

    private function assertAssigned(GameMatch $match, User $referee): void
    {
        if ($match->referee_user_id !== $referee->id) {
            throw new CustomErrorException(Message::AUTHORIZATION_EXCEPTION, Response::HTTP_FORBIDDEN);
        }
    }

    private function assertSameMatch(int $matchId, GameMatch $match): void
    {
        if ($matchId !== $match->id) {
            throw new CustomErrorException(Message::REFEREE_SYNC_UUID_TAKEN, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Hora del celular (ISO 8601 con zona) en la zona de la app, como se guardan las demás fechas.
     */
    private function deviceTime(string $value): Carbon
    {
        $timezone = config('app.timezone');

        return Carbon::parse($value)->setTimezone(is_string($timezone) ? $timezone : 'UTC');
    }
}
