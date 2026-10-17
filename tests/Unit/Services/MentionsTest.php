<?php

declare(strict_types=1);

use App\Services\Mentions;

it('finds mentions, lowercased and once each, in order', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->usernames('@NunoMaduro and @taylorotwell, thanks! cc @nunomaduro'))
        ->toBe(['nunomaduro', 'taylorotwell']);
});

it('finds mentions next to punctuation', function (string $text, array $usernames): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->usernames($text))->toBe($usernames);
})->with([
    'sentence end' => ['Thanks @nuno.', ['nuno']],
    'question' => ['Right, @nuno?', ['nuno']],
    'parentheses' => ['(with @nuno)', ['nuno']],
    'line start' => ["Shipped\n@nuno", ['nuno']],
    'hyphenated' => ['Hi @jess-archer', ['jess-archer']],
    'colon' => ['@nuno: look', ['nuno']],
]);

it('ignores what only looks like a mention', function (string $text): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->usernames($text))->toBe([]);
})->with([
    'email' => ['Mail me at nuno@laravel.com'],
    'scoped package' => ['npm i @inertiajs/react'],
    'domain' => ['Try @vue.js'],
    'path' => ['See medium.com/@nuno'],
    'lone at' => ['Meet @ 5pm'],
    'leading hyphen' => ['@-nuno'],
    'trailing hyphen' => ['@nuno-'],
    'double at' => ['@@nuno'],
    'too long' => ['@'.str_repeat('a', 40)],
]);

it('allows usernames of up to 39 characters', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->usernames('@'.str_repeat('a', 39)))->toBe([str_repeat('a', 39)]);
});

it('skips mentions in code', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->usernames("thanks @taylorotwell for `@Override`\n```java\n@Deprecated\nvoid run() {}\n```"))
        ->toBe(['taylorotwell']);
});

it('finds where every mention is, keeping its case', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->positions('@NunoMaduro and @taylorotwell, thanks! cc @nunomaduro'))->toBe([
        ['username' => 'NunoMaduro', 'offset' => 0, 'length' => 11],
        ['username' => 'taylorotwell', 'offset' => 16, 'length' => 13],
        ['username' => 'nunomaduro', 'offset' => 42, 'length' => 11],
    ]);
});

it('counts offsets in characters, not bytes', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->positions('Café 🎉 with @nuno.'))->toBe([
        ['username' => 'nuno', 'offset' => 12, 'length' => 5],
    ]);
});

it('finds no positions for what only looks like a mention', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->positions('Mail nuno@laravel.com, npm i @inertiajs/react, try @vue.js'))->toBe([]);
});

it('skips mentions in inline code and code blocks when finding where they are', function (): void {
    $mentions = resolve(Mentions::class);

    expect($mentions->positions("thanks @taylorotwell for `@Override`, @nuno\n```java\n@Deprecated\nvoid run() {}\n```\ncc @jess-archer"))->toBe([
        ['username' => 'taylorotwell', 'offset' => 7, 'length' => 13],
        ['username' => 'nuno', 'offset' => 38, 'length' => 5],
        ['username' => 'jess-archer', 'offset' => 85, 'length' => 12],
    ]);
});
