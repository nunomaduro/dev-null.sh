<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Deterministic Signal Weights
    |--------------------------------------------------------------------------
    |
    | Each matched signal adds its weight to the moderation score. The final
    | score is capped so a single commit cannot dominate the queue forever.
    |
    */

    'weights' => [
        'duplicate' => 50,
        'author_flood' => 40,
        'burst' => 25,
        'has_url' => 10,
        'invite_like' => 30,
    ],

    'score_cap' => 100,

    'author_flood_threshold' => (int) env('MODERATION_AUTHOR_FLOOD_THRESHOLD', 3),

    'burst' => [
        'count' => (int) env('MODERATION_BURST_COUNT', 5),
        'minutes' => (int) env('MODERATION_BURST_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Moderation (optional)
    |--------------------------------------------------------------------------
    |
    | AI scoring is off by default. Enable only when you have budget and a
    | provider bound. The app boots and moderates without any AI credentials.
    |
    */

    'ai' => [
        'enabled' => (bool) env('MODERATION_AI_ENABLED', false),
        'provider' => env('MODERATION_AI_PROVIDER'),
        'api_key' => env('MODERATION_AI_API_KEY'),
    ],

];
