<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Agents\TopicLabelGenerator;
use App\Ai\Agents\TopicMatcher;
use App\Models\Commit;
use App\Models\Topic;
use App\Queries\CommitBuilder;
use App\Services\CommitTopicClassifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCommitTopics
{
    public function __construct(private CommitTopicClassifier $classifier, private TopicLabelGenerator $generator, private TopicMatcher $matcher)
    {
        //
    }

    /**
     * @param  array<string, string>  $bodies  Commit bodies keyed by commit ID.
     */
    public function handle(array $bodies): void
    {
        Cache::lock('commit-topic-assignment', 960)->block(0, function () use ($bodies): void {
            $commits = Commit::query()->approved()->whereKey(array_keys($bodies))
                ->whereNull('topic_decision')->orderBy('id')->get(['id']);

            if ($commits->isEmpty()) {
                return;
            }

            $batch = $commits->mapWithKeys(fn (Commit $commit): array => [$commit->id => $bodies[$commit->id]]);
            $decisions = $this->classifier->classify($batch->all());
            $interesting = $batch->filter(fn (string $body, string $id): bool => $decisions[$id]);
            $ids = $interesting->keys()->all();
            $topics = Topic::query()->where('last_applied_at', '>=', now()->subMonthNoOverflow())
                ->whereHas('commits', fn (CommitBuilder $query): CommitBuilder => $query->approved())
                ->orderBy('id')->get();
            $texts = $interesting->values()->all();
            $values = $topics->map(fn (Topic $topic): string => $topic->value)->all();
            $matches = $this->matcher->match(
                collect($texts)->map(fn (string $body): array => ['body' => $body])->all(),
                collect($values)->map(fn (string $value): array => ['label' => $value])->all(),
            );
            $unmatched = collect($texts)->filter(fn (string $body, int $index): bool => $matches[$index] === []);
            $generated = $this->generator->generateBatch($unmatched->all());
            $labels = collect($generated)->flatMap(fn (array $values, int $index): array => collect($values)
                ->map(fn (string $value): array => ['label' => $value, 'body' => $texts[$index], 'commit' => $index])->all())->values()->all();
            $duplicates = $batch->count() > 1 ? $this->matcher->match(
                collect($labels)->map(fn (array $label): array => ['label' => $label['label'], 'body' => $label['body']])->all(),
                collect($labels)->map(fn (array $label): array => ['label' => $label['label'], 'body' => $label['body']])->all(),
                deduplicate: true,
            ) : [];
            $canonical = [];
            $assignments = collect($matches)->map(fn (array $indices): array => collect($indices)
                ->map(fn (int $index): string => $values[$index])->all())->all();

            foreach ($labels as $index => $label) {
                $first = collect($duplicates[$index] ?? [])->first(fn (int $candidate): bool => isset($canonical[$candidate]));
                $canonical[$first ?? $index] = $labels[$first ?? $index]['label'];
                $assignments[$label['commit']][] = $canonical[$first ?? $index];
            }

            $assignments = collect($assignments)->mapWithKeys(fn (array $values, int $index): array => [$ids[$index] => $values])->all();

            DB::transaction(function () use ($batch, $decisions, $assignments): void {
                $locked = Commit::query()->approved()->whereKey($batch->keys()->all())->whereNull('topic_decision')
                    ->orderBy('id')->lockForUpdate()->get(['id'])->keyBy('id');
                Topic::query()->whereIn(DB::raw('LOWER(value)'), collect($assignments)->flatten()
                    ->map(fn (string $value): string => mb_strtolower($value))->unique()->all())
                    ->orderBy('id')->lockForUpdate()->get(['id']);

                foreach ($batch as $id => $body) {
                    $commit = $locked->get($id);

                    if (! $commit instanceof Commit) {
                        continue;
                    }

                    foreach (array_unique($assignments[$id] ?? []) as $value) {
                        $topic = Topic::query()->whereRaw('LOWER(value) = ?', [mb_strtolower($value)])->lockForUpdate()->first();

                        if ($topic instanceof Topic && $topic->last_applied_at->lt(now()->subMonthNoOverflow())) {
                            $topic->delete();
                            $topic = null;
                        }

                        $topic ??= Topic::query()->create(['value' => $value, 'last_applied_at' => now()]);
                        $commit->topics()->syncWithoutDetaching([$topic->id]);
                        $topic->update(['last_applied_at' => now()]);
                    }

                    $commit->update(['topic_decision' => $decisions[$id]]);
                }
            });
        });
    }
}
