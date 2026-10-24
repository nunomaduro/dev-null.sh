<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

final readonly class UpdateMessagePin
{
    public function __construct(private UpdateMessageReactionsVersion $updateMessageReactionsVersion)
    {
        //
    }

    /**
     * Pins the message for everyone in its org, keeping when it was first pinned however often it is pinned again. The
     * conversation is locked before the message is updated, as reactions lock it, so a pin and a reaction on the same
     * message can't deadlock.
     */
    public function handle(Message $message): void
    {
        DB::transaction(function () use ($message): void {
            Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->first();

            $pinned = Message::query()->whereKey($message->id)->whereNull('pinned_at')->toBase()->update(['pinned_at' => now()]);

            if ($pinned > 0) {
                $this->updateMessageReactionsVersion->handle($message);
            }
        });
    }
}
