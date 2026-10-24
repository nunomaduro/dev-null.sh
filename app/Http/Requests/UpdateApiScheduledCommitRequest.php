<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ScheduledCommit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new body and time for a commit the user scheduled, with the rules it was scheduled with. Someone else's scheduled
 * commit is a 404, as though it didn't exist.
 */
final class UpdateApiScheduledCommitRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        $scheduled = $this->route('scheduledCommit');
        assert($scheduled instanceof ScheduledCommit);

        return $scheduled->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Blank lines are collapsed before the body is measured, as on the web.
     */
    public function prepareForValidation(): void
    {
        CreateCommitRequest::normalizeBody($this);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        $scheduled = $this->route('scheduledCommit');
        assert($scheduled instanceof ScheduledCommit);

        return [
            'body' => CreateCommitRequest::bodyRules($scheduled->images !== null),
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now', 'before_or_equal:'.now()->addDays(30)->toIso8601String()],
        ];
    }

    /**
     * When the commit is now due, in UTC.
     */
    public function scheduledAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('scheduled_at')->value())->utc();
    }
}
