<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ScheduledCommit;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Someone else's scheduled commit is a 404, as though it didn't exist.
 */
final class DeleteApiScheduledCommitRequest extends FormRequest
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
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
