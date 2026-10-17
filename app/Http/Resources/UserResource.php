<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user's public profile, with their counts when they were loaded. A signed request also gets the lane, the color
 * the web draws them in.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'company' => $this->company,
            'location' => $this->location,
            'website' => $this->website,
            'github_url' => "https://github.com/{$this->username}",
            'html_url' => route('user.show', $this->resource),
            'feed_url' => route('user-feed.show', $this->resource),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'commits_count' => $this->whenCounted('commits'),
            'followers_count' => $this->whenCounted('followers'),
            'following_count' => $this->whenCounted('following'),
            'lane' => $this->when($request->user() instanceof User, fn (): int => $this->lane()),
            'is_followed' => $this->whenHas('is_followed'),
        ];
    }
}
