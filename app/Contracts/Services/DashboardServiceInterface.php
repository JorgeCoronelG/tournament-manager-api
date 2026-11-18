<?php

namespace App\Contracts\Services;

use App\Exceptions\CustomErrorException;
use App\Models\League;

interface DashboardServiceInterface
{
    /**
     * Cifras del panel del superadmin y la semana usada (lunes a domingo, Y-m-d, en la zona horaria de la aplicación).
     * Sin `$leagueId` son globales (solo ligas activas); con él, las cifras de esa liga (aunque esté inactiva).
     * `leagues_active` es siempre el total global de ligas activas.
     *
     * @return array{league_id: int|null, leagues_active: int, tournaments_active: int, teams_in_active_tournaments: int, players_in_active_tournaments: int, matches_this_week: int, referees_active: int, week: array{from: string, to: string}}
     *
     * @throws CustomErrorException 404 si la liga no existe o está eliminada
     */
    public function summary(?int $leagueId = null): array;

    /**
     * Resumen de una liga para su administrador: las mismas cifras que `summary` filtrado, sin `leagues_active` ni
     * `league_id`.
     *
     * @return array{tournaments_active: int, teams_in_active_tournaments: int, players_in_active_tournaments: int, matches_this_week: int, referees_active: int, week: array{from: string, to: string}}
     */
    public function leagueSummary(League $league): array;
}
