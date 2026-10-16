<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CommitGift;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCommitTipRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        $commit = $this->route('commit');
        assert($commit instanceof Commit);

        return match (true) {
            $commit->user_id === null || $commit->user_id === $user->id => Response::deny(__('You cannot tip your own commit.')),
            $commit->isVisibleTo($user) => Response::allow(),
            default => Response::deny(),
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'gift' => ['required', Rule::enum(CommitGift::class)],
            'message' => ['nullable', 'string', 'max:140'],
        ];
    }

    public function gift(): CommitGift
    {
        return $this->enum('gift', CommitGift::class) ?? CommitGift::Coffee;
    }

    public function message(): ?string
    {
        $message = $this->string('message')->trim()->value();

        return $message !== '' ? $message : null;
    }
}
