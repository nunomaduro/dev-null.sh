<?php

declare(strict_types=1);

use App\Enums\RemotesFrom;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;

beforeEach(function (): void {
    config(['remotes.requests' => true, 'remotes.everyone' => true]);
});

it('lets both people react to a message and shows the reactions with it', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($mark, $nuno, 'migrations are green');

    $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])
        ->assertOk()
        ->assertJsonPath('reactions', [['user_id' => $nuno->id, 'emoji' => '😂']]);
    $this->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])->assertOk();
    $this->actingAs($mark)->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])->assertOk();
    $this->postJson(route('message-reaction.store', $message), ['emoji' => '✅'])->assertOk();

    $this->getJson(route('conversation-message.index', $nuno))
        ->assertJsonPath('messages.0.reactions', [
            ['user_id' => $nuno->id, 'emoji' => '😂'],
            ['user_id' => $mark->id, 'emoji' => '😂'],
            ['user_id' => $mark->id, 'emoji' => '✅'],
        ]);
});

it('takes back only the reaction the user removes', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($mark, $nuno);
    $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => '❤️'])->assertOk();
    $this->actingAs($mark)->postJson(route('message-reaction.store', $message), ['emoji' => '❤️'])->assertOk();

    $this->deleteJson(route('message-reaction.destroy', $message), ['emoji' => '❤️'])
        ->assertOk()
        ->assertJsonPath('reactions', [['user_id' => $nuno->id, 'emoji' => '❤️']]);
    $this->deleteJson(route('message-reaction.destroy', $message), ['emoji' => '❤️'])->assertOk();
});

it('only takes emoji as reactions', function (mixed $emoji): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($mark, $nuno);

    $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => $emoji])->assertJsonValidationErrors('emoji');
})->with([':joy:', 'lol', '', null, [['😂']]]);

it('caps how many reactions one person leaves on a message', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($mark, $nuno);
    $emoji = ['😂', '✅', '❤️', '🚀', '👀', '🔥', '🎉', '👍', '💯', '🙏', '🤔', '👏'];

    foreach ($emoji as $each) {
        $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => $each])->assertOk();
    }

    $this->postJson(route('message-reaction.store', $message), ['emoji' => '💀'])->assertJsonValidationErrors('emoji');
    $this->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])->assertOk();
    $this->actingAs($mark)->postJson(route('message-reaction.store', $message), ['emoji' => '💀'])->assertOk();
});

it('keeps reactions to the people who can see the message', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $message = sendChatMessage($mark, $nuno);

    $this->actingAs(User::factory()->withRemotes()->create())->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])->assertNotFound();
    $this->deleteJson(route('message-reaction.destroy', $message), ['emoji' => '😂'])->assertNotFound();

    $nuno->blocking()->attach($mark);

    $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => '😂'])->assertNotFound();
});

it('waits for a request to be merged before reactions go either way', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes(RemotesFrom::Following)->create();
    $message = sendChatMessage($mark, $nuno, 'hey, saw your polling PR');

    $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => '👀'])->assertForbidden();
    $this->actingAs($mark)->postJson(route('message-reaction.store', $message), ['emoji' => '👀'])->assertForbidden();

    $this->actingAs($nuno)->postJson(route('conversation-merge.store', $mark))->assertNoContent();

    $this->postJson(route('message-reaction.store', $message), ['emoji' => '👀'])->assertOk();
});

it('lets org members react within the history they can see, but not to org events', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $before = pushToOrg($nuno, $org, 'before taylor joined');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $after = pushToOrg($nuno, $org, 'welcome taylor');
    $event = $after->newQuery()->whereNotNull('event')->latest('id')->firstOrFail();

    $this->actingAs($taylor)->postJson(route('message-reaction.store', $after), ['emoji' => '👋'])->assertOk();
    $this->postJson(route('message-reaction.store', $before), ['emoji' => '👋'])->assertNotFound();
    $this->postJson(route('message-reaction.store', $event), ['emoji' => '👋'])->assertNotFound();

    $messages = collect($this->actingAs($mark)->getJson(route('org-message.index', $org))->json('messages'));

    expect($messages->firstWhere('id', $after->id)['reactions'])->toBe([['user_id' => $taylor->id, 'emoji' => '👋']]);
});

it('lets org members react to a pinned message from before their history, until it is unpinned', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $runbook = pushToOrg($nuno, $org, 'deploy runbook');
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $this->putJson(route('message-pin.update', $runbook))->assertOk();

    $this->actingAs($taylor)->postJson(route('message-reaction.store', $runbook), ['emoji' => '🙏'])
        ->assertOk()
        ->assertJsonPath('reactions', [['user_id' => $taylor->id, 'emoji' => '🙏']]);
    $this->getJson(route('org-message.index', $org))->assertJsonPath('pinned.0.reactions', [['user_id' => $taylor->id, 'emoji' => '🙏']]);

    $this->actingAs($mark)->deleteJson(route('message-pin.destroy', $runbook))->assertOk();

    $this->actingAs($taylor)->postJson(route('message-reaction.store', $runbook), ['emoji' => '👀'])->assertNotFound();
});

it('hands polls the reactions that changed since the last one, wherever the message is', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $old = sendChatMessage($mark, $nuno, 'old message');
    $other = sendChatMessage($nuno, $mark, 'untouched');
    $latest = sendChatMessage($mark, $nuno, 'latest');

    $version = $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->json('reactions_version');

    $this->getJson(route('conversation-message.index', [$nuno, 'after' => $latest->id, 'reactions_after' => $version]))
        ->assertJsonPath('reaction_changes', [])
        ->assertJsonPath('reactions_version', $version);

    $this->actingAs($nuno)->postJson(route('message-reaction.store', $old), ['emoji' => '🚀'])->assertOk();
    $this->postJson(route('message-reaction.store', $latest), ['emoji' => '🔥'])->assertOk();
    $this->deleteJson(route('message-reaction.destroy', $latest), ['emoji' => '🔥'])->assertOk();

    $poll = $this->actingAs($mark)->getJson(route('conversation-message.index', [$nuno, 'after' => $latest->id, 'reactions_after' => $version]))
        ->assertJsonPath('messages', [])
        ->assertJsonPath('reaction_changes', [
            ['id' => $old->id, 'reactions' => [['user_id' => $nuno->id, 'emoji' => '🚀']]],
            ['id' => $latest->id, 'reactions' => []],
        ]);

    expect($poll->json('reactions_version'))->toBeGreaterThan($version)
        ->and($poll->json('reaction_changes.*.id'))->not->toContain($other->id);

    $this->getJson(route('conversation-message.index', [$nuno, 'after' => $latest->id, 'reactions_after' => $poll->json('reactions_version')]))
        ->assertJsonPath('reaction_changes', []);
});

it('ranks the emoji a person reacts with most', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $messages = collect(range(1, 3))->map(fn (int $index): Message => sendChatMessage($mark, $nuno, "message {$index}"));

    $messages->each(fn ($message) => $this->actingAs($nuno)->postJson(route('message-reaction.store', $message), ['emoji' => '🚀'])->assertOk());
    $this->postJson(route('message-reaction.store', $messages->first()), ['emoji' => '👀'])->assertOk();
    $this->actingAs($mark)->postJson(route('message-reaction.store', $messages->first()), ['emoji' => '😂'])->assertOk();

    $this->actingAs($nuno)->getJson(route('message-reaction.index'))
        ->assertOk()
        ->assertExactJson(['usage' => [['emoji' => '🚀', 'count' => 3], ['emoji' => '👀', 'count' => 1]]]);

    expect(MessageReaction::query()->count())->toBe(5);
});
