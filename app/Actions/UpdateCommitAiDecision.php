<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\PollOption;
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
     * Asks the AI whether the commit, images included, should be approved. The decision is mostly a hint for
     * moderators: a clean commit is approved, and it is rejected, without a reason, when the AI is all but certain.
     * Failures, such as timeouts or rate limits, are thrown, so the caller can try again later.
     */
    public function handle(Commit $commit): void
    {
        $images = array_values($commit->images->map(
            fn (CommitImage $image): string => 'data:image/webp;base64,'.base64_encode((string) Storage::get($image->path)),
        )->all());

        $text = $this->text($commit);

        $scores = $this->openAI->moderate($text, $images);

        $categories = array_keys($scores);

        if ($text !== '' && $this->openAI->isHarmful($text)) {
            $categories[] = 'harmful-text';
        }

        $commit->update([
            'ai_decision' => $categories === [] ? CommitStatus::Approved : CommitStatus::Rejected,
            'ai_categories' => $categories,
        ]);

        if ($scores !== [] && max($scores) >= self::AUTO_REJECT_THRESHOLD) {
            $this->updateCommitStatuses->handle([$commit->id], CommitStatus::Rejected, onlyPending: true);
        }

        if ($categories === [] && $commit->user !== null) {
            $this->updateCommitStatuses->handle([$commit->id], CommitStatus::Approved, onlyPending: true);
        }
    }

    /**
     * All the text that goes public with the commit: the body, the image descriptions, and the poll options. A clean
     * commit is approved, so nothing the author wrote may be left unchecked.
     */
    private function text(Commit $commit): string
    {
        return collect([$commit->body])
            ->merge($commit->images->map(fn (CommitImage $image): ?string => $image->description))
            ->merge($commit->poll?->options->map(fn (PollOption $option): string => $option->label) ?? [])
            ->filter(fn (?string $part): bool => $part !== null && $part !== '')
            ->implode("\n\n");
    }
}
