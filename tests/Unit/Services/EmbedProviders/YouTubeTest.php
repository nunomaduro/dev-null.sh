<?php

declare(strict_types=1);

use App\Services\EmbedProviders\YouTube;
use Uri\WhatWg\Url;

it('embeds videos', function (string $url, string $src, string $aspectRatio): void {
    $embed = resolve(YouTube::class)->embed(Url::parse($url));

    expect($embed?->provider)->toBe('YouTube')
        ->and($embed?->src)->toBe($src)
        ->and($embed?->aspectRatio)->toBe($aspectRatio);
})->with([
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', '16 / 9'],
    'watch without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ&feature=share', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', '16 / 9'],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', '16 / 9'],
    'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', '16 / 9'],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', '16 / 9'],
    'short link with seconds' => ['https://youtu.be/dQw4w9WgXcQ?t=43', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&start=43', '16 / 9'],
    'watch with a timestamp' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1h2m3s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&start=3723', '16 / 9'],
    'embed with a start' => ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=90', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&start=90', '16 / 9'],
    'live' => ['https://www.youtube.com/live/nfmVdXZfzUE', 'https://www.youtube-nocookie.com/embed/nfmVdXZfzUE?autoplay=1', '16 / 9'],
    'shorts' => ['https://www.youtube.com/shorts/aqz-KE-bpKQ/', 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1', '9 / 16'],
    'playlist' => ['https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', 'https://www.youtube-nocookie.com/embed/videoseries?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG&autoplay=1', '16 / 9'],
]);

it('shows the video thumbnail as the poster', function (string $url, ?string $poster): void {
    expect(resolve(YouTube::class)->embed(Url::parse($url))?->poster)->toBe($poster);
})->with([
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=43', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
    'shorts' => ['https://www.youtube.com/shorts/aqz-KE-bpKQ', 'https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg'],
    'playlist, which has no one video' => ['https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', null],
]);

it('ignores a timestamp it can not read', function (): void {
    expect(resolve(YouTube::class)->embed(Url::parse('https://youtu.be/dQw4w9WgXcQ?t=soon'))?->src)
        ->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1');
});

it('embeds from youtube.com when privacy enhanced mode is off', function (): void {
    config(['embeds.youtube.privacy_enhanced' => false]);

    expect(resolve(YouTube::class)->embed(Url::parse('https://youtu.be/dQw4w9WgXcQ'))?->src)
        ->toBe('https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1');
});

it('does not embed other pages', function (string $url): void {
    expect(resolve(YouTube::class)->embed(Url::parse($url)))->toBeNull();
})->with([
    'home' => 'https://www.youtube.com/',
    'channel' => 'https://www.youtube.com/@nunomaduro',
    'watch without a video' => 'https://www.youtube.com/watch',
    'malformed video id' => 'https://www.youtube.com/watch?v=short',
    'video id as an array' => 'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
    'malformed playlist' => 'https://www.youtube.com/playlist?list=%3Cscript%3E',
    'short link to nothing' => 'https://youtu.be/',
    'other host' => 'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
]);
