<?php

namespace App\Contracts\Services;

use App\Core\Enum\WalkoverCause;
use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\PlayoffSource;
use App\Models\Tournament;

/**
 * Cierre de la fase regular, cruces de liguilla (llaves) y resultados de sus partidos.
 */
interface PlayoffServiceInterface
{
    /**
     * Bloqueos, avisos y, sin bloqueos, la propuesta (clasificados, cruces de primera ronda y fechas) de cada liguilla.
     *
     * @return array{blockers: list<array<string, mixed>>, warnings: list<array<string, mixed>>, schedule_warnings: list<array<string, mixed>>, playoffs: list<array<string, mixed>>} `schedule_warnings` avisa de capacidad de horarios en las fechas propuestas (no bloquea); `playoffs` trae la propuesta (sin bloqueos) y la comparativa `best_of` de las fuentes de mejores
     *
     * @throws CustomErrorException
     */
    public function preview(Tournament $tournament): array;

    /**
     * Guarda el orden manual de los candidatos empatados de una fuente de mejores de una posición. Solo con el torneo en curso.
     *
     * @param  list<int>  $teamIds  del primero al último
     *
     * @throws CustomErrorException
     */
    public function saveBestManualOrder(Tournament $tournament, PlayoffSource $source, array $teamIds): void;

    /**
     * Borra el orden manual de la comparativa de una fuente de mejores. Solo con el torneo en curso.
     *
     * @throws CustomErrorException
     */
    public function clearBestManualOrder(Tournament $tournament, PlayoffSource $source): void;

    /**
     * Cierra la fase regular: guarda fechas, crea cruces y partidos de cada liguilla y pasa el torneo a liguilla.
     *
     * @param  list<array{group_id: int, ties?: list<array{bracket_position: int, team_a_id: int, team_b_id: int}>|null, round_dates: list<string>}>  $entries
     *
     * @throws CustomErrorException
     */
    public function close(Tournament $tournament, array $entries): Tournament;

    /**
     * Llaves de cada liguilla y campeón. Con el torneo en liguilla o terminado.
     *
     * @return array{playoffs: list<array<string, mixed>>}
     *
     * @throws CustomErrorException
     */
    public function bracket(Tournament $tournament): array;

    /**
     * Resultado de un partido de liguilla: valida penales y orden, define el cruce y avanza al ganador.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>, advanced_to: array<string, mixed>|null}
     *
     * @throws CustomErrorException
     */
    public function recordResult(GameMatch $match, int $homeGoals, int $awayGoals, ?int $homePenalties, ?int $awayPenalties): array;

    /**
     * Anula el resultado de un partido de liguilla y limpia ganador y avance.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>, advanced_to: array<string, mixed>|null}
     *
     * @throws CustomErrorException
     */
    public function clearResult(GameMatch $match): array;

    /**
     * Partido de liguilla jugado por default: todos los partidos pendientes del cruce pasan a por default con el
     * marcador dado, el rival queda como ganador (decided_by walkover) y avanza.
     *
     * @return array{match: GameMatch, tie: array<string, mixed>, advanced_to: array<string, mixed>|null}
     */
    public function registerWalkover(GameMatch $match, int $loserRegistrationId, int $goals, WalkoverCause $cause, ?int $userId, ?string $reason): array;

    /**
     * El cruce del partido, como se muestra en las respuestas de resultado.
     *
     * @return array<string, mixed>
     */
    public function tieOf(GameMatch $match): array;
}
