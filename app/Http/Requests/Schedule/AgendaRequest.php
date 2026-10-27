<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class AgendaRequest extends FormRequest
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'tournament_id' => ['nullable', 'integer', 'min:1'],
            'field_id' => ['nullable', 'integer', 'min:1'],
            'referee_user_id' => ['nullable', 'integer', 'min:1'],
            'unassigned_referee' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date_from' => 'fecha de inicio',
            'date_to' => 'fecha de fin',
            'tournament_id' => 'torneo',
            'field_id' => 'cancha',
            'referee_user_id' => 'árbitro',
            'unassigned_referee' => 'sin árbitro',
        ];
    }

    /**
     * @return array{date_from?: string|null, date_to?: string|null, tournament_id?: int, field_id?: int, referee_user_id?: int, unassigned_referee?: bool}
     */
    public function filters(): array
    {
        $filters = [
            'date_from' => $this->filled('date_from') ? $this->string('date_from')->value() : null,
            'date_to' => $this->filled('date_to') ? $this->string('date_to')->value() : null,
        ];

        foreach (['tournament_id', 'field_id', 'referee_user_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->integer($key);
            }
        }

        if ($this->boolean('unassigned_referee')) {
            $filters['unassigned_referee'] = true;
        }

        return $filters;
    }

    public function tournamentId(): ?int
    {
        return $this->filled('tournament_id') ? $this->integer('tournament_id') : null;
    }
}
