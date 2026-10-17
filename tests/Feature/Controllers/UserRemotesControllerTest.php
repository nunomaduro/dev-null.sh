<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

it('is off by default, and hides remotes until turned on', function (): void {
    $mark = User::factory()->create();
    $nuno = User::factory()->withRemotes()->create();

    $this->actingAs($mark)->get(route('user-profile.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('auth.remotes', null));
    $this->get(route('conversation.index'))->assertNotFound();
    $this->get(route('conversation.show', $nuno))->assertNotFound();
    $this->getJson(route('inbox.index'))->assertNotFound();
    $this->postJson(route('conversation-message.store', $nuno), ['body' => 'hi', 'client_id' => Str::uuid()->toString()])->assertNotFound();

    $this->fromRoute('user-profile.edit')->put(route('user-remotes.update'), ['enabled' => true])
        ->assertRedirectToRoute('user-profile.edit');

    $this->get(route('conversation.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.remotes.from', 'following')
            ->where('auth.remotes.pull_receipts', true));

    $this->put(route('user-remotes.update'), ['enabled' => false])->assertRedirect();
    $this->get(route('conversation.index'))->assertNotFound();
});

it('keeps people with remotes off out of reach', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->create(['username' => 'nunomaduro']);

    $this->actingAs($mark)->postJson(route('conversation-message.store', $nuno), ['body' => 'hi', 'client_id' => Str::uuid()->toString()])
        ->assertForbidden()
        ->assertJsonPath('message', "@nunomaduro isn't taking remotes.");
    $this->getJson(route('conversation-message.index', $nuno))->assertJsonPath('access', 'closed');
});

it('validates the setting', function (mixed $enabled): void {
    $this->actingAs(User::factory()->create())->putJson(route('user-remotes.update'), ['enabled' => $enabled])
        ->assertUnprocessable()->assertJsonValidationErrors('enabled');
})->with([null, 'yes']);

it('requires authentication', function (): void {
    $this->putJson(route('user-remotes.update'), ['enabled' => true])->assertUnauthorized();
});
