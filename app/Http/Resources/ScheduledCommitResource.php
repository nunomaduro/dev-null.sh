<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScheduledCommit;
use App\Services\CommitBodies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commit the user scheduled, as the web's Scheduled commits page shows it, which only they see. Until it is
 * submitted it has its body, split like a commit's, the options of its poll and how many images it carries. Once
 * submitted, those belong to the commit it became: the body is null, the status is that commit's (or deleted once it
 * is), and commit_id is that commit while it exists.
 *
 * @mixin ScheduledCommit
 */
final class ScheduledCommitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $commit = $this->submitted_at === null || $this->commit?->trashed() !== false ? null : $this->commit;

        return [
            'id' => $this->id,
            ...($this->submitted_at === null && $this->body !== null
                ? ['body' => $this->body, ...resolve(CommitBodies::class)->segments($this->body)]
                : ['body' => null, 'body_segments' => [], 'preview_segments' => null]),
            'poll_options' => $this->poll['options'] ?? null,
            'image_count' => count($this->images ?? []),
            'scheduled_at' => $this->scheduled_at->toIso8601ZuluString(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'status' => $this->submitted_at === null ? 'scheduled' : ($commit?->status->value ?? 'deleted'),
            'commit_id' => $commit?->id,
        ];
    }
}
