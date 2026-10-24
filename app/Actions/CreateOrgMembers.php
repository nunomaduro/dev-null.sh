<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrgEvent;
use App\Enums\OrgRole;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class CreateOrgMembers
{
    public function __construct(private CreateConversationEvent $createConversationEvent)
    {
        //
    }

    /**
     * New contributors start caught up. Without the history, they only see what is pushed after they joined.
     *
     * @param  Collection<int, User>  $members
     */
    public function handle(User $actor, Conversation $org, Collection $members, bool $sharesHistory): void
    {
        DB::transaction(function () use ($actor, $org, $members, $sharesHistory): void {
            $lockedOrg = Conversation::query()->whereKey($org->id)->lockForUpdate()->firstOrFail();
            $existing = $lockedOrg->participants()->pluck('user_id');
            $toAdd = $members->reject(fn (User $member): bool => $existing->contains($member->id));

            if ($toAdd->isEmpty()) {
                return;
            }

            abort_if(
                $existing->count() + $toAdd->count() > Conversation::MAX_MEMBERS,
                422,
                'An org holds up to '.Conversation::MAX_MEMBERS.' people.',
            );

            $latest = $lockedOrg->last_message_id ?? 0;

            foreach ($toAdd as $member) {
                ConversationParticipant::query()->create([
                    'conversation_id' => $org->id,
                    'user_id' => $member->id,
                    'role' => OrgRole::Contributor,
                    'visible_from_id' => $sharesHistory ? 0 : $latest,
                    'read_through_id' => $latest,
                    'last_message_at' => now(),
                ]);

                $this->createConversationEvent->handle($actor, $org, OrgEvent::Added, $member);
            }
        });
    }
}
