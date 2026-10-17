<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('renders a link preview image of a profile for guests', function (): void {
    Http::fake(['avatars.githubusercontent.com/*' => Http::response(fakeAvatar(), 200, ['Content-Type' => 'image/png'])]);

    $user = User::factory()->create(['avatar_url' => 'https://avatars.githubusercontent.com/u/583231']);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600')
        ->and(array_slice((array) getimagesizefromstring((string) $response->getContent()), 0, 2))->toBe([1200, 630]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://avatars.githubusercontent.com/u/583231');
});

it('falls back to an initial when the avatar cannot be downloaded', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    $user = User::factory()->create();

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect(array_slice((array) getimagesizefromstring((string) $response->getContent()), 0, 2))->toBe([1200, 630]);
});

it('falls back to an initial when the avatar is not an image', function (): void {
    Http::fake(['*' => Http::response('<html>not an image</html>')]);

    $response = $this->get(route('user-open-graph-image.show', User::factory()->create()));

    $response->assertOk();

    expect(array_slice((array) getimagesizefromstring((string) $response->getContent()), 0, 2))->toBe([1200, 630]);
});

it('falls back to an initial when the avatar host cannot be reached', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    $this->get(route('user-open-graph-image.show', User::factory()->create()))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('renders a profile without an avatar or bio without requesting anything', function (): void {
    $user = User::factory()->withoutProfile()->create();

    $this->get(route('user-open-graph-image.show', $user))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('reuses the rendered image until the profile changes', function (): void {
    Http::fake(['*' => Http::response(fakeAvatar())]);

    $user = User::factory()->create();

    $this->get(route('user-open-graph-image.show', $user))->assertOk();
    $this->get(route('user-open-graph-image.show', $user))->assertOk();

    Http::assertSentCount(1);

    $this->travel(1)->minute();
    $user->update(['bio' => 'Updated on GitHub.']);

    $this->get(route('user-open-graph-image.show', $user))->assertOk();

    Http::assertSentCount(2);
});

it('returns not found for unknown usernames', function (): void {
    $this->get('/@missing/og.png')->assertNotFound();
});

it('loads local asset avatars directly from disk without making HTTP requests', function (): void {
    $user = User::factory()->create(['avatar_url' => asset('images/tom.webp')]);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('blocks non-https avatar URLs and falls back to an initial without requesting anything', function (): void {
    $user = User::factory()->create(['avatar_url' => 'http://169.254.169.254/latest/meta-data']);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('blocks private IP avatar URLs without making HTTP requests', function (): void {
    $user = User::factory()->create(['avatar_url' => 'https://127.0.0.1/avatar.png']);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('does not follow redirects when downloading remote avatars', function (): void {
    Http::fake([
        'https://example.com/avatar.png' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    $user = User::factory()->create(['avatar_url' => 'https://example.com/avatar.png']);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254'));
});

it('loads local asset avatars with query strings without making HTTP requests', function (): void {
    $user = User::factory()->create(['avatar_url' => asset('images/tom.webp?v=4')]);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('falls back to an initial when a local asset does not exist', function (): void {
    $user = User::factory()->create(['avatar_url' => asset('images/missing-avatar.png')]);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('falls back to an initial when a local asset is not an image', function (): void {
    $user = User::factory()->create(['avatar_url' => asset('robots.txt')]);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});

it('blocks IPv6 loopback avatar URLs without making HTTP requests', function (): void {
    $user = User::factory()->create(['avatar_url' => 'https://[::1]/avatar.png']);

    $response = $this->get(route('user-open-graph-image.show', $user));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    Http::assertNothingSent();
});
