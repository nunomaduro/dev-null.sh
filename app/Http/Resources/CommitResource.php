<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Commit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Commit
 */
final class CommitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'short_hash' => mb_substr(str_replace('-', '', $this->id), -7),
            'body' => $this->body,
            'parent_id' => $this->parent?->id,
            'html_url' => route('commit.show', [$this->user, $this->resource]),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'stars_count' => $this->stars_count,
            'replies_count' => $this->replies_count,
            'link_preview' => $this->linkPreview,
            'images' => $this->images,
            'gif' => $this->gif(),
            'author' => UserResource::make($this->user),
        ];
    }
}
