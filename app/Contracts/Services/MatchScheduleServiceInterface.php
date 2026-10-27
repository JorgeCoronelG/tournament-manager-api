<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\GameMatch;
use App\Models\League;

/**
 * Edición manual del horario de un partido, intercambio de horarios y árbitro. Los conflictos de cancha, árbitro y
 * equipo se revisan contra todos los partidos programados de la liga.
 */
interface MatchScheduleServiceInterface
{
    public const CODE_FIELD_CONFLICT = 'field_conflict';

    public const CODE_REFEREE_CONFLICT = 'referee_conflict';

    public const CODE_TEAM_CONFLICT = 'team_conflict';

    /**
     * Actualiza solo lo enviado (null quita el valor). Un cambio de horario, cancha o árbitro fija el partido salvo
     * que `schedule_locked` diga otra cosa.
     *
     * @param  array<string, mixed>  $data  Claves presentes: match_datetime (Y-m-d H:i:s o null), field_id, referee_user_id, schedule_locked.
     * @return array{match: GameMatch, warnings: list<array{code: string, message: string}>}
     *
     * @throws CustomErrorException
     */
    public function update(League $league, GameMatch $match, array $data): array;

    /**
     * Intercambia fecha, cancha y árbitro entre dos partidos del mismo torneo; ambos quedan fijados.
     *
     * @return array{match: GameMatch, with_match: GameMatch, warnings: list<array{code: string, message: string}>}
     *
     * @throws CustomErrorException
     */
    public function swap(League $league, GameMatch $match, int $withMatchId): array;

    /**
     * Espacios configurados de la fecha que están libres para el partido.
     *
     * @return array{date: string|null, slots: list<array{start_time: string, end_time: string, field_id: int, field_name: string}>}
     *
     * @throws CustomErrorException
     */
    public function availableSlots(League $league, GameMatch $match, ?string $date): array;

    /**
     * Árbitros activos de la liga y si pueden cubrir el partido en esa fecha y hora.
     *
     * @return list<array{id: int, name: string, available: bool, reason: string|null}>
     */
    public function availableReferees(League $league, GameMatch $match, ?string $datetime): array;

    /**
     * Avisos (no bloquean) de choque de equipo de un partido ya programado, p. ej. cuando un ganador avanza a un
     * partido de liguilla que ya tenía hora. Con `$registrationId` solo se revisa el equipo de esa inscripción (aunque
     * el partido aún no tenga local y visitante). Cada uno `{code: team_conflict, message}`; vacío sin hora o sin choque.
     *
     * @return list<array{code: string, message: string}>
     */
    public function teamConflictWarnings(GameMatch $match, ?int $registrationId = null): array;
}
