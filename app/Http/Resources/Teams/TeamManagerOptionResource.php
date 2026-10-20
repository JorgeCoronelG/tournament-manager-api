<?php

namespace App\Http\Resources\Teams;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Opción del filtro por delegado: solo lo necesario para mostrar y filtrar.
 *
 * @property User $resource
 */
class TeamManagerOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
        ];
    }
}
