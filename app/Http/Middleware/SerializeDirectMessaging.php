<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conversation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final readonly class SerializeDirectMessaging
{
    /**
     * Hold the same ordered user locks for sending, request decisions, privacy changes and blocks.
     * Form requests authorize inside this transaction, against refreshed state.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $user = $request->user();
            assert($user instanceof User);
            $ids = [$user->id];
            $recipient = $request->route('user');
            $conversation = $request->route('conversation');

            if ($recipient instanceof User) {
                $ids[] = $recipient->id;
            }

            if ($conversation instanceof Conversation) {
                $ids[] = $conversation->first_user_id;
                $ids[] = $conversation->second_user_id;
            }

            User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $user->refresh();
            if ($recipient instanceof User) {
                $recipient->refresh();
            }

            if ($conversation instanceof Conversation) {
                $conversation->refresh();
            }

            return $next($request);
        }, 3);
    }
}
