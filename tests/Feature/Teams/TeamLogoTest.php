<?php

namespace Tests\Feature\Teams;

use App\Contracts\Services\LogoProcessorInterface;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class TeamLogoTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    private function logoUrl(int $leagueId, Team $team): string
    {
        return "/api/leagues/{$leagueId}/teams/{$team->id}/logo";
    }

    /**
     * @return array{0: Team, 1: array<string, string>, 2: string}
     */
    private function teamWithUrl(): array
    {
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        return [$team, $header, $this->logoUrl($league->id, $team)];
    }

    public function test_uploads_a_large_png_and_stores_a_webp_of_at_most_512_px(): void
    {
        Storage::fake('public');
        [$team, $header, $url] = $this->teamWithUrl();

        $response = $this->post($url, ['logo' => UploadedFile::fake()->image('mi logo.png', 1200, 800)], $header + ['Accept' => 'application/json'])
            ->assertOk();

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^teams/logos/[0-9a-f-]{36}\.webp$#', $files[0]);
        $this->assertSame($files[0], $team->fresh()->logo_path);
        $this->assertSame(Storage::disk('public')->url($files[0]), $response->json('logo_url'));

        $info = getimagesizefromstring(Storage::disk('public')->get($files[0]));
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(512, $info[0]);
        $this->assertSame(341, $info[1]);
    }

    public function test_does_not_enlarge_small_images(): void
    {
        Storage::fake('public');
        [, $header, $url] = $this->teamWithUrl();

        $this->post($url, ['logo' => UploadedFile::fake()->image('a.jpg', 100, 80)], $header + ['Accept' => 'application/json'])->assertOk();

        $info = getimagesizefromstring(Storage::disk('public')->get(Storage::disk('public')->allFiles()[0]));
        $this->assertSame([100, 80], [$info[0], $info[1]]);
    }

    public function test_the_client_file_name_is_never_used(): void
    {
        Storage::fake('public');
        [, $header, $url] = $this->teamWithUrl();

        $this->post($url, ['logo' => UploadedFile::fake()->image('../../evil.png', 200, 200)], $header + ['Accept' => 'application/json'])->assertOk();

        $this->assertStringNotContainsString('evil', Storage::disk('public')->allFiles()[0]);
    }

    public function test_the_image_processor_can_be_replaced(): void
    {
        Storage::fake('public');
        [$team, $header, $url] = $this->teamWithUrl();
        $this->mock(LogoProcessorInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('process')->once()->andReturn('webp-bytes');
        });

        $this->post($url, ['logo' => UploadedFile::fake()->image('a.png', 200, 200)], $header + ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('webp-bytes', Storage::disk('public')->get($team->fresh()->logo_path));
    }

    public function test_rejects_invalid_files(): void
    {
        Storage::fake('public');
        [, $header, $url] = $this->teamWithUrl();
        $headers = $header + ['Accept' => 'application/json'];

        $invalid = [
            'más de 2 MB' => UploadedFile::fake()->image('big.png', 200, 200)->size(2049),
            'texto renombrado como png' => UploadedFile::fake()->createWithContent('fake.png', 'esto no es una imagen'),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>'),
            'menor a 64 px' => UploadedFile::fake()->image('small.png', 63, 200),
            'gif' => UploadedFile::fake()->image('anim.gif', 200, 200),
        ];

        foreach ($invalid as $case => $file) {
            $this->post($url, ['logo' => $file], $headers)->assertStatus(422, $case)->assertJsonStructure(['error' => ['logo']]);
        }

        $this->post($url, [], $headers)->assertStatus(422)->assertJsonStructure(['error' => ['logo']]);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        [$team, $header, $url] = $this->teamWithUrl();
        $headers = $header + ['Accept' => 'application/json'];

        $this->post($url, ['logo' => UploadedFile::fake()->image('a.png', 200, 200)], $headers)->assertOk();
        $first = $team->fresh()->logo_path;
        $this->post($url, ['logo' => UploadedFile::fake()->image('b.png', 300, 300)], $headers)->assertOk();
        $second = $team->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_deletes_the_file_and_logo_url_is_null(): void
    {
        Storage::fake('public');
        [$team, $header, $url] = $this->teamWithUrl();
        $headers = $header + ['Accept' => 'application/json'];

        $this->post($url, ['logo' => UploadedFile::fake()->image('a.png', 200, 200)], $headers)->assertOk();
        $path = $team->fresh()->logo_path;

        $this->deleteJson($url, [], $header)->assertOk()->assertJsonPath('logo_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($team->fresh()->logo_path);
        $this->deleteJson($url, [], $header)->assertOk();
    }

    public function test_deleting_the_team_keeps_the_file(): void
    {
        Storage::fake('public');
        [$team, $header, $url] = $this->teamWithUrl();

        $this->post($url, ['logo' => UploadedFile::fake()->image('a.png', 200, 200)], $header + ['Accept' => 'application/json'])->assertOk();
        $path = $team->fresh()->logo_path;

        $this->deleteJson("/api/leagues/{$team->league_id}/teams/{$team->id}", [], $header)->assertNoContent();

        Storage::disk('public')->assertExists($path);
    }

    public function test_logo_url_is_null_without_logo(): void
    {
        [$team, $header] = $this->teamWithUrl();
        [$league, $header] = $this->leagueWithAdminHeader();
        $team = Team::factory()->create(['league_id' => $league->id]);

        $this->getJson("/api/leagues/{$league->id}/teams/{$team->id}", $header)->assertOk()->assertJsonPath('logo_url', null);
    }
}
