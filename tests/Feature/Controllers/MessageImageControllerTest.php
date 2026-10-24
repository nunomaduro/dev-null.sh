<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\MessageImage;
use App\Models\User;
use App\Services\EncryptedFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
    config(['remotes.requests' => true, 'remotes.everyone' => true]);
});

function messageImageBytes(): string
{
    return (string) UploadedFile::fake()->image('image.webp', 10, 10)->getContent();
}

function storedMessageImage(Message $message): MessageImage
{
    $path = 'message-images/'.fake()->uuid();

    Storage::disk()->put($path, resolve(EncryptedFiles::class)->encrypt(messageImageBytes()));

    return MessageImage::factory()->for($message)->create(['path' => $path]);
}

it('serves images only to the people in the conversation', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $image = storedMessageImage(sendChatMessage($mark, $nuno));

    foreach ([$mark, $nuno] as $participant) {
        $this->actingAs($participant)->get(route('message-image.show', $image))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'max-age=3600, private');
    }

    $this->actingAs(User::factory()->create())->get(route('message-image.show', $image))->assertNotFound();
});

it('downloads the image with a webp filename', function (): void {
    $mark = User::factory()->withRemotes()->create();
    $nuno = User::factory()->withRemotes()->create();
    $image = storedMessageImage(sendChatMessage($mark, $nuno));

    $this->actingAs($nuno)->get(route('message-image.show', ['messageImage' => $image, 'download' => 1]))
        ->assertOk()
        ->assertDownload($image->id.'.webp')
        ->assertContent(messageImageBytes());

    $this->actingAs(User::factory()->create())
        ->get(route('message-image.show', ['messageImage' => $image, 'download' => 1]))
        ->assertNotFound();
});

it('keeps images out of the history an org member cannot see', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $before = storedMessageImage(pushToOrg($nuno, $org));
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $after = storedMessageImage(pushToOrg($nuno, $org));

    $this->actingAs($taylor)->get(route('message-image.show', $before))->assertNotFound();
    $this->get(route('message-image.show', $after))->assertOk();
});

it('serves the images of a pinned message to every org member, whatever history they were given', function (): void {
    $mark = User::factory()->create();
    [$nuno, $taylor] = followersOf($mark, 2);
    $org = createOrg($mark, [$nuno]);
    $message = pushToOrg($nuno, $org);
    $image = storedMessageImage($message);
    $this->actingAs($mark)->postJson(route('org-member.store', $org), ['members' => [$taylor->username], 'share_history' => false])->assertNoContent();
    $this->putJson(route('message-pin.update', $message))->assertOk();

    $this->actingAs($taylor)->get(route('message-image.show', $image))->assertOk();
});

it('requires authentication', function (): void {
    $image = storedMessageImage(Message::factory()->create());

    $this->get(route('message-image.show', $image))->assertRedirect();
});
