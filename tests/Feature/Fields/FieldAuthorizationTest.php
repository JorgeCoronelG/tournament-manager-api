<?php

namespace Tests\Feature\Fields;

use App\Core\Enum\Role as RoleEnum;
use App\Models\Field;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldAuthorizationTest extends TestCase
{
    use InteractsWithFields, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'index' => ['GET', ''],
            'store' => ['POST', ''],
            'show' => ['GET', '/{f}'],
            'update' => ['PUT', '/{f}'],
            'status' => ['PATCH', '/{f}/status'],
            'destroy' => ['DELETE', '/{f}'],
        ];
    }

    private function url(Field $field, string $suffix, ?int $leagueId = null): string
    {
        return '/api/leagues/'.($leagueId ?? $field->league_id).'/fields'.str_replace('{f}', (string) $field->id, $suffix);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_401_without_token(string $method, string $suffix): void
    {
        $this->json($method, $this->url(Field::factory()->create(), $suffix))->assertStatus(401);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_responds_403_for_roles_without_permission(string $method, string $suffix): void
    {
        $url = $this->url(Field::factory()->create(), $suffix);

        foreach ([RoleEnum::REFEREE, RoleEnum::PLAYER] as $role) {
            $this->json($method, $url, [], $this->otherUserHeader($role))->assertStatus(403);
        }
    }

    /**
     * @dataProvider endpoints
     */
    public function test_league_admin_of_another_league_gets_403(string $method, string $suffix): void
    {
        [, $header] = $this->leagueWithAdminHeader();

        $this->json($method, $this->url(Field::factory()->create(), $suffix), [], $header)->assertStatus(403);
    }

    /**
     * @dataProvider endpoints
     */
    public function test_field_of_another_league_responds_404(string $method, string $suffix): void
    {
        if (! str_contains($suffix, '{f}')) {
            $this->markTestSkipped('Sin parámetro de cancha.');
        }

        [$league, $header] = $this->leagueWithAdminHeader();
        $foreign = Field::factory()->create();

        $this->json($method, $this->url($foreign, $suffix, $league->id), ['name' => 'X', 'status' => 'inactive'], $header)
            ->assertStatus(404);
        $this->assertNotSoftDeleted('fields', ['id' => $foreign->id]);
    }

    public function test_superadmin_operates_on_any_league(): void
    {
        [, $token] = $this->actingAsSuperadmin();
        $league = League::factory()->create();
        $header = $this->authHeader($token);

        $id = $this->postJson("/api/leagues/{$league->id}/fields", ['name' => 'Cancha 1'], $header)
            ->assertCreated()->json('id');

        $this->getJson("/api/leagues/{$league->id}/fields/{$id}", $header)->assertOk();
        $this->getJson("/api/leagues/{$league->id}/fields", $header)->assertOk();
    }
}
