<?php

namespace App\Http\Requests\Referee;

use App\Core\Enum\MatchEventType;
use App\Core\Enum\MatchPeriod;
use App\Core\Enum\ScanMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lote que manda el celular del árbitro: escaneos, eventos y, al cerrar el partido, el marcador. Las horas son las
 * del celular (ISO 8601). El resultado de cada escaneo que mande el celular se ignora: lo calcula el servidor.
 */
class SyncRefereeMatchRequest extends FormRequest
{
    private const MAX_ITEMS = 500;

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
            'scans' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'scans.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'scans.*.method' => ['sometimes', Rule::enum(ScanMethod::class)],
            'scans.*.scanned_code' => ['required_unless:scans.*.method,manual', 'nullable', 'string', 'max:255'],
            'scans.*.player_id' => ['required_if:scans.*.method,manual', 'nullable', 'integer'],
            'scans.*.scanned_at' => ['required', 'date'],

            'events' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'events.*.client_uuid' => ['required', 'uuid', 'distinct'],
            'events.*.registration_id' => ['required', 'integer'],
            'events.*.player_id' => ['nullable', 'integer'],
            'events.*.related_player_id' => ['nullable', 'integer'],
            'events.*.type' => ['required', Rule::enum(MatchEventType::class)],
            'events.*.minute' => ['nullable', 'integer', 'min:0', 'max:200'],
            'events.*.period' => ['nullable', Rule::enum(MatchPeriod::class)],
            'events.*.recorded_at' => ['required', 'date'],
            'events.*.is_voided' => ['sometimes', 'boolean'],

            'result' => ['sometimes', 'nullable', 'array'],
            'result.home_score' => ['required_with:result', 'integer', 'min:0', 'max:99'],
            'result.away_score' => ['required_with:result', 'integer', 'min:0', 'max:99'],
            'result.home_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
            'result.away_penalties' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scans.*.scanned_code' => 'código escaneado',
            'scans.*.player_id' => 'jugador',
            'events.*.registration_id' => 'equipo del evento',
            'events.*.type' => 'tipo de evento',
            'events.*.minute' => 'minuto',
            'events.*.period' => 'tiempo del partido',
            'result.home_score' => 'goles del local',
            'result.away_score' => 'goles del visitante',
        ];
    }

    /**
     * @return array{scans: list<array{client_uuid: string, method: ScanMethod, scanned_code: string|null, player_id: int|null, scanned_at: string}>, events: list<array{client_uuid: string, registration_id: int, player_id: int|null, related_player_id: int|null, type: MatchEventType, minute: int|null, period: MatchPeriod|null, recorded_at: string, is_voided: bool}>, result: array{home_score: int, away_score: int, home_penalties: int|null, away_penalties: int|null}|null}
     */
    public function payload(): array
    {
        $scans = [];

        foreach ($this->items('scans') as $scan) {
            $scans[] = [
                'client_uuid' => $this->text($scan['client_uuid'] ?? null) ?? '',
                'method' => ScanMethod::tryFrom($this->text($scan['method'] ?? null) ?? '') ?? ScanMethod::QR,
                'scanned_code' => $this->text($scan['scanned_code'] ?? null),
                'player_id' => $this->int($scan['player_id'] ?? null),
                'scanned_at' => $this->text($scan['scanned_at'] ?? null) ?? '',
            ];
        }

        $events = [];

        foreach ($this->items('events') as $event) {
            $events[] = [
                'client_uuid' => $this->text($event['client_uuid'] ?? null) ?? '',
                'registration_id' => $this->int($event['registration_id'] ?? null) ?? 0,
                'player_id' => $this->int($event['player_id'] ?? null),
                'related_player_id' => $this->int($event['related_player_id'] ?? null),
                'type' => MatchEventType::from($this->text($event['type'] ?? null) ?? ''),
                'minute' => $this->int($event['minute'] ?? null),
                'period' => MatchPeriod::tryFrom($this->text($event['period'] ?? null) ?? ''),
                'recorded_at' => $this->text($event['recorded_at'] ?? null) ?? '',
                'is_voided' => filter_var($event['is_voided'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        $result = $this->input('result');
        $result = is_array($result) ? [
            'home_score' => $this->int($result['home_score'] ?? null) ?? 0,
            'away_score' => $this->int($result['away_score'] ?? null) ?? 0,
            'home_penalties' => $this->int($result['home_penalties'] ?? null),
            'away_penalties' => $this->int($result['away_penalties'] ?? null),
        ] : null;

        return ['scans' => $scans, 'events' => $events, 'result' => $result];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function items(string $key): array
    {
        $items = $this->input($key);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
