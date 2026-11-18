<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\Tournament;

interface TournamentSummaryServiceInterface
{
    /**
     * Resumen de un torneo finalizado: equipos que jugaron (activos y no vetados), liguillas, partidos jugados y goles
     * (de todo el torneo, sin penales), y el resultado de cada liguilla.
     *
     * Sin liguillas, `playoffs` es [] y `champion` es `{source: "standings", teams: [{id, name, logo_url, group_id,
     * group_name}]}` con el líder de cada grupo de la fase regular (null si algún primer lugar sigue empatado). Con
     * liguillas, `champion` es null: cada una trae el suyo.
     *
     * @return array<string, mixed>
     *
     * @throws CustomErrorException 422 si el torneo no está finalizado
     */
    public function summary(Tournament $tournament): array;
}
