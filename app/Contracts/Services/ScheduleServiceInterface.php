<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Tournament;
use Illuminate\Validation\ValidationException;

/**
 * Configuración de horarios del torneo (ventanas semanales por cancha y bloqueos) y su capacidad real.
 */
interface ScheduleServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function show(Tournament $tournament): array;

    /**
     * Reemplaza fecha de inicio, ventanas y bloqueos. Solo con el torneo en planeación o en curso.
     *
     * @param  array{schedule_start_date: string|null, windows: list<array{weekday: int, field_id: int, start_time: string, end_time: string}>, blocks: list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null, reason: string|null}>}  $data
     * @return array<string, mixed> La configuración guardada, como show().
     *
     * @throws CustomErrorException
     * @throws ValidationException
     */
    public function save(Tournament $tournament, array $data): array;

    /**
     * Espacios por semana y, con calendario de emparejamientos, partidos requeridos contra espacios por jornada.
     *
     * @return array<string, mixed>
     *
     * @throws CustomErrorException
     */
    public function capacity(Tournament $tournament): array;

    /**
     * Lo mismo que capacity(), pero con la configuración recibida (sin guardarla): para ver el efecto de los cambios antes de guardar.
     *
     * @param  array{schedule_start_date: string|null, windows: list<array{weekday: int, field_id: int, start_time: string, end_time: string}>, blocks: list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null, reason: string|null}>}  $data
     * @return array<string, mixed>
     *
     * @throws CustomErrorException
     */
    public function previewCapacity(Tournament $tournament, array $data): array;
}
