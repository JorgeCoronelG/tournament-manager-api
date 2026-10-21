<?php

namespace App\Http\Resources\Registrations;

use App\Core\Enum\UserAccountStatus;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Equipo tal como lo muestra el módulo de inscripciones: nombre, logo, estado (`banned` no se puede inscribir) y delegado (`null` si no tiene).
 * `pending` indica que el delegado aún no activa su cuenta.
 *
 * @property Team $resource
 */
class RegistrationTeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $manager = $this->resource->manager;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'logo_url' => $this->resource->logoUrl(),
            'status' => $this->resource->status->value,
            'manager' => $manager === null ? null : [
                'name' => trim($manager->first_name.' '.$manager->last_name),
                'pending' => $manager->accountStatus() === UserAccountStatus::PENDING,
            ],
        ];
    }
}
