<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\Moderation\AiModerationProvider;
use App\Models\Commit;
use App\Moderation\DeterministicModerationSignals;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class UpdateCommitModeration
{
    public function __construct(private DeterministicModerationSignals $deterministic)
    {
        //
    }

    public function handle(Commit $commit): void
    {
        $bodyHash = $this->deterministic->bodyHash($commit->body);

        $commit->forceFill(['body_hash' => $bodyHash])->saveQuietly();

        $signals = $this->deterministic->handle($commit->refresh());
        $signals = [...$signals, ...$this->aiSignals($commit)];

        $score = array_sum(array_column($signals, 'weight'));
        $cap = config('moderation.score_cap');
        $cap = is_numeric($cap) ? (int) $cap : 100;

        $commit->forceFill([
            'moderation_score' => min($cap, $score),
            'moderation_signals' => $signals === [] ? null : $signals,
        ])->saveQuietly();
    }

    /**
     * @return list<array{code: string, weight: int}>
     */
    private function aiSignals(Commit $commit): array
    {
        if (! config('moderation.ai.enabled')) {
            return [];
        }

        if (! app()->bound(AiModerationProvider::class)) {
            Log::warning('Moderation AI is enabled but no provider is bound.', [
                'commit_id' => $commit->id,
            ]);

            return [];
        }

        try {
            return resolve(AiModerationProvider::class)->signals($commit);
        } catch (Throwable $throwable) {
            Log::warning('Moderation AI scoring failed; keeping deterministic score.', [
                'commit_id' => $commit->id,
                'exception' => $throwable->getMessage(),
            ]);

            return [];
        }
    }
}
