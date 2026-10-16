<?php

declare(strict_types=1);

use App\Contracts\Services\EmbedProvider;
use App\Dto\Embed;
use App\Enums\EmbedKind;
use App\Services\EmbedProviders\YouTube;
use App\Services\Embeds;
use Dotenv\Repository\RepositoryBuilder;
use Uri\WhatWg\Url;

it('embeds links to a provider', function (): void {
    expect(resolve(Embeds::class)->for('https://www.youtube.com/watch?v=dQw4w9WgXcQ')?->toArray())->toBe([
        'provider' => 'YouTube',
        'kind' => 'video',
        'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1',
        'aspect_ratio' => '16 / 9',
        'height' => null,
    ]);
});

it('does not embed other links', function (string $url): void {
    expect(resolve(Embeds::class)->for($url))->toBeNull();
})->with([
    'another site' => 'https://example.com/watch?v=dQw4w9WgXcQ',
    'unsupported scheme' => 'ftp://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'not a url' => 'not a url',
]);

it('embeds nothing when embeds are disabled', function (): void {
    config(['embeds.enabled' => false]);

    expect(resolve(Embeds::class)->for('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeNull();
});

it('only embeds the providers it is limited to', function (): void {
    config(['embeds.only' => ['spotify']]);

    expect(resolve(Embeds::class)->for('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeNull()
        ->and(resolve(Embeds::class)->for('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT')?->provider)->toBe('Spotify');
});

it('asks the providers in order', function (): void {
    $provider = new class implements EmbedProvider
    {
        public function embed(Url $url): Embed
        {
            return Embed::fixed('Custom', EmbedKind::Code, 'https://embed.example.com/'.mb_ltrim($url->getPath(), '/'), 300);
        }
    };

    app()->instance($provider::class, $provider);
    config(['embeds.providers' => ['custom' => $provider::class, 'youtube' => YouTube::class, 'broken' => 42]]);

    expect(resolve(Embeds::class)->for('https://www.youtube.com/watch?v=dQw4w9WgXcQ')?->toArray())->toBe([
        'provider' => 'Custom',
        'kind' => 'code',
        'src' => 'https://embed.example.com/watch',
        'aspect_ratio' => null,
        'height' => 300,
    ]);
});

it('reads the provider list from the environment', function (): void {
    // Parallel workers inherit EMBEDS_PROVIDERS from .env, and Laravel's repository never overwrites a variable that is already set.
    $repository = RepositoryBuilder::createWithDefaultAdapters()->make();
    $original = $repository->get('EMBEDS_PROVIDERS');

    try {
        $repository->set('EMBEDS_PROVIDERS', ' youtube, ,spotify ');
        $config = require config_path('embeds.php');
    } finally {
        $original === null ? $repository->clear('EMBEDS_PROVIDERS') : $repository->set('EMBEDS_PROVIDERS', $original);
    }

    expect($config['only'])->toBe(['youtube', 'spotify']);
});
