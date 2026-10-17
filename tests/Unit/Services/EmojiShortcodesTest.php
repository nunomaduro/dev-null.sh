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

it('leaves a shortcode glued to the word before it untouched', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace('status:ok and ratio 1:100 stay put, but (:fire) and a :fire do not'))
        ->toBe('status:ok and ratio 1:100 stay put, but (🔥) and a 🔥 do not');
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

it('leaves shortcodes in code untouched', function (): void {
    $emoji = resolve(EmojiShortcodes::class);

    expect($emoji->replace("ship :fire `a ? b :like` and\n```php\nx :heart\n```"))
        ->toBe("ship 🔥 `a ? b :like` and\n```php\nx :heart\n```");
});
