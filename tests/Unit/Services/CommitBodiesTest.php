<?php

declare(strict_types=1);

use App\Services\CommitBodies;

it('collapses runs of blank lines in the prose into one', function (string $body, string $normalized): void {
    expect(resolve(CommitBodies::class)->normalize($body))->toBe($normalized);
})->with([
    'one blank line' => ["a\n\nb", "a\n\nb"],
    'two blank lines' => ["a\n\n\nb", "a\n\nb"],
    'many blank lines' => ["a\n\n\n\n\n\nb", "a\n\nb"],
    'blank lines with spaces and tabs' => ["a \n\t\n  \n\nb", "a\n\nb"],
    'windows line breaks' => ["a\r\nb\r\n\r\n\r\n\r\nc", "a\nb\n\nc"],
    'standalone carriage returns' => ["a\rb\r\r\rc", "a\nb\n\nc"],
    'mixed line endings' => ["a\r\nb\rc\n\n\rd", "a\nb\nc\n\nd"],
    'blank lines in a code block' => ["a\n```py\ndef a():\n    pass\n\n\ndef b():\n    pass\n```\nb", "a\n```py\ndef a():\n    pass\n\n\ndef b():\n    pass\n```\nb"],
    'blank lines around a code block' => ["a\n\n\n\n```\ncode\n```\n\n\n\nb", "a\n\n```\ncode\n```\n\nb"],
]);

it('has no excerpt for a body short enough to show whole', function (string $body): void {
    expect(resolve(CommitBodies::class)->excerpt($body))->toBeNull();
})->with([
    'short' => 'It was DNS.',
    '280 characters' => str_repeat('a', 280),
    '280 multibyte characters' => str_repeat('🚀', 280),
    '8 lines' => implode("\n", range(1, 8)),
]);

it('cuts a long body at the last whitespace that fits', function (string $body, string $excerpt): void {
    expect(resolve(CommitBodies::class)->excerpt($body))->toBe($excerpt);
})->with([
    'in the middle of a word' => [str_repeat('word ', 56).'overflowing words', mb_rtrim(str_repeat('word ', 56)).'…'],
    'right before a whitespace' => [str_repeat('a', 280).' more', str_repeat('a', 280).'…'],
    'after the 8th line' => [implode("\n", range(1, 12)), implode("\n", range(1, 8)).'…'],
    'multibyte characters' => [str_repeat('é ', 141), mb_rtrim(str_repeat('é ', 140)).'…'],
]);

it('cuts a word too long to fit only when nothing comes before it', function (string $body, string $excerpt): void {
    expect(resolve(CommitBodies::class)->excerpt($body))->toBe($excerpt);
})->with([
    'first word' => ['https://example.com/'.str_repeat('a', 300), 'https://example.com/'.str_repeat('a', 260).'…'],
    'after code' => ['`make`'.str_repeat('a', 300), '`make`…'],
]);

it('ends the excerpt before code that does not fit', function (string $body, string $excerpt): void {
    expect(resolve(CommitBodies::class)->excerpt($body))->toBe($excerpt);
})->with([
    'code block' => ["Look at this:\n```php\n".str_repeat("echo 1;\n", 40).'```', 'Look at this:…'],
    'code block past the 8th line' => ["One\nTwo\n```\n".implode("\n", range(1, 10))."\n```", "One\nTwo…"],
    'inline code' => [str_repeat('a', 270).' `composer install`', str_repeat('a', 270).'…'],
]);

it('keeps the first lines of a code block that the body starts with', function (string $body, string $excerpt): void {
    expect(resolve(CommitBodies::class)->excerpt($body))->toBe($excerpt);
})->with([
    'many short lines' => ["```php\n".implode("\n", array_map(fn (int $line): string => "echo {$line};", range(1, 12)))."\n```\nNeat.", "```php\n".implode("\n", array_map(fn (int $line): string => "echo {$line};", range(1, 6)))."\n```\n…"],
    'long lines' => ["```\n".str_repeat('a', 200)."\n".str_repeat('b', 200)."\n```", "```\n".str_repeat('a', 200)."\n```\n…"],
    'one line too long' => ["```\n".str_repeat('a', 400)."\n```", "```\n".str_repeat('a', 272)."\n```\n…"],
    'longer fence' => ["````\n".str_repeat("line\n", 20).'````', "````\n".mb_rtrim(str_repeat("line\n", 6))."\n````\n…"],
    'windows line breaks' => ["```\r\n".str_repeat("line\r\n", 60).'```', "```\n".mb_rtrim(str_repeat("line\n", 6))."\n```\n…"],
    'whole block before a word too long to fit' => ["```\nshort\n```\nhttps://example.com/".str_repeat('a', 300), "```\nshort\n```\n…"],
    'after blank prose' => ["  \n```\n".str_repeat("line\n", 20).'```', "  \n```\n".mb_rtrim(str_repeat("line\n", 5))."\n```\n…"],
]);

it('cuts inline code short when the body starts with it', function (): void {
    expect(resolve(CommitBodies::class)->excerpt('`'.str_repeat('a', 300).'` done'))->toBe('`'.str_repeat('a', 278).'`…');
});
