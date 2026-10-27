<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Configuración completa de horarios del torneo (reemplaza la anterior). Aquí solo la forma de los datos; las reglas
 * de negocio (empalmes, canchas, duración del partido) las valida ScheduleService.
 */
class SaveScheduleConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'schedule_start_date' => ['nullable', 'date_format:Y-m-d'],
            'windows' => ['present', 'array'],
            'windows.*.weekday' => ['required', 'integer', 'between:1,7'],
            'windows.*.field_id' => ['required', 'integer', 'min:1'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
            'blocks' => ['present', 'array'],
            'blocks.*.date' => ['required', 'date_format:Y-m-d'],
            'blocks.*.field_id' => ['nullable', 'integer', 'min:1'],
            'blocks.*.start_time' => ['nullable', 'date_format:H:i'],
            'blocks.*.end_time' => ['nullable', 'date_format:H:i'],
            'blocks.*.reason' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'schedule_start_date' => 'fecha de inicio del calendario',
            'windows' => 'ventanas',
            'windows.*.weekday' => 'día de la semana',
            'windows.*.field_id' => 'cancha',
            'windows.*.start_time' => 'hora de inicio',
            'windows.*.end_time' => 'hora de fin',
            'blocks' => 'bloqueos',
            'blocks.*.date' => 'fecha del bloqueo',
            'blocks.*.field_id' => 'cancha del bloqueo',
            'blocks.*.start_time' => 'hora de inicio del bloqueo',
            'blocks.*.end_time' => 'hora de fin del bloqueo',
            'blocks.*.reason' => 'motivo',
        ];
    }

    /**
     * @return array{schedule_start_date: string|null, windows: list<array{weekday: int, field_id: int, start_time: string, end_time: string}>, blocks: list<array{date: string, field_id: int|null, start_time: string|null, end_time: string|null, reason: string|null}>}
     */
    public function config(): array
    {
        $windows = [];
        $blocks = [];

        foreach (array_keys((array) $this->input('windows')) as $i) {
            $windows[] = [
                'weekday' => $this->integer("windows.{$i}.weekday"),
                'field_id' => $this->integer("windows.{$i}.field_id"),
                'start_time' => $this->string("windows.{$i}.start_time")->value(),
                'end_time' => $this->string("windows.{$i}.end_time")->value(),
            ];
        }

        foreach (array_keys((array) $this->input('blocks')) as $i) {
            $blocks[] = [
                'date' => $this->string("blocks.{$i}.date")->value(),
                'field_id' => $this->filled("blocks.{$i}.field_id") ? $this->integer("blocks.{$i}.field_id") : null,
                'start_time' => $this->filled("blocks.{$i}.start_time") ? $this->string("blocks.{$i}.start_time")->value() : null,
                'end_time' => $this->filled("blocks.{$i}.end_time") ? $this->string("blocks.{$i}.end_time")->value() : null,
                'reason' => $this->filled("blocks.{$i}.reason") ? $this->string("blocks.{$i}.reason")->value() : null,
            ];
        }

        return [
            'schedule_start_date' => $this->filled('schedule_start_date') ? $this->string('schedule_start_date')->value() : null,
            'windows' => $windows,
            'blocks' => $blocks,
        ];
    }
}
