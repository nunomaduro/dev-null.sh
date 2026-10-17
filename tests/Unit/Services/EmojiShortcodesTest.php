<?php

declare(strict_types=1);

use App\Services\EmojiShortcodes;

it('replaces every shortcode with its emoji', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('shipped it :heart :like :dislike :laugh'))
        ->toBe('shipped it ❤️ 👍 👎 😂');
});

it('leaves a shortcode inside a longer word untouched', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace(':hearted and :likewise stay put'))
        ->toBe(':hearted and :likewise stay put');
});

it('replaces shortcodes next to punctuation and the end of the text', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('one: :heart, two: :like!'))
        ->toBe('one: ❤️, two: 👍!');
});

it('replaces shortcodes with an optional closing colon', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('shipped it :heart: :like: :rocket:!'))
        ->toBe('shipped it ❤️ 👍 🚀!');
});

it('replaces adjacent shortcodes', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace(':heart::like:'))
        ->toBe('❤️👍');
});

it('does not modify text without shortcodes', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('plain text with a url https://example.com'))
        ->toBe('plain text with a url https://example.com');
});

it('returns immediately when text has no colons', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('plain text without any colons'))
        ->toBe('plain text without any colons');
});

it('leaves shortcodes in code untouched', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace("ship :fire `a ? b :like` and\n```php\nx :heart\n```"))
        ->toBe("ship 🔥 `a ? b :like` and\n```php\nx :heart\n```");
});
