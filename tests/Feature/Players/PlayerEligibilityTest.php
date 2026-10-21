<?php

namespace Tests\Feature\Players;

use App\Core\Enum\Message;
use App\Core\Enum\PlayerGender;
use App\Core\Enum\TournamentGender;
use App\Exceptions\CustomErrorException;
use App\Models\Player;
use App\Models\Tournament;
use App\Services\PlayerEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlayerEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function genderMatrix(): array
    {
        return [
            'male tournament, male player' => ['male', 'male', true],
            'male tournament, female player' => ['male', 'female', false],
            'female tournament, female player' => ['female', 'female', true],
            'female tournament, male player' => ['female', 'male', false],
            'mixed tournament, male player' => ['mixed', 'male', true],
            'mixed tournament, female player' => ['mixed', 'female', true],
        ];
    }

    #[DataProvider('genderMatrix')]
    public function test_gender_rule(string $tournamentGender, string $playerGender, bool $eligible): void
    {
        $tournament = Tournament::factory()->create(['gender' => TournamentGender::from($tournamentGender)]);
        $player = Player::factory()->gender(PlayerGender::from($playerGender))->create();

        $this->assertSame($eligible, (new PlayerEligibility)->isEligible($player, $tournament));
    }

    public function test_rejection_is_neutral_and_does_not_reveal_the_gender(): void
    {
        $tournament = Tournament::factory()->create(['gender' => TournamentGender::MALE]);
        $player = Player::factory()->gender(PlayerGender::FEMALE)->create();

        try {
            (new PlayerEligibility)->assertEligible($player, $tournament);
            $this->fail('Debió rechazar.');
        } catch (CustomErrorException $e) {
            $this->assertSame(Message::PLAYER_NOT_ELIGIBLE, $e->getMessage());
            $this->assertSame(422, $e->getCode());
            $this->assertStringNotContainsStringIgnoringCase('femenil', $e->getMessage());
        }
    }

    public function test_age_is_checked_at_the_tournament_start_date(): void
    {
        $tournament = Tournament::factory()->create([
            'gender' => TournamentGender::MIXED, 'min_age' => 18, 'max_age' => 30, 'start_date' => '2026-11-01',
        ]);
        $check = fn (string $birth) => (new PlayerEligibility)->isEligible(Player::factory()->create(['birth_date' => $birth]), $tournament);

        $this->assertTrue($check('2008-11-01'), 'cumple 18 el día de inicio');
        $this->assertFalse($check('2008-11-02'), 'cumple 18 un día después');
        $this->assertTrue($check('1996-11-02'), '30 años cumplidos');
        $this->assertFalse($check('1995-11-01'), '31 años');
    }

    public function test_no_age_limits_means_any_age(): void
    {
        $tournament = Tournament::factory()->create(['gender' => TournamentGender::MIXED, 'min_age' => null, 'max_age' => null]);

        $this->assertTrue((new PlayerEligibility)->isEligible(Player::factory()->create(['birth_date' => '1960-01-01']), $tournament));
    }
}
