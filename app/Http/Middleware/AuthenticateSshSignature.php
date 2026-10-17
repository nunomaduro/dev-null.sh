<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SignedCommits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
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
        try {
            $commit = $this->signedCommits->verify(
                $request->getContent(),
                $request->headers->get('X-SSH-Signature', '') ?? '',
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            abort(401, $invalidArgumentException->getMessage());
        }

        Auth::setUser($commit['user']);

        // Attributes are server-owned. Request input cannot forge this verified proof.
        $request->attributes->set('verified_ssh_proof', $commit['proof']);

        $request->merge([
            'body' => $commit['body'],
            'reply_to' => $commit['reply_to'],
        ]);

        return $next($request);
    }
}
