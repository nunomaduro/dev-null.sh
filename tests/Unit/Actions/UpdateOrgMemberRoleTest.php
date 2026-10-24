<?php

declare(strict_types=1);

use App\Actions\UpdateOrgMemberRole;
use App\Enums\OrgEvent;
use App\Enums\OrgRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('keeps the last maintainer when two maintainers demote each other at once', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = Conversation::query()->findOrFail(createOrg($mark, [$nuno]));
    $this->actingAs($mark)->putJson(route('org-member.update', [$org, $nuno]), ['role' => 'maintainer'])->assertNoContent();

    // Both requests were checked while the org still had two maintainers, then their changes land one after the other.
    resolve(UpdateOrgMemberRole::class)->handle($mark, $org, $nuno, OrgRole::Contributor);
    resolve(UpdateOrgMemberRole::class)->handle($nuno, $org, $mark, OrgRole::Contributor);

    expect($org->fresh()?->isMaintainedBy($mark))->toBeTrue()
        ->and($org->fresh()?->isMaintainedBy($nuno))->toBeFalse()
        ->and(Message::query()->where('conversation_id', $org->id)->where('event', OrgEvent::Demoted)->count())->toBe(1);
});
