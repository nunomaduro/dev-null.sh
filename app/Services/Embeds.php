<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\EmbedProvider;
use App\Dto\Embed;
use Generator;
use Illuminate\Contracts\Container\Container;
use Uri\WhatWg\Url;

final readonly class Embeds
{
    public function __construct(private Container $container)
    {
        //
    }

    public function for(string $url): ?Embed
    {
        $parsed = Url::parse($url);

        if (! $parsed instanceof Url || ! in_array($parsed->getScheme(), ['http', 'https'], true) || ! config()->boolean('embeds.enabled')) {
            return null;
        }

        foreach ($this->providers() as $provider) {
            $embed = $provider->embed($parsed);

            if ($embed instanceof Embed) {
                return $embed;
            }
        }

        return null;
    }

    /**
     * Made one at a time, so a link the first providers play does not build the rest. Live streams and link cards ask
     * for an embed for every one they show.
     *
     * @return Generator<int, EmbedProvider>
     */
    private function providers(): Generator
    {
        $only = config()->array('embeds.only');

        foreach (config()->array('embeds.providers') as $key => $class) {
            $provider = is_string($class) && ($only === [] || in_array($key, $only, true)) ? $this->container->make($class) : null;

            if ($provider instanceof EmbedProvider) {
                yield $provider;
            }
        }
    }
}
