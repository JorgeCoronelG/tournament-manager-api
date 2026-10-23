<?php

namespace Tests\Feature\Groups;

use App\Core\Enum\TournamentGroupType;
use App\Models\League;
use App\Models\Registration;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use Tests\Feature\Registrations\InteractsWithRegistrations;

trait InteractsWithGroups
{
    use InteractsWithRegistrations;

    private function groupsUrl(League $league, Tournament $tournament, string $suffix = ''): string
    {
        return "/api/leagues/{$league->id}/tournaments/{$tournament->id}/groups{$suffix}";
    }

    private function makeGroup(Tournament $tournament, string $name, TournamentGroupType $type = TournamentGroupType::REGULAR_STAGE): TournamentGroup
    {
        $group = new TournamentGroup;
        $group->forceFill(['tournament_id' => $tournament->id, 'name' => $name, 'type' => $type])->save();

        return $group;
    }

    /**
     * @return list<Registration>
     */
    private function teamsIn(Tournament $tournament, ?TournamentGroup $group, int $count): array
    {
        $registrations = [];

        for ($i = 0; $i < $count; $i++) {
            $registrations[] = Registration::factory()->create(['tournament_id' => $tournament->id, 'group_id' => $group?->id]);
        }

        return $registrations;
    }
}
