<?php

declare(strict_types=1);

use App\Enums\LivePlatform;
use App\Models\Commit;
use App\Models\User;

function liveUser(): User
{
    return User::factory()->live()->create();
}

it('goes live with a YouTube stream for a few hours', function (string $url): void {
    $user = User::factory()->create();

    $this->actingAs($user)->fromRoute('user.show', $user)
        ->put(route('user-live.update'), ['url' => " {$url} ", 'hours' => 3])
        ->assertRedirectToRoute('user.show', $user);

    expect($user->refresh())
        ->live_platform->toBe(LivePlatform::YouTube)
        ->live_url->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($user->live_ends_at?->toIso8601String())->toBe(now()->addHours(3)->toIso8601String());

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('user.live', [
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault_live.jpg',
                'platform' => 'youtube',
                'ends_at' => now()->addHours(3)->toIso8601ZuluString(),
                'embed' => [
                    'provider' => 'YouTube',
                    'kind' => 'video',
                    'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1',
                    'aspect_ratio' => '16 / 9',
                    'height' => null,
                    'poster' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
                ],
            ]));
})->with([
    'live link' => 'https://www.youtube.com/live/dQw4w9WgXcQ?si=share',
    'watch link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
    'short link' => 'https://youtu.be/dQw4w9WgXcQ',
]);

it('goes live with a Twitch stream for a few hours', function (string $url): void {
    $user = User::factory()->create();

    $this->actingAs($user)->fromRoute('user.show', $user)
        ->put(route('user-live.update'), ['url' => " {$url} ", 'hours' => 3])
        ->assertRedirectToRoute('user.show', $user);

    expect($user->refresh())
        ->live_platform->toBe(LivePlatform::Twitch)
        ->live_url->toBe('https://www.twitch.tv/octocat')
        ->and($user->live_ends_at?->toIso8601String())->toBe(now()->addHours(3)->toIso8601String());

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('user.live', [
                'url' => 'https://www.twitch.tv/octocat',
                'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_octocat-640x360.jpg',
                'platform' => 'twitch',
                'ends_at' => now()->addHours(3)->toIso8601ZuluString(),
                'embed' => [
                    'provider' => 'Twitch',
                    'kind' => 'video',
                    'src' => 'https://player.twitch.tv/?channel=octocat&parent='.parse_url(config()->string('app.url'), PHP_URL_HOST).'&autoplay=true',
                    'aspect_ratio' => '16 / 9',
                    'height' => null,
                    'poster' => null,
                ],
            ]));
})->with([
    'channel link' => 'https://www.twitch.tv/octocat',
    'channel without www' => 'https://twitch.tv/octocat',
    'mobile link' => 'https://m.twitch.tv/octocat',
]);

it('only links out to the stream when it may not play inline', function (array $embeds, LivePlatform $platform): void {
    config(['embeds' => [...config()->array('embeds'), ...$embeds]]);
    $user = User::factory()->live($platform)->create();

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('user.live.platform', $platform->value)
            ->where('user.live.url', $platform === LivePlatform::YouTube ? 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' : 'https://www.twitch.tv/octocat')
            ->where('user.live.embed', null));
})->with([
    'embeds off' => [['enabled' => false]],
    'platform not allowed' => [['only' => ['vimeo']]],
])->with([LivePlatform::YouTube, LivePlatform::Twitch]);

it('shows the live marker on their commits in the log', function (): void {
    $user = liveUser();
    Commit::factory()->for($user)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.user.live.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->missing('commits.data.0.user.live_url')
            ->missing('commits.data.0.user.live_ends_at'));
});

it('rejects links that are not a stream', function (array $payload): void {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(route('user-live.update'), [...$payload, 'hours' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect($user->refresh()->live_url)->toBeNull();
})->with([
    'missing' => fn (): array => [],
    'not a link' => fn (): array => ['url' => 'youtube'],
    'not a stream' => fn (): array => ['url' => 'https://example.com/live'],
    'twitch page' => fn (): array => ['url' => 'https://www.twitch.tv/directory'],
    'playlist' => fn (): array => ['url' => 'https://www.youtube.com/playlist?list=PL590L5WQmH8fJ54F369BLDSqIwcs-TCfs'],
    'too long' => fn (): array => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&x='.str_repeat('a', 2048)],
]);

it('takes between one and twelve hours', function (mixed $hours): void {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson(route('user-live.update'), ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'hours' => $hours])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hours');

    expect($user->refresh()->live_url)->toBeNull();
})->with([
    'missing' => null,
    'none' => 0,
    'too many' => User::MAX_LIVE_HOURS + 1,
    'not a number' => 'all day',
]);

it('ends the stream', function (): void {
    $user = liveUser();

    $this->actingAs($user)->fromRoute('user.show', $user)
        ->delete(route('user-live.destroy'))
        ->assertRedirectToRoute('user.show', $user);

    expect($user->refresh())
        ->live_platform->toBeNull()
        ->live_url->toBeNull()
        ->live_ends_at->toBeNull();

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page->where('user.live', null));
});

it('stops showing the stream once its hours are up', function (): void {
    $user = liveUser();

    $this->travel(2)->hours();

    $this->get(route('user.show', $user))
        ->assertInertia(fn ($page) => $page->where('user.live', null));
});

it('requires authentication', function (): void {
    $this->putJson(route('user-live.update'), ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'hours' => 2])->assertUnauthorized();
    $this->deleteJson(route('user-live.destroy'))->assertUnauthorized();
});
