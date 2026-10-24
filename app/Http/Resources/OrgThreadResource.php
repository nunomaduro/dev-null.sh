<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Queries\ConversationThreadQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An org as the web's thread loads and polls it: its name, a page of messages from where the user's history starts,
 * the reactions changed since the version a poll last saw, the messages it pinned unless they are unchanged since that
 * version, its members with their roles and, where both share pulls, how far each has pulled, who is typing, and the
 * user's own role.
 *
 * @phpstan-import-type OrgThread from ConversationThreadQuery
 *
 * @phpstan-type ThreadWithOrg array{
 *     org: Conversation,
 *     messages: OrgThread['messages'],
 *     has_older: OrgThread['has_older'],
 *     reactions_version: OrgThread['reactions_version'],
 *     reaction_changes: OrgThread['reaction_changes'],
 *     pinned: OrgThread['pinned'],
 *     read_through_id: OrgThread['read_through_id'],
 *     members: OrgThread['members'],
 *     typing: OrgThread['typing'],
 *     is_archived: OrgThread['is_archived'],
 *     pinned_at: OrgThread['pinned_at'],
 *     muted_until_at: OrgThread['muted_until_at'],
 *     role: OrgThread['role'],
 *     access: OrgThread['access']
 * }
 */
final class OrgThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ThreadWithOrg $thread */
        $thread = $this->resource;

        return [
            ...$thread,
            'org' => ['id' => $thread['org']->id, 'name' => $thread['org']->name],
            'messages' => MessageResource::collection($thread['messages']),
            'pinned' => $thread['pinned'] === null ? null : MessageResource::collection($thread['pinned']),
            'members' => $thread['members']->map(fn (array $member): array => [
                'role' => $member['role'],
                'read_through_id' => $member['read_through_id'],
                'user' => UserResource::make($member['user']),
            ]),
        ];
    }
}
