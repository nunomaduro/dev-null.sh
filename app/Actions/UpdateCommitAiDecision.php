<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Services\OpenAI;
use Illuminate\Support\Facades\Storage;

final readonly class UpdateCommitAiDecision
{
    /**
     * A category scoring this much leaves no doubt, so the commit is rejected without waiting for a moderator.
     */
    private const float AUTO_REJECT_THRESHOLD = 0.95;

    public function __construct(
        private OpenAI $openAI,
        private UpdateCommitStatuses $updateCommitStatuses,
    ) {
        //
    }

    /**
     * Asks the AI whether the commit, images and GIF included, should be approved. The decision is mostly a hint for
     * moderators: a clean commit is approved when its author already has an approved commit, and it is rejected,
     * without a reason, when the AI is all but certain. Failures, such as timeouts or rate limits, are thrown, so the
     * caller can try again later.
     */
    public function handle(Commit $commit): void
    {
        $images = array_values($commit->images->map(
            fn (CommitImage $image): string => 'data:image/webp;base64,'.base64_encode((string) Storage::get($image->path)),
        )->all());

        if ($commit->giphy_url !== null) {
            $images[] = $commit->giphy_url;
        }

        $scores = $this->openAI->moderate($commit->body, $images);

        $categories = array_keys($scores);

        if ($commit->body !== '' && $this->openAI->isHarmful($commit->body)) {
            $categories[] = 'harmful-text';
        }

        if ($images !== [] && $this->openAI->showsMinors($images)) {
            $categories[] = 'minor-in-image';
        }

        $commit->update([
            'ai_decision' => $categories === [] ? CommitStatus::Approved : CommitStatus::Rejected,
            'ai_categories' => $categories,
        ]);

        if ($scores !== [] && max($scores) >= self::AUTO_REJECT_THRESHOLD) {
            $this->updateCommitStatuses->handle([$commit->id], CommitStatus::Rejected, onlyPending: true);
        }

        if ($categories === [] && $commit->user_id !== null && Commit::query()
            ->where('user_id', $commit->user_id)
            ->whereKeyNot($commit->id)
            ->approved()
            ->exists()) {
            $this->updateCommitStatuses->handle([$commit->id], CommitStatus::Approved, onlyPending: true);
        }
    }
}
