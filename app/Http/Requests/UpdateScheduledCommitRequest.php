<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ScheduledCommit;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateScheduledCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scheduled = $this->route('scheduledCommit');
        assert($scheduled instanceof ScheduledCommit);

        return $this->user()?->id === $scheduled->user_id;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now', 'before_or_equal:'.now()->addDays(30)->toIso8601String()],
        ];
    }
}
