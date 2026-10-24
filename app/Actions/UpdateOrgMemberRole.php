<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrgEvent;
use App\Enums\OrgRole;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateOrgMemberRole
{
    public function __construct(private CreateConversationEvent $createConversationEvent)
    {
        //
    }

    /**
     * The request already turns away demoting the last maintainer. The org is locked while its roles change and the
     * check is made again here, so two maintainers demoting each other at once can't both see the other one left and
     * leave the org with nobody to run it: the second demotion then changes nothing.
     */
    public function handle(User $actor, Conversation $org, User $member, OrgRole $role): void
    {
        DB::transaction(function () use ($actor, $org, $member, $role): void {
            Conversation::query()->whereKey($org->id)->lockForUpdate()->first();

            $isLastMaintainer = $role === OrgRole::Contributor
                && ! $org->participants()->where('role', OrgRole::Maintainer)->where('user_id', '!=', $member->id)->exists();

            if ($isLastMaintainer) {
                return;
            }

            $updated = ConversationParticipant::query()
                ->where('conversation_id', $org->id)
                ->where('user_id', $member->id)
                ->where('role', '!=', $role)
                ->update(['role' => $role]);

            if ($updated > 0) {
                $this->createConversationEvent->handle($actor, $org, $role === OrgRole::Maintainer ? OrgEvent::Promoted : OrgEvent::Demoted, $member);
            }
        });
    }
}
