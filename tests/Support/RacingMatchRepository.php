<?php

namespace Tests\Support;

use App\Repositories\MatchRepository;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Repositorio de partidos que simula un cambio concurrente: ejecuta una vez $beforeLockedRead justo antes de la
 * lectura con bloqueo (la que se hace dentro de la transacción, entre el cálculo y el guardado).
 */
class RacingMatchRepository extends MatchRepository
{
    public static ?Closure $beforeLockedRead = null;

    public function scheduledInLeague(int $leagueId, CarbonInterface $from, CarbonInterface $to, array $excludeIds = [], bool $lock = false): Collection
    {
        if ($lock && self::$beforeLockedRead !== null) {
            $hook = self::$beforeLockedRead;
            self::$beforeLockedRead = null;
            $hook();
        }

        return parent::scheduledInLeague($leagueId, $from, $to, $excludeIds, $lock);
    }
}
