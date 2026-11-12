<?php

namespace App\Services;

use App\Contracts\Repositories\MatchRepositoryInterface;
use App\Core\Enum\MatchStatus;
use App\Helpers\ScheduleCapacity;
use App\Models\GameMatch;
use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regla única de disponibilidad de canchas de una liga. Carga una vez los partidos programados de la liga en un rango
 * (sin cancelados; jugados y fijados sí ocupan) y responde si un espacio (cancha, fecha, hora de inicio y duración)
 * está ocupado y por cuál partido.
 *
 * Se comparan intervalos completos [inicio, inicio + duración del torneo de cada partido): chocan si
 * inicio_a < fin_b e inicio_b < fin_a. Uno que termina a las 9:30 no choca con otro que empieza a las 9:30.
 *
 * forRange() y los filtros devuelven una copia: la instancia inyectada nunca guarda partidos.
 */
class FieldAvailability
{
    /** @var list<GameMatch> Ordenados por hora de inicio. */
    private array $matches = [];

    public function __construct(protected MatchRepositoryInterface $matchRepository) {}

    /**
     * Partidos de la liga que pueden tocar el rango [$from, $to): también los que empezaron antes y siguen en juego.
     *
     * @param  list<int>  $excludeIds  Partidos que no cuentan.
     * @param  bool  $lock  Bloquea las filas leídas (lockForUpdate); solo dentro de una transacción.
     */
    public function forRange(int $leagueId, CarbonInterface $from, CarbonInterface $to, array $excludeIds = [], bool $lock = false): static
    {
        $start = CarbonImmutable::instance($from)->subMinutes(Tournament::MAX_MATCH_DURATION_MINUTES);
        $loaded = $this->matchRepository->scheduledInLeague($leagueId, $start, $to, $excludeIds, $lock);

        return $this->with(array_values($loaded->all()));
    }

    /**
     * Sin los partidos indicados (por ejemplo, los que se van a reprogramar).
     *
     * @param  list<int>  $matchIds
     */
    public function withoutMatches(array $matchIds): static
    {
        $skip = array_flip($matchIds);

        return $this->with(array_filter($this->matches, fn (GameMatch $match) => ! isset($skip[$match->id])));
    }

    /**
     * Solo los partidos de otros torneos de la liga.
     */
    public function withoutTournament(int $tournamentId): static
    {
        return $this->with(array_filter($this->matches, fn (GameMatch $match) => $match->tournament_id !== $tournamentId));
    }

    /**
     * @return list<GameMatch>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    /**
     * Partido que ocupa la cancha en el espacio, o null si está libre.
     *
     * @param  string  $date  Y-m-d
     * @param  int  $start  Minutos desde la medianoche.
     * @param  int  $duration  Minutos del espacio.
     */
    public function occupant(int $fieldId, string $date, int $start, int $duration): ?GameMatch
    {
        return $this->occupantAt($fieldId, self::slotStart($date, $start), $duration);
    }

    /**
     * Lo mismo que occupant(), con el inicio como fecha y hora.
     */
    public function occupantAt(int $fieldId, CarbonInterface $at, int $duration): ?GameMatch
    {
        foreach ($this->overlapping($at, $duration) as $match) {
            if ($match->field_id === $fieldId) {
                return $match;
            }
        }

        return null;
    }

    public function isTaken(int $fieldId, string $date, int $start, int $duration): bool
    {
        return $this->occupant($fieldId, $date, $start, $duration) !== null;
    }

    /**
     * Partidos (de cualquier cancha) cuyo horario se traslapa con [$at, $at + $duration).
     *
     * @return list<GameMatch>
     */
    public function overlapping(CarbonInterface $at, int $duration): array
    {
        $from = intdiv($at->getTimestamp(), 60);
        $to = $from + max(1, $duration);
        $found = [];

        foreach ($this->matches as $match) {
            $interval = self::interval($match);

            if ($interval !== null && self::overlaps($from, $to, $interval[0], $interval[1])) {
                $found[] = $match;
            }
        }

        return $found;
    }

    /**
     * Dos intervalos semiabiertos [inicio, fin) se traslapan.
     */
    public static function overlaps(int $fromA, int $toA, int $fromB, int $toB): bool
    {
        return $fromA < $toB && $fromB < $toA;
    }

    /**
     * Intervalo del partido en minutos desde la época, con la duración de su torneo. Null si no ocupa (sin hora o
     * cancelado).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function interval(GameMatch $match): ?array
    {
        if ($match->match_datetime === null || $match->status === MatchStatus::CANCELLED) {
            return null;
        }

        $from = intdiv($match->match_datetime->getTimestamp(), 60);

        return [$from, $from + self::duration($match->tournament)];
    }

    /**
     * Minutos que dura un partido del torneo (el valor por defecto si no tiene uno válido).
     */
    public static function duration(?Tournament $tournament): int
    {
        $raw = $tournament?->getAttribute('match_duration_minutes');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : Tournament::DEFAULT_MATCH_DURATION_MINUTES;
    }

    /**
     * Inicio de un espacio (fecha Y-m-d y minutos desde la medianoche) como fecha y hora.
     */
    public static function slotStart(string $date, int $start): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay()->addMinutes($start);
    }

    /**
     * Datos del partido que ocupa un espacio, para explicar por qué no está disponible.
     *
     * @return array{date: string, field_id: int|null, field_name: string|null, start_time: string, end_time: string, tournament_id: int, tournament_name: string|null}
     */
    public static function describe(GameMatch $match): array
    {
        $at = $match->match_datetime === null ? CarbonImmutable::now() : CarbonImmutable::instance($match->match_datetime);
        $end = $at->addMinutes(self::duration($match->tournament));

        return [
            'date' => $at->toDateString(),
            'field_id' => $match->field_id,
            'field_name' => $match->field?->name,
            'start_time' => ScheduleCapacity::time((int) $at->format('G') * 60 + (int) $at->format('i')),
            'end_time' => ScheduleCapacity::time((int) $end->format('G') * 60 + (int) $end->format('i')),
            'tournament_id' => (int) $match->tournament_id,
            'tournament_name' => $match->tournament?->name,
        ];
    }

    /**
     * @param  array<int, GameMatch>  $matches
     */
    private function with(array $matches): static
    {
        $copy = clone $this;
        $copy->matches = array_values($matches);

        return $copy;
    }
}
