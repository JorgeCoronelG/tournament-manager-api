<?php

namespace Tests\Feature\Teams;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLogoTest extends TestCase
{
    public function test_serves_logo_with_cors_and_cache_headers(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:4200']]);
        Storage::fake('public');
        Storage::disk('public')->put('teams/logos/a.webp', 'img');

        $this->get('/media/teams/logos/a.webp', ['Origin' => 'http://localhost:4200'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Vary', 'Origin')
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_rejects_other_paths_traversal_and_missing_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('other/secret.txt', 'x');

        $this->get('/media/other/secret.txt')->assertNotFound();
        $this->get('/media/teams/logos/../../other/secret.txt')->assertNotFound();
        $this->get('/media/teams/logos/missing.webp')->assertNotFound();
    }
}
