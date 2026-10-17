<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\KnowledgeBaseSection;
use App\Services\MarkdownSections;
use App\Services\OpenAI;
use Illuminate\Support\Facades\DB;

final readonly class UpdateKnowledgeBaseSections
{
    public function __construct(private MarkdownSections $markdownSections, private OpenAI $openAI)
    {
        //
    }

    public function handle(string $source, string $markdown): int
    {
        $sections = $this->markdownSections->split($markdown);
        $existing = KnowledgeBaseSection::query()->where('source', $source)->get();
        $changed = [];
        $embeddings = [];

        foreach ($sections as $position => $section) {
            $match = $existing->first(fn (KnowledgeBaseSection $stored): bool => $stored->headings === $section['headings']
                && $stored->content === $section['content']);

            if ($match !== null) {
                $embeddings[$position] = $match->embedding;
            } else {
                $changed[$position] = $section;
            }
        }

        foreach (array_chunk($changed, 100, preserve_keys: true) as $batch) {
            $vectors = $this->openAI->embedMany(array_map(
                fn (array $section): string => mb_trim(implode(' > ', $section['headings'])."\n\n".$section['content']),
                array_values($batch),
            ));

            foreach (array_keys($batch) as $index => $position) {
                $embeddings[$position] = $vectors[$index];
            }
        }

        DB::transaction(function () use ($source, $sections, $embeddings): void {
            foreach ($sections as $position => $section) {
                KnowledgeBaseSection::query()->updateOrCreate([
                    'source' => $source,
                    'position' => $position,
                ], [
                    ...$section,
                    'embedding' => $embeddings[$position],
                ]);
            }

            KnowledgeBaseSection::query()->where('source', $source)
                ->whereNotIn('position', array_keys($sections))
                ->delete();
        });

        return count($changed);
    }
}
