<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Commit;
use App\Models\User;
use App\Services\CodeSpans;
use App\Services\Mentions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commit and its author, as anyone may see them.
 *
 * A signed request gets the commit as the web shows it to that user instead: parent_id is the parent the commit
 * replies to, whoever may see it, and on top come the body split into prose, inline code and highlighted code
 * blocks, where it mentions someone, its status, whether the author pinned it, and whether the user starred or saved
 * it. As on the web, the counts, link_preview, images, starred, is_saved and parent are
 * only there when they were loaded, so ancestors and the commits of notifications come without some of them.
 *
 * A deleted commit that still has replies stays in its thread as a tombstone: its id, parent_id, deleted_at and
 * replies_count, but never its author or body. Only signed requests are ever sent one.
 *
 * @mixin Commit
 */
final class CommitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->trashed()) {
            return [
                'id' => $this->id,
                'parent_id' => $this->parent_id,
                'deleted_at' => $this->deleted_at?->toIso8601ZuluString(),
                'replies_count' => $this->whenCounted('replies'),
            ];
        }

        $isSigned = $request->user() instanceof User;

        $commit = [
            'id' => $this->id,
            'short_hash' => mb_substr(str_replace('-', '', $this->id), -7),
            'body' => $this->body,
            'parent_id' => $isSigned ? $this->parent_id : $this->parent?->id,
            'html_url' => route('commit.show', [$this->user, $this->resource]),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'stars_count' => $this->whenCounted('stars'),
            'replies_count' => $this->whenCounted('replies'),
            'link_preview' => $this->whenLoaded('linkPreview'),
            'images' => $this->whenLoaded('images'),
            'author' => UserResource::make($this->user),
        ];

        if (! $isSigned) {
            return $commit;
        }

        return [
            ...$commit,
            'body_segments' => resolve(CodeSpans::class)->highlight($this->body),
            'mentions' => resolve(Mentions::class)->positions($this->body),
            'status' => $this->status,
            'is_pinned' => $this->user?->pinned_commit_id === $this->id,
            'starred' => $this->whenHas('starred'),
            'is_saved' => $this->whenHas('is_saved'),
            'deleted_at' => null,
            'parent' => $this->whenLoaded('parent', fn (Commit $parent): self => self::make($parent)),
        ];
    }
}
