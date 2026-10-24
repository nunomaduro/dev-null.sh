<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

final readonly class DeleteMessagePin
{
    public function __construct(private UpdateMessageReactionsVersion $updateMessageReactionsVersion)
    {
        //
    }

    /**
     * The conversation is locked before the message is updated, as reactions lock it, so an unpin and a reaction on
     * the same message can't deadlock.
     */
    public function handle(Message $message): void
    {
        DB::transaction(function () use ($message): void {
            Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->first();

            $unpinned = Message::query()->whereKey($message->id)->whereNotNull('pinned_at')->toBase()->update(['pinned_at' => null]);

            if ($unpinned > 0) {
                $this->updateMessageReactionsVersion->handle($message);
            }
        });
    }
}
