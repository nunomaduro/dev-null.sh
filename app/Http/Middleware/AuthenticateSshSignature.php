<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\CreateCommitImages;
use App\Services\SignedCommits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a commit signed with the author's SSH key, as sent by the `devnull commit` script.
 */
final readonly class AuthenticateSshSignature
{
    public function __construct(private SignedCommits $signedCommits)
    {
        //
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $withImages = $request->files->has('images');

        try {
            $commit = $this->signedCommits->verify(
                $withImages ? $this->payloadFile($request) : $request->getContent(),
                $request->headers->get('X-SSH-Signature', '') ?? '',
                $withImages ? fn (): array => $this->imageHashes($request) : null,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            abort(401, $invalidArgumentException->getMessage());
        }

        Auth::setUser($commit['user']);

        $request->merge([
            'body' => $commit['body'],
            'reply_to' => $commit['reply_to'],
        ]);

        return $next($request);
    }

    private function payloadFile(Request $request): string
    {
        $payload = $request->files->get('payload');
        $contents = $payload instanceof UploadedFile && $payload->isValid() ? file_get_contents($payload->getPathname()) : false;

        return is_string($contents) ? $contents : '';
    }

    /**
     * @return list<string>
     */
    private function imageHashes(Request $request): array
    {
        $images = $request->files->all()['images'] ?? [];
        $images = is_array($images) ? $images : [$images];

        throw_if(count($images) > CreateCommitImages::MAX_IMAGES, InvalidArgumentException::class, 'The attached images do not match the signed payload.');

        $hashes = [];

        foreach ($images as $image) {
            if ($image instanceof UploadedFile && ! $image->isValid()) {
                throw ValidationException::withMessages(['images' => __('validation.uploaded', ['attribute' => 'image'])]);
            }

            $path = $image instanceof UploadedFile ? $image->getRealPath() : false;
            $hash = is_string($path) ? hash_file('sha256', $path) : false;

            throw_if($hash === false, InvalidArgumentException::class, 'The attached images could not be read.');

            $hashes[] = $hash;
        }

        return $hashes;
    }
}
