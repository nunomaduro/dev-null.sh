<?php

declare(strict_types=1);

use App\Models\User;

it('moves a stashed conversation to the stashed tab, for you only', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create(['username' => 'nunomaduro']);
    sendChatMessage($nuno, $mark, 'ship it');

    $this->actingAs($mark)->postJson(route('conversation-archive.store', $nuno))->assertNoContent();

    $this->getJson(route('inbox.index'))->assertJsonPath('conversations', [])->assertJsonPath('unread', 0);
    $this->getJson(route('inbox.index', ['tab' => 'stashed']))
        ->assertJsonPath('conversations.0.partner.username', 'nunomaduro')
        ->assertJsonPath('conversations.0.is_archived', true);
    $this->getJson(route('conversation-message.index', $nuno))->assertJsonPath('is_archived', true);

    $this->actingAs($nuno)->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations');
});

it('pops a stashed conversation back when either of you writes', function (string $writer): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    sendChatMessage($nuno, $mark, 'ship it');
    $this->actingAs($mark)->postJson(route('conversation-archive.store', $nuno))->assertNoContent();

    $writer === 'you' ? sendChatMessage($mark, $nuno, 'one more thing') : sendChatMessage($nuno, $mark, 'one more thing');

    $this->actingAs($mark)->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations');
    $this->getJson(route('inbox.index', ['tab' => 'stashed']))->assertJsonPath('conversations', []);
})->with(['you', 'them']);

it('pops a stashed conversation', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    sendChatMessage($nuno, $mark, 'ship it');
    $this->actingAs($mark)->postJson(route('conversation-archive.store', $nuno));

    $this->deleteJson(route('conversation-archive.destroy', $nuno))->assertNoContent();

    $this->getJson(route('inbox.index'))->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.is_archived', false);
});

it('hides stashing from people you cannot message', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $nuno->blocking()->attach($mark);

    $this->actingAs($mark)->postJson(route('conversation-archive.store', $nuno))->assertNotFound();
    $this->deleteJson(route('conversation-archive.destroy', $nuno))->assertNotFound();
});

it('requires authentication', function (): void {
    $this->postJson(route('conversation-archive.store', User::factory()->withRemotes()->create()))->assertUnauthorized();
});
