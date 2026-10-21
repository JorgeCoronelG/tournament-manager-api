<?php

namespace Tests\Feature\Registrations;

use App\Models\League;
use App\Models\Tournament;
use Tests\Feature\Teams\InteractsWithTeams;

trait InteractsWithRegistrations
{
    use InteractsWithTeams;

    /**
     * @return array{0: League, 1: Tournament, 2: array<string, string>} Liga de un encargado, un torneo en planeación suyo y su cabecera
     */
    private function tournamentWithAdminHeader(): array
    {
        [$league, $header] = $this->leagueWithAdminHeader();

        return [$league, Tournament::factory()->create(['league_id' => $league->id]), $header];
    }

    private function registrationsUrl(League $league, Tournament $tournament, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/registrations{$suffix}";
    }
}
