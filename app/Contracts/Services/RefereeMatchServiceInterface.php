<?php

namespace App\Contracts\Services;

use App\Core\Enum\MatchEventType;
use App\Core\Enum\MatchPeriod;
use App\Core\Enum\ScanMethod;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * App móvil, rol Árbitro: sus partidos, la plantilla de cada uno para validar sin internet y la sincronización por
 * lote de lo que registró en la cancha. Cada método comprueba que el partido sea suyo (403).
 */
interface RefereeMatchServiceInterface
{
    /**
     * Partidos con fecha asignados al árbitro entre dos días (inclusive), solo de las ligas donde está activo.
     * Con torneo, liga, cancha, equipos y cruce de liguilla cargados, por fecha.
     *
     * @return Collection<int, GameMatch>
     */
    public function matchesOf(User $referee, CarbonImmutable $from, CarbonImmutable $to): Collection;

    /**
     * Plantillas de los dos equipos (activos y dados de baja) con el QR, la foto y si cada jugador está vetado o
     * suspendido, para que el celular valide sin conexión.
     *
     * @return array{match_id: int, downloaded_at: string, teams: list<array<string, mixed>>}
     *
     * @throws CustomErrorException
     */
    public function rostersOf(GameMatch $match, User $referee): array;

    /**
     * Guarda escaneos y eventos por client_uuid (reenviar el mismo lote no duplica nada) y, si viene, el marcador.
     * El resultado de cada escaneo lo calcula el servidor. Si el marcador no se puede guardar (ya lo capturó el
     * encargado o rompe una regla de penales), el resto se guarda igual y la respuesta dice por qué.
     *
     * @param  array{scans: list<array{client_uuid: string, method: ScanMethod, scanned_code: string|null, player_id: int|null, scanned_at: string}>, events: list<array{client_uuid: string, registration_id: int, player_id: int|null, related_player_id: int|null, type: MatchEventType, minute: int|null, period: MatchPeriod|null, recorded_at: string, is_voided: bool}>, result: array{home_score: int, away_score: int, home_penalties: int|null, away_penalties: int|null}|null}  $payload
     * @return array{scans: list<array{client_uuid: string, result: string}>, events: int, result: array{status: string, message: string|null}}
     *
     * @throws CustomErrorException
     */
    public function sync(GameMatch $match, User $referee, array $payload): array;
}
