<?php

declare(strict_types=1);

use App\Services\LivePlatforms\Twitch;

it('accepts links to a channel', function (string $url): void {
    $twitch = resolve(Twitch::class);

    expect($twitch->accepts($url))->toBeTrue()
        ->and($twitch->stream($url, now()->addHour())->toArray())->toMatchArray([
            'url' => 'https://www.twitch.tv/theprimeagen',
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_theprimeagen-640x360.jpg',
            'platform' => 'twitch',
        ]);
})->with([
    'channel' => 'https://www.twitch.tv/ThePrimeagen',
    'without www' => 'https://twitch.tv/theprimeagen',
    'mobile' => 'https://m.twitch.tv/theprimeagen',
    'trailing slash' => 'https://www.twitch.tv/ThePrimeagen/',
    'with query parameters' => 'https://www.twitch.tv/theprimeagen?referrer=raid',
]);

it('rejects links that are not a single Twitch channel', function (string $url): void {
    expect(resolve(Twitch::class)->accepts($url))->toBeFalse();
})->with([
    'home' => 'https://www.twitch.tv/',
    'directory' => 'https://www.twitch.tv/directory',
    'settings' => 'https://www.twitch.tv/Settings',
    'videos' => 'https://www.twitch.tv/videos/2245012345',
    'clip' => 'https://www.twitch.tv/theprimeagen/clip/AwkwardHelplessSalamanderSwiftRage-abc',
    'clips host' => 'https://clips.twitch.tv/AwkwardHelplessSalamanderSwiftRage',
    'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'other site' => 'https://example.com/theprimeagen',
    'not a link' => 'twitch',
    'too short' => 'https://www.twitch.tv/ab',
    'too long' => 'https://www.twitch.tv/'.str_repeat('a', 26),
]);

it('accepts links while embeds are turned off', function (): void {
    config(['embeds.enabled' => false]);

    expect(resolve(Twitch::class)->accepts('https://twitch.tv/theprimeagen'))->toBeTrue();
});
