<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;

final readonly class MarkdownSections
{
    /**
     * @return list<array{headings: list<string>, content: string}>
     */
    public function split(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());

        $document = new MarkdownParser($environment)->parse($markdown);
        $sections = [];
        $headings = [];
        $start = 0;

        foreach ($document->children() as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $headingStart = $node->getStartLine();
            $headingEnd = $node->getEndLine();
            assert(is_int($headingStart) && is_int($headingEnd));

            $this->append($sections, $headings, implode("\n", array_slice($lines, $start, $headingStart - 1 - $start)));

            foreach (array_keys($headings) as $level) {
                if ($level >= $node->getLevel()) {
                    unset($headings[$level]);
                }
            }

            $headings[$node->getLevel()] = StringContainerHelper::getChildText($node);
            $start = $headingEnd;
        }

        $this->append($sections, $headings, implode("\n", array_slice($lines, $start)));

        return $sections;
    }

    /**
     * @param  list<array{headings: list<string>, content: string}>  $sections
     * @param  array<int, string>  $headings
     */
    private function append(array &$sections, array $headings, string $body): void
    {
        if (mb_trim($body) !== '') {
            $sections[] = ['headings' => array_values($headings), 'content' => mb_trim($body, "\n")];
        }
    }
}
