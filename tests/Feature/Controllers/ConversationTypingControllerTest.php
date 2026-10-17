<?php

declare(strict_types=1);

use App\Models\User;

it('shows the partner typing for a few seconds', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    sendChatMessage($mark, $nuno, 'what do the dots mean?');

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('partner_typing', false);

    $this->actingAs($nuno)->putJson(route('conversation-typing.update', $mark))->assertNoContent();

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('partner_typing', true);
    $this->actingAs($nuno)->getJson(route('conversation-message.index', $mark))->assertJsonPath('partner_typing', false);

    $this->travel(6)->seconds();

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('partner_typing', false);
});

it('stops showing the partner typing once their message arrives', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    sendChatMessage($mark, $nuno, 'hi');

    $this->actingAs($nuno)->putJson(route('conversation-typing.update', $mark))->assertNoContent();
    sendChatMessage($nuno, $mark, 'hey');

    $this->actingAs($mark)->getJson(route('conversation-message.index', $nuno))->assertJsonPath('partner_typing', false);
});

it('hides typing from people you cannot message', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $nuno->blocking()->attach($mark);

    $this->actingAs($mark)->putJson(route('conversation-typing.update', $nuno))->assertNotFound();
});
