<?php

namespace Tests\Feature\Rosters;

use App\Core\Enum\PlayerGender;
use App\Core\Enum\RosterStatus;
use App\Core\Enum\TournamentGender;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Roster;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Teams\InteractsWithTeams;

trait InteractsWithRosters
{
    use InteractsWithTeams;

    /**
     * Torneo mixto en planeación de una liga con encargado.
     */
    private function mixedTournament(array $attributes = []): Tournament
    {
        [$league] = $this->leagueWithAdminHeader();

        return Tournament::factory()->create(['league_id' => $league->id, 'gender' => TournamentGender::MIXED, ...$attributes]);
    }

    /**
     * Inscripción de un equipo con delegado en el torneo.
     *
     * @return array{0: Registration, 1: User} La inscripción y su delegado
     */
    private function registrationWithManager(Tournament $tournament): array
    {
        $manager = $this->createManagerUser();
        $team = Team::factory()->create(['league_id' => $tournament->league_id, 'manager_user_id' => $manager->id]);

        return [Registration::factory()->create(['team_id' => $team->id, 'tournament_id' => $tournament->id]), $manager];
    }

    private function player(PlayerGender $gender = PlayerGender::MALE): Player
    {
        return Player::factory()->gender($gender)->create(['birth_date' => '1995-05-05']);
    }

    private function rosterRow(Registration $registration, Player $player, RosterStatus $status, int $jersey = 10): Roster
    {
        return Roster::factory()->forRegistration($registration)->status($status)->create([
            'player_id' => $player->id,
            'jersey_number' => $jersey,
        ]);
    }

    /**
     * Llave eliminatoria: desde ahí el torneo está en liguilla.
     */
    private function startPlayoffs(Tournament $tournament): void
    {
        $groupId = DB::table('tournament_groups')->insertGetId([
            'tournament_id' => $tournament->id, 'name' => 'Liguilla', 'type' => 'playoff',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('brackets')->insert([
            'group_id' => $groupId, 'phase' => 'quarterfinal', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
