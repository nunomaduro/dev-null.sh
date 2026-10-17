<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\NewPoll;
use App\Enums\CommitStatus;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\UnfurlLink;
use App\Models\Commit;
use App\Models\LinkPreview;
use App\Models\User;
use App\Services\EmojiShortcodes;
use App\Services\TheAlgorithm;
use App\Services\Urls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final readonly class CreateCommit
{
    public function __construct(
        private CreateReplyNotifications $createReplyNotifications,
        private CreateQuoteNotifications $createQuoteNotifications,
        private CreateMentionNotifications $createMentionNotifications,
        private CreateCommitImages $createCommitImages,
        private CreateWorldReply $createWorldReply,
        private UpdateCommitHotScores $updateCommitHotScores,
        private TheAlgorithm $theAlgorithm,
        private EmojiShortcodes $emojiShortcodes,
        private Urls $urls,
    ) {
        //
    }

    /**
     * The link preview is of the first URL in the body when true, of the given URL when a string, or none when false.
     *
     * @param  list<UploadedFile>  $images
     * @param  array<int, string|null>  $imageDescriptions
     */
    public function handle(User $user, string $body, ?Commit $parent = null, ?Commit $quotedCommit = null, array $images = [], array $imageDescriptions = [], bool|string $linkPreview = true, ?NewPoll $poll = null): Commit
    {
        $url = match ($linkPreview) {
            true => $this->urls->first($body),
            false => null,
            default => $linkPreview,
        };

        return DB::transaction(function () use ($user, $body, $parent, $quotedCommit, $images, $imageDescriptions, $url, $poll): Commit {
            $linkPreview = $this->linkPreviewFor($url);
            $createdAt = now();

            $commit = $user->commits()->create([
                'body' => $this->emojiShortcodes->replace($body),
                'parent_id' => $parent?->id,
                'root_id' => $parent?->threadRootId(),
                'status' => $user->isAdmin() ? CommitStatus::Approved : CommitStatus::Pending,
                'link_preview_id' => $linkPreview?->id,
                'quoted_commit_id' => $quotedCommit?->id,
                'hot_score' => $this->theAlgorithm->score($createdAt, 0, 0),
                'created_at' => $createdAt,
            ]);

            $this->createCommitImages->handle($commit, $images, $imageDescriptions);

            if ($poll instanceof NewPoll) {
                $this->createPoll($commit, $poll);
            }

            if (($parent instanceof Commit || $quotedCommit instanceof Commit) && $commit->status === CommitStatus::Approved) {
                $this->updateCommitHotScores->handle([$commit->id]);
            }

            $this->createReplyNotifications->handle($commit);
            $this->createQuoteNotifications->handle($commit);
            $this->createMentionNotifications->handle($commit);

            if ($linkPreview instanceof LinkPreview && $linkPreview->fetched_at === null) {
                dispatch(new UnfurlLink($linkPreview));
            }

            $this->createWorldReply->handle($commit);
            if ($commit->status === CommitStatus::Pending && filled(config('services.openai.key'))) {
                dispatch(new ModerateCommit($commit))->afterCommit();
            }

            if ($commit->body !== '' && filled(config('services.openai.key'))) {
                dispatch(new EmbedCommit($commit))->afterCommit();
            }

            return $commit;
        });
    }

    /**
     * A poll opens with its commit's approval, so polls of pending commits wait for moderation.
     */
    private function createPoll(Commit $commit, NewPoll $newPoll): void
    {
        $poll = $commit->poll()->create([
            'duration' => $newPoll->duration,
            'opened_at' => $commit->status === CommitStatus::Approved ? now() : null,
        ]);

        $poll->options()->createMany(array_map(
            fn (string $label, int $position): array => ['label' => $this->emojiShortcodes->replace($label), 'position' => $position],
            $newPoll->options,
            array_keys($newPoll->options),
        ));
    }

    /**
     * Commits that link to the same URL share one preview, so a page is fetched once however often it is linked.
     */
    private function linkPreviewFor(?string $url): ?LinkPreview
    {
        if ($url === null) {
            return null;
        }

        return LinkPreview::query()->firstOrCreate(
            ['url_hash' => hash('sha256', $url)],
            ['url' => $url],
        );
    }
}
