<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Poll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A poll as the web shows it, with each option's share of the votes rounded the way the web rounds it. Counts and
 * shares are null until the user voted, wrote the poll, or it closed, so they can't sway a vote.
 *
 * @mixin Poll
 */
final class PollResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof Poll);

        $poll = $this->resource->toArray();
        $total = $poll['total_votes'];

        return [
            ...$poll,
            'options' => array_map(fn (array $option): array => [
                ...$option,
                'percentage' => match (true) {
                    $total === null => null,
                    $total === 0 => 0,
                    default => (int) round(($option['votes_count'] ?? 0) / $total * 100),
                },
            ], $poll['options']),
        ];
    }
}
