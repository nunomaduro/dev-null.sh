<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateCommitImageSignedUrl;
use App\Http\Requests\ShowCommitImageRequest;
use App\Models\CommitImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class CommitImageController
{
    public function show(ShowCommitImageRequest $request, CommitImage $commitImage): RedirectResponse|StreamedResponse
    {
        $url = $request->signedUrl();

        if ($url !== null) {
            return redirect()->away($url)->withHeaders([
                'Cache-Control' => 'private, max-age='.CreateCommitImageSignedUrl::MAX_AGE,
            ]);
        }

        return Storage::disk()->response($commitImage->path, $commitImage->id.'.webp', headers: [
            'Content-Type' => 'image/webp',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], disposition: $request->download() ? 'attachment' : 'inline');
    }
}
