<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\UpdateKnowledgeBaseSections;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('knowledge-base:embed {source=KNOWLEDGE_BASE.md : Repository-relative Markdown file to embed} {--expected-hash= : Only import if the file matches this SHA-256 hash}')]
#[Description('Sync knowledge base sections, embedding only new or changed content')]
final class EmbedKnowledgeBaseCommand extends Command
{
    public function handle(UpdateKnowledgeBaseSections $action): int
    {
        $source = $this->argument('source');
        assert(is_string($source));

        $markdown = File::get(base_path($source));
        $expectedHash = $this->option('expected-hash');

        if (is_string($expectedHash) && ! hash_equals($expectedHash, hash('sha256', $markdown))) {
            $this->components->error('Knowledge base file does not match the expected release. No embeddings were changed.');

            return self::FAILURE;
        }

        $embedded = $action->handle($source, $markdown);

        $this->components->info("Embedded {$embedded} knowledge base sections from {$source}.");

        return self::SUCCESS;
    }
}
