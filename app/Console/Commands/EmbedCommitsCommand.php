<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Commit;
use App\Services\OpenAI;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('commits:embed {--all : Embed every commit again, instead of only the ones missing an embedding}')]
#[Description('Embed the body of every commit missing an embedding, so commits can be found by meaning')]
final class EmbedCommitsCommand extends Command
{
    private const int BATCH_SIZE = 100;

    public function handle(OpenAI $openAI): int
    {
        $embedded = 0;

        $this->commits()->chunkById(self::BATCH_SIZE, function (Collection $commits) use ($openAI, &$embedded): void {
            $this->embed($openAI, $commits);

            $embedded += $commits->count();
        });

        $this->components->info("Embedded {$embedded} commits.");

        return self::SUCCESS;
    }

    /**
     * Deleted commits and those without a body, such as a lone photo, are left out, but pending and rejected ones are
     * embedded too: nothing re-embeds a commit once it is approved, and a pending commit may still become searchable.
     *
     * @return Builder<Commit>
     */
    private function commits(): Builder
    {
        return Commit::query()
            ->where('body', '!=', '')
            ->unless($this->option('all'), fn (Builder $query): Builder => $query->whereNull('embedding'));
    }

    /**
     * One request embeds the whole batch, so the API is called once per hundred commits rather than once per commit.
     *
     * @param  Collection<int, Commit>  $commits
     */
    private function embed(OpenAI $openAI, Collection $commits): void
    {
        $embeddings = $openAI->embedMany(array_values($commits->map(fn (Commit $commit): string => $commit->body)->all()));

        $commits->each(function (Commit $commit, int $index) use ($embeddings): void {
            $commit->update(['embedding' => $embeddings[$index]]);
        });
    }
}
