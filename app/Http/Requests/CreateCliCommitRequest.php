<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Models\User;
use App\Queries\CommitByReferenceQuery;
use App\Rules\CommitReference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

/**
 * A commit signed by the `devnull commit` script, which replies by commit hash or link instead of by ID.
 */
final class CreateCliCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ValidationRule|File|Dimensions>>
     */
    public function rules(): array
    {
        $user = $this->user();
        assert($user instanceof User);

        return [
            'body' => CreateCommitRequest::BODY_RULES,
            'reply_to' => ['nullable', 'string', new CommitReference($user)],
            'images' => CreateCommitRequest::imageListRules(),
            'images.*' => CreateCommitRequest::imageRules(),
        ];
    }

    public function parent(): ?Commit
    {
        $user = $this->user();
        assert($user instanceof User);

        return $this->filled('reply_to')
            ? resolve(CommitByReferenceQuery::class)->get($user, $this->string('reply_to')->value())?->first()
            : null;
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        return CreateCommitRequest::imagesFrom($this);
    }

    public function prepareForValidation(): void
    {
        CreateCommitRequest::normalizeBody($this);
    }
}
