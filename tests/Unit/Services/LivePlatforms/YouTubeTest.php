<?php

declare(strict_types=1);

use App\Services\LivePlatforms\YouTube;

it('accepts links to a single video', function (string $url): void {
    $youTube = resolve(YouTube::class);

    expect($youTube->accepts($url))->toBeTrue()
        ->and($youTube->stream($url, now()->addHour())->toArray())->toMatchArray([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault_live.jpg',
            'platform' => 'youtube',
        ]);
})->with([
    'watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
    'live' => 'https://www.youtube.com/live/dQw4w9WgXcQ?si=share',
    'short link' => 'https://youtu.be/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
]);

it('rejects links that are not a single YouTube video', function (string $url): void {
    expect(resolve(YouTube::class)->accepts($url))->toBeFalse();
})->with([
    'playlist' => 'https://www.youtube.com/playlist?list=PL590L5WQmH8fJ54F369BLDSqIwcs-TCfs',
    'twitch' => 'https://www.twitch.tv/octocat',
    'other site' => 'https://example.com/live',
    'not a link' => 'youtube',
]);

it('accepts links while embeds are turned off', function (): void {
    config(['embeds.enabled' => false]);

    expect(resolve(YouTube::class)->accepts('https://youtu.be/dQw4w9WgXcQ'))->toBeTrue();
});
