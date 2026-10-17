<?php

declare(strict_types=1);

use App\Services\MarkdownSections;

it('splits at headings while retaining ancestry, preambles and repeated headings', function (): void {
    $markdown = <<<'MD'
        Before the title.

        # Knowledge base
        Introduction.

        ## Lore
        ### Tom
        A fictional profile.

        ##### Details
        DeepSeek is planned.

        ### Pastéis de nata
        Portuguese pastries.

        ## Stack
        Laravel and PostgreSQL.

        ## Stack
        A separate section with the same name.
        MD;

    expect(new MarkdownSections()->split($markdown))->toBe([
        ['headings' => [], 'content' => 'Before the title.'],
        ['headings' => ['Knowledge base'], 'content' => 'Introduction.'],
        ['headings' => ['Knowledge base', 'Lore', 'Tom'], 'content' => 'A fictional profile.'],
        ['headings' => ['Knowledge base', 'Lore', 'Tom', 'Details'], 'content' => 'DeepSeek is planned.'],
        ['headings' => ['Knowledge base', 'Lore', 'Pastéis de nata'], 'content' => 'Portuguese pastries.'],
        ['headings' => ['Knowledge base', 'Stack'], 'content' => 'Laravel and PostgreSQL.'],
        ['headings' => ['Knowledge base', 'Stack'], 'content' => 'A separate section with the same name.'],
    ]);
});

it('recognizes setext and formatted headings without splitting code or quoted headings', function (): void {
    $markdown = <<<'MD'
        Knowledge base
        ==============

        ## **Tom** and `DeepSeek` ##
        ```markdown
        # Not a section
        ```

        ~~~markdown
        ## Not a section either
        ~~~

        > # A quoted heading

            # An indented code sample

        Pastéis de nata
        --------------
        A tribute to Fernando.
        MD;

    expect(new MarkdownSections()->split($markdown))->toBe([
        [
            'headings' => ['Knowledge base', 'Tom and DeepSeek'],
            'content' => "```markdown\n# Not a section\n```\n\n~~~markdown\n## Not a section either\n~~~\n\n> # A quoted heading\n\n    # An indented code sample",
        ],
        ['headings' => ['Knowledge base', 'Pastéis de nata'], 'content' => 'A tribute to Fernando.'],
    ]);
});

it('handles documents without section bodies and normalizes line endings', function (string $markdown, array $expected): void {
    expect(new MarkdownSections()->split($markdown))->toBe($expected);
})->with([
    'empty' => ['', []],
    'headings only' => ["# Knowledge base\n\n## Lore\n", []],
    'no heading' => ['Plain text.', [['headings' => [], 'content' => 'Plain text.']]],
    'leading indented code' => ["# Example\n\n    # Not a heading\n", [['headings' => ['Example'], 'content' => '    # Not a heading']]],
    'windows line endings' => ["# Lore\r\nTom.\r\n\r\n## Pastries\r\nPastéis.", [
        ['headings' => ['Lore'], 'content' => 'Tom.'],
        ['headings' => ['Lore', 'Pastries'], 'content' => 'Pastéis.'],
    ]],
    'carriage returns' => ["# Lore\rTom.", [['headings' => ['Lore'], 'content' => 'Tom.']]],
]);
