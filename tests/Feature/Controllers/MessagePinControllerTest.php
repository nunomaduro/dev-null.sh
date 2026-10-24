<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

it('lets maintainers pin a message for the whole org, once', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org, 'deploys go out on fridays now');
    $version = Conversation::query()->findOrFail($org)->reactions_version;

    $this->actingAs($mark)->putJson(route('message-pin.update', $message))
        ->assertOk()
        ->assertJsonCount(1, 'pinned')
        ->assertJsonPath('pinned.0.id', $message->id)
        ->assertJsonPath('pinned.0.body', 'deploys go out on fridays now')
        ->assertJsonPath('pinned.0.user.username', $nuno->username)
        ->assertJsonPath('reactions_version', $version + 1);

    $pinnedAt = $message->fresh()?->pinned_at;

    expect($pinnedAt)->not->toBeNull()
        ->and(Conversation::query()->findOrFail($org)->reactions_version)->toBe($version + 1);

    $this->travel(5)->minutes();

    $this->putJson(route('message-pin.update', $message))
        ->assertOk()
        ->assertJsonPath('pinned.*.id', [$message->id])
        ->assertJsonPath('reactions_version', $version + 1);

    expect($message->fresh()?->pinned_at?->equalTo($pinnedAt))->toBeTrue();
});

it('lets maintainers unpin a message, however often', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $kept = pushToOrg($nuno, $org, 'kept');
    $dropped = pushToOrg($nuno, $org, 'dropped');
    $this->actingAs($mark)->putJson(route('message-pin.update', $kept))->assertOk();
    $this->putJson(route('message-pin.update', $dropped))->assertOk();
    $version = Conversation::query()->findOrFail($org)->reactions_version;

    $this->deleteJson(route('message-pin.destroy', $dropped))
        ->assertOk()
        ->assertJsonPath('pinned.*.id', [$kept->id])
        ->assertJsonPath('reactions_version', $version + 1);

    expect($dropped->fresh()?->pinned_at)->toBeNull()
        ->and(Conversation::query()->findOrFail($org)->reactions_version)->toBe($version + 1);

    $this->deleteJson(route('message-pin.destroy', $dropped))
        ->assertOk()
        ->assertJsonPath('pinned.*.id', [$kept->id])
        ->assertJsonPath('reactions_version', $version + 1);
});

it('only lets maintainers pin and unpin', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org);

    $this->actingAs($nuno)->putJson(route('message-pin.update', $message))
        ->assertForbidden()
        ->assertJsonPath('message', 'Only maintainers can pin messages.');
    $this->actingAs($mark)->putJson(route('message-pin.update', $message))->assertOk();
    $this->actingAs($nuno)->deleteJson(route('message-pin.destroy', $message))
        ->assertForbidden()
        ->assertJsonPath('message', 'Only maintainers can unpin messages.');

    expect($message->fresh()?->pinned_at)->not->toBeNull();
});

it('is not there for people outside the org', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org);

    $this->actingAs(User::factory()->create())->putJson(route('message-pin.update', $message))->assertNotFound();
    $this->deleteJson(route('message-pin.destroy', $message))->assertNotFound();
});

it('is not there while the maintainer has Remotes off', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org);
    $mark->update(['remotes_enabled' => false]);

    $this->actingAs($mark)->putJson(route('message-pin.update', $message))->assertNotFound();
    $this->deleteJson(route('message-pin.destroy', $message))->assertNotFound();
});

it('pins neither direct messages nor org events', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $direct = sendChatMessage($mark, $nuno);
    $org = createOrg($mark, [$nuno]);
    $event = Message::query()->where('conversation_id', $org)->whereNotNull('event')->sole();

    $this->actingAs($mark)->putJson(route('message-pin.update', $direct))->assertNotFound();
    $this->deleteJson(route('message-pin.destroy', $direct))->assertNotFound();
    $this->putJson(route('message-pin.update', $event))->assertNotFound();
});

it('pins only within the history the maintainer can see, but unpins whatever the org pinned', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $before = pushToOrg($nuno, $org, 'from before taylor joined');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $this->putJson(route('org-member.update', [$org, $taylor]), ['role' => 'maintainer'])->assertNoContent();

    $this->actingAs($taylor)->putJson(route('message-pin.update', $before))->assertNotFound();
    $this->actingAs($mark)->putJson(route('message-pin.update', $before))->assertOk();
    $this->actingAs($taylor)->deleteJson(route('message-pin.destroy', $before))->assertOk()->assertJsonPath('pinned', []);
});

it('caps how many messages an org pins', function (): void {
    $mark = User::factory()->create();
    [$nuno] = followersOf($mark, 1);
    $org = createOrg($mark, [$nuno]);
    $pinned = Message::factory()->count(Conversation::MAX_PINS)->pinned()->create(['conversation_id' => $org, 'user_id' => $nuno->id]);
    $message = pushToOrg($nuno, $org);

    $this->actingAs($mark)->putJson(route('message-pin.update', $message))
        ->assertForbidden()
        ->assertJsonPath('message', 'An org can pin up to 25 messages. Unpin one first.');
    $this->putJson(route('message-pin.update', $pinned->first()))->assertOk()->assertJsonCount(Conversation::MAX_PINS, 'pinned');
    $this->deleteJson(route('message-pin.destroy', $pinned->first()))->assertOk();
    $this->putJson(route('message-pin.update', $message))->assertOk()->assertJsonPath('pinned.0.id', $message->id);
});
