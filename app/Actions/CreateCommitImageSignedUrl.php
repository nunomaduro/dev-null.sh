<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CommitImage;
use Aws\Credentials\CredentialsInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

final readonly class CreateCommitImageSignedUrl
{
    /**
     * How long the URL lets anyone holding it fetch the image, which bounds how long an image stays reachable after its commit is deleted or rejected.
     */
    public const int LIFETIME = 1200;

    /**
     * How long the browser reuses the URL, and so the image behind it, kept well inside its lifetime.
     */
    public const int MAX_AGE = 600;

    /**
     * Only cloud storage serves files itself, and only while the credentials signing the URL outlive it: role credentials can expire first, which would leave the browser holding a dead URL.
     */
    public function handle(CommitImage $commitImage, bool $download = false): ?string
    {
        $disk = Storage::disk();

        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        $expires = now()->addSeconds(self::LIFETIME);
        $credentials = $disk->getClient()->getCredentials()->wait();

        if (! $credentials instanceof CredentialsInterface || ($credentials->getExpiration() !== null && $credentials->getExpiration() < $expires->getTimestamp())) {
            return null;
        }

        return $disk->temporaryUrl($commitImage->path, $expires, [
            'ResponseContentType' => 'image/webp',
            'ResponseContentDisposition' => $download
                ? 'attachment; filename="'.$commitImage->id.'.webp"'
                : 'inline',
            'ResponseCacheControl' => 'private, max-age='.self::MAX_AGE,
        ]);
    }
}
