<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrgEvent;
use App\Enums\OrgRole;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class DeleteOrgMember
{
    public function __construct(private CreateConversationEvent $createConversationEvent, private DeleteOrgs $deleteOrgs)
    {
        //
    }

    /**
     * Removes a member, or lets them leave. They lose access to the org. When the last maintainer goes, the
     * longest-standing member takes over, and an org nobody is left in is deleted, with the files of its messages.
     * The org is locked like a role change locks it, so a demotion at the same moment can't leave nobody to take over.
     */
    public function handle(User $actor, Conversation $org, User $member): void
    {
        DB::transaction(function () use ($actor, $org, $member): void {
            Conversation::query()->whereKey($org->id)->lockForUpdate()->first();

            ConversationParticipant::query()->where('conversation_id', $org->id)->where('user_id', $member->id)->delete();

            $remaining = $org->participants()->with('user')->oldest('id')->get();

            if ($remaining->isEmpty()) {
                $this->deleteOrgs->handle(Conversation::query()->whereKey($org->id));

                return;
            }

            $actor->is($member)
                ? $this->createConversationEvent->handle($member, $org, OrgEvent::Left)
                : $this->createConversationEvent->handle($actor, $org, OrgEvent::Removed, $member);

            if ($remaining->doesntContain(fn (ConversationParticipant $participant): bool => $participant->role === OrgRole::Maintainer)) {
                $heir = $remaining->firstOrFail();
                $heir->update(['role' => OrgRole::Maintainer]);

                $this->createConversationEvent->handle($heir->user, $org, OrgEvent::Inherited);
            }
        });
    }
}
