<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\User;
use Aws\Credentials\Credentials;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
});

function storedCommitImage(Commit $commit): CommitImage
{
    $path = 'commit-images/'.fake()->uuid().'.webp';

    Storage::disk()->put($path, (string) UploadedFile::fake()->image('image.webp', 10, 10)->getContent());

    return CommitImage::factory()->for($commit)->create(['path' => $path]);
}

it('serves images of approved commits to everyone', function (array $query): void {
    $image = storedCommitImage(Commit::factory()->create());

    $response = $this->get(route('commit-image.show', ['commitImage' => $image, ...$query]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");

    expect($response->headers->get('Content-Disposition'))->toBe('inline; filename='.$image->id.'.webp')
        ->and($response->streamedContent())->toBe(Storage::disk()->get($image->path));
})->with(['default' => [[]], 'download disabled' => [['download' => '0']]]);

it('downloads the stored image with a webp filename', function (): void {
    $image = storedCommitImage(Commit::factory()->create());

    $response = $this->get(route('commit-image.show', ['commitImage' => $image, 'download' => 1]));

    $response->assertOk()
        ->assertDownload($image->id.'.webp')
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'")
        ->assertStreamedContent(Storage::disk()->get($image->path));

    expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
});

it('hides images of pending commits from guests and other users', function (bool $download): void {
    $image = storedCommitImage(Commit::factory()->pending()->create());
    $url = route('commit-image.show', ['commitImage' => $image, 'download' => $download]);

    $this->get($url)->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertNotFound();
})->with([false, true]);

it('hides images of rejected commits from other users', function (): void {
    $image = storedCommitImage(Commit::factory()->rejected()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('commit-image.show', $image))
        ->assertNotFound();
});

it('serves images of pending commits to their author', function (bool $download): void {
    $commit = Commit::factory()->pending()->create();
    $image = storedCommitImage($commit);

    $this->actingAs($commit->user)
        ->get(route('commit-image.show', ['commitImage' => $image, 'download' => $download]))
        ->assertOk();
})->with([false, true]);

it('serves images of pending commits to moderators', function (): void {
    config(['app.admins' => ['admin']]);

    $image = storedCommitImage(Commit::factory()->pending()->create());

    $this->actingAs(User::factory()->create(['username' => 'admin']))
        ->get(route('commit-image.show', $image))
        ->assertOk();
});

it('hides images of deleted commits', function (): void {
    $commit = Commit::factory()->create();
    $image = storedCommitImage($commit);

    $commit->delete();

    $this->actingAs($commit->user)
        ->get(route('commit-image.show', $image))
        ->assertNotFound();
});

it('responds not found when the image file is missing from the disk', function (bool $download): void {
    $image = CommitImage::factory()->for(Commit::factory())->create();

    $this->get(route('commit-image.show', ['commitImage' => $image, 'download' => $download]))->assertNotFound();
})->with([false, true]);

it('does not cache images publicly', function (): void {
    $image = storedCommitImage(Commit::factory()->create());

    $response = $this->get(route('commit-image.show', $image));

    expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
});

function cloudDisk(?Credentials $credentials = null): AwsS3V3Adapter
{
    $disk = Storage::build([
        'driver' => 's3',
        'credentials' => $credentials ?? new Credentials('key', 'secret'),
        'region' => 'us-east-1',
        'bucket' => 'images',
    ]);
    assert($disk instanceof AwsS3V3Adapter);

    return $disk;
}

it('redirects public images to a signed cloud storage url, without asking storage about the file', function (): void {
    $image = CommitImage::factory()->for(Commit::factory())->create();
    Storage::set(config('filesystems.default'), cloudDisk());

    $response = $this->get(route('commit-image.show', $image));

    $response->assertRedirect();

    $url = (string) $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://images.s3.amazonaws.com/'.$image->path.'?')
        ->and($query)->toHaveKey('X-Amz-Signature')
        ->and((int) $query['X-Amz-Expires'])->toBeBetween(1190, 1200)
        ->and($query['response-content-type'])->toBe('image/webp')
        ->and($query['response-content-disposition'])->toBe('inline')
        ->and($query['response-cache-control'])->toBe('private, max-age=600')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=600')->not->toContain('public');
});

it('redirects download requests to a signed cloud storage url with attachment disposition', function (): void {
    $image = CommitImage::factory()->for(Commit::factory())->create();

    Storage::set(config('filesystems.default'), cloudDisk());

    $response = $this->get(route('commit-image.show', ['commitImage' => $image, 'download' => 1]));

    $response->assertRedirect();

    $url = (string) $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://images.s3.amazonaws.com/'.$image->path.'?')
        ->and($query)->toHaveKey('X-Amz-Signature')
        ->toHaveKey('response-content-disposition')
        ->and($query['response-content-disposition'])->toBe('attachment; filename='.$image->id.'.webp');
});

it('streams images of pending commits instead of handing out a signed url', function (): void {
    $commit = Commit::factory()->pending()->create();
    $image = CommitImage::factory()->for($commit)->create();

    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldNotReceive('temporaryUrl');
    $disk->shouldReceive('exists')->with($image->path)->andReturnFalse();
    Storage::set(config('filesystems.default'), $disk);

    $this->actingAs($commit->user)
        ->get(route('commit-image.show', $image))
        ->assertNotFound();
});

it('streams images when the storage credentials expire before a signed url would', function (): void {
    $image = CommitImage::factory()->for(Commit::factory())->create();

    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('getClient')->andReturn(cloudDisk(new Credentials('key', 'secret', 'token', now()->addMinutes(5)->getTimestamp()))->getClient());
    $disk->shouldNotReceive('temporaryUrl');
    $disk->shouldReceive('exists')->with($image->path)->andReturnFalse();
    Storage::set(config('filesystems.default'), $disk);

    $this->get(route('commit-image.show', $image))->assertNotFound();
});
