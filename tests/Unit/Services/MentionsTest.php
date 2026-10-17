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
    'no at symbol' => ['plain text without any mention'],
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
