<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CreateCommitImageSignedUrl;
use App\Models\Commit;
use App\Models\CommitImage;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;

final class ShowCommitImageRequest extends FormRequest
{
    private ?string $signedUrl = null;

    /**
     * Pending and rejected images never leave the server for anyone but their author and moderators, so only images anyone may see get a signed URL that works without this check.
     * Cloud storage answers a missing file behind a signed URL itself, so storage is only asked about files streamed from here.
     */
    public function authorize(CreateCommitImageSignedUrl $action): Response
    {
        $commitImage = $this->route('commitImage');
        assert($commitImage instanceof CommitImage);

        $commit = Commit::query()->find($commitImage->commit_id);

        if (! $commit instanceof Commit || ! $commit->imagesAreVisibleTo($this->user())) {
            return Response::denyAsNotFound();
        }

        $this->signedUrl = $commit->imagesAreVisibleTo(null)
            ? $action->handle($commitImage, $this->download())
            : null;

        return $this->signedUrl !== null || Storage::disk()->exists($commitImage->path)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Where the image can be fetched straight from cloud storage, if it can.
     */
    public function signedUrl(): ?string
    {
        return $this->signedUrl;
    }

    public function download(): bool
    {
        return $this->boolean('download');
    }
}
