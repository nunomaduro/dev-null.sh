<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Conversation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * A reply goes to a message in the same conversation, within the history the sender can see or pinned for everyone in
 * it, and never to an event.
 */
final readonly class RepliableMessage implements ValidationRule
{
    public function __construct(private ?Conversation $conversation, private int $visibleFrom = 0)
    {
        //
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isRepliable = $this->conversation instanceof Conversation
            && is_numeric($value)
            && $this->conversation->messages()
                ->whereKey((int) $value)
                ->where(fn (Builder $query): Builder => $query->where('id', '>', $this->visibleFrom)->orWhereNotNull('pinned_at'))
                ->whereNull('event')
                ->exists();

        if (! $isRepliable) {
            $fail('That message is no longer available to reply to.');
        }
    }
}
