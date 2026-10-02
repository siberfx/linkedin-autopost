<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Siberfx\LinkedInAutopost\Contracts\TokenStore;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Tests\Fixtures\Post;
use Siberfx\LinkedInAutopost\Tests\Fixtures\PostStatus;
use Siberfx\LinkedInAutopost\Tests\HeadlessTestCase;

uses(HeadlessTestCase::class);

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', 'urn:li:person:abc'));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('registers no views, Blade or Livewire components', function () {
    expect(View::getFinder()->getHints())->not->toHaveKey('linkedin-autopost')
        ->and(app('blade.compiler')->getClassComponentNamespaces())->not->toHaveKey('linkedin-autopost');

    if (class_exists(Livewire\Livewire::class)) {
        expect(fn () => app('livewire.factory')->resolveComponentClass('linkedin-autopost.connection'))->toThrow(Exception::class);
    }
});

it('keeps the routes, the facade and the commands', function () {
    expect(app('router')->has('linkedin-autopost.redirect'))->toBeTrue()
        ->and(app('router')->has('linkedin-autopost.share'))->toBeTrue();

    $this->artisan('linkedin:status')->assertSuccessful();
});

it('answers a form share with JSON instead of a redirect', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:4'])]);
    $url = URL::signedRoute('linkedin-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertCreated()
        ->assertJsonPath('message', 'Shared on LinkedIn.')
        ->assertJsonPath('data.post_urn', 'urn:li:share:4');
});

it('answers a form disconnect with JSON', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/linkedin/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('installs without asking for a theme', function () {
    $envDir = sys_get_temp_dir().'/linkedin-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('linkedin:install')
            ->expectsOutputToContain('LINKEDIN_UI=false')
            ->expectsOutputToContain("route('linkedin-autopost.redirect')")
            ->doesntExpectOutputToContain('Tailwind')
            ->assertSuccessful();

        expect(File::get($envDir.'/.env'))->toBe("LINKEDIN_UI=false\n");
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('linkedin-autopost.php'));
        foreach (File::glob(database_path('migrations/*linkedin_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
